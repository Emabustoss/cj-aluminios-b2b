<?php
/**
 * Pedido / cotización estructurado directo a WhatsApp.
 *
 * Agrega al carrito un formulario rápido; al confirmar registra el pedido en
 * WooCommerce como "pending" (historial en el admin) y devuelve el enlace
 * wa.me con el mensaje armado. Protegido con nonce, honeypot y rate limiting.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_WhatsApp_Checkout {

	private static ?self $instance = null;

	const NONCE         = 'cjb2b_whatsapp';
	const ACCION        = 'cjb2b_whatsapp_enviar';
	const SCRIPT_HANDLE = 'cj-b2b-whatsapp';

	const LIMITE_ENVIOS  = 5;
	const LIMITE_VENTANA = 10 * MINUTE_IN_SECONDS;

	const META_ENTREGA   = '_cjb2b_entrega';
	const META_DIRECCION = '_cjb2b_direccion_envio';
	const META_LISTA     = '_cjb2b_lista';
	const META_DESCUENTO = '_cjb2b_descuento';

	/** Evita imprimir el formulario dos veces en la misma página. */
	private bool $formulario_impreso = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_formulario' ), 5 );
		add_filter( 'render_block_woocommerce/cart', array( $this, 'anexar_a_carrito_de_bloques' ) );
		add_action( 'wp', array( $this, 'ocultar_checkout_estandar' ) );
		add_action( 'template_redirect', array( $this, 'redirigir_checkout' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'encolar_script' ) );

		add_action( 'wp_ajax_' . self::ACCION, array( $this, 'ajax_enviar' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACCION, array( $this, 'ajax_enviar' ) );

		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'mostrar_en_admin' ) );
	}

	public static function entregas(): array {
		return array(
			'retiro' => __( 'Retiro en local (Rosario)', 'cj-aluminios-b2b' ),
			'envio'  => __( 'Envío', 'cj-aluminios-b2b' ),
		);
	}

	private static function checkout_oculto(): bool {
		return 'yes' === CJB2B_Frontend::ajuste( 'ocultar_checkout' );
	}

	/* ------------------------------------------------- Checkout estándar */

	public function ocultar_checkout_estandar(): void {
		if ( self::checkout_oculto() ) {
			remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
		}
	}

	/**
	 * No hay pago online: si alguien entra directo al checkout, vuelve al
	 * carrito (donde está el pedido por WhatsApp). No afecta a las páginas
	 * de "pedido recibido" ni de pago de un pedido existente.
	 */
	public function redirigir_checkout(): void {
		if ( ! self::checkout_oculto() || ! function_exists( 'is_checkout' ) ) {
			return;
		}

		if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
	}

	/* ------------------------------------------------------------- Assets */

	public function encolar_script(): void {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		$archivo = CJB2B_PATH . 'assets/js/cj-b2b-whatsapp.js';

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			CJB2B_URL . 'assets/js/cj-b2b-whatsapp.js',
			array(),
			CJB2B_Frontend::version_archivo( $archivo ),
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'cjb2bWhatsApp',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'accion'  => self::ACCION,
				'textos'  => array(
					'enviando' => __( 'Registrando pedido…', 'cj-aluminios-b2b' ),
					'error'    => __( 'No se pudo registrar el pedido. Probá de nuevo en unos segundos.', 'cj-aluminios-b2b' ),
				),
			)
		);

		if ( self::checkout_oculto() ) {
			// Carrito de bloques: el botón "Finalizar compra" lo dibuja React.
			wp_add_inline_style( CJB2B_Frontend::STYLE_HANDLE, '.wc-block-cart__submit{display:none !important;}' );
		}
	}

	/* --------------------------------------------------------- Formulario */

	public function anexar_a_carrito_de_bloques( $contenido ) {
		if ( ! is_string( $contenido ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return $contenido;
		}

		ob_start();
		$this->render_formulario();

		return $contenido . ob_get_clean();
	}

	public function render_formulario(): void {
		if ( $this->formulario_impreso ) {
			return;
		}

		$this->formulario_impreso = true;

		$nombre   = '';
		$telefono = '';

		if ( is_user_logged_in() ) {
			$usuario  = wp_get_current_user();
			$empresa  = (string) get_user_meta( $usuario->ID, 'billing_company', true );
			$nombre   = '' !== $empresa ? $empresa : trim( $usuario->first_name . ' ' . $usuario->last_name );
			$nombre   = '' !== $nombre ? $nombre : $usuario->display_name;
			$telefono = (string) get_user_meta( $usuario->ID, 'billing_phone', true );
		}
		?>
		<div class="cj-b2b cj-wa" data-cj-wa>
			<form data-cj-wa-form novalidate>
				<p class="cj-wa__titulo"><?php esc_html_e( 'Enviar pedido / cotización', 'cj-aluminios-b2b' ); ?></p>
				<p class="cj-wa__intro"><?php esc_html_e( 'Completá tus datos y te abrimos WhatsApp con el pedido ya armado. No se cobra nada online.', 'cj-aluminios-b2b' ); ?></p>

				<p class="cj-campo">
					<label for="cj-wa-nombre"><?php esc_html_e( 'Nombre / Empresa', 'cj-aluminios-b2b' ); ?> *</label>
					<input type="text" id="cj-wa-nombre" name="cj_nombre" value="<?php echo esc_attr( $nombre ); ?>" maxlength="100" autocomplete="organization" required />
				</p>

				<p class="cj-campo">
					<label for="cj-wa-telefono"><?php esc_html_e( 'Teléfono', 'cj-aluminios-b2b' ); ?> *</label>
					<input type="tel" id="cj-wa-telefono" name="cj_telefono" value="<?php echo esc_attr( $telefono ); ?>" maxlength="30" autocomplete="tel" inputmode="tel" required />
				</p>

				<fieldset class="cj-campo">
					<legend><?php esc_html_e( 'Entrega', 'cj-aluminios-b2b' ); ?></legend>
					<div class="cj-opciones">
						<?php foreach ( self::entregas() as $valor => $etiqueta ) : ?>
							<label class="cj-opcion">
								<input type="radio" name="cj_entrega" value="<?php echo esc_attr( $valor ); ?>" <?php checked( 'retiro', $valor ); ?> />
								<?php echo esc_html( $etiqueta ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<p class="cj-campo" data-cj-wa-direccion hidden>
					<label for="cj-wa-direccion"><?php esc_html_e( 'Dirección / localidad de envío (opcional)', 'cj-aluminios-b2b' ); ?></label>
					<input type="text" id="cj-wa-direccion" name="cj_direccion" maxlength="150" autocomplete="street-address" />
				</p>

				<p class="cj-campo">
					<label for="cj-wa-observaciones"><?php esc_html_e( 'Observaciones (opcional)', 'cj-aluminios-b2b' ); ?></label>
					<textarea id="cj-wa-observaciones" name="cj_observaciones" maxlength="500" rows="2"></textarea>
				</p>

				<?php // Honeypot: las personas no lo ven ni lo completan; los bots sí. ?>
				<p class="cj-hp" aria-hidden="true">
					<label for="cj-wa-website">Website</label>
					<input type="text" id="cj-wa-website" name="cj_website" value="" tabindex="-1" autocomplete="off" />
				</p>

				<?php wp_nonce_field( self::NONCE, 'cj_wa_nonce', false ); ?>

				<p class="cj-aviso cj-aviso--error" data-cj-wa-error role="alert" hidden></p>

				<button type="submit" class="cj-btn cj-btn--whatsapp" data-cj-wa-enviar>
					<?php esc_html_e( 'Enviar Pedido / Cotización por WhatsApp', 'cj-aluminios-b2b' ); ?>
				</button>
				<p class="cj-wa__legal"><?php esc_html_e( 'Precios estimados, sujetos a confirmación por parte de CJ Aluminios.', 'cj-aluminios-b2b' ); ?></p>
			</form>

			<div class="cj-wa__exito" data-cj-wa-exito hidden>
				<p class="cj-wa__titulo" data-cj-wa-exito-titulo tabindex="-1"></p>
				<p><?php esc_html_e( 'Tu pedido quedó registrado. Falta un paso: enviá el mensaje por WhatsApp para que lo confirmemos.', 'cj-aluminios-b2b' ); ?></p>
				<a class="cj-btn cj-btn--whatsapp" data-cj-wa-link href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir WhatsApp y enviar', 'cj-aluminios-b2b' ); ?></a>
			</div>
		</div>
		<?php
	}

	/* --------------------------------------------------------- Anti-spam */

	/**
	 * Claves de rate limiting: una por IP y otra por sesión de WooCommerce.
	 */
	private function claves_limite(): array {
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$claves = array();

		if ( '' !== $ip ) {
			$claves[] = 'cjb2b_wa_ip_' . md5( $ip );
		}

		if ( WC()->session ) {
			$claves[] = 'cjb2b_wa_se_' . md5( (string) WC()->session->get_customer_id() );
		}

		return $claves;
	}

	private function limite_superado(): bool {
		foreach ( $this->claves_limite() as $clave ) {
			$registro = get_transient( $clave );

			if ( is_array( $registro ) && (int) $registro['cantidad'] >= self::LIMITE_ENVIOS ) {
				return true;
			}
		}

		return false;
	}

	private function registrar_envio(): void {
		$ahora = time();

		foreach ( $this->claves_limite() as $clave ) {
			$registro = get_transient( $clave );

			if ( ! is_array( $registro ) || $ahora >= (int) $registro['inicio'] + self::LIMITE_VENTANA ) {
				$registro = array(
					'cantidad' => 0,
					'inicio'   => $ahora,
				);
			}

			++$registro['cantidad'];

			// La ventana no se renueva con cada envío: vence a los 10 minutos del primero.
			set_transient( $clave, $registro, max( 1, (int) $registro['inicio'] + self::LIMITE_VENTANA - $ahora ) );
		}
	}

	/* --------------------------------------------------------------- AJAX */

	public function ajax_enviar(): void {
		check_ajax_referer( self::NONCE, 'cj_wa_nonce' );

		// Honeypot: si viene completo es un bot. Respuesta genérica, sin pistas.
		if ( ! empty( $_POST['cj_website'] ) ) {
			wp_send_json_error( array( 'mensaje' => __( 'No se pudo registrar el pedido.', 'cj-aluminios-b2b' ) ), 400 );
		}

		if ( $this->limite_superado() ) {
			wp_send_json_error(
				array( 'mensaje' => __( 'Enviaste demasiados pedidos seguidos. Esperá unos minutos y probá de nuevo.', 'cj-aluminios-b2b' ) ),
				429
			);
		}

		$nombre        = isset( $_POST['cj_nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['cj_nombre'] ) ) : '';
		$telefono      = isset( $_POST['cj_telefono'] ) ? sanitize_text_field( wp_unslash( $_POST['cj_telefono'] ) ) : '';
		$entrega       = isset( $_POST['cj_entrega'] ) ? sanitize_key( wp_unslash( $_POST['cj_entrega'] ) ) : 'retiro';
		$direccion     = isset( $_POST['cj_direccion'] ) ? sanitize_text_field( wp_unslash( $_POST['cj_direccion'] ) ) : '';
		$observaciones = isset( $_POST['cj_observaciones'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cj_observaciones'] ) ) : '';

		$nombre        = self::recortar( $nombre, 100 );
		$telefono      = self::recortar( preg_replace( '/[^0-9+()\-\s]/', '', $telefono ), 30 );
		$direccion     = self::recortar( $direccion, 150 );
		$observaciones = self::recortar( $observaciones, 500 );

		if ( strlen( $nombre ) < 2 ) {
			wp_send_json_error( array( 'mensaje' => __( 'Completá tu nombre o el de tu empresa.', 'cj-aluminios-b2b' ) ), 400 );
		}

		if ( strlen( preg_replace( '/\D+/', '', $telefono ) ) < 6 ) {
			wp_send_json_error( array( 'mensaje' => __( 'Completá un teléfono válido (con código de área).', 'cj-aluminios-b2b' ) ), 400 );
		}

		if ( ! isset( self::entregas()[ $entrega ] ) ) {
			$entrega = 'retiro';
		}

		if ( 'envio' !== $entrega ) {
			$direccion = '';
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'mensaje' => __( 'Tu pedido está vacío. Agregá productos desde el catálogo.', 'cj-aluminios-b2b' ) ), 400 );
		}

		WC()->cart->calculate_totals();

		$rol = CJB2B_Frontend::rol_actual();

		try {
			$pedido = $this->crear_pedido( $nombre, $telefono, $entrega, $direccion, $observaciones, $rol );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'mensaje' => __( 'No se pudo registrar el pedido. Probá de nuevo en unos segundos.', 'cj-aluminios-b2b' ) ), 500 );
		}

		$this->registrar_envio();

		$mensaje = $this->armar_mensaje( $pedido, $nombre, $telefono, $entrega, $direccion, $observaciones, $rol );
		// esc_url_raw() elimina los %0A (saltos de línea) de una URL, así que
		// se aplica solo a la base; el texto ya queda seguro con rawurlencode().
		$url = esc_url_raw( 'https://wa.me/' . CJB2B_Frontend::whatsapp_numero() ) . '?text=' . rawurlencode( $mensaje );

		WC()->cart->empty_cart();

		wp_send_json_success(
			array(
				'url'    => $url,
				'titulo' => sprintf(
					/* translators: %s: número de pedido */
					__( 'Pedido N° %s registrado', 'cj-aluminios-b2b' ),
					$pedido->get_order_number()
				),
			)
		);
	}

	private static function recortar( string $texto, int $maximo ): string {
		$texto = trim( $texto );
		return function_exists( 'mb_substr' ) ? mb_substr( $texto, 0, $maximo ) : substr( $texto, 0, $maximo );
	}

	/**
	 * Registra el carrito actual como pedido "pending" con los precios ya
	 * calculados por el motor (incluido el descuento del rol).
	 *
	 * @throws Exception Si WooCommerce no puede crear el pedido.
	 */
	private function crear_pedido( string $nombre, string $telefono, string $entrega, string $direccion, string $observaciones, array $rol ): WC_Order {
		$pedido = wc_create_order(
			array(
				'customer_id' => get_current_user_id(),
				'created_via' => 'cjb2b_whatsapp',
				'status'      => 'pending',
			)
		);

		if ( is_wp_error( $pedido ) ) {
			throw new Exception( $pedido->get_error_message() );
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}

			$pedido->add_product(
				$item['data'],
				(int) $item['quantity'],
				array(
					'subtotal' => (float) $item['line_subtotal'],
					'total'    => (float) $item['line_total'],
				)
			);
		}

		$pedido->set_billing_first_name( $nombre );
		$pedido->set_billing_phone( $telefono );

		if ( '' !== $direccion ) {
			$pedido->set_shipping_first_name( $nombre );
			$pedido->set_shipping_address_1( $direccion );
		}

		if ( '' !== $observaciones ) {
			$pedido->set_customer_note( $observaciones );
		}

		$pedido->update_meta_data( self::META_ENTREGA, $entrega );
		$pedido->update_meta_data( self::META_DIRECCION, $direccion );
		$pedido->update_meta_data( self::META_LISTA, $rol['nombre'] );
		$pedido->update_meta_data( self::META_DESCUENTO, $rol['descuento'] );
		$pedido->calculate_totals();
		$pedido->save();

		$pedido->add_order_note(
			sprintf(
				/* translators: 1: forma de entrega, 2: lista B2B */
				__( 'Cotización generada desde el carrito para enviar por WhatsApp. Entrega: %1$s. Lista: %2$s.', 'cj-aluminios-b2b' ),
				self::entregas()[ $entrega ],
				CJB2B_Frontend::rol_texto()
			)
		);

		return $pedido;
	}

	/**
	 * Mensaje de WhatsApp en texto plano (los * son negrita de WhatsApp).
	 */
	private function armar_mensaje( WC_Order $pedido, string $nombre, string $telefono, string $entrega, string $direccion, string $observaciones, array $rol ): string {
		$ver_precios = CJB2B_Frontend::precios_visibles();
		$lineas      = array();

		$lineas[] = sprintf( '*Pedido / Cotización N° %s* - CJ Aluminios', $pedido->get_order_number() );
		$lineas[] = 'Cliente / Empresa: ' . $nombre;
		$lineas[] = 'Teléfono: ' . $telefono;
		$lineas[] = 'Lista B2B: ' . CJB2B_Frontend::rol_texto();
		$lineas[] = 'Entrega: ' . self::entregas()[ $entrega ] . ( '' !== $direccion ? ' - ' . $direccion : '' );
		$lineas[] = '';
		$lineas[] = '*Detalle:*';

		$n = 0;

		foreach ( $pedido->get_items() as $item ) {
			$producto = $item->get_product();

			if ( ! $producto instanceof WC_Product ) {
				continue;
			}

			++$n;

			$id_padre  = $producto->is_type( 'variation' ) ? $producto->get_parent_id() : $producto->get_id();
			$es_perfil = $producto->is_type( 'variation' ) && CJB2B_Product_Fields::es_perfil( $id_padre );
			$sku       = (string) $producto->get_sku();
			$sku       = '' !== $sku ? $sku : CJB2B_Product_Fields::codigo_matriz( $id_padre );
			$titulo    = get_the_title( $id_padre );
			$cantidad  = (int) $item->get_quantity();
			$acabado   = '';

			if ( $producto->is_type( 'variation' ) ) {
				$slug    = (string) ( $producto->get_variation_attributes()['attribute_pa_acabado'] ?? '' );
				$acabado = '' !== $slug ? CJB2B_Frontend::nombre_acabado( $slug ) : '';
			}

			$unidad = $es_perfil
				? _n( 'barra', 'barras', $cantidad, 'cj-aluminios-b2b' )
				: _n( 'unidad', 'unidades', $cantidad, 'cj-aluminios-b2b' );

			$linea = $n . '. '
				. ( '' !== $sku ? '[' . $sku . '] ' : '' )
				. wp_strip_all_tags( $titulo )
				. ( '' !== $acabado ? ' - ' . $acabado : '' )
				. ' x ' . $cantidad . ' ' . $unidad;

			if ( $ver_precios ) {
				$linea .= ' = ' . CJB2B_Frontend::precio_texto( (float) $item->get_total() );
			}

			$lineas[] = $linea;
		}

		$lineas[] = '';
		$lineas[] = $ver_precios
			? '*Total estimado: ' . CJB2B_Frontend::precio_texto( (float) $pedido->get_total() ) . '*'
			: '*Total: a cotizar*';

		if ( '' !== $observaciones ) {
			$lineas[] = '';
			$lineas[] = 'Observaciones: ' . $observaciones;
		}

		return implode( "\n", $lineas );
	}

	/* -------------------------------------------------------------- Admin */

	public function mostrar_en_admin( $pedido ): void {
		if ( ! $pedido instanceof WC_Order || 'cjb2b_whatsapp' !== $pedido->get_created_via() ) {
			return;
		}

		$entrega   = (string) $pedido->get_meta( self::META_ENTREGA );
		$direccion = (string) $pedido->get_meta( self::META_DIRECCION );
		$lista     = (string) $pedido->get_meta( self::META_LISTA );
		$descuento = (float) $pedido->get_meta( self::META_DESCUENTO );

		echo '<p><strong>' . esc_html__( 'Cotización por WhatsApp', 'cj-aluminios-b2b' ) . '</strong><br>';
		echo esc_html__( 'Entrega:', 'cj-aluminios-b2b' ) . ' ' . esc_html( self::entregas()[ $entrega ] ?? $entrega );

		if ( '' !== $direccion ) {
			echo ' — ' . esc_html( $direccion );
		}

		echo '<br>' . esc_html__( 'Lista B2B:', 'cj-aluminios-b2b' ) . ' ' . esc_html( $lista ) . ' (' . esc_html( CJB2B_Frontend::numero( $descuento, 2 ) ) . '%)</p>';
	}
}
