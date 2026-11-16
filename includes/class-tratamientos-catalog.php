<?php
/**
 * Fase 3: catálogo centralizado de tratamientos (paleta comercial completa)
 * y shortcode [cj_muestrario_acabados].
 *
 * Es la única fuente de la paleta: la usan el muestrario, el simulador 2D
 * (class-simulador-2d.php) y la portada. Es una paleta VISUAL/comercial: los
 * precios siguen saliendo de los 9 acabados de CJB2B_Admin_Settings::acabados()
 * (atributo pa_acabado). La clave 'acabado' de cada tratamiento indica con
 * cuál de esos 9 se cotiza, cuando hay una equivalencia directa.
 *
 * Los HEX son aproximaciones de pantalla (referencia RAL / muestras del
 * fabricante), no una carta de color certificada.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Tratamientos_Catalog {

	private static ?self $instance = null;

	const SHORTCODE = 'cj_muestrario_acabados';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	/* -------------------------------------------------------------- Datos */

	/**
	 * Familias de tratamiento con su ficha técnica.
	 */
	public static function familias(): array {
		return array(
			'pintados'   => array(
				'nombre'      => __( 'Pintados estándar', 'cj-aluminios-b2b' ),
				'descripcion' => __( 'Pintura poliéster en polvo aplicada electrostáticamente y horneada. Color parejo, gran variedad y la mejor relación costo / terminación.', 'cj-aluminios-b2b' ),
				'ficha'       => array(
					__( 'Proceso', 'cj-aluminios-b2b' )       => __( 'Pintura en polvo poliéster termoconvertible, curada en horno.', 'cj-aluminios-b2b' ),
					__( 'Durabilidad', 'cj-aluminios-b2b' )   => __( 'Alta resistencia a la intemperie, a los rayos UV y a la corrosión. No se descascara ni necesita repintado.', 'cj-aluminios-b2b' ),
					__( 'Terminaciones', 'cj-aluminios-b2b' ) => __( 'Brillante, semimate y microtexturado (disimula rayones y marcas de uso).', 'cj-aluminios-b2b' ),
					__( 'Mantenimiento', 'cj-aluminios-b2b' ) => __( 'Agua tibia con detergente neutro y paño suave, dos veces al año. Sin solventes ni abrasivos.', 'cj-aluminios-b2b' ),
				),
			),
			'anodizados' => array(
				'nombre'      => __( 'Anodizados', 'cj-aluminios-b2b' ),
				'descripcion' => __( 'Tratamiento electroquímico que engrosa la capa natural de óxido del aluminio. Aspecto metálico y la mayor dureza superficial.', 'cj-aluminios-b2b' ),
				'ficha'       => array(
					__( 'Proceso', 'cj-aluminios-b2b' )       => __( 'Oxidación anódica con coloreado electrolítico y sellado de la capa.', 'cj-aluminios-b2b' ),
					__( 'Durabilidad', 'cj-aluminios-b2b' )   => __( 'Capa integrada al metal: no se salta ni se pela. Muy buena respuesta en ambientes exigentes.', 'cj-aluminios-b2b' ),
					__( 'Terminaciones', 'cj-aluminios-b2b' ) => __( 'Brillo metálico satinado; el tono puede variar levemente entre partidas.', 'cj-aluminios-b2b' ),
					__( 'Mantenimiento', 'cj-aluminios-b2b' ) => __( 'Agua y detergente neutro. Evitar cal, cemento y ácidos durante la obra: manchan la capa anódica.', 'cj-aluminios-b2b' ),
				),
			),
			'madera'     => array(
				'nombre'      => __( 'Símil madera', 'cj-aluminios-b2b' ),
				'descripcion' => __( 'La calidez visual de la madera con la estabilidad del aluminio: no se hincha, no se pudre y no necesita barniz.', 'cj-aluminios-b2b' ),
				'ficha'       => array(
					__( 'Proceso', 'cj-aluminios-b2b' )       => __( 'Base de pintura en polvo y transferencia de la veta por sublimación.', 'cj-aluminios-b2b' ),
					__( 'Durabilidad', 'cj-aluminios-b2b' )   => __( 'Buena resistencia a la intemperie y a los rayos UV. La veta queda dentro de la capa de pintura.', 'cj-aluminios-b2b' ),
					__( 'Terminaciones', 'cj-aluminios-b2b' ) => __( 'Veteado mate; el dibujo de la veta varía de barra a barra, como en la madera real.', 'cj-aluminios-b2b' ),
					__( 'Mantenimiento', 'cj-aluminios-b2b' ) => __( 'Agua con detergente neutro y paño suave. Sin lijas, solventes ni lustramuebles.', 'cj-aluminios-b2b' ),
				),
			),
		);
	}

	/**
	 * Etiquetas de terminación superficial.
	 */
	public static function terminaciones(): array {
		return array(
			'brillante' => __( 'Brillante', 'cj-aluminios-b2b' ),
			'semimate'  => __( 'Semimate', 'cj-aluminios-b2b' ),
			'microtex'  => __( 'Microtexturado', 'cj-aluminios-b2b' ),
			'anodizado' => __( 'Metálico satinado', 'cj-aluminios-b2b' ),
			'veteado'   => __( 'Veteado mate', 'cj-aluminios-b2b' ),
		);
	}

	/**
	 * Paleta completa: slug => datos.
	 *
	 * - hex:         color base.
	 * - veta:        color de la veta (solo símil madera).
	 * - terminacion: clave de terminaciones().
	 * - acabado:     clave de CJB2B_Admin_Settings::acabados() con la que se
	 *                cotiza ('' = sin equivalencia directa, se consulta).
	 */
	public static function tratamientos(): array {
		$p = static function ( string $nombre, string $hex, string $terminacion, string $acabado = '' ): array {
			return array(
				'nombre'      => $nombre,
				'familia'     => 'pintados',
				'hex'         => $hex,
				'veta'        => '',
				'terminacion' => $terminacion,
				'acabado'     => $acabado,
			);
		};

		$a = static function ( string $nombre, string $hex, string $acabado = '' ): array {
			return array(
				'nombre'      => $nombre,
				'familia'     => 'anodizados',
				'hex'         => $hex,
				'veta'        => '',
				'terminacion' => 'anodizado',
				'acabado'     => $acabado,
			);
		};

		$m = static function ( string $nombre, string $hex, string $veta ): array {
			return array(
				'nombre'      => $nombre,
				'familia'     => 'madera',
				'hex'         => $hex,
				'veta'        => $veta,
				'terminacion' => 'veteado',
				'acabado'     => 'simil-madera',
			);
		};

		return array(
			// Pintados estándar.
			'blanco'                  => $p( 'Blanco', '#F4F4F0', 'brillante', 'blanco' ),
			'negro'                   => $p( 'Negro', '#1B1B1D', 'semimate', 'negro' ),
			'negro-microtex'          => $p( 'Negro Microtex', '#232325', 'microtex', 'negro' ),
			'gris-oscuro-brillante'   => $p( 'Gris Oscuro Brillante', '#3A4045', 'brillante' ),
			'gris-oscuro-microtex'    => $p( 'Gris Oscuro Microtex', '#3D4246', 'microtex' ),
			'gris-oscuro-semimate'    => $p( 'Gris Oscuro S/Mate', '#42474B', 'semimate' ),
			'gris-medio'              => $p( 'Gris Medio', '#7D8286', 'semimate' ),
			'gris-perla'              => $p( 'Gris Perla', '#B6B9B3', 'semimate' ),
			'beige-tobago'            => $p( 'Beige Tobago', '#C9B79C', 'semimate' ),
			'bronce'                  => $p( 'Bronce', '#5B4733', 'semimate', 'bronce' ),
			'marron'                  => $p( 'Marrón', '#46332A', 'semimate' ),
			'marron-especial'         => $p( 'Marrón Especial', '#5E4336', 'semimate' ),
			'verde-ingles'            => $p( 'Verde Inglés', '#22382B', 'semimate' ),
			'verde-noche'             => $p( 'Verde Noche', '#17271F', 'semimate' ),

			// Anodizados.
			'anodizado-natural'       => $a( 'Natural', '#C6C9CC' ),
			'anodizado-champagne'     => $a( 'Champagne', '#D3C3A2', 'anodizado-champagne' ),
			'anodizado-bronce-claro'  => $a( 'Bronce Claro', '#A18662' ),
			'anodizado-bronce-oscuro' => $a( 'Bronce Oscuro', '#5F4B37' ),
			'anodizado-peltre'        => $a( 'Peltre', '#84847E', 'anodizado-peltre' ),
			'anodizado-negro'         => $a( 'Negro Anodizado', '#2B2B2D', 'anodizado-negro' ),

			// Símil madera.
			'roble-claro-e7'          => $m( 'Roble Claro E-7', '#C49A62', '#98703D' ),
			'guindo-d7'               => $m( 'Guindo D-7', '#7C402B', '#54261A' ),
			'nogal-b7'                => $m( 'Nogal B-7', '#6C4B2F', '#442B18' ),
			'douglas-g8'              => $m( 'Douglas G-8', '#B8773F', '#884E22' ),
			'cedro-c8'                => $m( 'Cedro C-8', '#A75F33', '#793E1C' ),
			'cedro-c7'                => $m( 'Cedro C-7', '#95512C', '#693418' ),
			'cerezo-g7'               => $m( 'Cerezo G-7', '#9D5B3D', '#6D3622' ),
		);
	}

	public static function tratamiento( string $slug ): ?array {
		$todos = self::tratamientos();
		return $todos[ $slug ] ?? null;
	}

	/** Tratamiento por defecto del simulador. */
	public static function slug_default(): string {
		return 'blanco';
	}

	/** Tratamientos agrupados por familia: familia => [ slug => datos ]. */
	public static function por_familia(): array {
		$grupos = array_fill_keys( array_keys( self::familias() ), array() );

		foreach ( self::tratamientos() as $slug => $datos ) {
			$grupos[ $datos['familia'] ][ $slug ] = $datos;
		}

		return $grupos;
	}

	/** "Símil madera — Nogal B-7" (para mensajes y resúmenes). */
	public static function nombre_completo( string $slug ): string {
		$datos = self::tratamiento( $slug );

		if ( ! $datos ) {
			return '';
		}

		return self::familias()[ $datos['familia'] ]['nombre'] . ' — ' . $datos['nombre'];
	}

	/** Paleta lista para pasar a JavaScript (simulador). */
	public static function para_js(): array {
		$salida = array();

		foreach ( self::tratamientos() as $slug => $datos ) {
			$salida[ $slug ] = array(
				'nombre'      => $datos['nombre'],
				'familia'     => $datos['familia'],
				'hex'         => $datos['hex'],
				'veta'        => $datos['veta'],
				'terminacion' => $datos['terminacion'],
			);
		}

		return $salida;
	}

	/* ------------------------------------------------- Muestra en CSS puro */

	/**
	 * Aclara (factor > 0) u oscurece (factor < 0) un color HEX. Factor en -1..1.
	 */
	public static function ajustar_color( string $hex, float $factor ): string {
		$hex = ltrim( $hex, '#' );

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#888888';
		}

		$salida = '#';

		foreach ( str_split( $hex, 2 ) as $canal ) {
			$valor   = hexdec( $canal );
			$valor   = $factor >= 0 ? $valor + ( 255 - $valor ) * $factor : $valor * ( 1 + $factor );
			$salida .= str_pad( dechex( (int) max( 0, min( 255, round( $valor ) ) ) ), 2, '0', STR_PAD_LEFT );
		}

		return $salida;
	}

	/**
	 * Declaraciones CSS (background) que dibujan la muestra de un tratamiento
	 * sin imágenes: brillo para pintados, degradado metálico para anodizados
	 * y veta repetible para símil madera. Solo usa valores de la paleta.
	 */
	public static function estilo_muestra( array $datos ): string {
		$hex = $datos['hex'];

		if ( 'madera' === $datos['familia'] ) {
			$veta  = '' !== $datos['veta'] ? $datos['veta'] : self::ajustar_color( $hex, -0.3 );
			$clara = self::ajustar_color( $hex, 0.14 );

			return 'background-color:' . $hex . ';background-image:'
				. 'repeating-linear-gradient(91deg,' . $veta . 'cc 0 1px,transparent 1px 7px),'
				. 'repeating-linear-gradient(88.5deg,' . $veta . '80 0 2px,transparent 2px 19px),'
				. 'repeating-linear-gradient(92deg,' . $clara . '66 0 3px,transparent 3px 31px),'
				. 'linear-gradient(90deg,' . $hex . ',' . self::ajustar_color( $hex, -0.12 ) . ' 45%,' . $clara . ' 70%,' . $hex . ');';
		}

		if ( 'anodizados' === $datos['familia'] ) {
			return 'background-color:' . $hex . ';background-image:'
				. 'repeating-linear-gradient(90deg,rgba(255,255,255,.07) 0 1px,transparent 1px 3px),'
				. 'linear-gradient(105deg,'
				. self::ajustar_color( $hex, -0.22 ) . ' 0%,'
				. self::ajustar_color( $hex, 0.30 ) . ' 24%,'
				. $hex . ' 44%,'
				. self::ajustar_color( $hex, 0.18 ) . ' 62%,'
				. self::ajustar_color( $hex, -0.26 ) . ' 84%,'
				. $hex . ' 100%);';
		}

		$brillo = array(
			'brillante' => '.42',
			'semimate'  => '.16',
			'microtex'  => '.07',
		);
		$alfa   = $brillo[ $datos['terminacion'] ] ?? '.16';
		$capas  = 'linear-gradient(135deg,rgba(255,255,255,' . $alfa . ') 0%,rgba(255,255,255,0) 48%,rgba(0,0,0,.16) 100%)';
		$tamano = '';

		if ( 'microtex' === $datos['terminacion'] ) {
			$capas  = 'radial-gradient(rgba(255,255,255,.13) 0.8px,transparent 1px),' . $capas;
			$tamano = 'background-size:4px 4px,100% 100%;';
		}

		return 'background-color:' . $hex . ';background-image:' . $capas . ';' . $tamano;
	}

	/* ---------------------------------------------------------- Shortcode */

	public function render_shortcode( $atts = array() ): string {
		$url_simulador = class_exists( 'CJB2B_Institutional_Pages' ) ? CJB2B_Institutional_Pages::url( 'simulador' ) : '';
		$terminaciones = self::terminaciones();
		$grupos        = self::por_familia();

		ob_start();
		?>
		<div class="cj-b2b cj-muestrario alignwide">
			<p class="cj-muestrario__intro">
				<?php esc_html_e( 'Todos los perfiles de CJ Aluminios pueden pedirse en cualquiera de estos tratamientos. Los colores en pantalla son orientativos: pedinos la muestra física antes de definir una obra.', 'cj-aluminios-b2b' ); ?>
			</p>

			<nav class="cj-nav" aria-label="<?php esc_attr_e( 'Familias de tratamiento', 'cj-aluminios-b2b' ); ?>">
				<?php foreach ( self::familias() as $clave => $familia ) : ?>
					<a class="cj-chip" href="#cj-familia-<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $familia['nombre'] ); ?> (<?php echo esc_html( (string) count( $grupos[ $clave ] ) ); ?>)</a>
				<?php endforeach; ?>
			</nav>

			<?php foreach ( self::familias() as $clave => $familia ) : ?>
				<section class="cj-familia" id="cj-familia-<?php echo esc_attr( $clave ); ?>">
					<h2 class="cj-familia__titulo"><?php echo esc_html( $familia['nombre'] ); ?></h2>
					<p class="cj-familia__desc"><?php echo esc_html( $familia['descripcion'] ); ?></p>

					<ul class="cj-muestras">
						<?php foreach ( $grupos[ $clave ] as $slug => $datos ) : ?>
							<li class="cj-muestra">
								<span class="cj-muestra__color" style="<?php echo esc_attr( self::estilo_muestra( $datos ) ); ?>" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: nombre del color */ __( 'Muestra de color %s', 'cj-aluminios-b2b' ), $datos['nombre'] ) ); ?>"></span>
								<span class="cj-muestra__cuerpo">
									<strong class="cj-muestra__nombre"><?php echo esc_html( $datos['nombre'] ); ?></strong>
									<span class="cj-muestra__meta">
										<?php echo esc_html( $terminaciones[ $datos['terminacion'] ] ?? '' ); ?>
										· <span class="cj-code"><?php echo esc_html( strtoupper( $datos['hex'] ) ); ?></span>
									</span>
									<?php if ( '' !== $url_simulador ) : ?>
										<a class="cj-btn cj-btn--ghost cj-muestra__btn" href="<?php echo esc_url( add_query_arg( 'tratamiento', $slug, $url_simulador ) ); ?>">
											<?php esc_html_e( 'Probar este color en el Simulador', 'cj-aluminios-b2b' ); ?>
										</a>
									<?php endif; ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>

					<div class="cj-desglose cj-ficha">
						<p class="cj-desglose__titulo"><?php esc_html_e( 'Ficha técnica', 'cj-aluminios-b2b' ); ?> — <?php echo esc_html( $familia['nombre'] ); ?></p>
						<table>
							<tbody>
								<?php foreach ( $familia['ficha'] as $etiqueta => $texto ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $etiqueta ); ?></th>
										<td><?php echo esc_html( $texto ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
