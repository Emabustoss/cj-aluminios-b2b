<?php
/**
 * Fase 3: portada B2B, alta de clientes gremio y páginas institucionales.
 *
 * - [cj_home_b2b]: portada comercial (buscador técnico, líneas, simulador,
 *   beneficios y contacto).
 * - [cj_alta_b2b]: solicitud de cuenta gremio. Crea el usuario SIN descuento
 *   (rol "publico") y lo deja pendiente: la lista de precios la asigna una
 *   persona desde el admin, nunca el formulario.
 * - Crea/actualiza las páginas del sitio y el menú principal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Institutional_Pages {

	private static ?self $instance = null;

	const SHORTCODE_HOME = 'cj_home_b2b';
	const SHORTCODE_ALTA = 'cj_alta_b2b';
	const NONCE_ALTA     = 'cjb2b_alta';
	const ACCION_ALTA    = 'cjb2b_alta_enviar';
	const SCRIPT_ALTA    = 'cj-b2b-alta';
	const SLUG_ADMIN     = 'cjb2b-solicitudes';

	const LIMITE_ALTAS   = 3;
	const LIMITE_VENTANA = HOUR_IN_SECONDS;

	const META_ESTADO  = 'cjb2b_alta_estado';
	const META_FECHA   = 'cjb2b_alta_fecha';
	const META_CUIT    = 'cjb2b_cuit';
	const META_VOLUMEN = 'cjb2b_volumen';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( self::SHORTCODE_HOME, array( $this, 'render_home' ) );
		add_shortcode( self::SHORTCODE_ALTA, array( $this, 'render_alta' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'registrar_script' ) );

		add_action( 'wp_ajax_' . self::ACCION_ALTA, array( $this, 'ajax_alta' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACCION_ALTA, array( $this, 'ajax_alta' ) );

		add_action( 'admin_menu', array( $this, 'registrar_submenu' ), 30 );
		add_action( 'set_user_role', array( $this, 'marcar_aprobada' ), 10, 2 );
	}

	/* ------------------------------------------------------------ Páginas */

	/**
	 * Páginas institucionales: clave => [ título, slug, shortcode ].
	 */
	public static function paginas(): array {
		return array(
			'inicio'       => array( __( 'Inicio', 'cj-aluminios-b2b' ), 'inicio', self::SHORTCODE_HOME ),
			'simulador'    => array( __( 'Simulador de Aberturas', 'cj-aluminios-b2b' ), 'simulador-de-aberturas', CJB2B_Simulador_2D::SHORTCODE ),
			'tratamientos' => array( __( 'Tratamientos y Acabados', 'cj-aluminios-b2b' ), 'tratamientos-y-acabados', CJB2B_Tratamientos_Catalog::SHORTCODE ),
			'alta'         => array( __( 'Registro Gremio B2B', 'cj-aluminios-b2b' ), 'registro-gremio-b2b', self::SHORTCODE_ALTA ),
		);
	}

	public static function activar(): void {
		self::asegurar_paginas();
	}

	/**
	 * Crea las páginas que falten (o les repone el shortcode) y guarda sus IDs.
	 * No toca el contenido de una página que ya tiene su shortcode.
	 *
	 * @return array<string,int> clave => ID.
	 */
	public static function asegurar_paginas(): array {
		$guardadas = (array) CJB2B_Frontend::ajuste( 'paginas' );
		$ids       = array();

		foreach ( self::paginas() as $clave => $definicion ) {
			list( $titulo, $slug, $shortcode ) = $definicion;

			$pagina = ! empty( $guardadas[ $clave ] ) ? get_post( (int) $guardadas[ $clave ] ) : null;

			if ( ! $pagina || 'page' !== $pagina->post_type || 'trash' === $pagina->post_status ) {
				$pagina = get_page_by_path( $slug );
			}

			$contenido = '<!-- wp:shortcode -->[' . $shortcode . ']<!-- /wp:shortcode -->';

			if ( $pagina ) {
				$page_id = (int) $pagina->ID;

				if ( ! has_shortcode( $pagina->post_content, $shortcode ) || 'publish' !== $pagina->post_status ) {
					wp_update_post(
						array(
							'ID'           => $page_id,
							'post_content' => has_shortcode( $pagina->post_content, $shortcode ) ? $pagina->post_content : $contenido,
							'post_status'  => 'publish',
						)
					);
				}
			} else {
				$page_id = (int) wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_title'   => $titulo,
						'post_name'    => $slug,
						'post_status'  => 'publish',
						'post_content' => $contenido,
					)
				);
			}

			if ( $page_id ) {
				$ids[ $clave ] = $page_id;
			}
		}

		CJB2B_Frontend::actualizar_ajuste( 'paginas', $ids );

		return $ids;
	}

	/** URL de una página institucional ('' si todavía no existe). */
	public static function url( string $clave ): string {
		$paginas = (array) CJB2B_Frontend::ajuste( 'paginas' );
		$page_id = isset( $paginas[ $clave ] ) ? (int) $paginas[ $clave ] : 0;
		$url     = $page_id ? get_permalink( $page_id ) : '';

		return $url ? $url : '';
	}

	/**
	 * Deja "Inicio" como portada del sitio y arma el menú principal. Cambia
	 * la configuración del sitio, por eso NO corre sola al activar el plugin:
	 * se llama a mano (wp eval 'CJB2B_Institutional_Pages::configurar_sitio();').
	 */
	public static function configurar_sitio(): void {
		$ids = self::asegurar_paginas();

		if ( ! empty( $ids['inicio'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', (int) $ids['inicio'] );

			// La portada trae su propio encabezado: se usa la plantilla sin título si el tema la tiene.
			if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && get_block_template( get_stylesheet() . '//page-no-title', 'wp_template' ) ) {
				update_post_meta( (int) $ids['inicio'], '_wp_page_template', 'page-no-title' );
			}
		}

		$enlaces = array();

		foreach ( array( 'inicio', 'catalogo', 'simulador', 'tratamientos', 'alta' ) as $clave ) {
			$page_id = 'catalogo' === $clave ? (int) CJB2B_Frontend::ajuste( 'catalogo_page_id' ) : (int) ( $ids[ $clave ] ?? 0 );

			if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
				$enlaces[] = array(
					'id'    => $page_id,
					'label' => get_the_title( $page_id ),
					'url'   => get_permalink( $page_id ),
				);
			}
		}

		$cuenta_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0;

		if ( $cuenta_id > 0 ) {
			$enlaces[] = array(
				'id'    => $cuenta_id,
				'label' => __( 'Mi cuenta', 'cj-aluminios-b2b' ),
				'url'   => get_permalink( $cuenta_id ),
			);
		}

		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			self::menu_de_bloques( $enlaces );
		} else {
			self::menu_clasico( $enlaces );
		}
	}

	/** Tema de bloques: el menú es una entrada wp_navigation. */
	private static function menu_de_bloques( array $enlaces ): void {
		$contenido = '';

		foreach ( $enlaces as $enlace ) {
			$contenido .= '<!-- wp:navigation-link ' . wp_json_encode(
				array(
					'label' => $enlace['label'],
					'type'  => 'page',
					'id'    => $enlace['id'],
					'url'   => $enlace['url'],
					'kind'  => 'post-type',
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			) . ' /-->' . "\n";
		}

		// wp_update_post() / wp_insert_post() quitan las barras: sin wp_slash() se rompe el JSON.
		$contenido = wp_slash( $contenido );

		$navegaciones = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		if ( ! empty( $navegaciones ) ) {
			wp_update_post(
				array(
					'ID'           => $navegaciones[0]->ID,
					'post_content' => $contenido,
				)
			);
			return;
		}

		wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_title'   => __( 'Menú principal', 'cj-aluminios-b2b' ),
				'post_status'  => 'publish',
				'post_content' => $contenido,
			)
		);
	}

	/** Tema clásico: menú de WordPress asignado a la primera ubicación del tema. */
	private static function menu_clasico( array $enlaces ): void {
		$nombre = __( 'Menú principal', 'cj-aluminios-b2b' );
		$menu   = wp_get_nav_menu_object( $nombre );
		$id     = $menu ? (int) $menu->term_id : wp_create_nav_menu( $nombre );

		if ( is_wp_error( $id ) || ! $id ) {
			return;
		}

		$existentes = array();

		foreach ( (array) wp_get_nav_menu_items( $id ) as $item ) {
			$existentes[] = (int) $item->object_id;
		}

		foreach ( $enlaces as $enlace ) {
			if ( in_array( (int) $enlace['id'], $existentes, true ) ) {
				continue;
			}

			wp_update_nav_menu_item(
				$id,
				0,
				array(
					'menu-item-title'     => $enlace['label'],
					'menu-item-object'    => 'page',
					'menu-item-object-id' => $enlace['id'],
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
				)
			);
		}

		$ubicaciones = array_keys( get_registered_nav_menus() );

		if ( ! empty( $ubicaciones ) ) {
			$asignadas                    = (array) get_theme_mod( 'nav_menu_locations', array() );
			$asignadas[ $ubicaciones[0] ] = $id;
			set_theme_mod( 'nav_menu_locations', $asignadas );
		}
	}

	/* ------------------------------------------------------------- Assets */

	public function registrar_script(): void {
		$archivo = CJB2B_PATH . 'assets/js/cj-b2b-alta.js';

		wp_register_script(
			self::SCRIPT_ALTA,
			CJB2B_URL . 'assets/js/cj-b2b-alta.js',
			array(),
			CJB2B_Frontend::version_archivo( $archivo ),
			true
		);

		wp_localize_script(
			self::SCRIPT_ALTA,
			'cjb2bAlta',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'accion'  => self::ACCION_ALTA,
				'textos'  => array(
					'enviando' => __( 'Enviando solicitud…', 'cj-aluminios-b2b' ),
					'error'    => __( 'No se pudo enviar la solicitud. Probá de nuevo en unos segundos.', 'cj-aluminios-b2b' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------ Portada */

	/** Texto corto de cada línea para las tarjetas de la portada. */
	private static function descripciones_lineas(): array {
		return array(
			'linea-herrero'               => __( 'Perfilería liviana y económica para aberturas estándar.', 'cj-aluminios-b2b' ),
			'linea-modena'                => __( 'La línea más usada en vivienda: corredizas y de abrir.', 'cj-aluminios-b2b' ),
			'a30-new'                     => __( 'Alta prestación: mayor hermeticidad y vidrios dobles.', 'cj-aluminios-b2b' ),
			'estructurales'               => __( 'Tubos, ángulos, planchuelas y perfiles de uso general.', 'cj-aluminios-b2b' ),
			'mampara-de-bano'             => __( 'Perfiles para mamparas y cerramientos de baño.', 'cj-aluminios-b2b' ),
			CJB2B_Catalog_UI::CAT_ACCESORIOS => __( 'Herrajes, burletes, felpas y complementos.', 'cj-aluminios-b2b' ),
		);
	}

	public function render_home( $atts = array() ): string {
		$url_catalogo     = CJB2B_Catalog_UI::url_catalogo();
		$url_simulador    = self::url( 'simulador' );
		$url_tratamientos = self::url( 'tratamientos' );
		$url_alta         = self::url( 'alta' );
		$url_cuenta       = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
		$descripciones    = self::descripciones_lineas();
		$direccion        = (string) CJB2B_Frontend::ajuste( 'direccion' );
		$horario          = (string) CJB2B_Frontend::ajuste( 'horario' );
		$email            = (string) CJB2B_Frontend::ajuste( 'email_contacto' );
		$tratamientos     = CJB2B_Tratamientos_Catalog::tratamientos();
		$whatsapp         = CJB2B_Frontend::whatsapp_url( __( 'Hola, quiero hacer una consulta sobre perfiles de aluminio.', 'cj-aluminios-b2b' ) );

		$beneficios = array(
			__( 'Precio de tu lista', 'cj-aluminios-b2b' )        => __( 'Iniciás sesión y ves cada perfil con el precio de tu lista de gremio ya aplicado.', 'cj-aluminios-b2b' ),
			__( 'Catálogo técnico', 'cj-aluminios-b2b' )          => __( 'Buscá por código de matriz o SKU. Cada perfil con su peso por metro y precio por barra de 6 m.', 'cj-aluminios-b2b' ),
			__( 'Precio transparente', 'cj-aluminios-b2b' )       => __( 'Desglose de cada precio: peso, precio por kilo del acabado y descuento de tu lista.', 'cj-aluminios-b2b' ),
			__( 'Pedido por WhatsApp', 'cj-aluminios-b2b' )       => __( 'Armás el pedido en el portal y nos llega por WhatsApp. Sin pago online: lo confirmamos nosotros.', 'cj-aluminios-b2b' ),
			/* translators: %d: cantidad de tratamientos */
			sprintf( __( '%d tratamientos', 'cj-aluminios-b2b' ), count( $tratamientos ) ) => __( 'Pintados, anodizados y símil madera para toda la perfilería.', 'cj-aluminios-b2b' ),
			__( 'Simulador para tus clientes', 'cj-aluminios-b2b' ) => __( 'Mostrale a tu cliente cómo queda la abertura sobre la foto de su obra antes de cotizar.', 'cj-aluminios-b2b' ),
		);

		ob_start();
		?>
		<div class="cj-b2b cj-home alignwide">

			<section class="cj-hero">
				<p class="cj-hero__sobre"><?php esc_html_e( 'CJ Aluminios · Rosario', 'cj-aluminios-b2b' ); ?></p>
				<h1 class="cj-hero__titulo"><?php esc_html_e( 'Perfiles de aluminio y accesorios para carpinteros y gremio', 'cj-aluminios-b2b' ); ?></h1>
				<p class="cj-hero__texto"><?php esc_html_e( 'Catálogo técnico con precio por lista, pedido directo por WhatsApp y simulador de aberturas para mostrarle a tu cliente.', 'cj-aluminios-b2b' ); ?></p>

				<?php if ( '' !== $url_catalogo ) : ?>
					<form class="cj-hero__buscador" role="search" method="get" action="<?php echo esc_url( $url_catalogo ); ?>">
						<label for="cj-home-buscar"><?php esc_html_e( 'Búsqueda rápida por código de matriz, SKU o nombre', 'cj-aluminios-b2b' ); ?></label>
						<div class="cj-hero__buscar-fila">
							<input type="search" id="cj-home-buscar" name="buscar" maxlength="80" placeholder="<?php esc_attr_e( 'Ej: 6045, MOD-6277, contravidrio', 'cj-aluminios-b2b' ); ?>" autocomplete="off" required />
							<button type="submit" class="cj-btn"><?php esc_html_e( 'Buscar', 'cj-aluminios-b2b' ); ?></button>
						</div>
					</form>
				<?php endif; ?>

				<div class="cj-hero__acciones">
					<?php if ( '' !== $url_catalogo ) : ?>
						<a class="cj-btn cj-btn--claro" href="<?php echo esc_url( $url_catalogo ); ?>"><?php esc_html_e( 'Ver catálogo completo', 'cj-aluminios-b2b' ); ?></a>
					<?php endif; ?>
					<?php if ( is_user_logged_in() ) : ?>
						<a class="cj-btn cj-btn--contorno" href="<?php echo esc_url( $url_cuenta ); ?>"><?php esc_html_e( 'Mi cuenta', 'cj-aluminios-b2b' ); ?></a>
					<?php elseif ( '' !== $url_alta ) : ?>
						<a class="cj-btn cj-btn--contorno" href="<?php echo esc_url( $url_alta ); ?>"><?php esc_html_e( 'Solicitar cuenta gremio', 'cj-aluminios-b2b' ); ?></a>
					<?php endif; ?>
				</div>
			</section>

			<?php if ( '' !== $url_catalogo ) : ?>
				<section class="cj-seccion" aria-labelledby="cj-home-lineas">
					<h2 class="cj-seccion__titulo" id="cj-home-lineas"><?php esc_html_e( 'Líneas de perfiles y accesorios', 'cj-aluminios-b2b' ); ?></h2>
					<ul class="cj-lineas">
						<?php foreach ( CJB2B_Catalog_UI::lineas() as $slug => $etiqueta ) : ?>
							<li>
								<a class="cj-linea" href="<?php echo esc_url( CJB2B_Catalog_UI::url_catalogo( $slug ) ); ?>">
									<strong class="cj-linea__nombre"><?php echo esc_html( $etiqueta ); ?></strong>
									<span class="cj-linea__desc"><?php echo esc_html( $descripciones[ $slug ] ?? '' ); ?></span>
									<span class="cj-linea__ir" aria-hidden="true"><?php esc_html_e( 'Ver perfiles →', 'cj-aluminios-b2b' ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( '' !== $url_simulador ) : ?>
				<section class="cj-banner" aria-labelledby="cj-home-simulador">
					<div class="cj-banner__texto">
						<p class="cj-hero__sobre"><?php esc_html_e( 'Nuevo', 'cj-aluminios-b2b' ); ?></p>
						<h2 class="cj-banner__titulo" id="cj-home-simulador"><?php esc_html_e( 'Simulador visual de aberturas', 'cj-aluminios-b2b' ); ?></h2>
						<p><?php esc_html_e( 'Subí la foto de la obra, elegí tipología, línea y color, y mirá cómo queda. Descargá la imagen o pedí la cotización por WhatsApp.', 'cj-aluminios-b2b' ); ?></p>
						<div class="cj-hero__acciones">
							<a class="cj-btn cj-btn--claro" href="<?php echo esc_url( $url_simulador ); ?>"><?php esc_html_e( 'Probar el simulador', 'cj-aluminios-b2b' ); ?></a>
							<?php if ( '' !== $url_tratamientos ) : ?>
								<a class="cj-btn cj-btn--contorno" href="<?php echo esc_url( $url_tratamientos ); ?>"><?php esc_html_e( 'Ver tratamientos y colores', 'cj-aluminios-b2b' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
					<ul class="cj-banner__paleta" aria-hidden="true">
						<?php foreach ( $tratamientos as $datos ) : ?>
							<li style="<?php echo esc_attr( CJB2B_Tratamientos_Catalog::estilo_muestra( $datos ) ); ?>"></li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<section class="cj-seccion" aria-labelledby="cj-home-beneficios">
				<h2 class="cj-seccion__titulo" id="cj-home-beneficios"><?php esc_html_e( 'Pensado para el taller', 'cj-aluminios-b2b' ); ?></h2>
				<ul class="cj-beneficios">
					<?php foreach ( $beneficios as $titulo => $texto ) : ?>
						<li class="cj-beneficio">
							<strong><?php echo esc_html( $titulo ); ?></strong>
							<span><?php echo esc_html( $texto ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<section class="cj-seccion cj-contacto" aria-labelledby="cj-home-contacto">
				<div>
					<h2 class="cj-seccion__titulo" id="cj-home-contacto"><?php esc_html_e( 'Contacto y ubicación', 'cj-aluminios-b2b' ); ?></h2>
					<dl class="cj-contacto__datos">
						<?php if ( '' !== $direccion ) : ?>
							<dt><?php esc_html_e( 'Dirección', 'cj-aluminios-b2b' ); ?></dt>
							<dd>
								<?php echo esc_html( $direccion ); ?><br>
								<a href="<?php echo esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $direccion ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Cómo llegar', 'cj-aluminios-b2b' ); ?></a>
							</dd>
						<?php endif; ?>
						<?php if ( '' !== $horario ) : ?>
							<dt><?php esc_html_e( 'Horario', 'cj-aluminios-b2b' ); ?></dt>
							<dd><?php echo esc_html( $horario ); ?></dd>
						<?php endif; ?>
						<dt><?php esc_html_e( 'WhatsApp', 'cj-aluminios-b2b' ); ?></dt>
						<dd><?php echo esc_html( CJB2B_Frontend::whatsapp_legible() ); ?></dd>
						<?php if ( '' !== $email && is_email( $email ) ) : ?>
							<dt><?php esc_html_e( 'Email', 'cj-aluminios-b2b' ); ?></dt>
							<dd><a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a></dd>
						<?php endif; ?>
					</dl>
				</div>
				<div class="cj-contacto__cta">
					<p><?php esc_html_e( '¿Tenés una obra para cotizar o querés abrir tu cuenta de gremio? Escribinos y lo vemos.', 'cj-aluminios-b2b' ); ?></p>
					<a class="cj-btn cj-btn--whatsapp" href="<?php echo esc_url( $whatsapp ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Escribinos por WhatsApp', 'cj-aluminios-b2b' ); ?></a>
				</div>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* --------------------------------------------------- Alta gremio B2B */

	public static function volumenes(): array {
		return array(
			'hasta-500' => __( 'Hasta 500 kg por mes', 'cj-aluminios-b2b' ),
			'500-2000'  => __( 'Entre 500 y 2.000 kg por mes', 'cj-aluminios-b2b' ),
			'mas-2000'  => __( 'Más de 2.000 kg por mes', 'cj-aluminios-b2b' ),
			'no-se'     => __( 'Todavía no lo sé', 'cj-aluminios-b2b' ),
		);
	}

	/** Valida un CUIT/CUIL argentino (11 dígitos con dígito verificador). */
	public static function cuit_valido( string $cuit ): bool {
		if ( ! preg_match( '/^\d{11}$/', $cuit ) ) {
			return false;
		}

		$pesos = array( 5, 4, 3, 2, 7, 6, 5, 4, 3, 2 );
		$suma  = 0;

		foreach ( $pesos as $i => $peso ) {
			$suma += (int) $cuit[ $i ] * $peso;
		}

		$verificador = 11 - ( $suma % 11 );

		if ( 11 === $verificador ) {
			$verificador = 0;
		}

		return 10 !== $verificador && (int) $cuit[10] === $verificador;
	}

	public function render_alta( $atts = array() ): string {
		if ( is_user_logged_in() ) {
			$url_catalogo = CJB2B_Catalog_UI::url_catalogo();

			return '<div class="cj-b2b cj-alta"><p class="cj-aviso cj-aviso--ok">'
				. esc_html__( 'Ya tenés una sesión iniciada.', 'cj-aluminios-b2b' ) . ' '
				. esc_html( CJB2B_Frontend::rol_texto() ) . '.'
				. ( '' !== $url_catalogo ? ' <a href="' . esc_url( $url_catalogo ) . '">' . esc_html__( 'Ir al catálogo', 'cj-aluminios-b2b' ) . '</a>' : '' )
				. '</p></div>';
		}

		wp_enqueue_script( self::SCRIPT_ALTA );

		$url_cuenta = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();

		ob_start();
		?>
		<div class="cj-b2b cj-alta" data-cj-alta>
			<form class="cj-wa" data-cj-alta-form novalidate>
				<p class="cj-wa__titulo"><?php esc_html_e( 'Solicitar cuenta de gremio', 'cj-aluminios-b2b' ); ?></p>
				<p class="cj-wa__intro"><?php esc_html_e( 'Completá los datos de tu taller. Revisamos la solicitud y te asignamos la lista de precios que corresponde; te avisamos por WhatsApp.', 'cj-aluminios-b2b' ); ?></p>

				<div class="cj-alta__grilla">
					<p class="cj-campo">
						<label for="cj-alta-nombre"><?php esc_html_e( 'Nombre y apellido', 'cj-aluminios-b2b' ); ?> *</label>
						<input type="text" id="cj-alta-nombre" name="cj_nombre" maxlength="100" autocomplete="name" required />
					</p>
					<p class="cj-campo">
						<label for="cj-alta-empresa"><?php esc_html_e( 'Taller / Empresa', 'cj-aluminios-b2b' ); ?> *</label>
						<input type="text" id="cj-alta-empresa" name="cj_empresa" maxlength="100" autocomplete="organization" required />
					</p>
					<p class="cj-campo">
						<label for="cj-alta-cuit"><?php esc_html_e( 'CUIT', 'cj-aluminios-b2b' ); ?> *</label>
						<input type="text" id="cj-alta-cuit" name="cj_cuit" maxlength="13" inputmode="numeric" autocomplete="off" placeholder="20-12345678-6" required />
					</p>
					<p class="cj-campo">
						<label for="cj-alta-whatsapp"><?php esc_html_e( 'WhatsApp', 'cj-aluminios-b2b' ); ?> *</label>
						<input type="tel" id="cj-alta-whatsapp" name="cj_whatsapp" maxlength="30" inputmode="tel" autocomplete="tel" placeholder="341 555 1234" required />
					</p>
					<p class="cj-campo">
						<label for="cj-alta-localidad"><?php esc_html_e( 'Localidad', 'cj-aluminios-b2b' ); ?> *</label>
						<input type="text" id="cj-alta-localidad" name="cj_localidad" maxlength="80" autocomplete="address-level2" required />
					</p>
					<p class="cj-campo">
						<label for="cj-alta-volumen"><?php esc_html_e( 'Volumen estimado de compra', 'cj-aluminios-b2b' ); ?> *</label>
						<select id="cj-alta-volumen" name="cj_volumen" required>
							<option value=""><?php esc_html_e( 'Elegí una opción', 'cj-aluminios-b2b' ); ?></option>
							<?php foreach ( self::volumenes() as $valor => $etiqueta ) : ?>
								<option value="<?php echo esc_attr( $valor ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="cj-campo cj-alta__ancho">
						<label for="cj-alta-email"><?php esc_html_e( 'Email', 'cj-aluminios-b2b' ); ?> *</label>
						<input type="email" id="cj-alta-email" name="cj_email" maxlength="100" autocomplete="email" required />
					</p>
				</div>

				<?php // Honeypot: las personas no lo ven ni lo completan; los bots sí. ?>
				<p class="cj-hp" aria-hidden="true">
					<label for="cj-alta-website">Website</label>
					<input type="text" id="cj-alta-website" name="cj_website" value="" tabindex="-1" autocomplete="off" />
				</p>

				<?php wp_nonce_field( self::NONCE_ALTA, 'cj_alta_nonce', false ); ?>

				<p class="cj-aviso cj-aviso--error" data-cj-alta-error role="alert" hidden></p>

				<button type="submit" class="cj-btn" data-cj-alta-enviar><?php esc_html_e( 'Enviar solicitud', 'cj-aluminios-b2b' ); ?></button>
				<p class="cj-wa__legal">
					<?php esc_html_e( '¿Ya tenés cuenta?', 'cj-aluminios-b2b' ); ?>
					<a href="<?php echo esc_url( $url_cuenta ); ?>"><?php esc_html_e( 'Iniciá sesión', 'cj-aluminios-b2b' ); ?></a>
				</p>
			</form>

			<div class="cj-wa cj-wa__exito" data-cj-alta-exito hidden>
				<p class="cj-wa__titulo" data-cj-alta-exito-titulo tabindex="-1"><?php esc_html_e( 'Solicitud registrada', 'cj-aluminios-b2b' ); ?></p>
				<p><?php esc_html_e( 'Creamos tu usuario y te enviamos un email para definir la contraseña. Falta un paso: avisanos por WhatsApp para que activemos tu lista de precios.', 'cj-aluminios-b2b' ); ?></p>
				<a class="cj-btn cj-btn--whatsapp" data-cj-alta-link href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Avisar por WhatsApp', 'cj-aluminios-b2b' ); ?></a>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function error_alta( string $mensaje, int $codigo = 400 ): void {
		wp_send_json_error( array( 'mensaje' => $mensaje ), $codigo );
	}

	public function ajax_alta(): void {
		check_ajax_referer( self::NONCE_ALTA, 'cj_alta_nonce' );

		$generico = __( 'No se pudo registrar la solicitud.', 'cj-aluminios-b2b' );

		// Honeypot: si viene completo es un bot. Respuesta genérica, sin pistas.
		if ( ! empty( $_POST['cj_website'] ) || is_user_logged_in() ) {
			self::error_alta( $generico );
		}

		if ( CJB2B_Frontend::limite_superado( 'alta', self::LIMITE_ALTAS ) ) {
			self::error_alta( __( 'Ya recibimos varias solicitudes desde tu conexión. Probá de nuevo en una hora o escribinos por WhatsApp.', 'cj-aluminios-b2b' ), 429 );
		}

		$nombre    = isset( $_POST['cj_nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['cj_nombre'] ) ) : '';
		$empresa   = isset( $_POST['cj_empresa'] ) ? sanitize_text_field( wp_unslash( $_POST['cj_empresa'] ) ) : '';
		$cuit      = isset( $_POST['cj_cuit'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['cj_cuit'] ) ) ) : '';
		$whatsapp  = isset( $_POST['cj_whatsapp'] ) ? sanitize_text_field( wp_unslash( $_POST['cj_whatsapp'] ) ) : '';
		$localidad = isset( $_POST['cj_localidad'] ) ? sanitize_text_field( wp_unslash( $_POST['cj_localidad'] ) ) : '';
		$volumen   = isset( $_POST['cj_volumen'] ) ? sanitize_key( wp_unslash( $_POST['cj_volumen'] ) ) : '';
		$email     = isset( $_POST['cj_email'] ) ? sanitize_email( wp_unslash( $_POST['cj_email'] ) ) : '';

		$nombre    = CJB2B_Frontend::recortar( $nombre, 100 );
		$empresa   = CJB2B_Frontend::recortar( $empresa, 100 );
		$localidad = CJB2B_Frontend::recortar( $localidad, 80 );
		$whatsapp  = CJB2B_Frontend::recortar( (string) preg_replace( '/[^0-9+()\-\s]/', '', $whatsapp ), 30 );
		$volumenes = self::volumenes();

		if ( strlen( $nombre ) < 3 ) {
			self::error_alta( __( 'Completá tu nombre y apellido.', 'cj-aluminios-b2b' ) );
		}

		if ( strlen( $empresa ) < 2 ) {
			self::error_alta( __( 'Completá el nombre de tu taller o empresa.', 'cj-aluminios-b2b' ) );
		}

		if ( ! self::cuit_valido( (string) $cuit ) ) {
			self::error_alta( __( 'El CUIT no es válido. Revisá los 11 dígitos.', 'cj-aluminios-b2b' ) );
		}

		if ( strlen( (string) preg_replace( '/\D+/', '', $whatsapp ) ) < 8 ) {
			self::error_alta( __( 'Completá un WhatsApp válido (con código de área).', 'cj-aluminios-b2b' ) );
		}

		if ( strlen( $localidad ) < 2 ) {
			self::error_alta( __( 'Completá tu localidad.', 'cj-aluminios-b2b' ) );
		}

		if ( ! isset( $volumenes[ $volumen ] ) ) {
			self::error_alta( __( 'Elegí un volumen estimado de compra.', 'cj-aluminios-b2b' ) );
		}

		if ( '' === $email || ! is_email( $email ) || strlen( $email ) > 100 ) {
			self::error_alta( __( 'Completá un email válido.', 'cj-aluminios-b2b' ) );
		}

		// A partir de acá cada intento cuenta para el límite (máx. 3 por hora por IP).
		CJB2B_Frontend::registrar_intento( 'alta', self::LIMITE_VENTANA );

		if ( email_exists( $email ) ) {
			self::error_alta( __( 'Ya hay una cuenta con ese email. Iniciá sesión o recuperá tu contraseña desde "Mi cuenta".', 'cj-aluminios-b2b' ) );
		}

		$base  = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base  = '' !== $base ? $base : 'gremio';
		$login = $base;
		$n     = 1;

		while ( username_exists( $login ) ) {
			++$n;
			$login = $base . $n;
		}

		// Siempre sin descuento: la lista de gremio la asigna una persona desde el admin.
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'display_name' => $nombre,
				'first_name'   => $nombre,
				'role'         => get_role( 'publico' ) ? 'publico' : 'customer',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			self::error_alta( $generico, 500 );
		}

		update_user_meta( $user_id, 'billing_company', $empresa );
		update_user_meta( $user_id, 'billing_phone', $whatsapp );
		update_user_meta( $user_id, 'billing_city', $localidad );
		update_user_meta( $user_id, 'billing_first_name', $nombre );
		update_user_meta( $user_id, self::META_CUIT, $cuit );
		update_user_meta( $user_id, self::META_VOLUMEN, $volumen );
		update_user_meta( $user_id, self::META_ESTADO, 'pendiente' );
		update_user_meta( $user_id, self::META_FECHA, time() );

		// Email al usuario (enlace para definir su contraseña) y aviso al admin.
		wp_new_user_notification( $user_id, null, 'both' );

		$lineas   = array();
		$lineas[] = '*Solicitud de cuenta gremio* - CJ Aluminios';
		$lineas[] = 'Nombre: ' . $nombre;
		$lineas[] = 'Taller / Empresa: ' . $empresa;
		$lineas[] = 'CUIT: ' . $cuit;
		$lineas[] = 'WhatsApp: ' . $whatsapp;
		$lineas[] = 'Localidad: ' . $localidad;
		$lineas[] = 'Volumen estimado: ' . $volumenes[ $volumen ];
		$lineas[] = 'Email: ' . $email;
		$lineas[] = 'Usuario web: ' . $login;

		wp_send_json_success(
			array(
				'url' => CJB2B_Frontend::whatsapp_url( implode( "\n", $lineas ) ),
			)
		);
	}

	/* ------------------------------------------------- Admin: solicitudes */

	/** Al asignarle una lista de gremio, la solicitud deja de estar pendiente. */
	public function marcar_aprobada( int $user_id, string $rol ): void {
		if ( in_array( $rol, array( 'lista_1', 'lista_2', 'mayorista_1' ), true )
			&& 'pendiente' === get_user_meta( $user_id, self::META_ESTADO, true )
		) {
			update_user_meta( $user_id, self::META_ESTADO, 'aprobada' );
		}
	}

	public function registrar_submenu(): void {
		add_submenu_page(
			CJB2B_Admin_Settings::MENU_SLUG,
			__( 'Solicitudes de gremio', 'cj-aluminios-b2b' ),
			__( 'Solicitudes de gremio', 'cj-aluminios-b2b' ),
			'promote_users',
			self::SLUG_ADMIN,
			array( $this, 'render_solicitudes' )
		);
	}

	public function render_solicitudes(): void {
		if ( ! current_user_can( 'promote_users' ) ) {
			wp_die( esc_html__( 'No tenés permisos suficientes para acceder a esta página.', 'cj-aluminios-b2b' ) );
		}

		$usuarios = get_users(
			array(
				'meta_key'   => self::META_ESTADO, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'pendiente', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => 'registered',
				'order'      => 'DESC',
				'number'     => 200,
			)
		);

		$volumenes = self::volumenes();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Solicitudes de cuenta gremio', 'cj-aluminios-b2b' ); ?></h1>
			<p><?php esc_html_e( 'Usuarios creados desde el formulario de registro que todavía no tienen lista asignada. Entrá a "Asignar lista" y cambiales el perfil a Lista 1, Lista 2 o Mayorista 1: al guardar salen de este listado.', 'cj-aluminios-b2b' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Fecha', 'cj-aluminios-b2b' ); ?></th>
						<th><?php esc_html_e( 'Nombre', 'cj-aluminios-b2b' ); ?></th>
						<th><?php esc_html_e( 'Taller / Empresa', 'cj-aluminios-b2b' ); ?></th>
						<th><?php esc_html_e( 'CUIT', 'cj-aluminios-b2b' ); ?></th>
						<th><?php esc_html_e( 'WhatsApp', 'cj-aluminios-b2b' ); ?></th>
						<th><?php esc_html_e( 'Localidad', 'cj-aluminios-b2b' ); ?></th>
						<th><?php esc_html_e( 'Volumen', 'cj-aluminios-b2b' ); ?></th>
						<th><?php esc_html_e( 'Email', 'cj-aluminios-b2b' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $usuarios ) ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'No hay solicitudes pendientes.', 'cj-aluminios-b2b' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $usuarios as $usuario ) : ?>
						<?php $volumen = (string) get_user_meta( $usuario->ID, self::META_VOLUMEN, true ); ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) get_user_meta( $usuario->ID, self::META_FECHA, true ) ) ); ?></td>
							<td><?php echo esc_html( $usuario->display_name ); ?></td>
							<td><?php echo esc_html( (string) get_user_meta( $usuario->ID, 'billing_company', true ) ); ?></td>
							<td><?php echo esc_html( (string) get_user_meta( $usuario->ID, self::META_CUIT, true ) ); ?></td>
							<td><?php echo esc_html( (string) get_user_meta( $usuario->ID, 'billing_phone', true ) ); ?></td>
							<td><?php echo esc_html( (string) get_user_meta( $usuario->ID, 'billing_city', true ) ); ?></td>
							<td><?php echo esc_html( $volumenes[ $volumen ] ?? $volumen ); ?></td>
							<td><?php echo esc_html( $usuario->user_email ); ?></td>
							<td><a class="button" href="<?php echo esc_url( get_edit_user_link( $usuario->ID ) ); ?>"><?php esc_html_e( 'Asignar lista', 'cj-aluminios-b2b' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
