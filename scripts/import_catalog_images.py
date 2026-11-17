#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Importador de imágenes de catálogo — CJ Aluminios B2B (Fase 4).

Lee los PDF del fabricante que estén en `Catalogos_pdf/`, recorta cada plano
técnico (perfiles) o foto (accesorios) sobre un lienzo blanco cuadrado de
800x800 px, guarda los PNG en `wp-content/uploads/cj-catalogo/` y los deja como
imagen destacada (`_thumbnail_id`) del producto correspondiente usando WP-CLI
(`wp media import --skip-copy`).

Cómo se reconoce cada cosa:
  * Perfiles: etiqueta "<código de matriz> - DESCRIPCIÓN - PESO: ..." y, debajo,
    el plano vectorial. Se cruza con la meta `_codigo_matriz` del producto.
  * Estructurales: el PDF trae un esquema por familia (ángulos, planchuela,
    tubos...) y una tabla de códigos. Cada código usa el esquema de su familia.
  * Accesorios: grilla de fotos con el rótulo arriba. Se cruza por SKU
    (`ACC-<CÓDIGO>`, ver `cj-catalogo/manifest.csv`). Si el producto todavía no
    existe la foto queda extraída y se vincula sola en la próxima corrida.

Es idempotente: cada adjunto guarda su clave y el hash del PNG; si nada cambió
no se vuelve a importar nada. No pisa una imagen destacada cargada a mano
(salvo `--force`).

Uso (desde la carpeta del plugin):
    python scripts/import_catalog_images.py                 # todo
    python scripts/import_catalog_images.py --dry-run       # solo informa
    python scripts/import_catalog_images.py --solo perfiles # o accesorios

En LocalWP, donde `wp` no está en el PATH:
    python scripts/import_catalog_images.py ^
      --php "C:\\...\\php.exe" --php-ini "C:\\...\\php.ini" --wp-cli "C:\\...\\wp-cli.phar"

Requisitos: pip install pymupdf pillow
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import re
import subprocess
import sys
import tempfile
import unicodedata
from dataclasses import dataclass, field
from pathlib import Path

try:
    import pymupdf
except ImportError:  # versiones viejas de PyMuPDF
    import fitz as pymupdf  # type: ignore
from PIL import Image, ImageChops, ImageDraw, ImageFont

LIENZO = 800          # px, lado del PNG final
MARGEN = 40           # px, aire alrededor del recorte
CARPETA_UPLOADS = "cj-catalogo"
CLAVE_PLACEHOLDER = "placeholder-sin-imagen"

PLUGIN_DIR = Path(__file__).resolve().parent.parent
WP_ROOT = PLUGIN_DIR.parent.parent.parent
HELPER_PHP = Path(__file__).resolve().parent / "wp_catalog_images.php"
MAPA_ACCESORIOS = Path(__file__).resolve().parent / "accesorios_imagenes.csv"

RE_ETIQUETA = re.compile(r"^\s*([A-Z0-9]{1,6}(?: [A-Z0-9]{1,4})?)\s*-\s+\S")
RE_NUMERO = re.compile(r"^[\d.,\s°º]+$")
RE_ESTRUCTURAL = re.compile(r"^(?:NL|NP|NU|NT|TC)-\d+$")
RE_TITULO_FAMILIA = re.compile(r"^[A-ZÁÉÍÓÚÑ ]{5,}$")
RE_PRESENTACION = re.compile(r"\s+X\s*\d+.*$", re.I)


@dataclass
class Recorte:
    """Una imagen lista para importar."""
    clave: str                    # nombre de archivo sin extensión (único)
    tipo: str                     # perfil | estructural | accesorio | placeholder
    codigo: str                   # código de matriz / rótulo
    nombre: str                   # descripción legible del PDF
    origen: str                   # "archivo.pdf p.N"
    png: bytes = b""
    sku_sugerido: str = ""
    productos: list[int] = field(default_factory=list)

    @property
    def hash(self) -> str:
        return hashlib.sha1(self.png).hexdigest()


# --------------------------------------------------------------------------- #
# Utilidades de imagen y texto
# --------------------------------------------------------------------------- #

def slug(texto: str) -> str:
    t = unicodedata.normalize("NFKD", texto).encode("ascii", "ignore").decode()
    return re.sub(r"[^a-z0-9]+", "-", t.lower()).strip("-")


def a_lienzo(img: Image.Image) -> bytes:
    """Centra la imagen sobre un cuadrado blanco de LIENZO px y devuelve el PNG."""
    img = img.convert("RGB")
    util = LIENZO - 2 * MARGEN
    escala = min(util / img.width, util / img.height)
    img = img.resize((max(1, round(img.width * escala)), max(1, round(img.height * escala))), Image.LANCZOS)
    lienzo = Image.new("RGB", (LIENZO, LIENZO), "white")
    lienzo.paste(img, ((LIENZO - img.width) // 2, (LIENZO - img.height) // 2))
    salida = io.BytesIO()
    lienzo.save(salida, "PNG", optimize=True)
    return salida.getvalue()


def render(pagina, rect, aire: float = 5, tapar=()) -> bytes:
    """Rasteriza una zona de la página a la resolución justa para el lienzo.

    `tapar` son rectángulos (rótulos, sellos) que se blanquean si caen dentro
    del recorte; después se descarta el margen blanco sobrante.
    """
    clip = (pymupdf.Rect(rect) + (-aire, -aire, aire, aire)) & pagina.rect
    zoom = min(16.0, (LIENZO - 2 * MARGEN) / max(clip.width, clip.height, 1))
    pix = pagina.get_pixmap(matrix=pymupdf.Matrix(zoom, zoom), clip=clip, alpha=False)
    img = Image.open(io.BytesIO(pix.tobytes("png"))).convert("RGB")
    lapiz = ImageDraw.Draw(img)
    for t in tapar:
        if clip.intersects(t):
            lapiz.rectangle([(t.x0 - clip.x0 - 1) * zoom, (t.y0 - clip.y0 - 1) * zoom,
                             (t.x1 - clip.x0 + 1) * zoom, (t.y1 - clip.y0 + 1) * zoom], fill="white")
    if tapar:
        caja = ImageChops.difference(img, Image.new("RGB", img.size, "white")).getbbox()
        if caja:
            img = img.crop(caja)
    return a_lienzo(img)


def lineas_de_texto(pagina):
    """Devuelve [(rect, texto, [spans])] de cada línea de texto de la página."""
    out = []
    for bloque in pagina.get_text("dict")["blocks"]:
        if bloque.get("type") != 0:
            continue
        for linea in bloque["lines"]:
            texto = unicodedata.normalize("NFKC", "".join(s["text"] for s in linea["spans"])).strip()
            if texto:
                out.append((pymupdf.Rect(linea["bbox"]), texto, linea["spans"]))
    return out


def zona_util(pagina):
    """Área de contenido: sin la banda decorativa, el encabezado ni el pie."""
    return pymupdf.Rect(62, 70, pagina.rect.width - 4, pagina.rect.height - 64)


def agrupar(rects, tolerancia: float = 5):
    """Une rectángulos que se tocan (con tolerancia) y devuelve los grupos."""
    padre = list(range(len(rects)))

    def raiz(i):
        while padre[i] != i:
            padre[i] = padre[padre[i]]
            i = padre[i]
        return i

    inflados = [r + (-tolerancia, -tolerancia, tolerancia, tolerancia) for r in rects]
    for i in range(len(rects)):
        for j in range(i + 1, len(rects)):
            if inflados[i].intersects(rects[j]):
                padre[raiz(i)] = raiz(j)
    grupos: dict[int, pymupdf.Rect] = {}
    for i, r in enumerate(rects):
        k = raiz(i)
        grupos[k] = (grupos[k] | r) if k in grupos else pymupdf.Rect(r)
    return list(grupos.values())


def distancia(a, b) -> float:
    """Separación entre dos rectángulos (0 si se tocan)."""
    dx = max(a.x0 - b.x1, b.x0 - a.x1, 0)
    dy = max(a.y0 - b.y1, b.y0 - a.y1, 0)
    return (dx * dx + dy * dy) ** 0.5


def trazos(pagina, zona):
    """Rectángulos de los dibujos vectoriales del contenido (sin adornos)."""
    out = []
    for d in pagina.get_drawings():
        r = pymupdf.Rect(d["rect"])
        if r.is_empty and r.width == 0 and r.height == 0:
            continue
        if not zona.contains(r) or r.get_area() > zona.get_area() * 0.6:
            continue
        if es_sello(d):
            continue
        out.append(r)
    return out


def es_sello(dibujo) -> bool:
    """Sello rojo "Diferenciado" que el catálogo pone sobre algunas etiquetas."""
    relleno = dibujo.get("fill")
    return bool(relleno and relleno[0] > 0.8 and relleno[1] < 0.3)


# --------------------------------------------------------------------------- #
# Extracción: perfiles
# --------------------------------------------------------------------------- #

def extraer_perfiles(pdf: Path) -> list[Recorte]:
    doc = pymupdf.open(pdf)
    recortes: dict[str, Recorte] = {}
    estructurales: list[Recorte] = []   # uno por código: varios comparten esquema
    for n, pagina in enumerate(doc, start=1):
        zona = zona_util(pagina)
        lineas = [l for l in lineas_de_texto(pagina) if zona.intersects(l[0])]
        if any(RE_ESTRUCTURAL.match(t) for _, t, _ in lineas):
            estructurales += extraer_estructurales(pagina, lineas, zona, f"{pdf.name} p.{n}")
            continue

        # Etiquetas "CÓDIGO - DESCRIPCIÓN": se buscan por tramo (span) porque
        # PyMuPDF a veces une en una sola línea las dos columnas de la página.
        etiquetas = []
        for _, _, spans in lineas:
            for i, s in enumerate(spans):
                # Un tramo abre etiqueta si es el primero de la línea o si está
                # separado del anterior (otra columna).
                if i and s["bbox"][0] - spans[i - 1]["bbox"][2] < 15:
                    continue
                resto = "".join(x["text"] for x in spans[i:])
                m = RE_ETIQUETA.match(resto)
                if not m or not any(c.isdigit() for c in m.group(1)):
                    continue
                desc = re.split(r"-?\s*PESO", resto.split("-", 1)[1], flags=re.I)[0]
                desc = re.split(r"\s{2,}\d", desc)[0]
                etiquetas.append((m.group(1), pymupdf.Rect(s["bbox"]), desc.strip(" -")))
        if not etiquetas:
            continue

        # Descripciones partidas en dos renglones: se completa con el siguiente.
        for i, (cod, rect, desc) in enumerate(etiquetas):
            for lr, lt, _ in lineas:
                if 0 < lr.y0 - rect.y0 < 14 and abs(lr.x0 - rect.x0) < 6 and not RE_ETIQUETA.match(lt):
                    extra = re.split(r"-?\s*PESO", lt, flags=re.I)[0].strip(" -")
                    if extra and "PESO" not in desc.upper():
                        etiquetas[i] = (cod, rect, (desc + " " + extra).strip())

        piezas = trazos(pagina, zona) + [r for r, t, _ in lineas if RE_NUMERO.match(t)]
        grupos = [g for g in agrupar(piezas) if g.width > 8 or g.height > 8]
        tapar = [r for r, t, _ in lineas if not RE_NUMERO.match(t)]
        tapar += [pymupdf.Rect(d["rect"]) for d in pagina.get_drawings() if es_sello(d)]
        por_etiqueta: dict[int, pymupdf.Rect] = {}
        sueltos = []
        for g in grupos:
            # La etiqueta dueña es la más cercana por encima que arranca dentro
            # del ancho del dibujo.
            cand = [
                (rect.y0, i) for i, (_, rect, _) in enumerate(etiquetas)
                if rect.y0 < g.y0 + 6 and g.x0 - 45 <= rect.x0 <= g.x1
            ]
            if cand:
                i = max(cand)[1]
                por_etiqueta[i] = (por_etiqueta[i] | g) if i in por_etiqueta else g
            else:
                sueltos.append(g)
        # Cotas sueltas (un número a la derecha de su etiqueta): van con el
        # dibujo más cercano si está a menos de 25 pt.
        for g in sueltos:
            cerca = [(distancia(g, r), i) for i, r in por_etiqueta.items()]
            if cerca and min(cerca)[0] < 25:
                i = min(cerca)[1]
                por_etiqueta[i] = por_etiqueta[i] | g
        for i, (cod, _, desc) in enumerate(etiquetas):
            if i not in por_etiqueta:
                continue
            clave = f"perfil-{slug(cod)}"
            if clave in recortes:   # código repetido en otra página: gana la primera
                continue
            recortes[clave] = Recorte(clave, "perfil", cod, desc.title(), f"{pdf.name} p.{n}",
                                      png=render(pagina, por_etiqueta[i], tapar=tapar))
    return list(recortes.values()) + estructurales


def extraer_estructurales(pagina, lineas, zona, origen) -> list[Recorte]:
    """Un esquema por familia; cada código de la tabla apunta al de su familia."""
    titulos = [(r, t) for r, t, _ in lineas if RE_TITULO_FAMILIA.match(t) and "ESCALA" not in t]
    cabeceras = [r for r, t, _ in lineas if t.startswith("Perfil")]
    piezas = trazos(pagina, zona) + [r for r, t, _ in lineas if len(t) <= 2]
    familias = []
    for rect, titulo in titulos:
        debajo = [c for c in cabeceras if c.y0 > rect.y1 and abs(c.x0 - rect.x0) < 40]
        if not debajo:
            continue
        tabla_y = min(c.y0 for c in debajo)
        franja = pymupdf.Rect(rect.x0 - 12, rect.y1 + 1, rect.x0 + 190, tabla_y - 12)
        dentro = [p for p in piezas if franja.contains(p)]
        if not dentro:
            continue
        # El esquema es el grupo de la figura más grande; así no entran los
        # bordes de las tablas vecinas.
        caja = max(agrupar(dentro, 6), key=lambda g: g.get_area())
        familias.append((rect, titulo, tabla_y, render(pagina, caja, aire=8)))

    out = []
    for r, t, _ in lineas:
        if not RE_ESTRUCTURAL.match(t):
            continue
        dueñas = [f for f in familias if f[2] < r.y0 and abs(f[0].x0 - r.x0) < 40]
        if not dueñas:
            continue
        _, titulo, _, png = max(dueñas, key=lambda f: f[2])
        out.append(Recorte(f"estructural-{slug(titulo)}", "estructural", t, titulo.title(), origen, png=png))
    return out


# --------------------------------------------------------------------------- #
# Extracción: accesorios
# --------------------------------------------------------------------------- #

def extraer_accesorios(pdf: Path) -> list[Recorte]:
    doc = pymupdf.open(pdf)
    out: list[Recorte] = []
    usados: set[str] = set()
    for n, pagina in enumerate(doc, start=1):
        zona = zona_util(pagina) | pymupdf.Rect(62, 70, pagina.rect.width, pagina.rect.height)
        lineas = lineas_de_texto(pagina)
        familia = max(lineas, key=lambda l: l[2][0]["size"])[1] if lineas else ""
        for info in pagina.get_image_info():
            caja = pymupdf.Rect(info["bbox"])
            if caja.y0 < 70 or caja.x0 < 62 or caja.width < 60 or caja.height < 60:
                continue
            rotulo = sorted(
                (l for l in lineas
                 if caja.y0 - 26 <= l[0].y0 and l[0].y1 <= caja.y0 + 2 and caja.x0 - 6 <= l[0].x0 <= caja.x1),
                key=lambda l: l[0].y0,
            )
            if not rotulo:
                continue
            primera = rotulo[0][1]
            nombre = " ".join(l[1] for l in rotulo)
            codigo = RE_PRESENTACION.sub("", primera).strip() or primera
            clave = base = f"accesorio-{slug(nombre)}"[:80].rstrip("-")
            i = 2
            while clave in usados:
                clave = f"{base}-{i}"
                i += 1
            usados.add(clave)
            sku = "ACC-" + slug(codigo).upper()
            if any(r.sku_sugerido == sku for r in out):
                sku = "ACC-" + clave[len("accesorio-"):].upper()
            out.append(Recorte(clave, "accesorio", codigo, f"{nombre} [{familia}]", f"{pdf.name} p.{n}",
                               png=render(pagina, caja, aire=0), sku_sugerido=sku))
    return out


# --------------------------------------------------------------------------- #
# Placeholder técnico neutro
# --------------------------------------------------------------------------- #

def placeholder() -> Recorte:
    img = Image.new("RGB", (LIENZO, LIENZO), "white")
    d = ImageDraw.Draw(img)
    gris, claro = (156, 163, 175), (229, 231, 235)
    for x in range(0, LIENZO, 40):          # grilla de papel técnico
        d.line([(x, 0), (x, LIENZO)], fill=(243, 244, 246))
        d.line([(0, x), (LIENZO, x)], fill=(243, 244, 246))
    # Sección genérica de un perfil tubular con aletas
    d.rectangle([220, 250, 580, 470], outline=gris, width=10)
    d.rectangle([260, 290, 540, 430], outline=claro, width=4)
    d.line([(220, 470), (160, 470), (160, 440)], fill=gris, width=10)
    d.line([(580, 250), (640, 250), (640, 280)], fill=gris, width=10)
    d.line([(220, 210), (580, 210)], fill=claro, width=3)   # cota
    d.line([(220, 200), (220, 220)], fill=claro, width=3)
    d.line([(580, 200), (580, 220)], fill=claro, width=3)
    try:
        fuente = ImageFont.load_default(size=30)
    except TypeError:
        fuente = ImageFont.load_default()
    texto = "Imagen no disponible"
    ancho = d.textlength(texto, font=fuente)
    d.text(((LIENZO - ancho) / 2, 540), texto, fill=gris, font=fuente)
    salida = io.BytesIO()
    img.save(salida, "PNG", optimize=True)
    return Recorte(CLAVE_PLACEHOLDER, "placeholder", "", "Imagen no disponible", "generado", png=salida.getvalue())


# --------------------------------------------------------------------------- #
# WP-CLI
# --------------------------------------------------------------------------- #

class WP:
    def __init__(self, args):
        if args.wp_cli and args.wp_cli.lower().endswith(".phar"):
            base = [args.php or "php"]
            if args.php_ini:
                base += ["-c", args.php_ini]
            base.append(args.wp_cli)
        else:
            base = [args.wp_cli or "wp"]
        self.base = base + [f"--path={args.wp_path}"]

    def run(self, *cmd: str) -> str:
        p = subprocess.run(self.base + list(cmd), capture_output=True, text=True, encoding="utf-8", errors="replace")
        if p.returncode != 0:
            raise SystemExit(f"WP-CLI falló ({' '.join(cmd[:3])}):\n{p.stderr.strip() or p.stdout.strip()}")
        return p.stdout

    def helper(self, accion: str, *extra: str) -> dict:
        salida = self.run("eval-file", str(HELPER_PHP), accion, *extra)
        return json.loads(salida[salida.index("{"):])   # ignora avisos previos de PHP


# --------------------------------------------------------------------------- #
# Flujo principal
# --------------------------------------------------------------------------- #

def main() -> int:
    ap = argparse.ArgumentParser(description="Extrae imágenes de los PDF de catálogo y las vincula en WooCommerce.")
    ap.add_argument("--pdf-dir", default=str(PLUGIN_DIR / "Catalogos_pdf"))
    ap.add_argument("--wp-path", default=str(WP_ROOT))
    ap.add_argument("--wp-cli", help="ejecutable wp o ruta a wp-cli.phar (por defecto: wp)")
    ap.add_argument("--php", help="php.exe a usar con wp-cli.phar")
    ap.add_argument("--php-ini", help="php.ini del sitio (LocalWP)")
    ap.add_argument("--solo", choices=["perfiles", "accesorios"], help="procesar un solo catálogo")
    ap.add_argument("--force", action="store_true", help="pisar imágenes destacadas cargadas a mano")
    ap.add_argument("--dry-run", action="store_true", help="no escribe archivos ni toca WordPress")
    args = ap.parse_args()
    sys.stdout.reconfigure(encoding="utf-8")

    pdfs = sorted(Path(args.pdf_dir).glob("*.pdf")) if Path(args.pdf_dir).is_dir() else []
    pdf_acc = [p for p in pdfs if "accesorio" in p.name.lower()]
    pdf_perf = [p for p in pdfs if p not in pdf_acc]
    if not pdfs:
        print(f"No hay PDF en {args.pdf_dir}: solo se asigna el placeholder.")

    recortes: list[Recorte] = [placeholder()]
    if args.solo != "accesorios":
        for p in pdf_perf:
            recortes += extraer_perfiles(p)
    if args.solo != "perfiles":
        for p in pdf_acc:
            recortes += extraer_accesorios(p)

    wp = WP(args)
    estado = wp.helper("estado")
    destino = Path(estado["basedir"]) / CARPETA_UPLOADS

    # Cruce con productos: perfiles por código de matriz, accesorios por SKU.
    por_matriz: dict[str, list[dict]] = {}
    por_sku: dict[str, dict] = {}
    for prod in estado["productos"]:
        if prod["matriz"]:
            por_matriz.setdefault(prod["matriz"].strip().upper(), []).append(prod)
        if prod["sku"]:
            por_sku[prod["sku"].strip().upper()] = prod

    # Accesorios: el SKU de la tienda no sale del PDF, así que el cruce foto ↔
    # SKU está en scripts/accesorios_imagenes.csv (varios SKU pueden compartir
    # foto: la misma pieza en otra presentación o color).
    fotos_por_clave: dict[str, list[str]] = {}
    if MAPA_ACCESORIOS.exists():
        with open(MAPA_ACCESORIOS, newline="", encoding="utf-8-sig") as f:
            for fila in csv.DictReader(f):
                if fila.get("sku") and fila.get("imagen"):
                    fotos_por_clave.setdefault(fila["imagen"].strip(), []).append(fila["sku"].strip().upper())

    # Un código estructural puede figurar en dos tablas del PDF (TC-031 es tubo
    # cuadrado y rectangular): gana la familia que mejor coincide con el nombre.
    def afinidad(r: Recorte, prod: dict) -> int:
        nombre = slug(prod.get("nombre", ""))
        return sum(palabra[:6] in nombre for palabra in slug(r.nombre).split("-"))

    familias_por_codigo: dict[str, list[Recorte]] = {}
    for r in recortes:
        if r.tipo == "estructural":
            familias_por_codigo.setdefault(r.codigo.upper(), []).append(r)

    finales: dict[str, Recorte] = {}
    for r in recortes:
        if r.tipo == "estructural":
            rivales = familias_por_codigo[r.codigo.upper()]
            ids = [p["id"] for p in por_matriz.get(r.codigo.upper(), [])
                   if max(rivales, key=lambda x: afinidad(x, p)) is r]
        elif r.tipo == "perfil":
            ids = [p["id"] for p in por_matriz.get(r.codigo.upper(), [])]
        elif r.tipo == "accesorio":
            skus = fotos_por_clave.get(r.clave, []) + [r.sku_sugerido]
            ids = [por_sku[s]["id"] for s in dict.fromkeys(skus) if s in por_sku]
        else:
            ids = []
        if r.clave in finales:              # esquema compartido por una familia
            finales[r.clave].productos += [i for i in ids if i not in finales[r.clave].productos]
            finales[r.clave].codigo += f", {r.codigo}"
        else:
            r.productos = ids
            finales[r.clave] = r

    # A la biblioteca de medios solo entran el placeholder y las imágenes que
    # tienen producto. Las fotos de accesorios sin producto quedan en disco y
    # en el manifiesto, y se importan solas cuando el producto exista.
    adjuntos = estado["adjuntos"]
    nuevos, viejos, vigentes, en_disco = [], [], {}, []
    for r in finales.values():
        previo = adjuntos.get(r.clave)
        archivo = destino / f"{r.clave}.png"
        if r.tipo != "placeholder" and not r.productos:
            if r.tipo == "accesorio":
                en_disco.append(r)
            continue                         # sin producto en la tienda
        if previo and previo["hash"] == r.hash and archivo.exists():
            vigentes[r.clave] = previo["id"]
            continue
        if previo:
            viejos.append(str(previo["id"]))
        nuevos.append(r)

    sin_producto = [r for r in finales.values() if r.tipo in ("perfil", "estructural") and not r.productos]
    print(f"Recortes: {sum(r.tipo == 'perfil' for r in finales.values())} perfiles, "
          f"{sum(r.tipo == 'estructural' for r in finales.values())} esquemas estructurales, "
          f"{sum(r.tipo == 'accesorio' for r in finales.values())} accesorios.")
    if estado.get("huerfanos"):
        print(f"Adjuntos huérfanos de una corrida interrumpida a limpiar: {len(estado['huerfanos'])}")
    print(f"A importar: {len(nuevos)} | sin cambios: {len(vigentes)} | a reemplazar: {len(viejos)} | "
          f"fotos de accesorios sin producto (quedan en disco): {len(en_disco)}")
    if sin_producto:
        print("Planos del PDF sin producto en la tienda:", ", ".join(r.clave if r.tipo == "estructural" else r.codigo for r in sin_producto))

    if args.dry_run:
        print("--dry-run: no se escribió nada.")
        return 0

    destino.mkdir(parents=True, exist_ok=True)
    # Adjuntos de una corrida interrumpida (sin clave): se borran y se rehacen.
    viejos += [str(i) for i in estado.get("huerfanos", [])]
    if viejos:
        wp.run("post", "delete", *viejos, "--force")
    for r in nuevos + en_disco:
        archivo = destino / f"{r.clave}.png"
        if not archivo.exists() or archivo.read_bytes() != r.png:
            archivo.write_bytes(r.png)
    for i in range(0, len(nuevos), 40):
        lote = nuevos[i:i + 40]
        # La ruta se arma con el mismo `basedir` que informa WordPress para que
        # el adjunto quede relativo a uploads (sin copia en uploads/AAAA/MM).
        rutas = [f"{estado['basedir']}/{CARPETA_UPLOADS}/{r.clave}.png" for r in lote]
        # Solo cuentan las líneas que son un ID: PHP puede colar avisos en la salida.
        salida = wp.run("media", "import", *rutas, "--skip-copy", "--porcelain")
        ids = [l.strip() for l in salida.splitlines() if l.strip().isdigit()]
        if len(ids) != len(lote):
            raise SystemExit(f"wp media import devolvió {len(ids)} IDs para {len(lote)} archivos.")
        for r, id_ in zip(lote, ids):
            vigentes[r.clave] = int(id_)

    # Manifiesto: sirve de base para armar el CSV de accesorios con sus SKU.
    with open(destino / "manifest.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["archivo", "tipo", "codigo", "sku_sugerido", "descripcion_pdf", "origen", "productos_vinculados"])
        for r in sorted(finales.values(), key=lambda r: r.clave):
            w.writerow([f"{r.clave}.png" if (r.clave in vigentes or r in en_disco) else "", r.tipo, r.codigo, r.sku_sugerido,
                        r.nombre, r.origen, " ".join(map(str, r.productos))])

    plan = {
        "force": args.force,
        "placeholder": vigentes.get(CLAVE_PLACEHOLDER),
        "items": [
            {"clave": r.clave, "hash": r.hash, "id": vigentes[r.clave], "tipo": r.tipo,
             "titulo": r.nombre, "productos": r.productos}
            for r in finales.values() if r.clave in vigentes
        ],
    }
    with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8") as tmp:
        json.dump(plan, tmp)
    try:
        res = wp.helper("vincular", tmp.name)
    finally:
        Path(tmp.name).unlink(missing_ok=True)

    print(f"Imágenes destacadas asignadas ahora: {res['asignadas']} (respetadas por ser manuales: {res['respetadas']})")
    print(f"RESULTADO → perfiles con imagen: {res['perfiles_con_imagen']} de {res['perfiles']} | "
          f"accesorios con imagen: {res['accesorios_con_imagen']} de {res['accesorios']}")
    if res["sin_imagen"]:
        print("Productos sin imagen (usan el placeholder):", ", ".join(res["sin_imagen"]))
    print(f"Archivos en: {destino}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
