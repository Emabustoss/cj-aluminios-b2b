<?php
/**
 * Motor de precios dinámico: Precio Barra 6m = (peso_metro * 6) * precio_kilo_color * (1 - descuento_rol / 100).
 * Solo aplica a productos marcados como es_perfil = true. Los demás conservan el precio fijo de WooCommerce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Pricing_Engine {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_product_get_price', array( $this, 'filtrar_precio_producto' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filtrar_precio_producto' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'recalcular_carrito' ), 20, 1 );
	}

	public function filtrar_precio_producto( $precio, $producto ) {
		if ( ! $producto instanceof WC_Product ) {
			return $precio;
		}

		$precio_calculado = $this->calcular_precio( $producto );

		return null === $precio_calculado ? $precio : $precio_calculado;
	}

	public function recalcular_carrito( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			$producto = $item['data'];

			if ( ! $producto instanceof WC_Product ) {
				continue;
			}

			$precio_calculado = $this->calcular_precio( $producto );

			if ( null !== $precio_calculado ) {
				$producto->set_price( $precio_calculado );
			}
		}
	}

	/**
	 * Devuelve el precio calculado del perfil (ya con el descuento del rol
	 * actual aplicado), o null si el producto no es un perfil o no tiene
	 * los datos necesarios (se conserva entonces el precio fijo de WooCommerce).
	 */
	private function calcular_precio( WC_Product $producto ): ?float {
		$base = $this->calcular_precio_base( $producto );

		if ( null === $base ) {
			return null;
		}

		$descuento = CJB2B_B2B_Roles::descuento_usuario();

		return round( $base * ( 1 - ( $descuento / 100 ) ), 2 );
	}

	/**
	 * Precio Barra 6m sin descuento de rol (precio "público"). Es el valor
	 * que se persiste en _regular_price/_price de cada variación, porque
	 * WooCommerce usa ese meta (no el filtro de get_price()) para el listado
	 * de la tienda, el rango de precio del producto variable y el ordenado
	 * por precio. El descuento de cada rol se sigue aplicando en vivo sobre
	 * este valor vía el filtro de get_price(), nunca se guarda en el meta.
	 */
	private function calcular_precio_base( WC_Product $producto ): ?float {
		$id_padre = $producto->is_type( 'variation' ) ? $producto->get_parent_id() : $producto->get_id();

		if ( ! CJB2B_Product_Fields::es_perfil( $id_padre ) ) {
			return null;
		}

		$peso_metro = CJB2B_Product_Fields::peso_metro( $id_padre );

		if ( $peso_metro <= 0 ) {
			return null;
		}

		$acabado = $this->obtener_acabado( $producto );

		if ( '' === $acabado ) {
			return null;
		}

		$tabla       = CJB2B_Admin_Settings::tabla_para_producto( $id_padre );
		$precio_kilo = CJB2B_Admin_Settings::precio_kilo_para( $acabado, $tabla );

		if ( $precio_kilo <= 0 ) {
			return null;
		}

		return round( ( $peso_metro * 6 ) * $precio_kilo, 2 );
	}

	/**
	 * Escribe el precio base (público) en _regular_price/_price de la
	 * variación, para que el listado de la tienda y el rango de precio del
	 * producto variable muestren un valor real en vez de 0.00.
	 */
	public function sincronizar_precio_variacion( int $variacion_id ): void {
		$variacion = wc_get_product( $variacion_id );

		if ( ! $variacion instanceof WC_Product_Variation ) {
			return;
		}

		$base = $this->calcular_precio_base( $variacion );

		if ( null === $base ) {
			return;
		}

		$variacion->set_regular_price( (string) $base );
		$variacion->save();
	}

	/**
	 * Resincroniza todas las variaciones de un producto variable y actualiza
	 * el rango de precio del padre. Se llama al guardar el producto (si
	 * cambió peso_metro) y al guardar los precios por kilo en el admin.
	 */
	public function sincronizar_precios_producto( int $product_id ): void {
		$producto = wc_get_product( $product_id );

		if ( ! $producto instanceof WC_Product_Variable ) {
			return;
		}

		foreach ( $producto->get_children() as $variacion_id ) {
			$this->sincronizar_precio_variacion( $variacion_id );
		}

		WC_Product_Variable::sync( $product_id );
	}

	/**
	 * Resuelve el slug del acabado (natural, blanco, negro, bronce, anodizado)
	 * a partir de la variación (atributo pa_acabado) o, si es un producto
	 * simple, de su primer término asignado en pa_acabado.
	 */
	private function obtener_acabado( WC_Product $producto ): string {
		$valor = '';

		if ( $producto->is_type( 'variation' ) ) {
			$atributos = $producto->get_variation_attributes();
			$valor     = $atributos['attribute_pa_acabado'] ?? '';
		} else {
			$terminos = wc_get_product_terms( $producto->get_id(), 'pa_acabado', array( 'fields' => 'names' ) );
			$valor    = $terminos[0] ?? '';
		}

		return $valor ? CJB2B_Admin_Settings::normalizar_clave_acabado( $valor ) : '';
	}
}
