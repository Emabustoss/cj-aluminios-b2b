# cj-aluminios-b2b

## Reglas de Desarrollo del Proyecto: Portal B2B CJ Aluminios

### Contexto del Proyecto
- **Empresa:** CJ Aluminios (Rosario). Distribuidora de perfiles de aluminio y herrajes.
- **Entorno:** WordPress + WooCommerce Avanzado (LocalWP).
- **Objetivo:** Plugin personalizado B2B con cálculo dinámico de precios por peso/color y gestión de roles.

### Estándares Técnicos
1. **Seguridad en WordPress:** Sanitizar todas las entradas (`sanitize_text_field`, `floatval`), escapar salidas (`esc_html`, `esc_attr`) y validar nonces en formularios de administración.
2. **Modularidad:** Dividir el código PHP en clases orientadas a objetos dentro de `/includes/`. No amontonar todo en un solo archivo.
3. **Motor de Precios:**
   - Si `es_perfil == true`: Precio Barra 6m = `(peso_metro * 6) * (precio_kilo_acabado) * (1 - descuento_rol / 100)`.
   - Si `es_perfil == false`: Usar precio fijo de WooCommerce.
   - El precio público (sin descuento de rol) se guarda en `_regular_price`/`_price` de cada variación, para que el listado de la tienda y el rango de precio de WooCommerce muestren un valor real. El descuento por rol se aplica solo en vivo (filtro de `get_price()`), nunca se guarda.
   - **Acabados (9, no 5):** Natural, Blanco, Negro, Símil Madera, Bronce, Anodizado Negro, Anodizado Especial, Anodizado Champagne, Anodizado Peltre. "Anodizado" genérico no es una opción válida (se reemplazó por sus 4 variantes).
   - **Tablas de precio por kilo (2):** "Estándar" (Herrero, Módena, A30 New, Mampara de Baño) y "Estructurales" (precio propio, más caro incluso en Natural). Qué tabla usa un producto se determina por su categoría (`product_cat` = "Estructurales" → tabla Estructurales; cualquier otra → Estándar). Son valores independientes cargados a mano en el admin, no una fórmula/porcentaje sobre el Natural.
4. **Roles B2B:** `publico` (0%), `lista_1` (Gremio B), `lista_2` (Gremio A), `mayorista_1` (Volumen).
5. **Herrajes/accesorios** (`es_perfil == false`): no llevan peso ni acabado, usan el precio fijo normal de WooCommerce. No requieren lógica especial, solo carga de datos.

### Estructura de Archivos del Plugin
```text
cj-aluminios-b2b/
├── cj-aluminios-b2b.php
└── includes/
    ├── class-admin-settings.php
    ├── class-product-fields.php
    ├── class-pricing-engine.php
    ├── class-b2b-roles.php
    ├── class-frontend.php            (Fase 2: estilos, ajustes "Catálogo y WhatsApp", helpers de rol/precio)
    ├── class-catalog-ui.php          (Fase 2: shortcode [cj_catalogo_b2b] + AJAX)
    ├── class-price-breakdown.php     (Fase 2: desglose en ficha y carrito)
    ├── class-whatsapp-checkout.php   (Fase 2: pedido pending + enlace wa.me)
    ├── class-tratamientos-catalog.php (Fase 3: paleta de 27 tratamientos + [cj_muestrario_acabados])
    ├── class-simulador-2d.php        (Fase 3: [cj_simulador_2d], validación de foto y cotización)
    ├── class-institutional-pages.php (Fase 3: [cj_home_b2b], [cj_alta_b2b], páginas y menú)
    └── class-site-navigation.php     (Fase 4: barra de cuenta con rol B2B, pie [cj_footer_b2b], lista única de enlaces)
assets/
├── css/cj-b2b-theme.css              (paleta en variables :root, mobile-first)
├── js/cj-b2b-catalog.js, cj-b2b-whatsapp.js, cj-simulador-2d.js, cj-b2b-alta.js
└── img/ambientes/*.svg               (3 ambientes de muestra del simulador, ilustraciones)
scripts/
├── import_catalog_images.py          (Fase 4: recorta planos/fotos de los PDF y los vincula por WP-CLI)
└── wp_catalog_images.php             (auxiliar del script; solo corre con `wp eval-file`)
Catalogos_pdf/                        (PDF del fabricante; no versionado, ver .gitignore)
```

### Notas de la Fase 4 (implementada, v1.3.0)
- **Enlaces en un solo lugar:** `CJB2B_Site_Navigation::enlaces()` alimenta el menú principal, la barra de cuenta y el pie. Para sumar una sección se agrega ahí y se vuelve a correr `configurar_sitio()`.
- **Barra de cuenta** (`wp_body_open`): WhatsApp, "Tu lista: <rol> −X%" si hay sesión, Mi cuenta / Ingresar, y Pedido con contador (`[data-cj-pedido-cuenta]`, lo actualiza el JS del catálogo y los fragmentos de WooCommerce).
- **Pie:** en tema de bloques es la parte de plantilla `footer` (entrada `wp_template_part` creada por `configurar_sitio()`); en tema clásico sale por `wp_footer`. Si se cambia de tema hay que volver a correr `configurar_sitio()`.
- **Colores:** ninguna regla fuera de `:root` lleva un color fijo; lo que va sobre fondo de acento usa las variables `--cj-sobre-acento-*`. Los HEX de `class-tratamientos-catalog.php` y del simulador son colores de producto (acabados), no de marca.
- **Imágenes de catálogo:** viven en `wp-content/uploads/cj-catalogo/` (no en `uploads/AAAA/MM`). Cada adjunto lleva `_cjb2b_catalog_key` y `_cjb2b_catalog_hash`; el importador solo reemplaza lo que cambió y no pisa imágenes cargadas a mano (salvo `--force`). Los productos sin imagen usan el placeholder de WooCommerce (`woocommerce_placeholder_image`), que el script reemplaza por uno técnico neutro.
- **Estructurales:** el PDF no trae un plano por medida sino un esquema por familia (ángulos, perfiles L, planchuela, U, tubo cuadrado, tubo rectangular, T); todos los productos de una familia comparten esa imagen.
- **Salida de WP-CLI en LocalWP:** PHP imprime un aviso de `php_imagick.dll` en cada llamada; cualquier script que lea la salida tiene que ignorar esas líneas.

### Notas de la Fase 3 (implementada, v1.2.0)
- **Paleta única:** `CJB2B_Tratamientos_Catalog::tratamientos()` es la única fuente de colores (14 pintados, 6 anodizados, 7 símil madera). Es una paleta visual: los precios siguen saliendo de los 9 acabados `pa_acabado` (la clave `acabado` de cada tratamiento indica con cuál se cotiza, `''` si no hay equivalencia). Los HEX son aproximados (no hay carta de color certificada del cliente).
- **Simulador:** la abertura se dibuja por código en Canvas (no usa fotos de aberturas ni máscaras) y se deforma en perspectiva a 4 esquinas. La foto del usuario se valida en el servidor (5 MB, MIME real JPG/PNG/WebP) y **no se guarda**. `marco`/`hoja` de cada línea en `CJB2B_Simulador_2D::lineas()` son anchos visuales proporcionales, no cotas de fabricación.
- **Tipologías (11, en 3 grupos):** se definen en `CJB2B_Simulador_2D::tipologias()` (`dibujo` + `hojas`) y se dibujan en `componer()` del JS. Para sumar una: agregarla al array PHP y, si el dibujo es nuevo, un `case` en `componer()`.
- **Render del simulador:** el vidrio va en una textura y capa propias, siempre opacas, y la transparencia se aplica al componer; los perfiles van en otra textura opaca. No dibujar píxeles semitransparentes en esas texturas (usar `velo()`): al deformar por triángulos dejan una grilla visible. La foto se reduce una vez a un búfer de 1280 px de lado mayor; al arrastrar se dibuja en calidad rápida y al soltar en calidad completa.
- **Tema de bloques:** el contenido se renderiza antes de `wp_enqueue_scripts`; un shortcode que llama a `wp_localize_script()` tiene que registrar el script antes (ver `render_shortcode()` del simulador).
- `wp_update_post()` quita barras: el contenido con JSON de bloques va con `wp_slash()`.
- **Alta gremio:** `[cj_alta_b2b]` crea el usuario siempre con rol `publico` (0%) y meta `cjb2b_alta_estado = pendiente`; la lista se asigna a mano (CJ Aluminios B2B → Solicitudes de gremio) y al asignarla pasa a `aprobada`. Máx. 3 envíos/hora por IP.
- Rate limiting compartido: `CJB2B_Frontend::limite_superado()` / `registrar_intento()`. Enlaces de WhatsApp: `CJB2B_Frontend::whatsapp_url()`.
- Portada y menú: `wp eval 'CJB2B_Institutional_Pages::configurar_sitio();'` (deja "Inicio" como portada y reescribe el menú; no corre sola al activar).

### Notas de la Fase 2 (implementada)
- La Fase 2 solo **lee** lo que calcula la Fase 1 (precio público en `_price` de la variación + descuento del rol). No duplicar la fórmula del precio por kilo fuera de `class-pricing-engine.php`.
- La página Carrito debe usar el shortcode clásico `[woocommerce_cart]`, no el bloque: el carrito de bloques ignora los filtros PHP (precios ocultos a visitantes) y no dispara `woocommerce_proceed_to_checkout`.
- `esc_url_raw()` borra los `%0A` de una URL: en el enlace de WhatsApp se aplica solo a la base y el texto va con `rawurlencode()`.
- Los accesorios se filtran por la categoría `accesorios`; sus familias son subcategorías de esa categoría.

## Comandos Útiles
- Git: Mantener commits atómicos por funcionalidad (ej. `feat(b2b): implement pricing hook`).

## Fase 2 (completa, v1.1.0)
1. **Navegación de catálogo por línea técnica** (Herrero, Módena, A30 New, Estructurales, Mamparas, Accesorios) — hecho: página "Catálogo B2B" con `[cj_catalogo_b2b]`.
2. **Cotizador por WhatsApp** (+54 341 5985683) en vez de checkout con pago online — hecho; el checkout estándar queda oculto (ajuste en CJ Aluminios B2B → Catálogo y WhatsApp).
3. **Desglose transparente de precios** en ficha de producto y líneas del carrito — hecho.

Cómo probar: `/catalogo-b2b/` en el sitio local. WooCommerce está en modo "Próximamente": desactivarlo temporalmente (`wp option update woocommerce_coming_soon no`) y reactivarlo al terminar. Los precios por rol se verifican por WP-CLI con `--user=<id>` (no hay usuarios de prueba con rol B2B creados).

## Fase 3 (hecha en v1.2.0: simulador, muestrario, portada y alta gremio)
Páginas: `/` (Inicio), `/simulador-de-aberturas/`, `/tratamientos-y-acabados/`, `/registro-gremio-b2b/`. El "alcance candidato" de más abajo quedó así: el visualizador se resolvió con el simulador 2D dibujado por código (ya no hacen falta las ~21 fotos base); herrajes e imágenes de producto siguen pendientes. El alta de clientes gremio ya tiene formulario.

Pendiente de contenido del cliente: validar los HEX contra el muestrario físico, validar los textos de las fichas técnicas, reemplazar los 3 ambientes ilustrados por fotos reales y confirmar horario de atención.

## Fase 4 (hecha en v1.3.0: imágenes de catálogo, navegación y preparación para producción)
1. **Imágenes de perfiles** — hecho. `scripts/import_catalog_images.py` recortó los planos de `Catalogo_CJ_Metales_2023.pdf` a PNG 800x800 sobre blanco: **206 de 207 perfiles con imagen** (164 planos propios + 42 Estructurales con el esquema de su familia). Sin imagen: `A30-6073` (el PDF no trae ese plano; usa el placeholder).
2. **Accesorios** — pospuesto por decisión del cliente (todavía no hay productos cargados). El script ya extrae las 109 fotos de `CJ-Metales-2024-Catalogo-ACCESORIO-final-1.pdf` a `uploads/cj-catalogo/accesorio-*.png` y las lista en `uploads/cj-catalogo/manifest.csv` con un `sku_sugerido` (`ACC-<CÓDIGO>`). No entran a la biblioteca de medios hasta que exista el producto.
3. **Navegación** — hecho: menú principal, barra de cuenta con rol B2B y pie B2B (ver "Notas de la Fase 4").
4. **Auditoría** — hecho: `php -l` limpio en todos los PHP; los 6 endpoints AJAX validan nonce (`check_ajax_referer`), los 5 formularios de admin validan nonce + capacidad, toda entrada pasa por `sanitize_*`/`absint`/`floatval`, la salida va escapada y la foto del simulador se valida por MIME real (extensión + `finfo` + `wp_getimagesize`) sin guardarse.
5. **Limpieza de LocalWP** — hecho: se borraron los 2 productos de prueba (`TEST-PERFIL-001`, `TEST-NOPERFIL-001`), la entrada "Hello world", la "Sample page" y las sesiones de carrito. No había pedidos ni usuarios de prueba (queda solo el administrador). El modo "Próximamente" de WooCommerce queda **activado** hasta el lanzamiento.

### Importador de imágenes: cómo correrlo
Requisitos (una vez): `pip install pymupdf pillow`. Los PDF van en `Catalogos_pdf/`. Desde la carpeta del plugin:

```bash
# LocalWP (Windows): wp no está en el PATH, se pasan PHP, php.ini y el .phar
python scripts/import_catalog_images.py --php "C:\Users\busto\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe" --php-ini "C:\Users\busto\AppData\Roaming\Local\run\G47ro8zBd\conf\php\php.ini" --wp-cli "C:\Users\busto\AppData\Local\Programs\Local\resources\extraResources\bin\wp-cli\wp-cli.phar"

# Hosting con wp en el PATH
python3 scripts/import_catalog_images.py
```

Opciones: `--dry-run` (solo informa), `--solo perfiles|accesorios`, `--force` (pisa imágenes cargadas a mano), `--pdf-dir`, `--wp-path`. Tarda unos 8 minutos la primera vez (WordPress genera las miniaturas) y segundos cuando no hay cambios. Al final informa cuántos perfiles y accesorios quedaron con imagen.

**Cuando se carguen los accesorios:** crear los productos simples con `es_perfil = no`, categoría `accesorios` (subcategoría por familia) y el SKU de la columna `sku_sugerido` de `manifest.csv` (o editar el SKU a gusto y renombrar igual en el CSV de carga). Volver a correr el script: vincula sola cada foto con su producto.

### Guía de carga de precios por kilo faltantes
Hoy están en 0 (y por eso esos acabados muestran "Consultar" y no se pueden pedir):
- **Tabla Estándar:** Símil Madera, Anodizado Negro, Anodizado Especial, Anodizado Champagne, Anodizado Peltre.
- **Tabla Estructurales:** los 9 acabados (toda la tabla).

Pasos:
1. Admin → **CJ Aluminios B2B → Precios por Kilo**.
2. Completar el precio por kilo **público** (sin descuento de lista) de cada acabado, en cada tabla. Son valores independientes: Estructurales no se calcula a partir de Estándar.
3. **Guardar.** El plugin recalcula solo el precio de barra de 6 m de todas las variaciones (`peso_metro × 6 × precio_kilo`) y lo guarda en `_regular_price`/`_price`. No hay que tocar los productos.
4. Verificar en `/catalogo-b2b/?linea=estructurales` que el acabado ya muestra precio y permite "Agregar".

Los descuentos de cada lista se editan en **CJ Aluminios B2B → Descuentos por Rol** y se aplican en vivo. Antes de publicar, confirmar con el cliente que los valores actuales de Estándar (Natural 3000, Blanco 3200, Negro 3500, Bronce 4000) son los reales y no de prueba.

### Migración de LocalWP al hosting definitivo
Requisitos del hosting: PHP 8.1 o superior, MySQL/MariaDB, HTTPS y, si es posible, WP-CLI por SSH.

1. **Congelar el local.** Con Local abierto y el sitio corriendo, exportar la base:
   ```bash
   wp db export cj-aluminio.sql
   ```
2. **Empaquetar archivos.** Comprimir `app/public/wp-content/` completo (incluye `plugins/cj-aluminios-b2b`, `plugins/woocommerce`, `themes/` y `uploads/` con `cj-catalogo/`). No subir `Catalogos_pdf/` ni `wp-config.php` del local.
3. **En el hosting:** instalar WordPress limpio (misma versión o superior), reemplazar su `wp-content/` por el del paquete e importar la base:
   ```bash
   wp db import cj-aluminio.sql
   ```
   Si el prefijo de tablas del hosting no es `wp_`, ajustar `$table_prefix` en `wp-config.php` al del local.
4. **Cambiar el dominio** (reemplazar `https://DOMINIO` por el real):
   ```bash
   wp search-replace "http://cj-aluminio.local" "https://DOMINIO" --all-tables --precise
   ```
5. **Refrescar reglas y cachés:**
   ```bash
   wp rewrite flush
   ```
   ```bash
   wp cache flush
   ```
   ```bash
   wp wc tool run clear_transients --user=1
   ```
6. **Seguridad:** crear un administrador nuevo con contraseña fuerte y borrar el usuario `emanuel` del local si no va a usarse; generar claves nuevas en `wp-config.php` (`wp config shuffle-salts`); dejar `WP_DEBUG` en `false`.
7. **Ajustes de producción** (ver checklist): moneda ARS, idioma es_AR, precios por kilo, número de WhatsApp.
8. **Probar** en el dominio real: `/`, `/catalogo-b2b/`, ficha de un perfil, pedido por WhatsApp, `/simulador-de-aberturas/` (subida de foto), `/registro-gremio-b2b/` (el alta manda correo: verificar que el hosting envíe mail).
9. **Publicar:** desactivar "Próximamente":
   ```bash
   wp option update woocommerce_coming_soon no
   ```

Sin SSH: mismo flujo con un plugin de migración (Duplicator / All-in-One WP Migration), que hace los pasos 1 a 4 de una vez.

### Checklist de producción (pendiente de datos o decisión del cliente)
- [ ] **Precios por kilo** faltantes cargados (guía de arriba) y valores de Estándar confirmados.
- [ ] **Moneda y formato:** WooCommerce sigue en USD / `en_US`. Pasar a ARS y es_AR: `wp option update woocommerce_currency ARS`, separador decimal `,` y de miles `.` (WooCommerce → Ajustes → General), `wp language core install es_AR --activate`.
- [ ] **IVA:** sin definir; no tocar impuestos de WooCommerce hasta que el cliente avise.
- [ ] **Precios para visitantes:** hoy ocultos ("Iniciá sesión para ver precio gremio"). Checkbox en CJ Aluminios B2B → Catálogo y WhatsApp.
- [ ] **Descuento por rol en accesorios:** hoy no se aplica (el motor solo descuenta perfiles). Cambiarlo implica tocar `class-pricing-engine.php`.
- [ ] **Accesorios:** CSV con productos y precios; después correr el importador de imágenes.
- [ ] **Marca:** nombre del sitio (hoy `cj-aluminio`), logo y paleta. La paleta se cambia solo en el bloque `:root` de `assets/css/cj-b2b-theme.css`.
- [ ] **Contenido de la Fase 3:** HEX reales del muestrario, textos de fichas técnicas, fotos reales de ambientes, horario de atención.
- [ ] **Páginas legales:** "Política de privacidad" y "Reembolsos" están en borrador.
- [ ] **Usuarios gremio:** no hay ninguno creado; se dan de alta por `/registro-gremio-b2b/` y se les asigna lista en CJ Aluminios B2B → Solicitudes de gremio.
- [ ] **Modo "Próximamente":** activado; se desactiva al publicar.
