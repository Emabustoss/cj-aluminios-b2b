<?php
/**
 * Cambio de acabado (color) de un perfil directamente en el carrito.
 *
 * Cada línea de perfil muestra un selector con los acabados que tienen precio
 * (y el precio por barra de cada uno, si el visitante puede ver precios). Al
 * elegir otro, el JS dispara "Actualizar carrito" y acá se reemplaza la
 * variación conservando la cantidad y la posición de la línea.
 *
 * El nonce lo valida WooCommerce (WC_Form_Handler::update_cart_action) antes de
 * aplicar el filtro woocommerce_update_cart_action_cart_updated.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Cart_Acabado {

	private static ?self $instance = null;

	const CAMPO       = 'cjb2b_acabado';
	const CAMPO_TODOS = 'cjb2b_acabado_todos';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_after_cart_item_name', array( $this, 'render_selector' ), 20, 2 );
		add_action( 'woocommerce_before_cart_table', array( $this, 'render_aplicar_todos' ) );
		add_filter( 'woocommerce_update_cart_action_cart_updated', array( $this, 'aplicar_cambios' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'encolar_script' ), 20 );
	}

	/**
	 * Acabados de un perfil variable con precio: variation_id => [nombre, precio].
	 */
	private static function opciones( int $product_id ): array {
		$producto = wc_get_product( $product_id );

		if ( ! $producto instanceof WC_Product_Variable ) {
			return array();
		}

		$opciones = array();

		foreach ( $producto->get_children() as $variacion_id ) {
			$variacion = wc_get_product( $variacion_id );

			if ( ! $variacion instanceof WC_Product_Variation || 'publish' !== $variacion->get_status() ) {
				continue;
			}

			$slug = (string) ( $variacion->get_variation_attributes()['attribute_pa_acabado'] ?? '' );

			if ( '' === $slug ) {
				continue;
			}

			$opciones[ $variacion_id ] = array(
				'clave'  => CJB2B_Admin_Settings::normalizar_clave_acabado( $slug ),
				'nombre' => CJB2B_Frontend::nombre_acabado( $slug ),
				'precio' => (float) $variacion->get_price(), // Con el descuento de la lista del usuario.
			);
		}

		// Mismo orden que el resto del sitio (Natural, Blanco, Negro...).
		$orden = array_values( CJB2B_Admin_Settings::acabados() );
		uasort(
			$opciones,
			static function ( $a, $b ) use ( $orden ) {
				$pa = array_search( $a['nombre'], $orden, true );
				$pb = array_search( $b['nombre'], $orden, true );
				return ( false === $pa ? 99 : $pa ) <=> ( false === $pb ? 99 : $pb );
			}
		);

		return $opciones;
	}

	public function render_selector( $item, $clave ): void {
		$producto = $item['data'] ?? null;

		if ( ! $producto instanceof WC_Product_Variation || ! CJB2B_Product_Fields::es_perfil( $producto->get_parent_id() ) ) {
			return;
		}

		$opciones = self::opciones( $producto->get_parent_id() );

		if ( count( $opciones ) < 2 ) {
			return;
		}

		$ver_precios = CJB2B_Frontend::precios_visibles();
		$id_campo    = 'cj-acabado-' . sanitize_html_class( $clave );
		?>
		<p class="cj-b2b cj-carrito-acabado">
			<label for="<?php echo esc_attr( $id_campo ); ?>"><?php esc_html_e( 'Color / acabado:', 'cj-aluminios-b2b' ); ?></label>
			<select id="<?php echo esc_attr( $id_campo ); ?>" name="<?php echo esc_attr( self::CAMPO . '[' . $clave . ']' ); ?>" data-cj-carrito-acabado>
				<?php foreach ( $opciones as $variacion_id => $opcion ) : ?>
					<?php
					$sin_precio = $opcion['precio'] <= 0;
					$texto      = $opcion['nombre'];

					if ( $sin_precio ) {
						$texto .= ' — ' . __( 'consultar', 'cj-aluminios-b2b' );
					} elseif ( $ver_precios ) {
						$texto .= ' — ' . CJB2B_Frontend::precio_texto( $opcion['precio'] ) . ' ' . __( 'por barra', 'cj-aluminios-b2b' );
					}
					?>
					<option value="<?php echo esc_attr( (string) $variacion_id ); ?>" <?php selected( $producto->get_id(), $variacion_id ); ?> <?php disabled( $sin_precio && $producto->get_id() !== $variacion_id ); ?>><?php echo esc_html( $texto ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/** Perfiles del carrito (variaciones de productos con es_perfil). */
	private static function lineas_perfil(): array {
		$lineas = array();

		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $clave => $item ) {
			$producto = $item['data'] ?? null;

			if ( $producto instanceof WC_Product_Variation && CJB2B_Product_Fields::es_perfil( $producto->get_parent_id() ) ) {
				$lineas[ $clave ] = $item;
			}
		}

		return $lineas;
	}

	/**
	 * Barra "Aplicar a todos": va dentro del formulario del carrito (así viaja
	 * con el nonce de WooCommerce) y se procesa en aplicar_cambios().
	 */
	public function render_aplicar_todos(): void {
		if ( count( self::lineas_perfil() ) < 2 ) {
			return;
		}
		?>
		<div class="cj-b2b cj-carrito-todos">
			<label for="cj-acabado-todos"><?php esc_html_e( 'Cambiar el color de todos los perfiles:', 'cj-aluminios-b2b' ); ?></label>
			<div class="cj-carrito-todos__fila">
				<select id="cj-acabado-todos" name="<?php echo esc_attr( self::CAMPO_TODOS ); ?>">
					<option value=""><?php esc_html_e( 'Elegí un color…', 'cj-aluminios-b2b' ); ?></option>
					<?php foreach ( CJB2B_Admin_Settings::acabados() as $clave => $nombre ) : ?>
						<option value="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $nombre ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="cj-btn" name="update_cart" value="1" data-cj-aplicar-todos><?php esc_html_e( 'Aplicar a todos', 'cj-aluminios-b2b' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Reemplaza la variación de las líneas cuyo acabado cambió. Mantiene la
	 * cantidad y el lugar de la línea; si el nuevo color ya estaba en el
	 * carrito, WooCommerce suma las cantidades.
	 */
	public function aplicar_cambios( $actualizado ) {
		if ( ! WC()->cart ) {
			return $actualizado;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce ya validó woocommerce-cart-nonce.
		$pedidos = isset( $_POST[ self::CAMPO ] ) && is_array( $_POST[ self::CAMPO ] ) ? array_map( 'absint', wp_unslash( $_POST[ self::CAMPO ] ) ) : array();
		$todos   = isset( $_POST[ self::CAMPO_TODOS ] ) ? sanitize_key( wp_unslash( $_POST[ self::CAMPO_TODOS ] ) ) : '';
		// phpcs:enable

		$sin_precio = 0;

		// "Aplicar a todos": el color elegido pisa el de cada línea, si ese perfil lo tiene con precio.
		if ( '' !== $todos && isset( CJB2B_Admin_Settings::acabados()[ $todos ] ) ) {
			foreach ( self::lineas_perfil() as $clave => $item ) {
				$destino = 0;

				foreach ( self::opciones( (int) $item['product_id'] ) as $variacion_id => $opcion ) {
					if ( $opcion['clave'] === $todos && $opcion['precio'] > 0 ) {
						$destino = $variacion_id;
						break;
					}
				}

				if ( $destino ) {
					$pedidos[ $clave ] = $destino;
				} else {
					unset( $pedidos[ $clave ] );
					++$sin_precio;
				}
			}
		}

		if ( empty( $pedidos ) ) {
			return $actualizado;
		}

		$carrito = WC()->cart;
		$orden   = array_keys( $carrito->get_cart() );
		$cambios = array(); // clave vieja => clave nueva.

		foreach ( $pedidos as $clave => $variacion_id ) {
			$clave = sanitize_key( $clave );
			$item  = $carrito->get_cart_item( $clave );

			if ( empty( $item ) || ! $variacion_id || (int) $item['variation_id'] === $variacion_id ) {
				continue;
			}

			$variacion = wc_get_product( $variacion_id );

			// Solo variaciones del mismo producto, publicadas y con precio.
			if ( ! $variacion instanceof WC_Product_Variation
				|| $variacion->get_parent_id() !== (int) $item['product_id']
				|| 'publish' !== $variacion->get_status()
				|| (float) $variacion->get_price() <= 0
			) {
				continue;
			}

			$cantidad = (int) $item['quantity'];
			$carrito->remove_cart_item( $clave );
			$nueva = $carrito->add_to_cart( (int) $item['product_id'], $cantidad, $variacion_id, $variacion->get_variation_attributes() );

			if ( $nueva ) {
				$cambios[ $clave ] = $nueva;
				$actualizado       = true;
			} else {
				$carrito->restore_cart_item( $clave );
			}
		}

		if ( $cambios ) {
			// La línea nueva vuelve al lugar de la que reemplazó.
			$contenido = $carrito->get_cart_contents();
			$ordenado  = array();

			foreach ( $orden as $clave ) {
				$clave = $cambios[ $clave ] ?? $clave;

				if ( isset( $contenido[ $clave ] ) && ! isset( $ordenado[ $clave ] ) ) {
					$ordenado[ $clave ] = $contenido[ $clave ];
				}
			}

			$carrito->set_cart_contents( $ordenado + $contenido );
			wc_add_notice( __( 'Se actualizó el color del pedido.', 'cj-aluminios-b2b' ), 'success' );
		}

		if ( $sin_precio ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: cantidad de productos, 2: color */
					_n( '%1$d perfil no tiene precio cargado en %2$s y quedó con su color anterior.', '%1$d perfiles no tienen precio cargado en %2$s y quedaron con su color anterior.', $sin_precio, 'cj-aluminios-b2b' ),
					$sin_precio,
					CJB2B_Admin_Settings::acabados()[ $todos ]
				),
				'notice'
			);
		}

		return $actualizado;
	}

	public function encolar_script(): void {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		// Al cambiar el color se actualiza el carrito sin tener que tocar "Actualizar carrito".
		wp_register_script( 'cj-b2b-carrito-acabado', '', array(), CJB2B_VERSION, true );
		wp_enqueue_script( 'cj-b2b-carrito-acabado' );
		wp_add_inline_script(
			'cj-b2b-carrito-acabado',
			// WooCommerce deja deshabilitado todo botón "update_cart" hasta que cambie una cantidad:
			// el de "Aplicar a todos" se habilita al elegir un color.
			"document.addEventListener('change',function(e){var t=e.target,f=t.closest('form');if(!f)return;if(t.id==='cj-acabado-todos'){var a=f.querySelector('[data-cj-aplicar-todos]');if(a){a.disabled=!t.value;a.removeAttribute('aria-disabled');}return;}if(!t.matches('[data-cj-carrito-acabado]'))return;var b=f.querySelector('.actions [name=\"update_cart\"]')||f.querySelector('[name=\"update_cart\"]:not([data-cj-aplicar-todos])');if(b){b.disabled=false;b.removeAttribute('aria-disabled');b.click();}});"
		);
	}
}
