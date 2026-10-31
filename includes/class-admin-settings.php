<?php
/**
 * Ajustes de administración: precio por kilo según acabado y línea de producto.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Admin_Settings {

	private static ?self $instance = null;

	const MENU_SLUG      = 'cjb2b-settings';
	const OPTION_PRECIOS = 'cjb2b_precios_kilo';

	const TABLA_ESTANDAR      = 'estandar';
	const TABLA_ESTRUCTURALES = 'estructurales';

	/** Slug de la categoría de producto que usa la tabla de precios "estructurales". */
	const CATEGORIA_ESTRUCTURALES_SLUG = 'estructurales';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'registrar_menu' ) );
	}

	/**
	 * Acabados soportados. La clave debe coincidir con el slug del término
	 * del atributo global "Acabado" (pa_acabado) usado en las variaciones.
	 * "Anodizado" ya no es un acabado genérico: se maneja como 4 variantes
	 * específicas, cada una con su propio precio por kilo.
	 */
	public static function acabados(): array {
		return array(
			'natural'             => __( 'Natural', 'cj-aluminios-b2b' ),
			'blanco'              => __( 'Blanco', 'cj-aluminios-b2b' ),
			'negro'               => __( 'Negro', 'cj-aluminios-b2b' ),
			'simil-madera'        => __( 'Símil Madera', 'cj-aluminios-b2b' ),
			'bronce'              => __( 'Bronce', 'cj-aluminios-b2b' ),
			'anodizado-negro'     => __( 'Anodizado Negro', 'cj-aluminios-b2b' ),
			'anodizado-especial'  => __( 'Anodizado Especial', 'cj-aluminios-b2b' ),
			'anodizado-champagne' => __( 'Anodizado Champagne', 'cj-aluminios-b2b' ),
			'anodizado-peltre'    => __( 'Anodizado Peltre', 'cj-aluminios-b2b' ),
		);
	}

	/**
	 * Tablas de precio por kilo. Estructurales tiene su propio "Natural"
	 * (más caro) y por lo tanto su propio precio para cada acabado; el
	 * resto de las líneas (Herrero, Módena, A30 New, Mampara de Baño)
	 * comparten la tabla "estándar".
	 */
	public static function tablas(): array {
		return array(
			self::TABLA_ESTANDAR      => __( 'Estándar (Herrero, Módena, A30 New, Mampara de Baño)', 'cj-aluminios-b2b' ),
			self::TABLA_ESTRUCTURALES => __( 'Estructurales', 'cj-aluminios-b2b' ),
		);
	}

	public static function activar(): void {
		self::migrar_formato_precios_legacy();
	}

	public static function precios_default(): array {
		return array_fill_keys( array_keys( self::acabados() ), 0.0 );
	}

	private static function estructura_precios_default(): array {
		return array(
			self::TABLA_ESTANDAR      => self::precios_default(),
			self::TABLA_ESTRUCTURALES => self::precios_default(),
		);
	}

	/**
	 * Convierte el formato viejo (un solo array plano con 5 claves, sin
	 * distinguir línea) al formato nuevo (dos tablas de 9 claves). Los
	 * valores ya cargados para natural/blanco/negro/bronce se conservan en
	 * la tabla "estándar"; el "anodizado" genérico no tiene a dónde migrar
	 * (se reemplazó por 4 variantes específicas), así que se descarta.
	 */
	private static function migrar_formato_precios_legacy(): void {
		$guardado = get_option( self::OPTION_PRECIOS );

		if ( ! is_array( $guardado ) || isset( $guardado[ self::TABLA_ESTANDAR ] ) ) {
			return;
		}

		$nuevo = self::estructura_precios_default();

		foreach ( $guardado as $clave => $valor ) {
			if ( isset( $nuevo[ self::TABLA_ESTANDAR ][ $clave ] ) ) {
				$nuevo[ self::TABLA_ESTANDAR ][ $clave ] = (float) $valor;
			}
		}

		update_option( self::OPTION_PRECIOS, $nuevo );
	}

	public static function obtener_precios( string $tabla = self::TABLA_ESTANDAR ): array {
		self::migrar_formato_precios_legacy();

		$guardado = get_option( self::OPTION_PRECIOS, self::estructura_precios_default() );
		$tabla    = isset( $guardado[ $tabla ] ) ? $tabla : self::TABLA_ESTANDAR;

		return wp_parse_args( $guardado[ $tabla ] ?? array(), self::precios_default() );
	}

	public static function precio_kilo_para( string $acabado, string $tabla = self::TABLA_ESTANDAR ): float {
		$precios = self::obtener_precios( $tabla );
		$clave   = self::normalizar_clave_acabado( $acabado );
		return isset( $precios[ $clave ] ) ? (float) $precios[ $clave ] : 0.0;
	}

	/**
	 * Qué tabla de precios usar para un producto, según su categoría.
	 */
	public static function tabla_para_producto( int $product_id ): string {
		$categorias = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'slugs' ) );

		if ( is_wp_error( $categorias ) ) {
			return self::TABLA_ESTANDAR;
		}

		return in_array( self::CATEGORIA_ESTRUCTURALES_SLUG, $categorias, true )
			? self::TABLA_ESTRUCTURALES
			: self::TABLA_ESTANDAR;
	}

	/**
	 * Un cambio en los precios por kilo afecta el precio público "congelado"
	 * en _regular_price de todas las variaciones de todos los perfiles
	 * (ver CJB2B_Pricing_Engine::sincronizar_precios_producto()), así que
	 * hay que recalcularlos tras guardar esta página.
	 */
	private static function resincronizar_precios_perfiles(): void {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => CJB2B_Product_Fields::META_ES_PERFIL,
						'value'   => array( '1', 'yes' ),
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			CJB2B_Pricing_Engine::instance()->sincronizar_precios_producto( $id );
		}
	}

	/**
	 * Normaliza cualquier variante de un acabado (nombre de término, slug,
	 * texto con acentos/mayúsculas/espacios) a una de las claves canónicas
	 * usadas en todo el plugin. Usa sanitize_title() (no sanitize_key())
	 * porque transliteral acentos correctamente, igual que WordPress al
	 * generar el slug del término.
	 */
	public static function normalizar_clave_acabado( string $valor ): string {
		return sanitize_title( $valor );
	}

	public function registrar_menu(): void {
		add_menu_page(
			__( 'CJ Aluminios B2B', 'cj-aluminios-b2b' ),
			__( 'CJ Aluminios B2B', 'cj-aluminios-b2b' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_pagina' ),
			'dashicons-screenoptions'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Precios por Kilo', 'cj-aluminios-b2b' ),
			__( 'Precios por Kilo', 'cj-aluminios-b2b' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_pagina' )
		);
	}

	public function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tenés permisos suficientes para acceder a esta página.', 'cj-aluminios-b2b' ) );
		}

		if ( isset( $_POST['cjb2b_precios_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cjb2b_precios_nonce'] ) ), 'cjb2b_guardar_precios' )
		) {
			$nuevo = self::estructura_precios_default();

			foreach ( array_keys( self::tablas() ) as $tabla ) {
				foreach ( array_keys( self::acabados() ) as $clave ) {
					$valor               = isset( $_POST['cjb2b_precio_kilo'][ $tabla ][ $clave ] ) ? wp_unslash( $_POST['cjb2b_precio_kilo'][ $tabla ][ $clave ] ) : 0;
					$nuevo[ $tabla ][ $clave ] = max( 0.0, floatval( $valor ) );
				}
			}

			update_option( self::OPTION_PRECIOS, $nuevo );
			self::resincronizar_precios_perfiles();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Precios por kilo actualizados correctamente.', 'cj-aluminios-b2b' ) . '</p></div>';
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CJ Aluminios - Precios por Kilo', 'cj-aluminios-b2b' ); ?></h1>
			<p><?php esc_html_e( 'Precio del kilo de aluminio según acabado y línea. Se usa para calcular el precio de la barra de 6m de los perfiles.', 'cj-aluminios-b2b' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'cjb2b_guardar_precios', 'cjb2b_precios_nonce' ); ?>
				<?php foreach ( self::tablas() as $tabla => $etiqueta ) : ?>
					<h2><?php echo esc_html( $etiqueta ); ?></h2>
					<table class="form-table" role="presentation">
						<?php $precios = self::obtener_precios( $tabla ); ?>
						<?php foreach ( self::acabados() as $clave => $nombre ) : ?>
							<tr>
								<th scope="row">
									<label for="cjb2b_precio_kilo_<?php echo esc_attr( $tabla . '_' . $clave ); ?>"><?php echo esc_html( $nombre ); ?></label>
								</th>
								<td>
									$
									<input
										type="number"
										step="0.01"
										min="0"
										id="cjb2b_precio_kilo_<?php echo esc_attr( $tabla . '_' . $clave ); ?>"
										name="cjb2b_precio_kilo[<?php echo esc_attr( $tabla ); ?>][<?php echo esc_attr( $clave ); ?>]"
										value="<?php echo esc_attr( $precios[ $clave ] ); ?>"
									/>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>
				<?php submit_button( __( 'Guardar Precios', 'cj-aluminios-b2b' ) ); ?>
			</form>
		</div>
		<?php
	}
}
