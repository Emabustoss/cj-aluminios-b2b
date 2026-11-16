<?php
/**
 * Desglose transparente de precios: bloque técnico en la ficha de producto
 * y datos por línea en el carrito (código, pesos, lista/rol aplicado).
 *
 * Solo muestra información; el cálculo sigue siendo el de CJB2B_Pricing_Engine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Price_Breakdown {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_ficha' ), 25 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'datos_item_carrito' ), 10, 2 );
	}

	/** "Código de matriz / SKU" en una sola línea, omitiendo lo que falte. */
	public static function codigo_y_sku( WC_Product $producto ): string {
		$id_padre = $producto->is_type( 'variation' ) ? $producto->get_parent_id() : $producto->get_id();
		$partes   = array_filter(
			array(
				CJB2B_Product_Fields::codigo_matriz( $id_padre ),
				(string) $producto->get_sku(),
			),
			'strlen'
		);

		return implode( ' / ', array_unique( $partes ) );
	}

	/* -------------------------------------------------------------- Ficha */

	public function render_ficha(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$id        = $product->get_id();
		$es_perfil = CJB2B_Product_Fields::es_perfil( $id ) && $product->is_type( 'variable' );
		$codigo    = self::codigo_y_sku( $product );
		$filas     = array();

		if ( '' !== $codigo ) {
			$filas[ __( 'Código de matriz / SKU', 'cj-aluminios-b2b' ) ] = '<span class="cj-code">' . esc_html( $codigo ) . '</span>';
		}

		if ( $es_perfil ) {
			$peso = CJB2B_Product_Fields::peso_metro( $id );

			if ( $peso > 0 ) {
				$filas[ __( 'Peso métrico', 'cj-aluminios-b2b' ) ]   = esc_html( CJB2B_Frontend::numero( $peso ) ) . ' kg/m';
				$filas[ __( 'Peso barra 6 m', 'cj-aluminios-b2b' ) ] = esc_html( CJB2B_Frontend::numero( $peso * 6 ) ) . ' kg';
			}

			$filas[ __( 'Lista / rol aplicado', 'cj-aluminios-b2b' ) ] = esc_html( CJB2B_Frontend::rol_texto() );
		} else {
			$filas[ __( 'Presentación', 'cj-aluminios-b2b' ) ] = esc_html( CJB2B_Frontend::presentacion( $id ) );

			if ( '' !== $product->get_price() ) {
				$filas[ __( 'Precio unitario', 'cj-aluminios-b2b' ) ] = CJB2B_Frontend::precios_visibles()
					? esc_html( CJB2B_Frontend::precio_texto( (float) $product->get_price() ) )
					: CJB2B_Frontend::mensaje_visitante_html();
			}

			$filas[ __( 'Lista / rol aplicado', 'cj-aluminios-b2b' ) ] = esc_html( self::texto_rol_accesorio() );
		}

		echo '<div class="cj-b2b cj-desglose">';
		echo '<p class="cj-desglose__titulo">' . esc_html__( 'Datos técnicos y precio', 'cj-aluminios-b2b' ) . '</p>';
		echo '<table><tbody>';

		foreach ( $filas as $etiqueta => $valor ) {
			echo '<tr><th scope="row">' . esc_html( $etiqueta ) . '</th><td>' . wp_kses_post( $valor ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( $es_perfil ) {
			$this->render_tabla_acabados( $product );
		}

		echo '</div>';
	}

	/**
	 * Los accesorios usan el precio fijo de WooCommerce: el motor de la
	 * Fase 1 no les aplica descuento por rol, y acá se informa tal cual.
	 */
	private static function texto_rol_accesorio(): string {
		$rol = CJB2B_Frontend::rol_actual();

		return sprintf(
			/* translators: %s: nombre de la lista */
			__( '%s — precio fijo de lista (sin descuento por rol)', 'cj-aluminios-b2b' ),
			$rol['nombre']
		);
	}

	/**
	 * Tabla "Acabado | Precio lista | Tu precio" de la barra de 6 m, usando
	 * el precio público persistido y el precio final que devuelve el motor.
	 */
	private function render_tabla_acabados( WC_Product $producto ): void {
		if ( ! CJB2B_Frontend::precios_visibles() ) {
			echo '<p class="cj-desglose__nota">' . wp_kses_post( CJB2B_Frontend::mensaje_visitante_html() ) . '</p>';
			return;
		}

		$rol        = CJB2B_Frontend::rol_actual();
		$por_slug   = array();
		$hay_precio = false;

		foreach ( $producto->get_children() as $variacion_id ) {
			$variacion = wc_get_product( $variacion_id );

			if ( ! $variacion instanceof WC_Product_Variation || 'publish' !== $variacion->get_status() ) {
				continue;
			}

			$slug  = (string) ( $variacion->get_variation_attributes()['attribute_pa_acabado'] ?? '' );
			$lista = (float) $variacion->get_regular_price();

			if ( '' === $slug || $lista <= 0 ) {
				continue;
			}

			$por_slug[ $slug ] = array(
				'lista' => $lista,
				'final' => (float) $variacion->get_price(),
			);
			$hay_precio        = true;
		}

		if ( ! $hay_precio ) {
			echo '<p class="cj-desglose__nota">' . esc_html__( 'Precio a consultar: todavía no hay precio por kilo cargado para esta línea.', 'cj-aluminios-b2b' ) . '</p>';
			return;
		}

		$con_descuento = $rol['descuento'] > 0;

		echo '<div class="cj-desglose__scroll"><table class="cj-desglose__precios"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Acabado', 'cj-aluminios-b2b' ) . '</th>';

		if ( $con_descuento ) {
			echo '<th scope="col">' . esc_html__( 'Precio lista', 'cj-aluminios-b2b' ) . '</th>';
		}

		echo '<th scope="col">' . esc_html__( 'Tu precio (barra 6 m)', 'cj-aluminios-b2b' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_keys( CJB2B_Admin_Settings::acabados() ) as $slug ) {
			if ( ! isset( $por_slug[ $slug ] ) ) {
				continue;
			}

			echo '<tr><td>' . esc_html( CJB2B_Frontend::nombre_acabado( $slug ) ) . '</td>';

			if ( $con_descuento ) {
				echo '<td>' . esc_html( CJB2B_Frontend::precio_texto( $por_slug[ $slug ]['lista'] ) ) . '</td>';
			}

			echo '<td><strong>' . esc_html( CJB2B_Frontend::precio_texto( $por_slug[ $slug ]['final'] ) ) . '</strong></td></tr>';
		}

		echo '</tbody></table></div>';
		echo '<p class="cj-desglose__nota">' . esc_html__( 'Precio = peso de la barra de 6 m × precio por kilo del acabado, menos el descuento de tu lista.', 'cj-aluminios-b2b' ) . '</p>';
	}

	/* ------------------------------------------------------------ Carrito */

	public function datos_item_carrito( $datos, $item ) {
		$producto = $item['data'] ?? null;

		if ( ! is_array( $datos ) || ! $producto instanceof WC_Product ) {
			return $datos;
		}

		$id_padre = (int) ( $item['product_id'] ?? 0 );
		$codigo   = self::codigo_y_sku( $producto );

		if ( '' !== $codigo ) {
			$datos[] = array(
				'key'   => __( 'Código de matriz / SKU', 'cj-aluminios-b2b' ),
				'value' => $codigo,
			);
		}

		if ( CJB2B_Product_Fields::es_perfil( $id_padre ) && $producto->is_type( 'variation' ) ) {
			$peso = CJB2B_Product_Fields::peso_metro( $id_padre );

			if ( $peso > 0 ) {
				$datos[] = array(
					'key'   => __( 'Peso', 'cj-aluminios-b2b' ),
					'value' => sprintf(
						/* translators: 1: kg por metro, 2: kg por barra */
						__( '%1$s kg/m | barra 6 m: %2$s kg', 'cj-aluminios-b2b' ),
						CJB2B_Frontend::numero( $peso ),
						CJB2B_Frontend::numero( $peso * 6 )
					),
				);
			}

			$datos[] = array(
				'key'   => __( 'Lista', 'cj-aluminios-b2b' ),
				'value' => CJB2B_Frontend::rol_texto(),
			);
		} else {
			$datos[] = array(
				'key'   => __( 'Presentación', 'cj-aluminios-b2b' ),
				'value' => CJB2B_Frontend::presentacion( $id_padre ),
			);

			if ( CJB2B_Frontend::precios_visibles() ) {
				$datos[] = array(
					'key'   => __( 'Precio unitario', 'cj-aluminios-b2b' ),
					'value' => CJB2B_Frontend::precio_texto( (float) $producto->get_price() ),
				);
			}

			$datos[] = array(
				'key'   => __( 'Lista', 'cj-aluminios-b2b' ),
				'value' => self::texto_rol_accesorio(),
			);
		}

		return $datos;
	}
}
