<?php
/**
 * Metadata de producto: es_perfil, peso_metro, codigo_matriz.
 * El acabado/color se maneja como variación del atributo global "Acabado" (pa_acabado).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Product_Fields {

	private static ?self $instance = null;

	const META_ES_PERFIL     = '_es_perfil';
	const META_PESO_METRO    = '_peso_metro';
	const META_CODIGO_MATRIZ = '_codigo_matriz';

	/**
	 * IDs de producto que se están convirtiendo en este request, para evitar
	 * reentrancia si el guardado del producto vuelve a disparar save_post_product.
	 */
	private static array $en_proceso = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'mostrar_campos' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'guardar_campos' ) );
		add_action( 'save_post_product', array( $this, 'manejar_guardado_producto' ), 10, 2 );
		add_action( 'woocommerce_product_import_inserted_product_object', array( $this, 'manejar_producto_importado' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'cjb2b convertir-perfiles', array( $this, 'comando_convertir_perfiles' ) );
		}
	}

	public static function activar(): void {
		self::crear_atributo_acabado();
	}

	/**
	 * Crea el atributo global "Acabado" (pa_acabado) con los términos fijos
	 * usados por el motor de precios, si todavía no existen.
	 */
	private static function crear_atributo_acabado(): void {
		if ( ! function_exists( 'wc_create_attribute' ) ) {
			return;
		}

		if ( ! taxonomy_exists( 'pa_acabado' ) ) {
			$resultado = wc_create_attribute(
				array(
					'name'         => __( 'Acabado', 'cj-aluminios-b2b' ),
					'slug'         => 'acabado',
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);

			if ( is_wp_error( $resultado ) ) {
				return;
			}

			// wc_create_attribute() no registra la taxonomía dentro del mismo
			// request (eso ocurre en el siguiente "init"), así que la
			// registramos manualmente para poder crear los términos ahora.
			register_taxonomy(
				'pa_acabado',
				'product',
				array(
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
				)
			);

			delete_transient( 'wc_attribute_taxonomies' );
		}

		if ( ! taxonomy_exists( 'pa_acabado' ) ) {
			return;
		}

		foreach ( CJB2B_Admin_Settings::acabados() as $nombre ) {
			if ( ! term_exists( $nombre, 'pa_acabado' ) ) {
				wp_insert_term( $nombre, 'pa_acabado' );
			}
		}

		self::migrar_acabado_anodizado_generico();
	}

	/**
	 * Migración: el acabado "Anodizado" genérico se reemplazó por 4
	 * variantes específicas (Negro/Especial/Champagne/Peltre) y ya no es
	 * una opción válida. Se borra el término viejo; las variaciones que
	 * todavía lo usan se limpian producto por producto en
	 * eliminar_variaciones_obsoletas() al reconvertir cada perfil.
	 */
	private static function migrar_acabado_anodizado_generico(): void {
		$obsoleto = get_term_by( 'slug', 'anodizado', 'pa_acabado' );

		if ( $obsoleto ) {
			wp_delete_term( $obsoleto->term_id, 'pa_acabado' );
		}
	}

	public function mostrar_campos(): void {
		global $post;

		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_ES_PERFIL,
				'label'       => __( '¿Es perfil?', 'cj-aluminios-b2b' ),
				'description' => __( 'Si está marcado, el precio se calcula por peso y acabado (barra de 6m). Si no, se usa el precio fijo de WooCommerce.', 'cj-aluminios-b2b' ),
				'value'       => $this->valor_es_perfil( $post ? $post->ID : 0 ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_PESO_METRO,
				'label'             => __( 'Peso por metro (kg/m)', 'cj-aluminios-b2b' ),
				'desc_tip'          => true,
				'description'       => __( 'Peso lineal del perfil en kg por metro.', 'cj-aluminios-b2b' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.001',
					'min'  => '0',
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_CODIGO_MATRIZ,
				'label'       => __( 'Código de matriz', 'cj-aluminios-b2b' ),
				'desc_tip'    => true,
				'description' => __( 'Código interno de la matriz, ej: MOD-001.', 'cj-aluminios-b2b' ),
				'type'        => 'text',
			)
		);

		echo '</div>';
	}

	private function valor_es_perfil( int $product_id ): string {
		if ( ! $product_id ) {
			return 'yes';
		}

		$valor_guardado = get_post_meta( $product_id, self::META_ES_PERFIL, true );

		return '' === $valor_guardado ? 'yes' : $valor_guardado;
	}

	public function guardar_campos( int $post_id ): void {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$es_perfil = isset( $_POST[ self::META_ES_PERFIL ] ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_ES_PERFIL, $es_perfil );

		if ( isset( $_POST[ self::META_PESO_METRO ] ) ) {
			$peso = floatval( wp_unslash( $_POST[ self::META_PESO_METRO ] ) );
			update_post_meta( $post_id, self::META_PESO_METRO, max( 0.0, $peso ) );
		}

		if ( isset( $_POST[ self::META_CODIGO_MATRIZ ] ) ) {
			$codigo = sanitize_text_field( wp_unslash( $_POST[ self::META_CODIGO_MATRIZ ] ) );
			update_post_meta( $post_id, self::META_CODIGO_MATRIZ, $codigo );
		}
	}

	public static function es_perfil( int $product_id ): bool {
		$valor = get_post_meta( $product_id, self::META_ES_PERFIL, true );
		return '' === $valor ? true : 'yes' === $valor;
	}

	public static function peso_metro( int $product_id ): float {
		return (float) get_post_meta( $product_id, self::META_PESO_METRO, true );
	}

	public static function codigo_matriz( int $product_id ): string {
		return (string) get_post_meta( $product_id, self::META_CODIGO_MATRIZ, true );
	}

	/**
	 * Lectura estricta de es_perfil para la conversión automática: a diferencia
	 * de es_perfil(), un meta vacío/ausente se considera "no" (no se auto-convierte
	 * un producto que nunca tocó este campo, p. ej. importado sin esa columna).
	 */
	private static function es_perfil_marcado( int $product_id ): bool {
		$valor = get_post_meta( $product_id, self::META_ES_PERFIL, true );
		return filter_var( $valor, FILTER_VALIDATE_BOOLEAN );
	}

	public function manejar_guardado_producto( int $post_id, $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}

		$this->convertir_si_corresponde( $post_id );
	}

	public function manejar_producto_importado( WC_Product $producto ): void {
		$this->convertir_si_corresponde( $producto->get_id() );
	}

	/**
	 * `wp cjb2b convertir-perfiles`
	 *
	 * Recorre todos los productos existentes con meta _es_perfil en 1/yes y
	 * los convierte a variable + atributo pa_acabado + 5 variaciones, por si
	 * quedaron como "simple" (importados antes de este hook, o por SQL/CSV
	 * directo sin pasar por save_post_product).
	 */
	public function comando_convertir_perfiles(): void {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::META_ES_PERFIL,
						'value'   => array( '1', 'yes' ),
						'compare' => 'IN',
					),
				),
			)
		);

		$procesados = 0;

		foreach ( $ids as $id ) {
			$this->convertir_si_corresponde( $id );
			++$procesados;

			if ( class_exists( 'WP_CLI' ) ) {
				WP_CLI::log( sprintf( '  - #%d %s', $id, get_the_title( $id ) ) );
			}
		}

		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::success( sprintf( '%d producto(s) con es_perfil procesado(s).', $procesados ) );
		}
	}

	private function convertir_si_corresponde( int $product_id ): void {
		if ( ! $product_id || in_array( $product_id, self::$en_proceso, true ) ) {
			return;
		}

		if ( ! self::es_perfil_marcado( $product_id ) ) {
			return;
		}

		self::$en_proceso[] = $product_id;
		$this->convertir_a_variable_con_acabados( $product_id );
		self::$en_proceso = array_diff( self::$en_proceso, array( $product_id ) );
	}

	/**
	 * Convierte el producto a tipo "variable", le asigna el atributo global
	 * "Acabado" (pa_acabado) habilitado para variaciones con los términos
	 * vigentes, genera las variaciones que falten y borra las que hayan
	 * quedado obsoletas (p. ej. un acabado que se reemplazó por otros).
	 * Las opciones del atributo se resincronizan siempre (no solo la
	 * primera vez) para que un cambio en la lista de acabados se propague
	 * a los productos ya convertidos.
	 */
	private function convertir_a_variable_con_acabados( int $product_id ): void {
		self::crear_atributo_acabado();

		if ( ! taxonomy_exists( 'pa_acabado' ) ) {
			return;
		}

		$terminos = get_terms( array( 'taxonomy' => 'pa_acabado', 'hide_empty' => false ) );

		if ( is_wp_error( $terminos ) || empty( $terminos ) ) {
			return;
		}

		$ids_terminos   = wp_list_pluck( $terminos, 'term_id' );
		$slugs_terminos = wp_list_pluck( $terminos, 'slug' );

		wp_set_object_terms( $product_id, $ids_terminos, 'pa_acabado' );

		$producto = new WC_Product_Variable( $product_id );

		$atributo = new WC_Product_Attribute();
		$atributo->set_id( wc_attribute_taxonomy_id_by_name( 'acabado' ) );
		$atributo->set_name( 'pa_acabado' );
		$atributo->set_options( $ids_terminos );
		$atributo->set_position( 0 );
		$atributo->set_visible( true );
		$atributo->set_variation( true );

		$atributos = array_values(
			array_filter(
				$producto->get_attributes(),
				static function ( $attr ) {
					return $attr instanceof WC_Product_Attribute && 'pa_acabado' !== $attr->get_name();
				}
			)
		);
		$atributos[] = $atributo;

		$producto->set_attributes( $atributos );
		$producto->save();

		$this->generar_variaciones_faltantes( $product_id, $slugs_terminos );
		$this->eliminar_variaciones_obsoletas( $product_id, $slugs_terminos );

		// Escribe el precio público real en _regular_price/_price de cada
		// variación (si ya hay peso_metro y precio por kilo cargados), para
		// que el listado de la tienda y el rango de precio no muestren 0.00.
		CJB2B_Pricing_Engine::instance()->sincronizar_precios_producto( $product_id );
	}

	/**
	 * Borra las variaciones cuyo acabado ya no está entre los términos
	 * vigentes de pa_acabado (p. ej. el "Anodizado" genérico, reemplazado
	 * por sus 4 variantes específicas).
	 */
	private function eliminar_variaciones_obsoletas( int $product_id, array $slugs_validos ): void {
		global $wpdb;

		$variaciones = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, pm.meta_value
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'attribute_pa_acabado'
				 WHERE p.post_parent = %d AND p.post_type = 'product_variation'",
				$product_id
			)
		);

		foreach ( $variaciones as $variacion ) {
			if ( '' !== $variacion->meta_value && ! in_array( $variacion->meta_value, $slugs_validos, true ) ) {
				wp_delete_post( (int) $variacion->ID, true );
			}
		}
	}

	/**
	 * Crea las variaciones de Acabado que todavía no existan, con precio
	 * regular fijo en 0.00 (el motor de precios dinámico lo sobrescribe
	 * según peso, acabado y rol al mostrarlo/cotizarlo).
	 */
	private function generar_variaciones_faltantes( int $product_id, array $slugs_acabado ): void {
		global $wpdb;

		// Se consulta postmeta directamente (en vez de wc_get_product() por
		// cada hijo) para que la detección de "ya existe" no dependa de la
		// capa de caché de objetos de WooCommerce.
		$slugs_con_variacion = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_parent = %d
				   AND p.post_type = 'product_variation'
				   AND p.post_status IN ( 'publish', 'private' )
				   AND pm.meta_key = 'attribute_pa_acabado'",
				$product_id
			)
		);

		foreach ( $slugs_acabado as $slug ) {
			if ( in_array( $slug, $slugs_con_variacion, true ) ) {
				continue;
			}

			$variacion = new WC_Product_Variation();
			$variacion->set_parent_id( $product_id );
			$variacion->set_attributes( array( 'pa_acabado' => $slug ) );
			$variacion->set_regular_price( '0.00' );
			$variacion->set_price( '0.00' );
			$variacion->set_status( 'publish' );
			$variacion->save();
		}
	}
}
