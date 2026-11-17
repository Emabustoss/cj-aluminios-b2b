#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Genera woocommerce_import_cjmet.csv a partir de los dos reportes de OLF de
CJ MET (Cristian Joannou):

  * Lista de precios  (OLFS - Lista de precios, "Lista: 01 CONS FINAL"):
    CODIGO, ARTICULO (línea), ORIGEN (matriz), DESCRIPCION (30 car.),
    RUBRO, SUBRUBRO, ..., LST[01] (precio de lista SIN IVA).
  * Maestro de artículos: Codigo, Descripcion (34 car.), Rubro, Stock,
    Importe A (precio de lista CON IVA = LST[01] x 1,21).

Cruza ambos por código (CJM...), descarta encabezados repetidos, filas vacías,
códigos que no son CJM (GASTOS, RECHAZO, 123456...) y artículos con precio 0.

Uso (desde la carpeta del plugin):
    python scripts/generar_csv_cjmet.py
    python scripts/generar_csv_cjmet.py --lista X.pdf --maestro Y.pdf --salida Z.csv

IMPORTANTE (unidades): en OLF los perfiles se venden POR KILO y el precio de
lista está en la moneda del sistema de gestión (el presupuesto 0105-00001444
convierte 9,75 por kg a $12.722,75 por kg, cotización ≈ 1.305). "Regular price"
queda tal cual figura en la lista (con IVA); revisar la moneda antes de
importar. Ver el informe que imprime el script al final.
"""
from __future__ import annotations

import argparse
import csv
import json
import re
import subprocess
import sys
import unicodedata
from collections import Counter, OrderedDict
from pathlib import Path

try:
    import pymupdf
except ImportError:  # versiones viejas de PyMuPDF
    import fitz as pymupdf  # type: ignore

PLUGIN_DIR = Path(__file__).resolve().parent.parent
PDF_DIR = PLUGIN_DIR / "Catalogos_pdf"

RE_CODIGO = re.compile(r"^CJM\d{6}$")
RE_FILA_LISTA = re.compile(r"\d+\.\d{4}\s*$")
RE_FILA_MAESTRO = re.compile(
    r"^(?P<cod>\S+)\s+(?P<desc>.*?)\s+(?P<rubro>PERFILES|ACCESORIOS)\s+(?P<stock>-?[\d.]+)\s+(?P<importe>[\d.]+)\s+\S\s*$"
)

# Línea de OLF (columna ARTICULO) -> subcategoría de Perfiles.
LINEAS = {
    "MODENA": ("Módena", "Módena"),
    "HERRERO": ("Herrero", "Herrero"),
    "A30 o ESPECIA": ("A30", "A30"),
    "ESPECIAL": ("Especiales", "especial"),
    "Estructural": ("Estructurales", ""),
    "ESTRUCTURAL": ("Estructurales", ""),
    "F.I": ("Frente Integral", "Frente Integral"),
}

# Rubro/subrubro de accesorios -> nombre de familia.
FAMILIAS = {
    "PLASTICOS": "Plásticos", "RUEDAS": "Ruedas", "TIRADORES": "Tiradores", "CIERRES": "Cierres",
    "ALDABA": "Aldabas", "ESCUADRAS": "Escuadras", "BISAGRAS": "Bisagras", "BURLETES": "Burletes",
    "MECANISMOS": "Mecanismos", "PANELES": "Paneles", "PUERTAS PL": "Puertas plegadizas",
    "TEJIDOS": "Tejidos", "SILICONA": "Siliconas", "TORNILLOS": "Tornillos", "FELPAS": "Felpas",
    "GRAMPAS": "Grampas", "PASADORES": "Pasadores", "FALLEBA": "Fallebas", "LUBRICANTE": "Lubricantes",
    "VARIOS": "Varios",
}

# Orden fijo de las 12 variantes de cada perfil en OLF. Los estructurales no
# traen el tratamiento (todos dicen "Estructura"), pero sus precios siguen el
# mismo patrón (la 5.ª variante cuesta ~x1,8: símil madera), así que se asigna
# por posición y se marca como inferido.
ORDEN_12 = ["sin", "pin", "pin", "pin", "sim", "ano", "ano", "ano", "ano", "ano", "ano", "pin"]

TRATAMIENTOS = {
    "sin": "Natural",
    "pin": "Pintura",
    "sim": "Símil madera",
    "ano": "Anodizado",
    "est": "",
}

# Abreviaturas frecuentes de las descripciones de OLF.
ABREVIATURAS = [
    (r"\bVna\b", "ventana"), (r"\bPrta\b", "puerta"), (r"\bPrt\b", "puerta"), (r"\bcral\b", "central"),
    (r"\bPano\b", "Paño"), (r"\bpano\b", "paño"), (r"\bTravesano\b", "Travesaño"), (r"\bZocalo\b", "Zócalo"),
    (r"\bGuia\b", "Guía"), (r"\bcamara\b", "cámara"), (r"\bmosqui\b", "mosquitero"), (r"\brever\.?", "reversible"),
    (r"\bBCO\b", "Blanco"), (r"\bNG\b", "Negro"), (r"\bPatin\b", "Patín"), (r"\bAngulo\b", "Ángulo"),
    (r"\bmetalica\b", "metálica"), (r"\bplastica\b", "plástica"), (r"\bcor\b", "corrediza"),
    (r"\bcorr\b", "corrediza"), (r"\bcorred\b", "corrediza"),
]


def texto_pdf(ruta: Path) -> list[str]:
    doc = pymupdf.open(ruta)
    return [linea for pagina in doc for linea in pagina.get_text().splitlines()]


def leer_lista(ruta: Path) -> "OrderedDict[str, dict]":
    lineas = texto_pdf(ruta)
    cab = next(l for l in lineas if "CODIGO" in l and "LST[" in l)
    nombres = ["CODIGO", "ARTICULO", "ORIGEN", "DESCRIPCION", "RUBRO", "SUBRUBRO", "ULT.PRECIO", "ULT.COMPRA", "COSTO", "LST["]
    pos = [cab.index(n) for n in nombres]
    salida: "OrderedDict[str, dict]" = OrderedDict()
    for l in lineas:
        # Solo filas de datos: descarta encabezados de página, subrayados y vacías.
        if not re.match(r"\s+\S", l) or not RE_FILA_LISTA.search(l) or "CODIGO" in l:
            continue
        campos = [l[pos[i] - 2:(pos[i + 1] - 2) if i + 1 < len(pos) else None].strip() for i in range(len(pos))]
        cod = campos[0]
        salida[cod] = {
            "codigo": cod, "linea": campos[1], "origen": campos[2], "descripcion": campos[3],
            "rubro": campos[4], "subrubro": campos[5], "lista_sin_iva": float(campos[9] or 0),
        }
    return salida


def leer_maestro(ruta: Path) -> dict[str, dict]:
    salida = {}
    for l in texto_pdf(ruta):
        m = RE_FILA_MAESTRO.match(l.rstrip())
        if m:
            salida[m["cod"]] = {"descripcion": m["desc"].strip(), "importe_con_iva": float(m["importe"])}
    return salida


def unir_descripciones(lista: str, maestro: str) -> str:
    """El maestro trae 4 caracteres más pero cambia "/ - [ ]" por puntos; la lista los conserva."""
    base = maestro if len(maestro) >= len(lista) else lista
    chars = list(base)
    for i, c in enumerate(lista[: len(chars)]):
        if chars[i] == "." and c in "/-[]":
            chars[i] = c
    return "".join(chars).strip()


def limpiar(texto: str) -> str:
    t = re.sub(r"\s*[aAbBeE]\[mm\]", "", texto)          # "15 a[mm] x 15 a[mm]" -> "15 x 15"
    t = re.sub(r"^(\S+) - ", lambda m: m.group(1) + " ", t)  # "Angulo - 15" -> "Angulo 15"
    t = re.sub(r"\s+", " ", t).strip(" .-")
    t = re.sub(r"\s+x$", "", t)
    for patron, reemplazo in ABREVIATURAS:
        t = re.sub(patron, reemplazo, t)
    t = re.sub(r"\s*/\s*", "/", t)
    return t[:1].upper() + t[1:] if t else t


def nombres_existentes(wp: list[str] | None) -> dict[tuple[str, str], str]:
    """(línea, matriz) -> nombre ya cargado en la tienda (opcional, vía WP-CLI)."""
    if not wp:
        return {}
    php = (
        'global $wpdb; echo json_encode($wpdb->get_results("SELECT m.meta_value matriz, p.post_title t, t.slug cat '
        'FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key=\'_codigo_matriz\' '
        'JOIN {$wpdb->term_relationships} r ON r.object_id=p.ID JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id '
        'AND tt.taxonomy=\'product_cat\' JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE p.post_type=\'product\'"));'
    )
    try:
        out = subprocess.run(wp + ["eval", php], capture_output=True, text=True, encoding="utf-8", timeout=120).stdout
        datos = json.loads(out[out.index("["):])
    except Exception:  # noqa: BLE001 - es una mejora opcional
        return {}
    linea_por_cat = {"linea-modena": "MODENA", "linea-herrero": "HERRERO", "a30-new": "A30 o ESPECIA", "mampara-de-bano": "MAMPARA",
                     "estructurales": "Estructural"}
    mapa = {}
    for d in datos:
        linea = linea_por_cat.get(d["cat"])
        if linea == "Estructural":
            mapa[(linea, d["matriz"].replace("-", "."))] = d["t"]
        elif linea:
            mapa[(linea, d["matriz"])] = d["t"]
            if linea == "A30 o ESPECIA":
                mapa[("ESPECIAL", d["matriz"])] = d["t"]
        if linea == "MODENA":
            mapa[("ESPECIAL", d["matriz"])] = d["t"]
    return mapa


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--lista", default=str(PDF_DIR / "20327415779_MaestroArticulos_20261117_84058.pdf"))
    ap.add_argument("--maestro", default=str(PDF_DIR / "Maestro_de_Articulos-20327415779_2026_11_17_JN.pdf"))
    ap.add_argument("--salida", default=str(PDF_DIR / "woocommerce_import_cjmet.csv"))
    ap.add_argument("--wp", help='comando de WP-CLI con sus partes separadas por "|", para reutilizar los nombres de la tienda '
                    '(ej. "php|-c|php.ini|wp-cli.phar|--path=C:/sitio")')
    args = ap.parse_args()
    sys.stdout.reconfigure(encoding="utf-8")

    lista = leer_lista(Path(args.lista))
    maestro = leer_maestro(Path(args.maestro))
    existentes = nombres_existentes(args.wp.split("|") if args.wp else None)

    # Variantes de un mismo perfil: se numeran por tratamiento (Pintura 1, 2...).
    grupos: dict[tuple[str, str], list[str]] = {}
    for cod, a in lista.items():
        if a["rubro"] == "PERFILES":
            grupos.setdefault((a["linea"], a["origen"]), []).append(cod)

    filas, descartados, sin_maestro = [], Counter(), []
    for cod, a in lista.items():
        if not RE_CODIGO.match(cod):
            descartados["código no CJM"] += 1
            continue
        if a["lista_sin_iva"] <= 0:
            descartados["precio 0"] += 1
            continue

        m = maestro.get(cod)
        if not m:
            sin_maestro.append(cod)
        desc = limpiar(unir_descripciones(a["descripcion"], m["descripcion"] if m else ""))
        precio = m["importe_con_iva"] if m else round(a["lista_sin_iva"] * 1.21, 2)

        if a["rubro"] == "PERFILES":
            categoria, linea_txt = LINEAS.get(a["linea"], ("Otros", ""))
            if desc.lower().startswith("servicio"):
                categoria = "Servicios"
            matriz = a["origen"].replace(".", "-") if a["linea"].lower() == "estructural" else a["origen"]
            base = existentes.get((a["linea"], a["origen"])) or desc
            grupo = grupos[(a["linea"], a["origen"])]
            claves = [lista[c]["subrubro"][:3].lower() for c in grupo]
            inferido = False
            if set(claves) == {"est"}:
                if len(grupo) == 12:
                    claves, inferido = list(ORDEN_12), True
                else:
                    # Una sola variante: el color ya suele venir en la descripción.
                    claves = ["est"] * len(grupo) if len(grupo) == 1 else ["var"] * len(grupo)
            trat_clave = claves[grupo.index(cod)]
            trat = TRATAMIENTOS.get(trat_clave, "Variante")
            hermanos = [c for c, k in zip(grupo, claves) if k == trat_clave]
            if len(hermanos) > 1 and trat:
                trat = f"{trat} {hermanos.index(cod) + 1}"
            partes = [base]
            if linea_txt and linea_txt.lower() not in base.lower():
                partes.append(linea_txt)
            if matriz and matriz.lower() not in base.lower() and not a["linea"].upper().startswith("ESTRUCTURAL") or a["linea"] == "Estructural":
                partes.append(matriz)
            nombre = " ".join(p for p in partes if p)
            if trat and trat.lower() not in nombre.lower() and categoria != "Servicios":
                nombre += f" - {trat}"
            categorias = f"Perfiles > {categoria}"
            tratamiento_meta = a["subrubro"] + (" (tratamiento inferido por posición)" if inferido else "")
        else:
            nombre = desc
            if a["origen"] and a["origen"].split()[0].lower() not in nombre.lower():
                nombre += f" ({a['origen']})"
            categorias = f"Accesorios > {FAMILIAS.get(a['subrubro'], a['subrubro'].title())}"
            matriz, tratamiento_meta = a["origen"], ""

        filas.append({
            "Type": "simple",
            "SKU": cod,
            "Name": re.sub(r"\s+", " ", nombre).strip(),
            "Published": "1",
            "Regular price": f"{precio:.2f}",
            "Categories": categorias,
            "In stock?": "1",
            "Stock": "",
            "Meta: _cjm_lista01_sin_iva": f"{a['lista_sin_iva']:.4f}",
            "Meta: _cjm_origen": matriz,
            "Meta: _cjm_linea_olf": a["linea"],
            "Meta: _cjm_tratamiento_olf": tratamiento_meta,
        })

    # Nombres que igual quedan repetidos (mismo texto en OLF): se agrega el código.
    repetidos = Counter(f["Name"] for f in filas)
    for f in filas:
        if repetidos[f["Name"]] > 1:
            f["Name"] = f"{f['Name']} ({f['SKU']})"

    salida = Path(args.salida)
    with open(salida, "w", newline="", encoding="utf-8-sig") as fh:
        w = csv.DictWriter(fh, fieldnames=list(filas[0]))
        w.writeheader()
        w.writerows(filas)

    nombres = Counter(f["Name"] for f in filas)
    print(f"Artículos leídos: lista {len(lista)} | maestro {len(maestro)}")
    print(f"Filas exportadas: {len(filas)} -> {salida}")
    print("Descartados:", dict(descartados))
    print("Sin dato en el maestro (precio = LST x 1,21):", len(sin_maestro), sin_maestro[:10])
    print("Por categoría:", dict(Counter(f["Categories"] for f in filas).most_common()))
    print("Nombres repetidos:", sum(n - 1 for n in nombres.values() if n > 1))
    return 0


if __name__ == "__main__":
    sys.exit(main())
