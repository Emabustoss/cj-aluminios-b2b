<?php
/**
 * Fase 4: cohesión de navegación.
 *
 * - Barra de cuenta arriba de todo el sitio (wp_body_open): contacto, rol B2B
 *   activo, Mi cuenta y Pedido con contador.
 * - Pie B2B ([cj_footer_b2b]) con todas las secciones del portal. En temas de
 *   bloques reemplaza la parte de plantilla "footer" (lo hace
 *   CJB2B_Institutional_Pages::configurar_sitio()); en temas clásicos sale por
 *   wp_footer.
 *
 * Los enlaces salen de una sola lista (enlaces()) que también usa el menú
 * principal, así header, menú y pie nunca quedan desalineados.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Site_Navigation {

	private static ?self $instance = null;

	const SHORTCODE_FOOTER = 'cj_footer_b2b';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_body_open', array( $this, 'render_barra' ), 5 );
		add_shortcode( self::SHORTCODE_FOOTER, array( $this, 'render_footer' ) );
		add_action( 'wp_footer', array( $this, 'footer_tema_clasico' ), 5 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'fragmento_contador' ) );
	}

	/* ------------------------------------------------------------ Enlaces */

	/**
	 * Secciones del portal, en el orden del menú.
	 *
	 * @return array<int,array{clave:string,id:int,label:string,url:string}>
	 */
	public static function enlaces(): array {
		$paginas = (array) CJB2B_Frontend::ajuste( 'paginas' );
		$ids     = array(
			'inicio'       => (int) ( $paginas['inicio'] ?? 0 ),
			'catalogo'     => (int) CJB2B_Frontend::ajuste( 'catalogo_page_id' ),
			'tratamientos' => (int) ( $paginas['tratamientos'] ?? 0 ),
			'simulador'    => (int) ( $paginas['simulador'] ?? 0 ),
			'alta'         => (int) ( $paginas['alta'] ?? 0 ),
		);
		$enlaces = array();

		foreach ( $ids as $clave => $page_id ) {
			if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
				$enlaces[] = array(
					'clave' => $clave,
					'id'    => $page_id,
					'label' => get_the_title( $page_id ),
					'url'   => (string) get_permalink( $page_id ),
				);
			}
		}

		return $enlaces;
	}

	private static function url_cuenta(): string {
		return function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : wp_login_url();
	}

	private static function url_pedido(): string {
		return function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : home_url( '/' );
	}

	private static function cantidad_pedido(): int {
		return ( function_exists( 'WC' ) && WC()->cart ) ? (int) WC()->cart->get_cart_contents_count() : 0;
	}

	/** Contador del pedido: se actualiza por fragmentos de WooCommerce y por el JS del catálogo. */
	private static function contador_html(): string {
		return '<span class="cj-barra__contador" data-cj-pedido-cuenta>' . esc_html( (string) self::cantidad_pedido() ) . '</span>';
	}

	public function fragmento_contador( $fragmentos ) {
		$fragmentos['span.cj-barra__contador'] = self::contador_html();
		return $fragmentos;
	}

	/* -------------------------------------------------------------- Barra */

	public function render_barra(): void {
		if ( is_admin() ) {
			return;
		}

		$whatsapp = CJB2B_Frontend::whatsapp_url();
		$usuario  = wp_get_current_user();
		$logueado = $usuario && $usuario->exists();
		?>
		<div class="cj-b2b cj-barra" role="navigation" aria-label="<?php esc_attr_e( 'Cuenta y pedido', 'cj-aluminios-b2b' ); ?>">
			<div class="cj-barra__interior">
				<?php if ( '' !== $whatsapp ) : ?>
					<a class="cj-barra__contacto" href="<?php echo esc_url( $whatsapp ); ?>" target="_blank" rel="noopener">
						<?php
						/* translators: %s: número de WhatsApp */
						echo esc_html( sprintf( __( 'WhatsApp %s', 'cj-aluminios-b2b' ), CJB2B_Frontend::whatsapp_legible() ) );
						?>
					</a>
				<?php endif; ?>

				<div class="cj-barra__cuenta">
					<?php if ( $logueado ) : ?>
						<?php $rol = CJB2B_Frontend::rol_actual(); ?>
						<span class="cj-barra__rol" data-rol="<?php echo esc_attr( $rol['slug'] ); ?>" title="<?php echo esc_attr( CJB2B_Frontend::rol_texto() ); ?>">
							<span class="cj-barra__rol-etiqueta"><?php esc_html_e( 'Tu lista:', 'cj-aluminios-b2b' ); ?></span>
							<strong><?php echo esc_html( $rol['nombre'] ); ?></strong>
							<?php if ( $rol['descuento'] > 0 ) : ?>
								<span class="cj-barra__descuento">−<?php echo esc_html( CJB2B_Frontend::numero( $rol['descuento'], 2 ) ); ?>%</span>
							<?php endif; ?>
						</span>
						<a href="<?php echo esc_url( self::url_cuenta() ); ?>"><?php esc_html_e( 'Mi cuenta', 'cj-aluminios-b2b' ); ?></a>
					<?php else : ?>
						<a href="<?php echo esc_url( self::url_cuenta() ); ?>"><?php esc_html_e( 'Ingresar', 'cj-aluminios-b2b' ); ?></a>
						<?php $alta = CJB2B_Institutional_Pages::url( 'alta' ); ?>
						<?php if ( '' !== $alta ) : ?>
							<a href="<?php echo esc_url( $alta ); ?>"><?php esc_html_e( 'Registro gremio', 'cj-aluminios-b2b' ); ?></a>
						<?php endif; ?>
					<?php endif; ?>

					<a class="cj-barra__pedido" href="<?php echo esc_url( self::url_pedido() ); ?>">
						<?php esc_html_e( 'Pedido', 'cj-aluminios-b2b' ); ?>
						<?php echo self::contador_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en contador_html(). ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- Pie */

	public function footer_tema_clasico(): void {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return; // En temas de bloques el pie va en la parte de plantilla "footer".
		}

		echo $this->render_footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML escapado en render_footer().
	}

	public function render_footer( $atts = array() ): string {
		$whatsapp = CJB2B_Frontend::whatsapp_url();
		$logueado = is_user_logged_in();

		ob_start();
		?>
		<footer class="cj-b2b cj-pie">
			<div class="cj-pie__interior">
				<div class="cj-pie__col cj-pie__marca">
					<p class="cj-pie__titulo"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
					<p><?php esc_html_e( 'Distribuidora de perfiles de aluminio y accesorios para el gremio. Rosario, Santa Fe.', 'cj-aluminios-b2b' ); ?></p>
					<?php if ( '' !== $whatsapp ) : ?>
						<a class="cj-btn cj-btn--whatsapp" href="<?php echo esc_url( $whatsapp ); ?>" target="_blank" rel="noopener">
							<?php
							/* translators: %s: número de WhatsApp */
							echo esc_html( sprintf( __( 'WhatsApp %s', 'cj-aluminios-b2b' ), CJB2B_Frontend::whatsapp_legible() ) );
							?>
						</a>
					<?php endif; ?>
				</div>

				<nav class="cj-pie__col" aria-label="<?php esc_attr_e( 'Secciones', 'cj-aluminios-b2b' ); ?>">
					<p class="cj-pie__titulo"><?php esc_html_e( 'Secciones', 'cj-aluminios-b2b' ); ?></p>
					<ul>
						<?php foreach ( self::enlaces() as $enlace ) : ?>
							<li><a href="<?php echo esc_url( $enlace['url'] ); ?>"><?php echo esc_html( $enlace['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>

				<nav class="cj-pie__col" aria-label="<?php esc_attr_e( 'Líneas', 'cj-aluminios-b2b' ); ?>">
					<p class="cj-pie__titulo"><?php esc_html_e( 'Líneas', 'cj-aluminios-b2b' ); ?></p>
					<ul>
						<?php foreach ( CJB2B_Catalog_UI::lineas() as $slug => $etiqueta ) : ?>
							<li><a href="<?php echo esc_url( CJB2B_Catalog_UI::url_catalogo( $slug ) ); ?>"><?php echo esc_html( $etiqueta ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>

				<nav class="cj-pie__col" aria-label="<?php esc_attr_e( 'Cuenta', 'cj-aluminios-b2b' ); ?>">
					<p class="cj-pie__titulo"><?php esc_html_e( 'Cuenta gremio', 'cj-aluminios-b2b' ); ?></p>
					<ul>
						<li><a href="<?php echo esc_url( self::url_cuenta() ); ?>"><?php echo $logueado ? esc_html__( 'Mi cuenta', 'cj-aluminios-b2b' ) : esc_html__( 'Ingresar', 'cj-aluminios-b2b' ); ?></a></li>
						<li><a href="<?php echo esc_url( self::url_pedido() ); ?>"><?php esc_html_e( 'Mi pedido', 'cj-aluminios-b2b' ); ?></a></li>
						<?php if ( $logueado ) : ?>
							<li class="cj-pie__rol"><?php echo esc_html( CJB2B_Frontend::rol_texto() ); ?></li>
						<?php endif; ?>
					</ul>
				</nav>
			</div>

			<p class="cj-pie__legal">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: año, 2: nombre del sitio */
						__( '© %1$s %2$s. Precios y pesos de referencia, sujetos a confirmación.', 'cj-aluminios-b2b' ),
						wp_date( 'Y' ),
						get_bloginfo( 'name' )
					)
				);
				?>
			</p>
		</footer>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------- Parte de plantilla (FSE) */

	/**
	 * Tema de bloques: deja la parte de plantilla "footer" del tema activo con
	 * el pie B2B. Solo se llama desde configurar_sitio() (cambia el sitio).
	 */
	public static function instalar_footer_de_bloques(): void {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return;
		}

		$tema      = get_stylesheet();
		$contenido = '<!-- wp:shortcode -->[' . self::SHORTCODE_FOOTER . ']<!-- /wp:shortcode -->';
		$previa    = get_block_template( $tema . '//footer', 'wp_template_part' );

		if ( $previa && $previa->wp_id ) {
			wp_update_post(
				array(
					'ID'           => (int) $previa->wp_id,
					'post_content' => $contenido,
				)
			);
			return;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'wp_template_part',
				'post_name'    => 'footer',
				'post_title'   => __( 'Pie B2B', 'cj-aluminios-b2b' ),
				'post_status'  => 'publish',
				'post_content' => $contenido,
			)
		);

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			wp_set_object_terms( $post_id, $tema, 'wp_theme' );
			wp_set_object_terms( $post_id, 'footer', 'wp_template_part_area' );
		}
	}
}
