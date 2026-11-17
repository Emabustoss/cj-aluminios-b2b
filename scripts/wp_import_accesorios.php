<?php
/**
 * Carga o actualiza accesorios desde un CSV. Se ejecuta solo por WP-CLI:
 *
 *   wp eval-file scripts/wp_import_accesorios.php <archivo.csv> [--dry-run]
 *
 * Columnas del CSV (UTF-8, con encabezado): SKU, Nombre, Precio normal,
 * Categorías ("Accesorios > Familia") y, opcional, Presentación.
 *
 * - Crea productos simples con `es_perfil = no`, en la categoría `accesorios`
 *   y la subcategoría de su familia (la crea si no existe).
 * - Es idempotente: si el SKU ya existe actualiza nombre, precio, categoría y
 *   presentación; no toca la imagen ni la descripción.
 * - Precio 0 o vacío = sin precio: el catálogo muestra "Consultar".
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$cjb2b_csv = isset( $args[0] ) ? $args[0] : '';
$cjb2b_dry = in_array( '--dry-run', $args, true );

if ( '' === $cjb2b_csv || ! is_readable( $cjb2b_csv ) ) {
	WP_CLI::error( 'Indicá el CSV: wp eval-file scripts/wp_import_accesorios.php <archivo.csv>' );
}

$cjb2b_fh     = fopen( $cjb2b_csv, 'r' );
$cjb2b_cabeza = fgetcsv( $cjb2b_fh );

if ( ! is_array( $cjb2b_cabeza ) ) {
	WP_CLI::error( 'CSV vacío.' );
}

$cjb2b_cabeza[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cjb2b_cabeza[0] ); // BOM.
$cjb2b_cabeza    = array_map( 'trim', $cjb2b_cabeza );

foreach ( array( 'SKU', 'Nombre', 'Categorías' ) as $cjb2b_col ) {
	if ( ! in_array( $cjb2b_col, $cjb2b_cabeza, true ) ) {
		WP_CLI::error( "Falta la columna \"$cjb2b_col\"." );
	}
}

/**
 * ID de la subcategoría "Accesorios > Familia" (crea lo que falte).
 *
 * @return int[] IDs de categoría a asignar (raíz + familia).
 */
function cjb2b_acc_categorias( string $ruta, bool $dry ): array {
	static $cache = array();

	if ( isset( $cache[ $ruta ] ) ) {
		return $cache[ $ruta ];
	}

	$raiz = get_term_by( 'slug', 'accesorios', 'product_cat' );

	if ( ! $raiz ) {
		WP_CLI::error( 'No existe la categoría "accesorios".' );
	}

	$ids    = array( (int) $raiz->term_id );
	$partes = array_values( array_filter( array_map( 'trim', explode( '>', $ruta ) ) ) );
	$padre  = (int) $raiz->term_id;

	// La primera parte es la raíz ("Accesorios"); el resto son familias anidadas.
	foreach ( array_slice( $partes, 1 ) as $nombre ) {
		$existe = term_exists( $nombre, 'product_cat', $padre );

		if ( ! $existe && ! $dry ) {
			$existe = wp_insert_term(
				sanitize_text_field( $nombre ),
				'product_cat',
				array(
					'parent' => $padre,
					'slug'   => 'acc-' . sanitize_title( $nombre ),
				)
			);

			if ( is_wp_error( $existe ) ) {
				WP_CLI::error( 'No se pudo crear la categoría ' . $nombre . ': ' . $existe->get_error_message() );
			}
		}

		if ( $existe ) {
			$padre = (int) ( is_array( $existe ) ? $existe['term_id'] : $existe );
			$ids   = array( $padre ); // WooCommerce incluye a los padres al filtrar: alcanza con la familia.
		}
	}

	$cache[ $ruta ] = $ids;

	return $ids;
}

$cjb2b_res = array(
	'creados'      => 0,
	'actualizados' => 0,
	'omitidos'     => 0,
	'sin_precio'   => 0,
);

while ( ( $cjb2b_fila = fgetcsv( $cjb2b_fh ) ) !== false ) {
	if ( count( $cjb2b_fila ) < count( $cjb2b_cabeza ) ) {
		$cjb2b_fila = array_pad( $cjb2b_fila, count( $cjb2b_cabeza ), '' );
	}

	$dato   = array_combine( $cjb2b_cabeza, array_slice( $cjb2b_fila, 0, count( $cjb2b_cabeza ) ) );
	$sku    = sanitize_text_field( $dato['SKU'] );
	$nombre = sanitize_text_field( $dato['Nombre'] );

	if ( '' === $sku || '' === $nombre ) {
		++$cjb2b_res['omitidos'];
		continue;
	}

	$precio = isset( $dato['Precio normal'] ) ? (float) str_replace( ',', '.', $dato['Precio normal'] ) : 0.0;
	$id     = wc_get_product_id_by_sku( $sku );

	if ( $id && CJB2B_Product_Fields::es_perfil( $id ) ) {
		WP_CLI::warning( "$sku ya existe como perfil: se omite." );
		++$cjb2b_res['omitidos'];
		continue;
	}

	if ( $precio <= 0 ) {
		++$cjb2b_res['sin_precio'];
	}

	++$cjb2b_res[ $id ? 'actualizados' : 'creados' ];

	if ( $cjb2b_dry ) {
		continue;
	}

	$producto = $id ? wc_get_product( $id ) : new WC_Product_Simple();

	$producto->set_name( $nombre );
	$producto->set_sku( $sku );
	$producto->set_status( 'publish' );
	$producto->set_catalog_visibility( 'visible' );
	$producto->set_regular_price( $precio > 0 ? wc_format_decimal( $precio ) : '' );
	$producto->set_manage_stock( false );
	$producto->set_stock_status( 'instock' );
	$producto->set_category_ids( cjb2b_acc_categorias( (string) $dato['Categorías'], false ) );
	$producto->update_meta_data( CJB2B_Product_Fields::META_ES_PERFIL, 'no' );

	if ( isset( $dato['Presentación'] ) && '' !== trim( $dato['Presentación'] ) ) {
		$producto->update_meta_data( CJB2B_Frontend::META_PRESENTA, sanitize_text_field( $dato['Presentación'] ) );
	}

	$producto->save();
}

fclose( $cjb2b_fh );

if ( ! $cjb2b_dry && function_exists( 'wc_delete_product_transients' ) ) {
	wc_delete_product_transients();
}

echo wp_json_encode( $cjb2b_res ) . "\n";
