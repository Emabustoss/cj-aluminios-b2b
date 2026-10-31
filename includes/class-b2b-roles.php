<?php
/**
 * Roles B2B y administración de descuentos por rol.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_B2B_Roles {

	private static ?self $instance = null;

	const OPTION_DESCUENTOS = 'cjb2b_descuentos_roles';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'registrar_submenu' ) );
	}

	public static function roles(): array {
		return array(
			'publico'     => __( 'Consumidor Final (Público)', 'cj-aluminios-b2b' ),
			'lista_1'     => __( 'Lista 1 (Gremio B)', 'cj-aluminios-b2b' ),
			'lista_2'     => __( 'Lista 2 (Gremio A)', 'cj-aluminios-b2b' ),
			'mayorista_1' => __( 'Mayorista 1 (Alto Volumen)', 'cj-aluminios-b2b' ),
		);
	}

	public static function activar(): void {
		$rol_customer = get_role( 'customer' );
		$capacidades  = $rol_customer ? $rol_customer->capabilities : array( 'read' => true );

		foreach ( self::roles() as $slug => $nombre ) {
			if ( ! get_role( $slug ) ) {
				add_role( $slug, $nombre, $capacidades );
			}
		}

		if ( false === get_option( self::OPTION_DESCUENTOS ) ) {
			add_option( self::OPTION_DESCUENTOS, self::descuentos_default() );
		}
	}

	public static function descuentos_default(): array {
		return array(
			'publico'     => 0.0,
			'lista_1'     => 0.0,
			'lista_2'     => 0.0,
			'mayorista_1' => 0.0,
		);
	}

	public static function obtener_descuentos(): array {
		$guardados = get_option( self::OPTION_DESCUENTOS, self::descuentos_default() );
		return wp_parse_args( $guardados, self::descuentos_default() );
	}

	/**
	 * Descuento (%) del usuario indicado. Si tiene más de un rol B2B,
	 * se aplica el de mayor beneficio.
	 */
	public static function descuento_usuario( int $user_id = 0 ): float {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return 0.0;
		}

		$usuario = get_userdata( $user_id );
		if ( ! $usuario ) {
			return 0.0;
		}

		$descuentos = self::obtener_descuentos();
		$prioridad  = array( 'mayorista_1', 'lista_2', 'lista_1', 'publico' );

		foreach ( $prioridad as $rol ) {
			if ( in_array( $rol, (array) $usuario->roles, true ) ) {
				return (float) $descuentos[ $rol ];
			}
		}

		return 0.0;
	}

	public function registrar_submenu(): void {
		add_submenu_page(
			CJB2B_Admin_Settings::MENU_SLUG,
			__( 'Descuentos por Rol', 'cj-aluminios-b2b' ),
			__( 'Descuentos por Rol', 'cj-aluminios-b2b' ),
			'manage_options',
			'cjb2b-descuentos-roles',
			array( $this, 'render_pagina' )
		);
	}

	public function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tenés permisos suficientes para acceder a esta página.', 'cj-aluminios-b2b' ) );
		}

		if ( isset( $_POST['cjb2b_descuentos_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cjb2b_descuentos_nonce'] ) ), 'cjb2b_guardar_descuentos' )
		) {
			$nuevos = array();
			foreach ( array_keys( self::roles() ) as $slug ) {
				$valor           = isset( $_POST['cjb2b_descuento'][ $slug ] ) ? wp_unslash( $_POST['cjb2b_descuento'][ $slug ] ) : 0;
				$nuevos[ $slug ] = max( 0.0, min( 100.0, floatval( $valor ) ) );
			}
			update_option( self::OPTION_DESCUENTOS, $nuevos );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Descuentos actualizados correctamente.', 'cj-aluminios-b2b' ) . '</p></div>';
		}

		$descuentos = self::obtener_descuentos();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Descuentos por Rol B2B', 'cj-aluminios-b2b' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'cjb2b_guardar_descuentos', 'cjb2b_descuentos_nonce' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( self::roles() as $slug => $nombre ) : ?>
						<tr>
							<th scope="row">
								<label for="cjb2b_descuento_<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $nombre ); ?></label>
							</th>
							<td>
								<input
									type="number"
									step="0.01"
									min="0"
									max="100"
									id="cjb2b_descuento_<?php echo esc_attr( $slug ); ?>"
									name="cjb2b_descuento[<?php echo esc_attr( $slug ); ?>]"
									value="<?php echo esc_attr( $descuentos[ $slug ] ); ?>"
								/> %
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Guardar Descuentos', 'cj-aluminios-b2b' ) ); ?>
			</form>
		</div>
		<?php
	}
}
