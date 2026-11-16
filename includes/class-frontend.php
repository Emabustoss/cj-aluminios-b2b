<?php
/**
 * Base del frontend de la Fase 2: hoja de estilos global, ajustes de
 * administración (WhatsApp, visibilidad de precios para visitantes) y
 * helpers compartidos por el catálogo, el desglose y el pedido por WhatsApp.
 *
 * No modifica el motor de precios: solo lee lo que la Fase 1 ya calcula.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Frontend {

	private static ?self $instance = null;

	const OPTION        = 'cjb2b_fase2';
	const STYLE_HANDLE  = 'cj-b2b-theme';
	const META_PRESENTA = '_presentacion';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'encolar_estilos' ) );
		add_action( 'admin_menu', array( $this, 'registrar_submenu' ), 20 );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'mostrar_campo_presentacion' ), 20 );
		add_action( 'woocommerce_process_product_meta', array( $this, 'guardar_campo_presentacion' ), 20 );

		// Ocultar precios a visitantes sin sesión (configurable).
		add_filter( 'woocommerce_get_price_html', array( $this, 'ocultar_precio_html' ), 100 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'ocultar_precio_carrito' ), 100 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'ocultar_precio_carrito' ), 100 );
		add_filter( 'woocommerce_cart_subtotal', array( $this, 'ocultar_precio_carrito' ), 100 );
		add_filter( 'woocommerce_cart_totals_order_total_html', array( $this, 'ocultar_precio_carrito' ), 100 );
	}

	/* ------------------------------------------------------------ Ajustes */

	public static function ajustes_default(): array {
		return array(
			'whatsapp_numero'            => '5493415985683',
			'ocultar_precios_visitantes' => 'yes',
			'mensaje_visitante'          => __( 'Iniciá sesión para ver precio gremio', 'cj-aluminios-b2b' ),
			'ocultar_checkout'           => 'yes',
			'por_pagina'                 => 24,
			'catalogo_page_id'           => 0,
			// Fase 3: datos de contacto de la portada e IDs de las páginas institucionales.
			'direccion'                  => 'Dean Funes 779, Rosario, Santa Fe',
			'horario'                    => __( 'Lunes a viernes', 'cj-aluminios-b2b' ),
			'email_contacto'             => 'cjmet14@yahoo.com',
			'paginas'                    => array(),
		);
	}

	public static function ajustes(): array {
		$guardado = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $guardado ) ? $guardado : array(), self::ajustes_default() );
	}

	public static function ajuste( string $clave ) {
		$ajustes = self::ajustes();
		return $ajustes[ $clave ] ?? null;
	}

	public static function actualizar_ajuste( string $clave, $valor ): void {
		$ajustes           = self::ajustes();
		$ajustes[ $clave ] = $valor;
		update_option( self::OPTION, $ajustes );
	}

	/** Número de WhatsApp solo con dígitos, formato internacional (wa.me). */
	public static function whatsapp_numero(): string {
		$numero = preg_replace( '/\D+/', '', (string) self::ajuste( 'whatsapp_numero' ) );
		return '' !== $numero ? $numero : '5493415985683';
	}

	/**
	 * Enlace wa.me con el mensaje ya armado. esc_url_raw() borra los %0A, así
	 * que se aplica solo a la base; el texto va con rawurlencode().
	 */
	public static function whatsapp_url( string $mensaje = '' ): string {
		$base = esc_url_raw( 'https://wa.me/' . self::whatsapp_numero() );
		return '' !== $mensaje ? $base . '?text=' . rawurlencode( $mensaje ) : $base;
	}

	/** Número de WhatsApp legible: +54 9 341 598-5683 → "+54 341 5985683". */
	public static function whatsapp_legible(): string {
		$numero = self::whatsapp_numero();

		if ( preg_match( '/^549(\d{3})(\d{7})$/', $numero, $m ) ) {
			return '+54 ' . $m[1] . ' ' . $m[2];
		}

		return '+' . $numero;
	}

	/* ------------------------------------------- Anti-spam (rate limiting) */

	/** Recorta un texto ya saneado a un máximo de caracteres. */
	public static function recortar( string $texto, int $maximo ): string {
		$texto = trim( $texto );
		return function_exists( 'mb_substr' ) ? mb_substr( $texto, 0, $maximo ) : substr( $texto, 0, $maximo );
	}

	private static function clave_limite( string $prefijo ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'cjb2b_rl_' . sanitize_key( $prefijo ) . '_' . md5( $ip );
	}

	/** ¿Esta IP ya llegó al máximo de envíos de este formulario? */
	public static function limite_superado( string $prefijo, int $maximo ): bool {
		$registro = get_transient( self::clave_limite( $prefijo ) );
		return is_array( $registro ) && (int) $registro['cantidad'] >= $maximo;
	}

	/**
	 * Suma un envío a la cuenta de esta IP. La ventana no se renueva con cada
	 * envío: vence $ventana segundos después del primero.
	 */
	public static function registrar_intento( string $prefijo, int $ventana ): void {
		$clave    = self::clave_limite( $prefijo );
		$ahora    = time();
		$registro = get_transient( $clave );

		if ( ! is_array( $registro ) || $ahora >= (int) $registro['inicio'] + $ventana ) {
			$registro = array(
				'cantidad' => 0,
				'inicio'   => $ahora,
			);
		}

		++$registro['cantidad'];

		set_transient( $clave, $registro, max( 1, (int) $registro['inicio'] + $ventana - $ahora ) );
	}

	/* ------------------------------------------------------------ Helpers */

	/**
	 * Rol B2B vigente del usuario actual, con la misma prioridad que usa
	 * CJB2B_B2B_Roles::descuento_usuario() (el de mayor beneficio).
	 *
	 * @return array{slug:string,nombre:string,descuento:float}
	 */
	public static function rol_actual(): array {
		$roles   = CJB2B_B2B_Roles::roles();
		$slug    = 'publico';
		$usuario = wp_get_current_user();

		if ( $usuario && $usuario->exists() ) {
			foreach ( array( 'mayorista_1', 'lista_2', 'lista_1', 'publico' ) as $candidato ) {
				if ( in_array( $candidato, (array) $usuario->roles, true ) ) {
					$slug = $candidato;
					break;
				}
			}
		}

		return array(
			'slug'      => $slug,
			'nombre'    => $roles[ $slug ] ?? $slug,
			'descuento' => (float) CJB2B_B2B_Roles::descuento_usuario(),
		);
	}

	/** Texto "Lista 1 (Gremio B) — 17,17% de descuento". */
	public static function rol_texto(): string {
		$rol = self::rol_actual();

		if ( $rol['descuento'] > 0 ) {
			return sprintf(
				/* translators: 1: nombre de la lista, 2: porcentaje */
				__( '%1$s — %2$s%% de descuento', 'cj-aluminios-b2b' ),
				$rol['nombre'],
				self::numero( $rol['descuento'], 2 )
			);
		}

		return sprintf(
			/* translators: %s: nombre de la lista */
			__( '%s — sin descuento', 'cj-aluminios-b2b' ),
			$rol['nombre']
		);
	}

	/** ¿El visitante actual puede ver precios? */
	public static function precios_visibles(): bool {
		return is_user_logged_in() || 'yes' !== self::ajuste( 'ocultar_precios_visitantes' );
	}

	/** Enlace "Iniciá sesión para ver precio gremio" hacia Mi cuenta. */
	public static function mensaje_visitante_html(): string {
		$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();

		return '<a class="cj-precio-login" href="' . esc_url( $url ) . '">'
			. esc_html( (string) self::ajuste( 'mensaje_visitante' ) )
			. '</a>';
	}

	/** Número sin ceros de relleno, con el separador decimal de la tienda. */
	public static function numero( float $valor, int $decimales = 3 ): string {
		$texto = number_format( $valor, $decimales, '.', '' );
		$texto = rtrim( rtrim( $texto, '0' ), '.' );
		$sep   = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';

		return str_replace( '.', $sep, '' === $texto ? '0' : $texto );
	}

	/** Precio como texto plano (para data-attributes y el mensaje de WhatsApp). */
	public static function precio_texto( float $valor ): string {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $valor ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/** Presentación/unidad de venta de un accesorio (ej. "Unidad", "Caja x 100"). */
	public static function presentacion( int $product_id ): string {
		$valor = (string) get_post_meta( $product_id, self::META_PRESENTA, true );
		return '' !== $valor ? $valor : __( 'Unidad', 'cj-aluminios-b2b' );
	}

	/** Nombre legible de un acabado a partir de su slug. */
	public static function nombre_acabado( string $slug ): string {
		$acabados = CJB2B_Admin_Settings::acabados();
		$clave    = CJB2B_Admin_Settings::normalizar_clave_acabado( $slug );

		if ( isset( $acabados[ $clave ] ) ) {
			return $acabados[ $clave ];
		}

		$termino = get_term_by( 'slug', $slug, 'pa_acabado' );
		return $termino ? $termino->name : $slug;
	}

	/* ------------------------------------------------------------ Estilos */

	public function encolar_estilos(): void {
		$archivo = CJB2B_PATH . 'assets/css/cj-b2b-theme.css';

		wp_enqueue_style(
			self::STYLE_HANDLE,
			CJB2B_URL . 'assets/css/cj-b2b-theme.css',
			array(),
			self::version_archivo( $archivo )
		);
	}

	/** Versión dinámica (fecha de modificación) para invalidar caché al editar. */
	public static function version_archivo( string $ruta ): string {
		return file_exists( $ruta ) ? (string) filemtime( $ruta ) : CJB2B_VERSION;
	}

	/* ------------------------------------- Precios ocultos para visitantes */

	public function ocultar_precio_html( $html ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html;
		}

		return self::precios_visibles() ? $html : self::mensaje_visitante_html();
	}

	public function ocultar_precio_carrito( $html ) {
		return self::precios_visibles() ? $html : '<span class="cj-muted">' . esc_html__( 'A cotizar', 'cj-aluminios-b2b' ) . '</span>';
	}

	/* ---------------------------------------- Campo "Presentación" (admin) */

	public function mostrar_campo_presentacion(): void {
		echo '<div class="options_group">';

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_PRESENTA,
				'label'       => __( 'Presentación / unidad', 'cj-aluminios-b2b' ),
				'desc_tip'    => true,
				'description' => __( 'Solo para accesorios/herrajes (no perfiles). Ej: Unidad, Par, Caja x 100. Si queda vacío se muestra "Unidad".', 'cj-aluminios-b2b' ),
				'type'        => 'text',
			)
		);

		echo '</div>';
	}

	public function guardar_campo_presentacion( int $post_id ): void {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST[ self::META_PRESENTA ] ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_PRESENTA, sanitize_text_field( wp_unslash( $_POST[ self::META_PRESENTA ] ) ) );
	}

	/* --------------------------------------------------- Página de ajustes */

	public function registrar_submenu(): void {
		add_submenu_page(
			CJB2B_Admin_Settings::MENU_SLUG,
			__( 'Catálogo y WhatsApp', 'cj-aluminios-b2b' ),
			__( 'Catálogo y WhatsApp', 'cj-aluminios-b2b' ),
			'manage_options',
			'cjb2b-catalogo-whatsapp',
			array( $this, 'render_pagina' )
		);
	}

	public function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tenés permisos suficientes para acceder a esta página.', 'cj-aluminios-b2b' ) );
		}

		if ( isset( $_POST['cjb2b_fase2_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cjb2b_fase2_nonce'] ) ), 'cjb2b_guardar_fase2' )
		) {
			$ajustes = self::ajustes();
			$numero  = isset( $_POST['cjb2b_whatsapp_numero'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['cjb2b_whatsapp_numero'] ) ) ) : '';
			$mensaje = isset( $_POST['cjb2b_mensaje_visitante'] ) ? sanitize_text_field( wp_unslash( $_POST['cjb2b_mensaje_visitante'] ) ) : '';

			$ajustes['whatsapp_numero']            = '' !== $numero ? $numero : self::ajustes_default()['whatsapp_numero'];
			$ajustes['ocultar_precios_visitantes'] = isset( $_POST['cjb2b_ocultar_precios_visitantes'] ) ? 'yes' : 'no';
			$ajustes['mensaje_visitante']          = '' !== $mensaje ? $mensaje : self::ajustes_default()['mensaje_visitante'];
			$ajustes['ocultar_checkout']           = isset( $_POST['cjb2b_ocultar_checkout'] ) ? 'yes' : 'no';
			$ajustes['por_pagina']                 = isset( $_POST['cjb2b_por_pagina'] ) ? max( 6, min( 100, absint( wp_unslash( $_POST['cjb2b_por_pagina'] ) ) ) ) : 24;
			$ajustes['direccion']                  = isset( $_POST['cjb2b_direccion'] ) ? sanitize_text_field( wp_unslash( $_POST['cjb2b_direccion'] ) ) : '';
			$ajustes['horario']                    = isset( $_POST['cjb2b_horario'] ) ? sanitize_text_field( wp_unslash( $_POST['cjb2b_horario'] ) ) : '';
			$ajustes['email_contacto']             = isset( $_POST['cjb2b_email_contacto'] ) ? sanitize_email( wp_unslash( $_POST['cjb2b_email_contacto'] ) ) : '';

			update_option( self::OPTION, $ajustes );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Ajustes guardados correctamente.', 'cj-aluminios-b2b' ) . '</p></div>';
		}

		$ajustes = self::ajustes();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Catálogo B2B y pedido por WhatsApp', 'cj-aluminios-b2b' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: shortcode */
					esc_html__( 'El catálogo se inserta en cualquier página con el shortcode %s.', 'cj-aluminios-b2b' ),
					'<code>[cj_catalogo_b2b]</code>'
				);
				?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'cjb2b_guardar_fase2', 'cjb2b_fase2_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cjb2b_whatsapp_numero"><?php esc_html_e( 'Número de WhatsApp', 'cj-aluminios-b2b' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="cjb2b_whatsapp_numero" name="cjb2b_whatsapp_numero" value="<?php echo esc_attr( $ajustes['whatsapp_numero'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Formato internacional, solo dígitos. Ej: 5493415985683.', 'cj-aluminios-b2b' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Precios para visitantes', 'cj-aluminios-b2b' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="cjb2b_ocultar_precios_visitantes" value="yes" <?php checked( 'yes', $ajustes['ocultar_precios_visitantes'] ); ?> />
								<?php esc_html_e( 'Ocultar precios a quien no inició sesión (se muestra el mensaje de abajo).', 'cj-aluminios-b2b' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Si se desmarca, los visitantes ven el precio público (Consumidor Final).', 'cj-aluminios-b2b' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cjb2b_mensaje_visitante"><?php esc_html_e( 'Mensaje para visitantes', 'cj-aluminios-b2b' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="cjb2b_mensaje_visitante" name="cjb2b_mensaje_visitante" value="<?php echo esc_attr( $ajustes['mensaje_visitante'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Checkout estándar', 'cj-aluminios-b2b' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="cjb2b_ocultar_checkout" value="yes" <?php checked( 'yes', $ajustes['ocultar_checkout'] ); ?> />
								<?php esc_html_e( 'Ocultar el checkout con pago de WooCommerce y dejar solo el pedido por WhatsApp.', 'cj-aluminios-b2b' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cjb2b_por_pagina"><?php esc_html_e( 'Productos por tanda en el catálogo', 'cj-aluminios-b2b' ); ?></label></th>
						<td>
							<input type="number" min="6" max="100" step="1" id="cjb2b_por_pagina" name="cjb2b_por_pagina" value="<?php echo esc_attr( $ajustes['por_pagina'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cjb2b_direccion"><?php esc_html_e( 'Dirección (portada)', 'cj-aluminios-b2b' ); ?></label></th>
						<td><input type="text" class="regular-text" id="cjb2b_direccion" name="cjb2b_direccion" value="<?php echo esc_attr( $ajustes['direccion'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cjb2b_horario"><?php esc_html_e( 'Horario de atención (portada)', 'cj-aluminios-b2b' ); ?></label></th>
						<td><input type="text" class="regular-text" id="cjb2b_horario" name="cjb2b_horario" value="<?php echo esc_attr( $ajustes['horario'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cjb2b_email_contacto"><?php esc_html_e( 'Email de contacto (portada)', 'cj-aluminios-b2b' ); ?></label></th>
						<td><input type="email" class="regular-text" id="cjb2b_email_contacto" name="cjb2b_email_contacto" value="<?php echo esc_attr( $ajustes['email_contacto'] ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Guardar Ajustes', 'cj-aluminios-b2b' ) ); ?>
			</form>
		</div>
		<?php
	}
}
