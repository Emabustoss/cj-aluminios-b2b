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
    └── class-whatsapp-checkout.php   (Fase 2: pedido pending + enlace wa.me)
assets/
├── css/cj-b2b-theme.css              (paleta en variables :root, mobile-first)
└── js/cj-b2b-catalog.js, cj-b2b-whatsapp.js
```

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

## Fase 3 (pendiente, no iniciada)
Antes de arrancar, confirmar con el cliente estas decisiones abiertas de la Fase 2:
- **Precios para visitantes:** hoy ocultos por defecto (mensaje "Iniciá sesión para ver precio gremio"). Es un checkbox en CJ Aluminios B2B → Catálogo y WhatsApp.
- **Descuento por rol en accesorios:** hoy no se aplica (el motor solo descuenta perfiles). Cambiarlo implica tocar `class-pricing-engine.php`.
- **Precios por kilo faltantes:** tabla Estructurales completa en 0 y, en Estándar, Símil Madera y los 4 anodizados en 0 → esos productos/acabados muestran "Consultar" y no se pueden agregar al pedido.
- **Moneda y formato:** WooCommerce sigue en USD y locale `en_US` (los precios salen como `$13,554.00`). Falta pasar a ARS / es_AR.
- **IVA:** sin definir; no tocar impuestos de WooCommerce hasta que el cliente avise.
- **Marca:** la paleta es gris neutro; cuando haya logo, cambiar solo las variables `:root` de `assets/css/cj-b2b-theme.css`.
- **Alta de clientes gremio:** los roles se asignan a mano desde el admin; no hay formulario de registro/solicitud de cuenta.

Alcance candidato:
1. **Visualizador de acabados** (versión simple aprobada, no la de "subí tu foto" estilo Nexo Aberturas):
   - Selector de línea + tipo de abertura + acabado, con **overlay de color en vivo** (Canvas/JS) sobre una imagen base neutra por tipo de abertura — no una imagen por cada combinación de color.
   - Contenido necesario: ~7 tipos de abertura por línea × 3 líneas (Herrero, Módena, A30 New) ≈ 21 fotos/renders base, vista frontal/ortogonal (no perspectiva), acabado claro, fondo limpio, alta resolución. El trabajo manual es la máscara (separar perfil de aluminio del resto de la imagen), una vez por foto.
   - Sin costo recurrente (no usa IA ni APIs pagas; corre client-side).
2. **Herrajes/accesorios:** cargar el catálogo real cuando el cliente arme el CSV con precios (hay un PDF de accesorios del fabricante sin revisar todavía: `Catalogos_pdf/CJ-Metales-2024-Catalogo-ACCESORIO-final-1.pdf`, no versionado). Cargarlos como productos simples con `es_perfil = no`, en la categoría `accesorios` y una subcategoría por familia (el catálogo ya los filtra así), con el campo "Presentación / unidad".
3. **Imágenes de producto:** ningún producto tiene imagen cargada todavía.
