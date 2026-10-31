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
    └── class-b2b-roles.php
```

## Comandos Útiles
- Git: Mantener commits atómicos por funcionalidad (ej. `feat(b2b): implement pricing hook`).
