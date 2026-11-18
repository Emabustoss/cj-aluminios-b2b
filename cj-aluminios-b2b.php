<?php
/**
 * Plugin Name: CJ Aluminios B2B
 * Description: Portal B2B para CJ Aluminios: roles mayoristas, listas de precios y motor de precios dinámico por peso y acabado para perfiles de aluminio.
 * Version: 1.4.0
 * Author: CJ Aluminios
 * Text Domain: cj-aluminios-b2b
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CJB2B_VERSION', '1.4.0' );
define( 'CJB2B_PATH', plugin_dir_path( __FILE__ ) );
define( 'CJB2B_URL', plugin_dir_url( __FILE__ ) );

final class CJ_Aluminios_B2B {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	private function includes(): void {
		require_once CJB2B_PATH . 'includes/class-admin-settings.php';
		require_once CJB2B_PATH . 'includes/class-b2b-roles.php';
		require_once CJB2B_PATH . 'includes/class-product-fields.php';
		require_once CJB2B_PATH . 'includes/class-pricing-engine.php';

		// Fase 2: catálogo técnico, desglose de precios y pedido por WhatsApp.
		require_once CJB2B_PATH . 'includes/class-frontend.php';
		require_once CJB2B_PATH . 'includes/class-catalog-ui.php';
		require_once CJB2B_PATH . 'includes/class-price-breakdown.php';
		require_once CJB2B_PATH . 'includes/class-whatsapp-checkout.php';

		// Fase 3: tratamientos, simulador 2D, portada y alta de gremio.
		require_once CJB2B_PATH . 'includes/class-tratamientos-catalog.php';
		require_once CJB2B_PATH . 'includes/class-simulador-2d.php';
		require_once CJB2B_PATH . 'includes/class-institutional-pages.php';

		// Fase 4: barra de cuenta, pie B2B y enlaces compartidos.
		require_once CJB2B_PATH . 'includes/class-site-navigation.php';

		// Presupuestos web con numeración propia (WEB-00001).
		require_once CJB2B_PATH . 'includes/class-presupuestos-web.php';
		require_once CJB2B_PATH . 'includes/class-cart-acabado.php';
	}

	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'aviso_falta_woocommerce' ) );
			return;
		}

		CJB2B_Admin_Settings::instance();
		CJB2B_B2B_Roles::instance();
		CJB2B_Product_Fields::instance();
		CJB2B_Pricing_Engine::instance();

		CJB2B_Frontend::instance();
		CJB2B_Catalog_UI::instance();
		CJB2B_Price_Breakdown::instance();
		CJB2B_WhatsApp_Checkout::instance();

		CJB2B_Tratamientos_Catalog::instance();
		CJB2B_Simulador_2D::instance();
		CJB2B_Institutional_Pages::instance();

		CJB2B_Site_Navigation::instance();
		CJB2B_Presupuestos_Web::instance();
		CJB2B_Cart_Acabado::instance();
	}

	public function aviso_falta_woocommerce(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'CJ Aluminios B2B requiere que WooCommerce esté instalado y activo.', 'cj-aluminios-b2b' ) . '</p></div>';
	}
}

function cjb2b(): CJ_Aluminios_B2B {
	return CJ_Aluminios_B2B::instance();
}
cjb2b();

register_activation_hook( __FILE__, array( 'CJB2B_Admin_Settings', 'activar' ) );
register_activation_hook( __FILE__, array( 'CJB2B_B2B_Roles', 'activar' ) );
register_activation_hook( __FILE__, array( 'CJB2B_Product_Fields', 'activar' ) );
register_activation_hook( __FILE__, array( 'CJB2B_Catalog_UI', 'activar' ) );
register_activation_hook( __FILE__, array( 'CJB2B_Institutional_Pages', 'activar' ) );

// El plugin solo usa la API CRUD de pedidos, así que es compatible con HPOS.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
