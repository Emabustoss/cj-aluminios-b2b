<?php
/**
 * Presupuestos web con numeración propia (WEB-00001).
 *
 * - Tabla {prefix}cj_presupuestos: el número sale del AUTO_INCREMENT de la
 *   tabla (atómico, nunca se repite), independiente de los pedidos de
 *   WooCommerce y de la numeración de OLF (0105-...).
 * - Comprobante imprimible A4 que replica el presupuesto de OLF Software. Se
 *   abre en una pestaña y el navegador lo guarda como PDF
 *   ("PRESUPUESTO_WEB-00001"). Acceso: dueño logueado, administrador o enlace
 *   con clave secreta.
 * - "Mis Presupuestos Web" en Mi cuenta y "Presupuestos Web" en el admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Presupuestos_Web {

	private static ?self $instance = null;

	const DB_VERSION     = '1';
	const OPCION_DB      = 'cjb2b_presupuestos_db';
	const OPCION_EMISOR  = 'cjb2b_emisor';
	const OPCION_FLUSH   = 'cjb2b_presupuestos_rewrite';
	const QUERY          = 'cjb2b_presupuesto';
	const ENDPOINT       = 'presupuestos-web';
	const SLUG_ADMIN     = 'cjb2b-presupuestos';
	const NONCE_ESTADO   = 'cjb2b_presupuesto_estado';
	const NONCE_EMISOR   = 'cjb2b_emisor';
	const IVA            = 21.0;
	const POR_PAGINA     = 30;

	const META_CONDICION = 'cjb2b_condicion_iva';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'iniciar' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'mostrar_comprobante' ), 1 );

		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_cuenta' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_cuenta' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'titulo_cuenta' ) );

		add_action( 'admin_menu', array( $this, 'registrar_submenu' ), 40 );
		add_action( 'admin_post_cjb2b_presupuesto_estado', array( $this, 'guardar_estado' ) );
		add_action( 'admin_post_cjb2b_emisor', array( $this, 'guardar_emisor' ) );
	}

	/* ------------------------------------------------------------- Base */

	public static function tabla(): string {
		global $wpdb;
		return $wpdb->prefix . 'cj_presupuestos';
	}

	public static function estados(): array {
		return array(
			'pendiente'  => __( 'Pendiente', 'cj-aluminios-b2b' ),
			'confirmado' => __( 'Confirmado / Facturado', 'cj-aluminios-b2b' ),
			'cancelado'  => __( 'Cancelado', 'cj-aluminios-b2b' ),
		);
	}

	public static function condiciones_iva(): array {
		return array(
			'consumidor_final' => __( 'Consumidor Final', 'cj-aluminios-b2b' ),
			'responsable_insc' => __( 'IVA Responsable Inscripto', 'cj-aluminios-b2b' ),
			'monotributo'      => __( 'Responsable Monotributo', 'cj-aluminios-b2b' ),
			'exento'           => __( 'IVA Exento', 'cj-aluminios-b2b' ),
		);
	}

	/** Datos fijos del emisor (editables en el admin). */
	public static function emisor(): array {
		$defecto = array(
			'marca'      => 'CJ MET',
			'titular'    => 'de CRISTIAN JOANNOU',
			'direccion'  => 'BOLIVAR 2212',
			'localidad'  => '(2124) VILLA GDRO GALVEZ',
			'provincia'  => 'SANTA FE',
			'mail'       => 'info@cjmet.com.ar',
			'cuit'       => '20-32741577-9',
			'iibb'       => '021-6560266-6',
			'inicio'     => '01/04/2018',
			'condicion'  => 'IVA Responsable Inscripto',
			'leyenda'    => 'Presupuesto generado desde Tienda Web CJ MET - Sujeto a confirmación de stock',
		);

		$guardado = get_option( self::OPCION_EMISOR, array() );

		return array_merge( $defecto, is_array( $guardado ) ? array_filter( $guardado, 'strlen' ) : array() );
	}

	public function iniciar(): void {
		self::instalar();

		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

		// Una sola vez por versión: registra la URL de "Mis Presupuestos Web".
		if ( get_option( self::OPCION_FLUSH ) !== self::DB_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::OPCION_FLUSH, self::DB_VERSION, false );
		}
	}

	/** Crea o actualiza la tabla si cambió la versión. */
	public static function instalar(): void {
		if ( get_option( self::OPCION_DB ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tabla   = self::tabla();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$tabla} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				numero varchar(20) NOT NULL DEFAULT '',
				clave char(32) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				pedido_id bigint(20) unsigned NOT NULL DEFAULT 0,
				origen varchar(20) NOT NULL DEFAULT 'carrito',
				estado varchar(20) NOT NULL DEFAULT 'pendiente',
				cliente_nombre varchar(150) NOT NULL DEFAULT '',
				cliente longtext NOT NULL,
				items longtext NOT NULL,
				subtotal decimal(18,4) NOT NULL DEFAULT 0,
				descuentos decimal(18,4) NOT NULL DEFAULT 0,
				total decimal(18,4) NOT NULL DEFAULT 0,
				con_precios tinyint(1) NOT NULL DEFAULT 1,
				lista varchar(100) NOT NULL DEFAULT '',
				creado datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY numero (numero),
				KEY user_id (user_id),
				KEY estado (estado)
			) {$collate};"
		);

		update_option( self::OPCION_DB, self::DB_VERSION, false );
	}

	public function query_vars( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	/* ------------------------------------------------------------ Datos */

	/**
	 * Registra un presupuesto y le asigna el próximo número WEB-XXXXX.
	 *
	 * @param array $datos cliente (array), items (array), subtotal, descuentos,
	 *                     total, con_precios (bool), origen, pedido_id, lista.
	 * @return array|null Presupuesto guardado (como lo devuelve obtener()).
	 */
	public static function crear( array $datos ): ?array {
		global $wpdb;

		self::instalar();

		$cliente = isset( $datos['cliente'] ) && is_array( $datos['cliente'] ) ? $datos['cliente'] : array();
		$items   = isset( $datos['items'] ) && is_array( $datos['items'] ) ? array_values( $datos['items'] ) : array();

		$ok = $wpdb->insert(
			self::tabla(),
			array(
				'numero'         => '',
				'clave'          => wp_generate_password( 32, false, false ),
				'user_id'        => get_current_user_id(),
				'pedido_id'      => absint( $datos['pedido_id'] ?? 0 ),
				'origen'         => sanitize_key( $datos['origen'] ?? 'carrito' ),
				'estado'         => 'pendiente',
				'cliente_nombre' => CJB2B_Frontend::recortar( (string) ( $cliente['nombre'] ?? '' ), 150 ),
				'cliente'        => wp_json_encode( $cliente ),
				'items'          => wp_json_encode( $items ),
				'subtotal'       => (float) ( $datos['subtotal'] ?? 0 ),
				'descuentos'     => (float) ( $datos['descuentos'] ?? 0 ),
				'total'          => (float) ( $datos['total'] ?? 0 ),
				'con_precios'    => empty( $datos['con_precios'] ) ? 0 : 1,
				'lista'          => CJB2B_Frontend::recortar( (string) ( $datos['lista'] ?? '' ), 100 ),
				'creado'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%s', '%s' )
		);

		if ( ! $ok ) {
			return null;
		}

		$id     = (int) $wpdb->insert_id;
		$numero = sprintf( 'WEB-%05d', $id );

		$wpdb->update( self::tabla(), array( 'numero' => $numero ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		return self::obtener( $numero );
	}

	public static function obtener( string $numero ): ?array {
		global $wpdb;

		$tabla = self::tabla();
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE numero = %s", strtoupper( $numero ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $fila ? self::normalizar( $fila ) : null;
	}

	private static function normalizar( array $fila ): array {
		$fila['cliente'] = json_decode( (string) $fila['cliente'], true ) ?: array();
		$fila['items']   = json_decode( (string) $fila['items'], true ) ?: array();

		foreach ( array( 'id', 'user_id', 'pedido_id', 'con_precios' ) as $campo ) {
			$fila[ $campo ] = (int) $fila[ $campo ];
		}

		foreach ( array( 'subtotal', 'descuentos', 'total' ) as $campo ) {
			$fila[ $campo ] = (float) $fila[ $campo ];
		}

		return $fila;
	}

	/** Enlace al comprobante (con clave: sirve también para visitantes). */
	public static function url_comprobante( array $p, bool $imprimir = false ): string {
		$args = array(
			self::QUERY => $p['numero'],
			'k'         => $p['clave'],
		);

		if ( $imprimir ) {
			$args['imprimir'] = '1';
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/** Datos del cliente guardados en el perfil del usuario. */
	public static function datos_usuario( int $user_id ): array {
		if ( ! $user_id ) {
			return array();
		}

		$u       = get_userdata( $user_id );
		$empresa = (string) get_user_meta( $user_id, 'billing_company', true );
		$nombre  = '' !== $empresa ? $empresa : trim( get_user_meta( $user_id, 'billing_first_name', true ) . ' ' . get_user_meta( $user_id, 'billing_last_name', true ) );

		if ( '' === $nombre && $u ) {
			$nombre = $u->display_name;
		}

		$provincia = (string) get_user_meta( $user_id, 'billing_state', true );
		$pais      = (string) get_user_meta( $user_id, 'billing_country', true );

		if ( '' !== $provincia && function_exists( 'WC' ) && WC()->countries ) {
			$estados   = WC()->countries->get_states( '' !== $pais ? $pais : 'AR' );
			$provincia = is_array( $estados ) && isset( $estados[ $provincia ] ) ? $estados[ $provincia ] : $provincia;
		}

		return array(
			'nombre'        => $nombre,
			'cuit'          => (string) get_user_meta( $user_id, 'cjb2b_cuit', true ),
			'domicilio'     => trim( get_user_meta( $user_id, 'billing_address_1', true ) . ' ' . get_user_meta( $user_id, 'billing_address_2', true ) ),
			'localidad'     => (string) get_user_meta( $user_id, 'billing_city', true ),
			'cp'            => (string) get_user_meta( $user_id, 'billing_postcode', true ),
			'provincia'     => $provincia,
			'telefono'      => (string) get_user_meta( $user_id, 'billing_phone', true ),
			'email'         => $u ? (string) $u->user_email : '',
			'condicion_iva' => (string) get_user_meta( $user_id, self::META_CONDICION, true ),
		);
	}

	/**
	 * Ítems y totales del carrito actual, con la misma lógica de OLF:
	 * perfiles por kilo (bultos = barras, cantidad = kg, precio por kg) y
	 * accesorios por unidad. Precio unitario = precio público; %Dto = lista.
	 *
	 * @return array{items:array,subtotal:float,descuentos:float,total:float}
	 */
	public static function desde_carrito(): array {
		$items    = array();
		$subtotal = 0.0;
		$total    = 0.0;
		$rol      = CJB2B_Frontend::rol_actual();

		foreach ( WC()->cart->get_cart() as $linea ) {
			$producto = $linea['data'] ?? null;

			if ( ! $producto instanceof WC_Product ) {
				continue;
			}

			$id_padre  = $producto->is_type( 'variation' ) ? $producto->get_parent_id() : $producto->get_id();
			$es_perfil = $producto->is_type( 'variation' ) && CJB2B_Product_Fields::es_perfil( $id_padre );
			$cantidad  = (int) $linea['quantity'];
			$linea_tot = (float) $linea['line_total'];
			$dto_rol   = $es_perfil ? (float) $rol['descuento'] : 0.0;
			// Precio público = el que cobra el carrito sin el descuento de la
			// lista (no _regular_price: puede quedar viejo si cambió la tabla).
			$publico   = $cantidad > 0 && $dto_rol < 100 ? $linea_tot / $cantidad / ( 1 - $dto_rol / 100 ) : 0.0;
			$sku       = (string) $producto->get_sku();
			$sku       = '' !== $sku ? $sku : (string) wc_get_product( $id_padre )->get_sku();
			$nombre    = wp_strip_all_tags( get_the_title( $id_padre ) );
			$acabado   = '';

			if ( $producto->is_type( 'variation' ) ) {
				$slug    = (string) ( $producto->get_variation_attributes()['attribute_pa_acabado'] ?? '' );
				$acabado = '' !== $slug ? CJB2B_Frontend::nombre_acabado( $slug ) : '';
			}

			$peso  = $es_perfil ? CJB2B_Product_Fields::peso_metro( $id_padre ) : 0.0;
			$kilos = $peso > 0 ? $peso * 6 * $cantidad : 0.0;

			if ( $es_perfil && $kilos > 0 ) {
				$matriz = CJB2B_Product_Fields::codigo_matriz( $id_padre );
				$item   = array(
					'sku'         => $sku,
					'bultos'      => $cantidad,
					'cantidad'    => round( $kilos, 4 ),
					'unidad'      => 'kg',
					'descripcion' => implode( '|', array_filter( array( $matriz, $nombre, function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $acabado ) : strtoupper( $acabado ) ) ) ),
					'p_unitario'  => round( $publico / ( $peso * 6 ), 4 ),
					'dto'         => $dto_rol,
					'total'       => round( $linea_tot, 2 ),
					'resumen'     => $nombre . ( '' !== $acabado ? ' / ' . $acabado : '' ),
					'detalle_qty' => sprintf( _n( '%d barra', '%d barras', $cantidad, 'cj-aluminios-b2b' ), $cantidad ),
				);
				$subtotal += $publico * $cantidad;
			} else {
				// Accesorios: el motor no los descuenta, el precio fijo es el público.
				$publico      = max( $publico, (float) $producto->get_regular_price() );
				$presentacion = CJB2B_Frontend::presentacion( $id_padre );
				$item         = array(
					'sku'         => $sku,
					'bultos'      => $cantidad,
					'cantidad'    => $cantidad,
					'unidad'      => 'u',
					'descripcion' => $nombre . ( '' !== $acabado ? '|' . $acabado : '' ),
					'p_unitario'  => round( $publico, 4 ),
					'dto'         => $publico > 0 && $cantidad > 0 ? round( max( 0, 100 - $linea_tot / ( $publico * $cantidad ) * 100 ), 2 ) : 0.0,
					'total'       => round( $linea_tot, 2 ),
					'resumen'     => $nombre . ( '' !== $acabado ? ' / ' . $acabado : '' ),
					'detalle_qty' => sprintf( '%d %s', $cantidad, strtolower( $presentacion ) === 'unidad' ? _n( 'unidad', 'unidades', $cantidad, 'cj-aluminios-b2b' ) : '(' . $presentacion . ')' ),
				);
				$subtotal += $publico * $cantidad;
			}

			$total  += $linea_tot;
			$items[] = $item;
		}

		return array(
			'items'      => $items,
			'subtotal'   => round( $subtotal, 2 ),
			'descuentos' => round( max( 0, $subtotal - $total ), 2 ),
			'total'      => round( $total, 2 ),
		);
	}

	/** Mensaje de WhatsApp listo para leer desde el celular del depósito. */
	public static function mensaje_whatsapp( array $p, string $extra = '' ): string {
		$e      = self::emisor();
		$lineas = array();

		$lineas[] = sprintf( 'Hola %1$s, generé el presupuesto *%2$s* desde la web:', $e['marca'], $p['numero'] );
		$lineas[] = 'Cliente: ' . ( $p['cliente']['nombre'] ?? '' );

		if ( ! empty( $p['cliente']['telefono'] ) ) {
			$lineas[] = 'Teléfono: ' . $p['cliente']['telefono'];
		}

		$lineas[] = '';
		$lineas[] = '*Detalle del pedido:*';

		foreach ( $p['items'] as $item ) {
			$lineas[] = '• ' . ( '' !== (string) $item['sku'] ? $item['sku'] . ' x ' : '' )
				. ( $item['detalle_qty'] ?? $item['cantidad'] ) . ' — ' . ( $item['resumen'] ?? $item['descripcion'] );
		}

		if ( '' !== $extra ) {
			$lineas[] = '';
			$lineas[] = $extra;
		}

		$lineas[] = '';
		$lineas[] = '*Total estimado:* ' . ( $p['con_precios'] && $p['total'] > 0 ? CJB2B_Frontend::precio_texto( (float) $p['total'] ) : 'a cotizar' );
		$lineas[] = '(Adjunto el PDF del presupuesto para su control y confirmación de stock)';

		return implode( "\n", $lineas );
	}

	/* ------------------------------------------------------ Comprobante */

	private static function puede_ver( array $p ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- acceso por clave secreta, solo lectura.
		$clave = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';

		if ( '' !== $clave && hash_equals( (string) $p['clave'], $clave ) ) {
			return true;
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return is_user_logged_in() && $p['user_id'] > 0 && get_current_user_id() === $p['user_id'];
	}

	public function mostrar_comprobante(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lectura; el acceso se valida en puede_ver().
		if ( ! isset( $_GET[ self::QUERY ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$numero = strtoupper( sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ) );
		$p      = preg_match( '/^WEB-\d{5,}$/', $numero ) ? self::obtener( $numero ) : null;

		if ( ! $p || ! self::puede_ver( $p ) ) {
			wp_die( esc_html__( 'Presupuesto no encontrado o sin permiso para verlo.', 'cj-aluminios-b2b' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Content-Type: text/html; charset=utf-8' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$imprimir = ! empty( $_GET['imprimir'] );

		echo self::html_comprobante( $p, $imprimir ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en html_comprobante().
		exit;
	}

	private static function num( float $valor, int $decimales = 2 ): string {
		return number_format( $valor, $decimales, ',', '.' );
	}

	/** Documento A4 completo (réplica del presupuesto de OLF Software). */
	public static function html_comprobante( array $p, bool $imprimir = false ): string {
		$e        = self::emisor();
		$c        = $p['cliente'];
		$precios  = (bool) $p['con_precios'];
		$fecha    = mysql2date( 'd/m/Y', $p['creado'] );
		$cond     = self::condiciones_iva()[ $c['condicion_iva'] ?? '' ] ?? ( $c['condicion_iva'] ?? '' );
		$cond     = '' !== $cond ? $cond : __( 'Consumidor Final', 'cj-aluminios-b2b' );
		$iva_cont = $p['total'] * self::IVA / ( 100 + self::IVA );
		$titulo   = 'PRESUPUESTO_' . $p['numero'];
		$estado   = self::estados()[ $p['estado'] ] ?? $p['estado'];

		ob_start();
		?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( $titulo ); ?></title>
<style>
	@page { size: A4; margin: 10mm; }
	* { box-sizing: border-box; }
	html { background: #e5e5e5; }
	body { margin: 0; font: 10.5px/1.45 Arial, Helvetica, sans-serif; color: #111; }
	.hoja { width: 210mm; min-height: 297mm; margin: 12px auto; padding: 12mm 12mm 10mm; background: #fff; display: flex; flex-direction: column; }
	.cab { display: grid; grid-template-columns: 1fr 70px 1.05fr; gap: 14px; align-items: start; padding-bottom: 10px; border-bottom: 1.5px solid #bbb; }
	.marca { font: 900 46px/0.95 "Times New Roman", Georgia, serif; letter-spacing: -1px; transform: scaleX(1.08); transform-origin: left; margin-bottom: 6px; }
	.cab p { margin: 0 0 3px; }
	.letra { text-align: center; font-size: 10px; line-height: 1.2; }
	.letra b { display: block; width: 54px; height: 54px; margin: 0 auto 4px; background: #d6d6d6; font: 700 28px/54px Arial, sans-serif; }
	.derecha p:first-child { font-weight: 700; margin-bottom: 4px; }
	.cliente { padding: 10px 0; border-bottom: 1.5px solid #bbb; }
	.cliente p { margin: 0 0 3px; }
	table { width: 100%; border-collapse: collapse; margin-top: 12px; }
	th { background: #d6d6d6; text-align: left; padding: 3px 4px; font-size: 10.5px; white-space: nowrap; }
	td { padding: 2px 4px; vertical-align: top; }
	.n { text-align: right; white-space: nowrap; }
	.c { text-align: center; }
	.relleno { flex: 1; }
	.pie { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; border-top: 1.5px solid #bbb; padding-top: 8px; margin-top: 16px; }
	.tot { display: grid; grid-template-columns: 1fr auto; gap: 2px 10px; }
	.tot b.total, .tot .total { font-size: 14px; font-weight: 700; margin-top: 10px; }
	.leyenda { margin-top: 14px; padding-top: 6px; border-top: 1px solid #999; text-align: center; font-size: 10px; }
	.barra { max-width: 210mm; margin: 12px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
	.barra button { font: 600 13px Arial, sans-serif; padding: 8px 14px; border: 1px solid #333; background: #222; color: #fff; border-radius: 4px; cursor: pointer; }
	.estado { font-size: 10px; color: #555; }
	@media screen and (max-width: 820px) { .hoja { width: auto; min-height: 0; margin: 0; padding: 14px; } .cab { grid-template-columns: 1fr; } .tabla { overflow-x: auto; } }
	@media print { html, body { background: #fff; } .hoja { margin: 0; padding: 0; width: auto; min-height: 277mm; } .barra { display: none; } }
</style>
</head>
<body>
	<div class="barra">
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Imprimir / Guardar como PDF', 'cj-aluminios-b2b' ); ?></button>
	</div>
	<main class="hoja">
		<header class="cab">
			<div>
				<div class="marca"><?php echo esc_html( $e['marca'] ); ?></div>
				<p><?php echo esc_html( $e['titular'] ); ?></p>
				<p><b>Direccion:</b> <?php echo esc_html( $e['direccion'] ); ?></p>
				<p><b>Localidad:</b> <?php echo esc_html( $e['localidad'] ); ?></p>
				<p><b>Provincia:</b> <?php echo esc_html( $e['provincia'] ); ?></p>
				<p><b>Mail:</b> <?php echo esc_html( $e['mail'] ); ?></p>
			</div>
			<div class="letra"><b>X</b>(Comprobante no valido como factura)</div>
			<div class="derecha">
				<p>PRESUPUESTO</p>
				<p><b>Nro:</b> <?php echo esc_html( $p['numero'] ); ?></p>
				<p><b>Fecha de emision:</b> <?php echo esc_html( $fecha ); ?></p>
				<p><b>CUIT:</b> <?php echo esc_html( $e['cuit'] ); ?></p>
				<p><b>Ingresos Brutos:</b> <?php echo esc_html( $e['iibb'] ); ?></p>
				<p><b>Inicio de actividades:</b> <?php echo esc_html( $e['inicio'] ); ?></p>
				<p><b>Condicion frente al IVA:</b> <?php echo esc_html( $e['condicion'] ); ?></p>
				<p class="estado"><?php echo esc_html( sprintf( 'Estado: %s', $estado ) ); ?></p>
			</div>
		</header>

		<section class="cliente">
			<p><b>Apellido y nombre / Razon Social:</b> <?php echo esc_html( $c['nombre'] ?? '' ); ?></p>
			<p>
				<b>Domicilio:</b> <?php echo esc_html( '' !== ( $c['domicilio'] ?? '' ) ? $c['domicilio'] : 'S/D' ); ?>
				<b>Localidad:</b> <?php echo esc_html( trim( ( ! empty( $c['cp'] ) ? '(' . $c['cp'] . ')' : '' ) . ( $c['localidad'] ?? '' ) ) ); ?>
				<b>Prov.:</b> <?php echo esc_html( $c['provincia'] ?? '' ); ?>
				<b>Tel.:</b> <?php echo esc_html( $c['telefono'] ?? '' ); ?>
			</p>
			<p>
				<b>Condicion frente al IVA:</b> <?php echo esc_html( $cond ); ?>
				<b><?php echo esc_html( strlen( preg_replace( '/\D/', '', (string) ( $c['cuit'] ?? '' ) ) ) === 11 ? 'CUIT' : 'CUIT/DNI' ); ?>:</b> <?php echo esc_html( $c['cuit'] ?? '' ); ?>
			</p>
			<p><b>Condicion de pago:</b> A convenir <b>Origen:</b> <?php echo esc_html( 'simulador' === $p['origen'] ? 'Simulador de aberturas' : 'Tienda web' ); ?><?php echo '' !== $p['lista'] ? ' <b>Lista:</b> ' . esc_html( $p['lista'] ) : ''; ?></p>
		</section>

		<div class="tabla">
		<table>
			<thead>
				<tr>
					<th>Item</th><th>ARTICULO</th><th class="c">BULTOS</th><th class="n">CANTIDAD</th><th>Productos/Servicios</th>
					<th class="n">P. UNITARIO</th><th class="n">IVA</th><th class="n">%Dto</th><th class="n">TOTAL</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $p['items'] as $i => $item ) : ?>
					<tr>
						<td><?php echo esc_html( sprintf( '%03d', $i + 1 ) ); ?></td>
						<td><?php echo esc_html( (string) $item['sku'] ); ?></td>
						<td class="c"><?php echo esc_html( (string) $item['bultos'] ); ?></td>
						<td class="n"><?php echo esc_html( self::num( (float) $item['cantidad'], 'kg' === ( $item['unidad'] ?? '' ) ? 4 : 2 ) ); ?></td>
						<td><?php echo esc_html( (string) $item['descripcion'] ); ?></td>
						<td class="n"><?php echo $precios && $item['p_unitario'] > 0 ? esc_html( self::num( (float) $item['p_unitario'], 4 ) ) : '—'; ?></td>
						<td class="n"><?php echo esc_html( self::num( self::IVA ) ); ?></td>
						<td class="n"><?php echo esc_html( self::num( (float) $item['dto'] ) ); ?></td>
						<td class="n"><?php echo $precios && $item['total'] > 0 ? esc_html( self::num( (float) $item['total'], 4 ) ) : esc_html__( 'A cotizar', 'cj-aluminios-b2b' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<div class="relleno"></div>

		<footer class="pie">
			<div>
				<p>VENDEDOR: TIENDA WEB</p>
				<p style="margin-top:60px">|IVA Contenido (<?php echo esc_html( self::num( self::IVA, 0 ) ); ?>%): <?php echo $precios ? esc_html( self::num( $iva_cont ) ) : '—'; ?></p>
			</div>
			<div class="tot">
				<b>Subtotal:</b><span class="n"><?php echo $precios ? esc_html( self::num( $p['subtotal'] ) ) : '—'; ?></span>
				<b>Descuentos:</b><span class="n"><?php echo $precios ? esc_html( self::num( $p['descuentos'] ) ) : '—'; ?></span>
				<b>Recargos:</b><span class="n">0,00</span>
				<b>No gravados:</b><span class="n">0,00</span>
				<b>Neto:</b><span class="n"><?php echo $precios ? esc_html( self::num( $p['total'] ) ) : '—'; ?></span>
				<b class="total">TOTAL:</b><span class="n total"><?php echo $precios && $p['total'] > 0 ? esc_html( self::num( $p['total'] ) ) : esc_html__( 'A cotizar', 'cj-aluminios-b2b' ); ?></span>
			</div>
		</footer>
		<p class="leyenda"><?php echo esc_html( $e['leyenda'] ); ?></p>
	</main>
	<?php if ( $imprimir ) : ?>
		<script>window.addEventListener( 'load', function () { window.setTimeout( function () { window.print(); }, 300 ); } );</script>
	<?php endif; ?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------- Mi cuenta */

	public function menu_cuenta( array $items ): array {
		$nuevo = array();

		foreach ( $items as $clave => $etiqueta ) {
			$nuevo[ $clave ] = $etiqueta;

			if ( 'orders' === $clave ) {
				$nuevo[ self::ENDPOINT ] = __( 'Mis Presupuestos Web', 'cj-aluminios-b2b' );
			}
		}

		if ( ! isset( $nuevo[ self::ENDPOINT ] ) ) {
			$nuevo = array_slice( $nuevo, 0, 1, true ) + array( self::ENDPOINT => __( 'Mis Presupuestos Web', 'cj-aluminios-b2b' ) ) + array_slice( $nuevo, 1, null, true );
		}

		return $nuevo;
	}

	public function titulo_cuenta(): string {
		return __( 'Mis Presupuestos Web', 'cj-aluminios-b2b' );
	}

	public function render_cuenta(): void {
		global $wpdb;

		$tabla = self::tabla();
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE user_id = %d ORDER BY id DESC LIMIT 200", get_current_user_id() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $filas ) ) {
			echo '<p>' . esc_html__( 'Todavía no generaste presupuestos desde la web.', 'cj-aluminios-b2b' ) . '</p>';
			return;
		}

		echo '<table class="woocommerce-orders-table shop_table shop_table_responsive cj-presupuestos"><thead><tr>';
		echo '<th>' . esc_html__( 'Número', 'cj-aluminios-b2b' ) . '</th><th>' . esc_html__( 'Fecha', 'cj-aluminios-b2b' ) . '</th><th>' . esc_html__( 'Estado', 'cj-aluminios-b2b' ) . '</th><th>' . esc_html__( 'Total', 'cj-aluminios-b2b' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $filas as $fila ) {
			$p = self::normalizar( $fila );
			echo '<tr>';
			echo '<td data-title="' . esc_attr__( 'Número', 'cj-aluminios-b2b' ) . '"><strong>' . esc_html( $p['numero'] ) . '</strong></td>';
			echo '<td data-title="' . esc_attr__( 'Fecha', 'cj-aluminios-b2b' ) . '">' . esc_html( mysql2date( 'd/m/Y H:i', $p['creado'] ) ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Estado', 'cj-aluminios-b2b' ) . '">' . esc_html( self::estados()[ $p['estado'] ] ?? $p['estado'] ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Total', 'cj-aluminios-b2b' ) . '">' . ( $p['con_precios'] && $p['total'] > 0 ? esc_html( CJB2B_Frontend::precio_texto( $p['total'] ) ) : esc_html__( 'A cotizar', 'cj-aluminios-b2b' ) ) . '</td>';
			echo '<td><a class="woocommerce-button button" href="' . esc_url( self::url_comprobante( $p ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ver', 'cj-aluminios-b2b' ) . '</a> ';
			echo '<a class="woocommerce-button button" href="' . esc_url( self::url_comprobante( $p, true ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Imprimir / PDF', 'cj-aluminios-b2b' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------ Admin */

	public function registrar_submenu(): void {
		add_submenu_page(
			CJB2B_Admin_Settings::MENU_SLUG,
			__( 'Presupuestos Web', 'cj-aluminios-b2b' ),
			__( 'Presupuestos Web', 'cj-aluminios-b2b' ),
			'manage_options',
			self::SLUG_ADMIN,
			array( $this, 'render_admin' )
		);
	}

	private static function volver( array $args = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG_ADMIN ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function guardar_estado(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'cj-aluminios-b2b' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_ESTADO );

		$numero = isset( $_POST['numero'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['numero'] ) ) ) : '';
		$estado = isset( $_POST['estado'] ) ? sanitize_key( wp_unslash( $_POST['estado'] ) ) : '';

		if ( '' !== $numero && isset( self::estados()[ $estado ] ) ) {
			global $wpdb;
			$wpdb->update( self::tabla(), array( 'estado' => $estado ), array( 'numero' => $numero ), array( '%s' ), array( '%s' ) );
		}

		$s = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		self::volver( array_filter( array( 's' => $s, 'actualizado' => $numero ) ) );
	}

	public function guardar_emisor(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'cj-aluminios-b2b' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_EMISOR );

		$datos = array();

		foreach ( array_keys( self::emisor() ) as $campo ) {
			if ( isset( $_POST['emisor'][ $campo ] ) ) {
				$datos[ $campo ] = 'mail' === $campo
					? sanitize_email( wp_unslash( $_POST['emisor'][ $campo ] ) )
					: sanitize_text_field( wp_unslash( $_POST['emisor'][ $campo ] ) );
			}
		}

		update_option( self::OPCION_EMISOR, $datos, false );
		self::volver( array( 'emisor' => '1' ) );
	}

	public function render_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- búsqueda y paginación de solo lectura.
		$s      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$pagina = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$aviso  = isset( $_GET['actualizado'] ) ? sanitize_text_field( wp_unslash( $_GET['actualizado'] ) ) : '';
		$emisor = isset( $_GET['emisor'] );
		// phpcs:enable

		$tabla  = self::tabla();
		$where  = '1=1';
		$params = array();

		if ( '' !== $s ) {
			$like     = '%' . $wpdb->esc_like( $s ) . '%';
			$where    = '(numero LIKE %s OR cliente_nombre LIKE %s OR cliente LIKE %s)';
			$params   = array( $like, $like, $like );
		}

		$offset = ( $pagina - 1 ) * self::POR_PAGINA;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tabla} WHERE {$where}", $params ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla}" ) );
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( self::POR_PAGINA, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		$paginas = max( 1, (int) ceil( $total / self::POR_PAGINA ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Presupuestos Web', 'cj-aluminios-b2b' ); ?></h1>
			<p><?php esc_html_e( 'Presupuestos generados desde el carrito y el simulador. La numeración WEB-XXXXX es propia de la tienda: no reemplaza a la de OLF.', 'cj-aluminios-b2b' ); ?></p>

			<?php if ( '' !== $aviso ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Estado de %s actualizado.', 'cj-aluminios-b2b' ), $aviso ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $emisor ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Datos del emisor guardados.', 'cj-aluminios-b2b' ); ?></p></div>
			<?php endif; ?>

			<form method="get" style="margin:12px 0">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG_ADMIN ); ?>" />
				<input type="search" name="s" value="<?php echo esc_attr( $s ); ?>" placeholder="<?php esc_attr_e( 'WEB-00001, cliente o CUIT', 'cj-aluminios-b2b' ); ?>" style="min-width:280px" />
				<button class="button"><?php esc_html_e( 'Buscar', 'cj-aluminios-b2b' ); ?></button>
				<span style="margin-left:8px"><?php echo esc_html( sprintf( _n( '%d presupuesto', '%d presupuestos', $total, 'cj-aluminios-b2b' ), $total ) ); ?></span>
			</form>

			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Número', 'cj-aluminios-b2b' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'cj-aluminios-b2b' ); ?></th>
					<th><?php esc_html_e( 'Cliente', 'cj-aluminios-b2b' ); ?></th>
					<th><?php esc_html_e( 'CUIT/DNI', 'cj-aluminios-b2b' ); ?></th>
					<th><?php esc_html_e( 'Origen', 'cj-aluminios-b2b' ); ?></th>
					<th><?php esc_html_e( 'Ítems', 'cj-aluminios-b2b' ); ?></th>
					<th><?php esc_html_e( 'Total', 'cj-aluminios-b2b' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'cj-aluminios-b2b' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $filas ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'No hay presupuestos para mostrar.', 'cj-aluminios-b2b' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( (array) $filas as $fila ) : ?>
					<?php $p = self::normalizar( $fila ); ?>
					<tr>
						<td><strong><?php echo esc_html( $p['numero'] ); ?></strong><?php echo $p['pedido_id'] ? '<br><a href="' . esc_url( admin_url( 'post.php?post=' . $p['pedido_id'] . '&action=edit' ) ) . '">' . esc_html( sprintf( __( 'Pedido #%d', 'cj-aluminios-b2b' ), $p['pedido_id'] ) ) . '</a>' : ''; ?></td>
						<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $p['creado'] ) ); ?></td>
						<td><?php echo esc_html( $p['cliente']['nombre'] ?? '' ); ?><br><span class="description"><?php echo esc_html( $p['cliente']['telefono'] ?? '' ); ?></span></td>
						<td><?php echo esc_html( $p['cliente']['cuit'] ?? '' ); ?></td>
						<td><?php echo esc_html( 'simulador' === $p['origen'] ? __( 'Simulador', 'cj-aluminios-b2b' ) : __( 'Carrito', 'cj-aluminios-b2b' ) ); ?></td>
						<td><?php echo esc_html( (string) count( $p['items'] ) ); ?></td>
						<td><?php echo $p['con_precios'] && $p['total'] > 0 ? esc_html( CJB2B_Frontend::precio_texto( $p['total'] ) ) : esc_html__( 'A cotizar', 'cj-aluminios-b2b' ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:4px">
								<?php wp_nonce_field( self::NONCE_ESTADO ); ?>
								<input type="hidden" name="action" value="cjb2b_presupuesto_estado" />
								<input type="hidden" name="numero" value="<?php echo esc_attr( $p['numero'] ); ?>" />
								<input type="hidden" name="s" value="<?php echo esc_attr( $s ); ?>" />
								<select name="estado">
									<?php foreach ( self::estados() as $clave => $etiqueta ) : ?>
										<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $p['estado'], $clave ); ?>><?php echo esc_html( $etiqueta ); ?></option>
									<?php endforeach; ?>
								</select>
								<button class="button"><?php esc_html_e( 'Guardar', 'cj-aluminios-b2b' ); ?></button>
							</form>
						</td>
						<td>
							<a class="button" href="<?php echo esc_url( self::url_comprobante( $p ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver', 'cj-aluminios-b2b' ); ?></a>
							<a class="button" href="<?php echo esc_url( self::url_comprobante( $p, true ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'PDF', 'cj-aluminios-b2b' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $paginas > 1 ) : ?>
				<p>
					<?php for ( $i = 1; $i <= $paginas; $i++ ) : ?>
						<?php if ( $i === $pagina ) : ?>
							<strong><?php echo esc_html( (string) $i ); ?></strong>
						<?php else : ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG_ADMIN, 's' => $s, 'paged' => $i ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( (string) $i ); ?></a>
						<?php endif; ?>
					<?php endfor; ?>
				</p>
			<?php endif; ?>

			<h2 style="margin-top:32px"><?php esc_html_e( 'Datos del emisor (encabezado del comprobante)', 'cj-aluminios-b2b' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_EMISOR ); ?>
				<input type="hidden" name="action" value="cjb2b_emisor" />
				<table class="form-table">
					<?php
					$etiquetas = array(
						'marca'     => __( 'Marca', 'cj-aluminios-b2b' ),
						'titular'   => __( 'Titular', 'cj-aluminios-b2b' ),
						'direccion' => __( 'Dirección', 'cj-aluminios-b2b' ),
						'localidad' => __( 'Localidad', 'cj-aluminios-b2b' ),
						'provincia' => __( 'Provincia', 'cj-aluminios-b2b' ),
						'mail'      => __( 'Mail', 'cj-aluminios-b2b' ),
						'cuit'      => 'CUIT',
						'iibb'      => __( 'Ingresos Brutos', 'cj-aluminios-b2b' ),
						'inicio'    => __( 'Inicio de actividades', 'cj-aluminios-b2b' ),
						'condicion' => __( 'Condición frente al IVA', 'cj-aluminios-b2b' ),
						'leyenda'   => __( 'Leyenda al pie', 'cj-aluminios-b2b' ),
					);
					foreach ( self::emisor() as $campo => $valor ) :
						?>
						<tr>
							<th scope="row"><label for="emisor-<?php echo esc_attr( $campo ); ?>"><?php echo esc_html( $etiquetas[ $campo ] ?? $campo ); ?></label></th>
							<td><input type="text" class="regular-text" id="emisor-<?php echo esc_attr( $campo ); ?>" name="emisor[<?php echo esc_attr( $campo ); ?>]" value="<?php echo esc_attr( $valor ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Guardar datos del emisor', 'cj-aluminios-b2b' ) ); ?>
			</form>
		</div>
		<?php
	}
}
