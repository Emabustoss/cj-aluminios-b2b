<?php
/**
 * Catálogo técnico B2B: shortcode [cj_catalogo_b2b] con navegación por línea,
 * buscador AJAX (SKU / código de matriz / título) y tabla con precio por rol.
 *
 * Los precios salen de lo que la Fase 1 ya persiste: el precio público de
 * cada variación (_price) más el descuento del rol actual. No se recalcula
 * el precio por kilo acá.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Catalog_UI {

	private static ?self $instance = null;

	const SHORTCODE      = 'cj_catalogo_b2b';
	const NONCE          = 'cjb2b_catalogo';
	const ACCION_BUSCAR  = 'cjb2b_catalogo_buscar';
	const ACCION_AGREGAR = 'cjb2b_catalogo_agregar';
	const SCRIPT_HANDLE  = 'cj-b2b-catalog';
	const CAT_ACCESORIOS = 'accesorios';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'registrar_script' ) );

		add_action( 'wp_ajax_' . self::ACCION_BUSCAR, array( $this, 'ajax_buscar' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACCION_BUSCAR, array( $this, 'ajax_buscar' ) );
		add_action( 'wp_ajax_' . self::ACCION_AGREGAR, array( $this, 'ajax_agregar' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACCION_AGREGAR, array( $this, 'ajax_agregar' ) );

		// Mejoras visuales en tienda / categorías de WooCommerce.
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render_lineas_en_tienda' ), 5 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_meta_en_tienda' ), 4 );
	}

	/**
	 * Líneas técnicas del catálogo: slug de la categoría de producto => etiqueta.
	 */
	public static function lineas(): array {
		return array(
			'linea-herrero'      => __( 'Línea Herrero', 'cj-aluminios-b2b' ),
			'linea-modena'       => __( 'Línea Modena', 'cj-aluminios-b2b' ),
			'a30-new'            => __( 'A30 New', 'cj-aluminios-b2b' ),
			'estructurales'      => __( 'Estructurales', 'cj-aluminios-b2b' ),
			'mampara-de-bano'    => __( 'Mamparas', 'cj-aluminios-b2b' ),
			self::CAT_ACCESORIOS => __( 'Accesorios', 'cj-aluminios-b2b' ),
		);
	}

	/**
	 * Crea la categoría "Accesorios" (las familias de accesorios se cargan
	 * como subcategorías de esta) y la página del catálogo si no existen.
	 */
	public static function activar(): void {
		if ( taxonomy_exists( 'product_cat' ) && ! term_exists( self::CAT_ACCESORIOS, 'product_cat' ) ) {
			wp_insert_term( __( 'Accesorios', 'cj-aluminios-b2b' ), 'product_cat', array( 'slug' => self::CAT_ACCESORIOS ) );
		}

		self::asegurar_pagina();
	}

	/**
	 * Crea o actualiza la página "Catálogo B2B" con el shortcode y guarda su ID.
	 */
	public static function asegurar_pagina(): int {
		$page_id = (int) CJB2B_Frontend::ajuste( 'catalogo_page_id' );
		$pagina  = $page_id ? get_post( $page_id ) : null;

		if ( ! $pagina || 'page' !== $pagina->post_type || 'trash' === $pagina->post_status ) {
			$pagina = get_page_by_path( 'catalogo-b2b' );
		}

		$contenido = '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->';

		if ( $pagina ) {
			$page_id = (int) $pagina->ID;

			if ( ! has_shortcode( $pagina->post_content, self::SHORTCODE ) || 'publish' !== $pagina->post_status ) {
				wp_update_post(
					array(
						'ID'           => $page_id,
						'post_content' => has_shortcode( $pagina->post_content, self::SHORTCODE ) ? $pagina->post_content : $contenido,
						'post_status'  => 'publish',
					)
				);
			}
		} else {
			$page_id = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_title'   => __( 'Catálogo B2B', 'cj-aluminios-b2b' ),
					'post_name'    => 'catalogo-b2b',
					'post_status'  => 'publish',
					'post_content' => $contenido,
				)
			);
		}

		if ( $page_id ) {
			CJB2B_Frontend::actualizar_ajuste( 'catalogo_page_id', $page_id );
		}

		return $page_id;
	}

	public static function url_catalogo( string $linea = '' ): string {
		$page_id = (int) CJB2B_Frontend::ajuste( 'catalogo_page_id' );
		$url     = $page_id ? get_permalink( $page_id ) : '';

		if ( ! $url ) {
			return '';
		}

		return '' !== $linea ? add_query_arg( 'linea', rawurlencode( $linea ), $url ) : $url;
	}

	/* ------------------------------------------------------------- Assets */

	public function registrar_script(): void {
		$archivo = CJB2B_PATH . 'assets/js/cj-b2b-catalog.js';

		wp_register_script(
			self::SCRIPT_HANDLE,
			CJB2B_URL . 'assets/js/cj-b2b-catalog.js',
			array(),
			CJB2B_Frontend::version_archivo( $archivo ),
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'cjb2bCatalogo',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE ),
				'accionBuscar'  => self::ACCION_BUSCAR,
				'accionAgregar' => self::ACCION_AGREGAR,
				'textos'        => array(
					'buscando'  => __( 'Buscando…', 'cj-aluminios-b2b' ),
					'agregando' => __( 'Agregando…', 'cj-aluminios-b2b' ),
					'agregar'   => __( 'Agregar', 'cj-aluminios-b2b' ),
					'error'     => __( 'No se pudo completar la operación. Probá de nuevo.', 'cj-aluminios-b2b' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------- Shortcode */

	public function render_shortcode( $atts = array() ): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		wp_enqueue_script( self::SCRIPT_HANDLE );

		// Estado inicial desde la URL (solo lectura, sin efectos: no requiere nonce).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$linea   = isset( $_GET['linea'] ) ? sanitize_title( wp_unslash( $_GET['linea'] ) ) : '';
		$familia = isset( $_GET['familia'] ) ? sanitize_title( wp_unslash( $_GET['familia'] ) ) : '';
		$q       = isset( $_GET['buscar'] ) ? sanitize_text_field( wp_unslash( $_GET['buscar'] ) ) : '';
		// phpcs:enable

		if ( ! isset( self::lineas()[ $linea ] ) ) {
			$linea   = '';
			$familia = '';
		}

		$resultado = $this->consultar( $linea, $familia, $q, 1 );
		$rol       = CJB2B_Frontend::rol_actual();
		$cantidad  = ( WC()->cart ) ? (int) WC()->cart->get_cart_contents_count() : 0;

		ob_start();
		?>
		<div class="cj-b2b cj-catalogo-wrap alignwide"><div class="cj-catalogo" data-cj-catalogo data-linea="<?php echo esc_attr( $linea ); ?>" data-familia="<?php echo esc_attr( $familia ); ?>" data-paginas="<?php echo esc_attr( (string) $resultado['paginas'] ); ?>">
			<div class="cj-catalogo__barra">
				<span class="cj-rol">
					<?php esc_html_e( 'Lista aplicada:', 'cj-aluminios-b2b' ); ?>
					<strong><?php echo esc_html( $rol['nombre'] ); ?></strong>
					<?php if ( $rol['descuento'] > 0 ) : ?>
						<span>−<?php echo esc_html( CJB2B_Frontend::numero( $rol['descuento'], 2 ) ); ?>%</span>
					<?php endif; ?>
				</span>
				<?php if ( ! is_user_logged_in() ) : ?>
					<?php echo wp_kses_post( CJB2B_Frontend::mensaje_visitante_html() ); ?>
				<?php endif; ?>
			</div>

			<nav class="cj-nav" aria-label="<?php esc_attr_e( 'Líneas técnicas', 'cj-aluminios-b2b' ); ?>" data-cj-lineas>
				<button type="button" class="cj-chip<?php echo '' === $linea ? ' is-active' : ''; ?>" data-linea="" aria-pressed="<?php echo '' === $linea ? 'true' : 'false'; ?>"><?php esc_html_e( 'Todas', 'cj-aluminios-b2b' ); ?></button>
				<?php foreach ( self::lineas() as $slug => $etiqueta ) : ?>
					<button type="button" class="cj-chip<?php echo $slug === $linea ? ' is-active' : ''; ?>" data-linea="<?php echo esc_attr( $slug ); ?>" aria-pressed="<?php echo $slug === $linea ? 'true' : 'false'; ?>"><?php echo esc_html( $etiqueta ); ?></button>
				<?php endforeach; ?>
			</nav>

			<nav class="cj-nav cj-nav--sub" aria-label="<?php esc_attr_e( 'Familias', 'cj-aluminios-b2b' ); ?>" data-cj-familias><?php echo $this->render_familias( $linea, $familia ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en render_familias(). ?></nav>

			<div class="cj-buscador">
				<label for="cj-buscar"><?php esc_html_e( 'Buscar por código de matriz, SKU o nombre', 'cj-aluminios-b2b' ); ?></label>
				<input type="search" id="cj-buscar" data-cj-buscar value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Ej: 6045, MOD-6277, contravidrio', 'cj-aluminios-b2b' ); ?>" autocomplete="off" />
			</div>

			<p class="cj-aviso" data-cj-aviso role="status" aria-live="polite" hidden></p>
			<p class="cj-catalogo__estado" data-cj-estado aria-live="polite"><?php echo esc_html( $this->texto_total( $resultado['total'] ) ); ?></p>

			<table class="cj-tabla">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Código', 'cj-aluminios-b2b' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Producto', 'cj-aluminios-b2b' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Peso', 'cj-aluminios-b2b' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Acabado', 'cj-aluminios-b2b' ); ?></th>
						<th scope="col" class="cj-th-precio"><?php esc_html_e( 'Tu precio', 'cj-aluminios-b2b' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Pedido', 'cj-aluminios-b2b' ); ?></th>
					</tr>
				</thead>
				<tbody data-cj-filas><?php echo $this->render_filas( $resultado['ids'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en render_filas(). ?></tbody>
			</table>

			<div class="cj-catalogo__pie" data-cj-pie <?php echo $resultado['paginas'] > 1 ? '' : 'hidden'; ?>>
				<button type="button" class="cj-btn cj-btn--ghost" data-cj-mas><?php esc_html_e( 'Cargar más productos', 'cj-aluminios-b2b' ); ?></button>
			</div>

			<div class="cj-pedido-bar" data-cj-pedido <?php echo $cantidad > 0 ? '' : 'hidden'; ?>>
				<span><?php esc_html_e( 'Tu pedido:', 'cj-aluminios-b2b' ); ?> <strong data-cj-cantidad><?php echo esc_html( (string) $cantidad ); ?></strong> <?php esc_html_e( 'ítems', 'cj-aluminios-b2b' ); ?></span>
				<a class="cj-btn" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Ver pedido', 'cj-aluminios-b2b' ); ?></a>
			</div>
		</div></div>
		<?php
		return (string) ob_get_clean();
	}

	private function texto_total( int $total ): string {
		if ( 0 === $total ) {
			return __( 'Sin resultados.', 'cj-aluminios-b2b' );
		}

		return sprintf(
			/* translators: %d: cantidad de productos */
			_n( '%d producto', '%d productos', $total, 'cj-aluminios-b2b' ),
			$total
		);
	}

	/* ----------------------------------------------------------- Consulta */

	/**
	 * Familias (subcategorías) de una línea: slug => nombre.
	 */
	private function familias( string $linea ): array {
		if ( '' === $linea ) {
			return array();
		}

		$padre = get_term_by( 'slug', $linea, 'product_cat' );

		if ( ! $padre ) {
			return array();
		}

		$hijas = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => $padre->term_id,
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $hijas ) ) {
			return array();
		}

		return wp_list_pluck( $hijas, 'name', 'slug' );
	}

	private function render_familias( string $linea, string $activa ): string {
		$familias = $this->familias( $linea );

		if ( empty( $familias ) ) {
			return '';
		}

		$html = '<button type="button" class="cj-chip' . ( '' === $activa ? ' is-active' : '' ) . '" data-familia="">'
			. esc_html__( 'Todas las familias', 'cj-aluminios-b2b' ) . '</button>';

		foreach ( $familias as $slug => $nombre ) {
			$html .= '<button type="button" class="cj-chip' . ( $slug === $activa ? ' is-active' : '' ) . '" data-familia="' . esc_attr( $slug ) . '">'
				. esc_html( $nombre ) . '</button>';
		}

		return $html;
	}

	/**
	 * IDs de productos cuyo título, SKU o código de matriz contiene el texto.
	 */
	private function ids_por_busqueda( string $q ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $q ) . '%';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m
				   ON m.post_id = p.ID AND m.meta_key IN ( '_sku', %s )
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				   AND ( p.post_title LIKE %s OR m.meta_value LIKE %s )",
				CJB2B_Product_Fields::META_CODIGO_MATRIZ,
				$like,
				$like
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * @return array{ids:int[],total:int,paginas:int}
	 */
	private function consultar( string $linea, string $familia, string $q, int $pagina ): array {
		$vacio = array(
			'ids'     => array(),
			'total'   => 0,
			'paginas' => 0,
		);

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => max( 6, (int) CJB2B_Frontend::ajuste( 'por_pagina' ) ),
			'paged'          => max( 1, $pagina ),
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'tax_query'      => array(
				'relation' => 'AND',
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				),
			),
		);

		if ( '' !== $linea && isset( self::lineas()[ $linea ] ) ) {
			$categoria = $linea;

			if ( '' !== $familia && isset( $this->familias( $linea )[ $familia ] ) ) {
				$categoria = $familia;
			}

			$args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => array( $categoria ),
			);
		}

		if ( '' !== $q ) {
			$ids = $this->ids_por_busqueda( $q );

			if ( empty( $ids ) ) {
				return $vacio;
			}

			$args['post__in'] = $ids;
		}

		$consulta = new WP_Query( $args );

		return array(
			'ids'     => array_map( 'intval', $consulta->posts ),
			'total'   => (int) $consulta->found_posts,
			'paginas' => (int) $consulta->max_num_pages,
		);
	}

	/**
	 * Variaciones publicadas de un conjunto de productos, con su precio
	 * público persistido por la Fase 1: [ product_id => [ slug_acabado => [id, base] ] ].
	 */
	private function variaciones_de( array $product_ids ): array {
		global $wpdb;

		$product_ids = array_filter( array_map( 'intval', $product_ids ) );

		if ( empty( $product_ids ) ) {
			return array();
		}

		$marcadores = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $marcadores solo contiene %d.
		$filas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_parent AS padre, a.meta_value AS acabado, pr.meta_value AS precio
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = 'attribute_pa_acabado'
				 LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = '_price'
				 WHERE p.post_type = 'product_variation'
				   AND p.post_status = 'publish'
				   AND p.post_parent IN ( {$marcadores} )",
				$product_ids
			)
		);
		// phpcs:enable

		$mapa = array();

		foreach ( $filas as $fila ) {
			$mapa[ (int) $fila->padre ][ (string) $fila->acabado ] = array(
				'id'   => (int) $fila->id,
				'base' => (float) $fila->precio,
			);
		}

		return $mapa;
	}

	/* ------------------------------------------------------------- Render */

	private function render_filas( array $ids ): string {
		if ( empty( $ids ) ) {
			return '<tr class="cj-fila-vacia"><td colspan="6">'
				. esc_html__( 'No hay productos para mostrar con ese filtro.', 'cj-aluminios-b2b' )
				. '</td></tr>';
		}

		$variaciones = $this->variaciones_de( $ids );
		$descuento   = CJB2B_Frontend::rol_actual()['descuento'];
		$ver_precios = CJB2B_Frontend::precios_visibles();
		$lineas      = self::lineas();
		$html        = '';

		foreach ( $ids as $id ) {
			$producto = wc_get_product( $id );

			if ( ! $producto instanceof WC_Product ) {
				continue;
			}

			$es_perfil = CJB2B_Product_Fields::es_perfil( $id ) && $producto->is_type( 'variable' );
			$codigo    = CJB2B_Product_Fields::codigo_matriz( $id );
			$sku       = (string) $producto->get_sku();
			$peso      = CJB2B_Product_Fields::peso_metro( $id );

			$etiqueta_linea = '';
			$categorias     = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'slugs' ) );

			if ( ! is_wp_error( $categorias ) ) {
				foreach ( $categorias as $slug ) {
					if ( isset( $lineas[ $slug ] ) ) {
						$etiqueta_linea = $lineas[ $slug ];
						break;
					}
				}
			}

			$html .= '<tr data-product-id="' . esc_attr( (string) $id ) . '">';

			// Código de matriz / SKU.
			$html .= '<td class="cj-col-codigo" data-label="' . esc_attr__( 'Código', 'cj-aluminios-b2b' ) . '">'
				. '<span class="cj-code">' . esc_html( '' !== $codigo ? $codigo : ( '' !== $sku ? $sku : '—' ) ) . '</span>'
				. ( '' !== $codigo && '' !== $sku ? '<span class="cj-sku">' . esc_html( $sku ) . '</span>' : '' )
				. '</td>';

			// Nombre.
			$html .= '<td class="cj-col-nombre">'
				. '<a href="' . esc_url( $producto->get_permalink() ) . '">' . esc_html( $producto->get_name() ) . '</a>'
				. ( '' !== $etiqueta_linea ? '<br><span class="cj-tag">' . esc_html( $etiqueta_linea ) . '</span>' : '' )
				. '</td>';

			// Peso.
			$html .= '<td class="cj-col-peso" data-label="' . esc_attr__( 'Peso', 'cj-aluminios-b2b' ) . '">'
				. ( $es_perfil && $peso > 0 ? esc_html( CJB2B_Frontend::numero( $peso ) ) . ' kg/m' : '—' )
				. '</td>';

			if ( $es_perfil ) {
				$html .= $this->render_celdas_perfil( $variaciones[ $id ] ?? array(), $descuento, $ver_precios );
			} else {
				$html .= $this->render_celdas_simple( $producto, $ver_precios );
			}

			$html .= '</tr>';
		}

		return $html;
	}

	/**
	 * Celdas Acabado / Precio / Pedido de un perfil (producto variable).
	 */
	private function render_celdas_perfil( array $variaciones, float $descuento, bool $ver_precios ): string {
		$opciones     = '';
		$seleccionada = null;

		// Mismo orden que la lista canónica de acabados de la Fase 1.
		foreach ( array_keys( CJB2B_Admin_Settings::acabados() ) as $slug ) {
			if ( ! isset( $variaciones[ $slug ] ) ) {
				continue;
			}

			$base     = $variaciones[ $slug ]['base'];
			$final    = round( $base * ( 1 - ( $descuento / 100 ) ), 2 );
			$cotizado = $base > 0;
			$nombre   = CJB2B_Frontend::nombre_acabado( $slug );

			if ( $cotizado && null === $seleccionada ) {
				$seleccionada = array(
					'slug'  => $slug,
					'final' => $final,
				);
			}

			$opciones .= '<option value="' . esc_attr( (string) $variaciones[ $slug ]['id'] ) . '"'
				. ' data-acabado="' . esc_attr( $slug ) . '"'
				. ( $cotizado && $ver_precios ? ' data-precio="' . esc_attr( CJB2B_Frontend::precio_texto( $final ) ) . '"' : '' )
				. ( $cotizado ? '' : ' disabled' )
				. ( $cotizado && $seleccionada && $seleccionada['slug'] === $slug ? ' selected' : '' )
				. '>' . esc_html( $cotizado ? $nombre : $nombre . ' ' . __( '(consultar)', 'cj-aluminios-b2b' ) ) . '</option>';
		}

		$disponible = null !== $seleccionada;

		$html = '<td class="cj-col-acabado" data-label="' . esc_attr__( 'Acabado', 'cj-aluminios-b2b' ) . '">';

		if ( '' !== $opciones ) {
			$html .= '<select data-cj-acabado aria-label="' . esc_attr__( 'Acabado', 'cj-aluminios-b2b' ) . '"' . ( $disponible ? '' : ' disabled' ) . '>' . $opciones . '</select>';
		} else {
			$html .= '—';
		}

		$html .= '</td>';

		$html .= '<td class="cj-col-precio" data-label="' . esc_attr__( 'Tu precio', 'cj-aluminios-b2b' ) . '">';

		if ( ! $disponible ) {
			$html .= '<span class="cj-precio">' . esc_html__( 'Consultar', 'cj-aluminios-b2b' ) . '</span>';
		} elseif ( ! $ver_precios ) {
			$html .= CJB2B_Frontend::mensaje_visitante_html();
		} else {
			$html .= '<span class="cj-precio" data-cj-precio>' . esc_html( CJB2B_Frontend::precio_texto( $seleccionada['final'] ) ) . '</span>'
				. '<span class="cj-precio-nota">' . esc_html__( 'por barra de 6 m', 'cj-aluminios-b2b' ) . '</span>';
		}

		$html .= '</td>';
		$html .= $this->render_celda_accion( $disponible, __( 'Cantidad de barras', 'cj-aluminios-b2b' ) );

		return $html;
	}

	/**
	 * Celdas de un accesorio/herraje (precio fijo de WooCommerce).
	 */
	private function render_celdas_simple( WC_Product $producto, bool $ver_precios ): string {
		$precio     = $producto->get_price();
		$disponible = $producto->is_type( 'simple' ) && '' !== $precio && (float) $precio > 0 && $producto->is_purchasable() && $producto->is_in_stock();

		$html = '<td class="cj-col-acabado" data-label="' . esc_attr__( 'Presentación', 'cj-aluminios-b2b' ) . '">'
			. esc_html( CJB2B_Frontend::presentacion( $producto->get_id() ) )
			. '</td>';

		$html .= '<td class="cj-col-precio" data-label="' . esc_attr__( 'Tu precio', 'cj-aluminios-b2b' ) . '">';

		if ( ! $disponible ) {
			$html .= '<span class="cj-precio">' . esc_html__( 'Consultar', 'cj-aluminios-b2b' ) . '</span>';
		} elseif ( ! $ver_precios ) {
			$html .= CJB2B_Frontend::mensaje_visitante_html();
		} else {
			$html .= '<span class="cj-precio">' . esc_html( CJB2B_Frontend::precio_texto( (float) $precio ) ) . '</span>'
				. '<span class="cj-precio-nota">' . esc_html__( 'precio unitario', 'cj-aluminios-b2b' ) . '</span>';
		}

		$html .= '</td>';
		$html .= $this->render_celda_accion( $disponible, __( 'Cantidad de unidades', 'cj-aluminios-b2b' ) );

		return $html;
	}

	private function render_celda_accion( bool $disponible, string $etiqueta_cantidad ): string {
		$inactivo = $disponible ? '' : ' disabled';

		return '<td class="cj-col-accion">'
			. '<input type="number" min="1" max="9999" step="1" value="1" inputmode="numeric" data-cj-qty aria-label="' . esc_attr( $etiqueta_cantidad ) . '"' . $inactivo . ' />'
			. '<button type="button" class="cj-btn" data-cj-agregar' . $inactivo . '>' . esc_html__( 'Agregar', 'cj-aluminios-b2b' ) . '</button>'
			. '</td>';
	}

	/* --------------------------------------------------------------- AJAX */

	public function ajax_buscar(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$linea   = isset( $_POST['linea'] ) ? sanitize_title( wp_unslash( $_POST['linea'] ) ) : '';
		$familia = isset( $_POST['familia'] ) ? sanitize_title( wp_unslash( $_POST['familia'] ) ) : '';
		$q       = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$pagina  = isset( $_POST['pagina'] ) ? max( 1, absint( wp_unslash( $_POST['pagina'] ) ) ) : 1;

		$q = function_exists( 'mb_substr' ) ? mb_substr( $q, 0, 80 ) : substr( $q, 0, 80 );

		if ( ! isset( self::lineas()[ $linea ] ) ) {
			$linea   = '';
			$familia = '';
		}

		$resultado = $this->consultar( $linea, $familia, $q, $pagina );

		wp_send_json_success(
			array(
				'html'     => $this->render_filas( $resultado['ids'] ),
				'familias' => $this->render_familias( $linea, $familia ),
				'total'    => $resultado['total'],
				'paginas'  => $resultado['paginas'],
				'pagina'   => $pagina,
				'estado'   => $this->texto_total( $resultado['total'] ),
			)
		);
	}

	public function ajax_agregar(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
		$cantidad     = isset( $_POST['cantidad'] ) ? absint( wp_unslash( $_POST['cantidad'] ) ) : 1;
		$cantidad     = max( 1, min( 9999, $cantidad ) );
		$error        = __( 'No se pudo agregar el producto al pedido.', 'cj-aluminios-b2b' );

		$producto = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $producto instanceof WC_Product || 'publish' !== $producto->get_status() || ! WC()->cart ) {
			wp_send_json_error( array( 'mensaje' => $error ), 400 );
		}

		$atributos = array();

		if ( $producto->is_type( 'variable' ) ) {
			$variacion = $variation_id ? wc_get_product( $variation_id ) : null;

			if ( ! $variacion instanceof WC_Product_Variation
				|| $variacion->get_parent_id() !== $product_id
				|| 'publish' !== $variacion->get_status()
			) {
				wp_send_json_error( array( 'mensaje' => __( 'Elegí un acabado válido.', 'cj-aluminios-b2b' ) ), 400 );
			}

			if ( (float) $variacion->get_price() <= 0 ) {
				wp_send_json_error( array( 'mensaje' => __( 'Ese acabado todavía no tiene precio cargado. Consultanos por WhatsApp.', 'cj-aluminios-b2b' ) ), 400 );
			}

			$atributos = $variacion->get_variation_attributes();
		} else {
			$variation_id = 0;
		}

		$clave = WC()->cart->add_to_cart( $product_id, $cantidad, $variation_id, $atributos );

		if ( ! $clave ) {
			$avisos = wc_get_notices( 'error' );
			wc_clear_notices();

			if ( ! empty( $avisos[0]['notice'] ) ) {
				$error = wp_strip_all_tags( $avisos[0]['notice'] );
			}

			wp_send_json_error( array( 'mensaje' => $error ), 400 );
		}

		wc_clear_notices();

		wp_send_json_success(
			array(
				'mensaje'  => sprintf(
					/* translators: 1: cantidad, 2: nombre del producto */
					__( 'Agregado al pedido: %1$d × %2$s', 'cj-aluminios-b2b' ),
					$cantidad,
					$producto->get_name()
				),
				'cantidad' => (int) WC()->cart->get_cart_contents_count(),
			)
		);
	}

	/* ------------------------------------------ Mejoras en tienda/categoría */

	public function render_lineas_en_tienda(): void {
		if ( '' === self::url_catalogo() ) {
			return;
		}

		$actual = is_product_category() ? (string) get_queried_object()->slug : '';

		echo '<nav class="cj-b2b cj-nav cj-lineas-tienda" aria-label="' . esc_attr__( 'Líneas técnicas', 'cj-aluminios-b2b' ) . '">';
		echo '<a class="cj-chip" href="' . esc_url( self::url_catalogo() ) . '">' . esc_html__( 'Catálogo técnico', 'cj-aluminios-b2b' ) . '</a>';

		foreach ( self::lineas() as $slug => $etiqueta ) {
			echo '<a class="cj-chip' . ( $slug === $actual ? ' is-active' : '' ) . '" href="' . esc_url( self::url_catalogo( $slug ) ) . '">' . esc_html( $etiqueta ) . '</a>';
		}

		echo '</nav>';
	}

	public function render_meta_en_tienda(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$id     = $product->get_id();
		$codigo = CJB2B_Product_Fields::codigo_matriz( $id );
		$peso   = CJB2B_Product_Fields::es_perfil( $id ) ? CJB2B_Product_Fields::peso_metro( $id ) : 0.0;
		$partes = array();

		if ( '' !== $codigo ) {
			$partes[] = '<span class="cj-code">' . esc_html( $codigo ) . '</span>';
		}

		if ( $peso > 0 ) {
			$partes[] = esc_html( CJB2B_Frontend::numero( $peso ) ) . ' kg/m';
		}

		if ( ! empty( $partes ) ) {
			echo '<span class="cj-loop-meta">' . wp_kses_post( implode( ' · ', $partes ) ) . '</span>';
		}
	}
}
