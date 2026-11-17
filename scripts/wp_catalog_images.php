<?php
/**
 * Auxiliar de scripts/import_catalog_images.py. Se ejecuta solo por WP-CLI:
 *
 *   wp eval-file scripts/wp_catalog_images.php estado
 *   wp eval-file scripts/wp_catalog_images.php vincular <plan.json>
 *
 * "estado" informa productos y adjuntos ya importados; "vincular" guarda la
 * clave/hash de cada adjunto y lo asigna como imagen destacada.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$cjb2b_accion = isset( $args[0] ) ? $args[0] : '';

/**
 * ¿El producto es un accesorio? (es_perfil = no, o categoría "accesorios").
 */
function cjb2b_img_es_accesorio( $product_id ) {
	return 'yes' !== get_post_meta( $product_id, '_es_perfil', true );
}

if ( 'estado' === $cjb2b_accion ) {
	global $wpdb;

	$productos = array();
	$ids       = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $ids as $id ) {
		$productos[] = array(
			'id'     => (int) $id,
			'sku'    => (string) get_post_meta( $id, '_sku', true ),
			'nombre' => get_the_title( $id ),
			'matriz' => (string) get_post_meta( $id, '_codigo_matriz', true ),
		);
	}

	$adjuntos = array();
	$filas    = $wpdb->get_results(
		"SELECT k.post_id, k.meta_value AS clave, h.meta_value AS hash
		 FROM {$wpdb->postmeta} k
		 LEFT JOIN {$wpdb->postmeta} h ON h.post_id = k.post_id AND h.meta_key = '_cjb2b_catalog_hash'
		 WHERE k.meta_key = '_cjb2b_catalog_key'"
	);
	foreach ( $filas as $fila ) {
		if ( 'attachment' === get_post_type( $fila->post_id ) ) {
			$adjuntos[ $fila->clave ] = array(
				'id'   => (int) $fila->post_id,
				'hash' => (string) $fila->hash,
			);
		}
	}

	// Adjuntos de la carpeta del catálogo que quedaron sin clave (corrida cortada).
	$huerfanos = $wpdb->get_col(
		"SELECT f.post_id FROM {$wpdb->postmeta} f
		 LEFT JOIN {$wpdb->postmeta} k ON k.post_id = f.post_id AND k.meta_key = '_cjb2b_catalog_key'
		 WHERE f.meta_key = '_wp_attached_file' AND f.meta_value LIKE 'cj-catalogo/%' AND k.meta_id IS NULL"
	);

	$uploads = wp_get_upload_dir();
	echo wp_json_encode(
		array(
			'basedir'   => $uploads['basedir'],
			'productos' => $productos,
			'adjuntos'  => (object) $adjuntos,
			'huerfanos' => array_map( 'intval', $huerfanos ),
		)
	);
	return;
}

if ( 'vincular' === $cjb2b_accion ) {
	$plan = isset( $args[1] ) ? json_decode( file_get_contents( $args[1] ), true ) : null;
	if ( ! is_array( $plan ) ) {
		WP_CLI::error( 'Plan inválido.' );
	}

	$asignadas  = 0;
	$respetadas = 0;

	foreach ( $plan['items'] as $item ) {
		$adjunto = (int) $item['id'];
		if ( 'attachment' !== get_post_type( $adjunto ) ) {
			continue;
		}
		update_post_meta( $adjunto, '_cjb2b_catalog_key', sanitize_text_field( $item['clave'] ) );
		update_post_meta( $adjunto, '_cjb2b_catalog_hash', sanitize_text_field( $item['hash'] ) );
		update_post_meta( $adjunto, '_wp_attachment_image_alt', sanitize_text_field( $item['titulo'] ) );

		foreach ( (array) $item['productos'] as $product_id ) {
			$product_id = (int) $product_id;
			$actual     = (int) get_post_meta( $product_id, '_thumbnail_id', true );
			if ( $actual === $adjunto ) {
				continue;
			}
			// Una imagen que no vino de este importador se cargó a mano: se respeta.
			$es_nuestra = $actual && get_post_meta( $actual, '_cjb2b_catalog_key', true );
			if ( $actual && get_post( $actual ) && ! $es_nuestra && empty( $plan['force'] ) ) {
				++$respetadas;
				continue;
			}
			set_post_thumbnail( $product_id, $adjunto );
			++$asignadas;
		}
	}

	if ( ! empty( $plan['placeholder'] ) ) {
		update_option( 'woocommerce_placeholder_image', (int) $plan['placeholder'] );
	}

	// Resumen final sobre los productos publicados.
	$resumen = array(
		'asignadas'             => $asignadas,
		'respetadas'            => $respetadas,
		'perfiles'              => 0,
		'perfiles_con_imagen'   => 0,
		'accesorios'            => 0,
		'accesorios_con_imagen' => 0,
		'sin_imagen'            => array(),
	);
	$ids     = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $ids as $id ) {
		$tipo  = cjb2b_img_es_accesorio( $id ) ? 'accesorios' : 'perfiles';
		$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
		++$resumen[ $tipo ];
		if ( $thumb && get_post( $thumb ) ) {
			++$resumen[ $tipo . '_con_imagen' ];
		} else {
			$resumen['sin_imagen'][] = (string) get_post_meta( $id, '_sku', true );
		}
	}
	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}

	echo wp_json_encode( $resumen );
	return;
}

WP_CLI::error( 'Acción desconocida. Usar: estado | vincular <plan.json>' );
