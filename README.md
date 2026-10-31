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
   - Si `es_perfil == true`: Precio Barra 6m = `(peso_metro * 6) * (precio_kilo_color) * (1 - descuento_rol / 100)`.
   - Si `es_perfil == false`: Usar precio fijo de WooCommerce.
   - `precio_kilo_color` debe obtenerse de los ajustes globales según el acabado del producto (Blanco, Negro, Anodizado, Bronce, Natural).
4. **Roles B2B:** `publico` (0%), `lista_1` (Gremio B), `lista_2` (Gremio A), `mayorista_1` (Volumen).

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
