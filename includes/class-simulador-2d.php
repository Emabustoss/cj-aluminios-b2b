<?php
/**
 * Fase 3: simulador visual 2D de aberturas sobre una foto de ambiente.
 *
 * Shortcode [cj_simulador_2d]. Todo el dibujo corre en el navegador (Canvas,
 * assets/js/cj-simulador-2d.js): no usa IA ni servicios pagos. La foto de
 * obra del cliente se abre, se valida y se reduce en el navegador: nunca se
 * sube al servidor. El servidor solo arma el enlace de WhatsApp de la
 * cotización (honeypot + rate limiting).
 *
 * La paleta sale de CJB2B_Tratamientos_Catalog (única fuente).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CJB2B_Simulador_2D {

	private static ?self $instance = null;

	const SHORTCODE      = 'cj_simulador_2d';
	const NONCE          = 'cjb2b_simulador';
	const ACCION_COTIZAR = 'cjb2b_sim_cotizar';
	const SCRIPT_HANDLE  = 'cj-simulador-2d';

	// Tope de la foto de obra (se controla en el navegador; no se sube).
	const FOTO_MAX_BYTES = 15 * MB_IN_BYTES;

	const LIMITE_COTIZAR    = 5;
	const LIMITE_VENTANA    = 10 * MINUTE_IN_SECONDS;
	const MEDIDA_MIN_CM     = 20;
	const MEDIDA_MAX_CM     = 600;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'registrar_script' ) );

		add_action( 'wp_ajax_' . self::ACCION_COTIZAR, array( $this, 'ajax_cotizar' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACCION_COTIZAR, array( $this, 'ajax_cotizar' ) );
	}

	/* -------------------------------------------------------------- Datos */

	/**
	 * Tipologías: medidas por defecto en cm y cómo se dibujan.
	 */
	public static function tipologias(): array {
		$t = static function ( string $grupo, string $nombre, string $dibujo, int $ancho, int $alto, int $hojas = 1 ): array {
			return array(
				'grupo'  => $grupo,
				'nombre' => $nombre,
				'dibujo' => $dibujo,
				'hojas'  => $hojas,
				'ancho'  => $ancho,
				'alto'   => $alto,
			);
		};

		return array(
			// Corredizas.
			'corrediza-2h'         => $t( 'corredizas', __( 'Ventana corrediza 2 hojas', 'cj-aluminios-b2b' ), 'corrediza', 150, 110, 2 ),
			'corrediza-3h'         => $t( 'corredizas', __( 'Ventana corrediza 3 hojas', 'cj-aluminios-b2b' ), 'corrediza', 240, 120, 3 ),
			'corrediza-4h'         => $t( 'corredizas', __( 'Ventana corrediza 4 hojas', 'cj-aluminios-b2b' ), 'corrediza', 300, 130, 4 ),
			'corrediza-mosquitera' => $t( 'corredizas', __( 'Corrediza con hoja mosquitera', 'cj-aluminios-b2b' ), 'mosquitera', 150, 110, 2 ),
			'puerta-balcon'        => $t( 'corredizas', __( 'Puerta balcón corrediza', 'cj-aluminios-b2b' ), 'balcon', 180, 205, 2 ),

			// De abrir / proyectantes.
			'abrir'                => $t( 'abrir', __( 'Ventana de abrir / oscilobatiente', 'cj-aluminios-b2b' ), 'abrir', 80, 120 ),
			'banderola'            => $t( 'abrir', __( 'Banderola / proyectante', 'cj-aluminios-b2b' ), 'banderola', 100, 50 ),
			'vidrio-repartido'     => $t( 'abrir', __( 'Ventana con vidrio repartido', 'cj-aluminios-b2b' ), 'repartido', 120, 120, 2 ),

			// Puertas y fijos.
			'pano-fijo'            => $t( 'puertas', __( 'Paño fijo', 'cj-aluminios-b2b' ), 'fijo', 120, 150 ),
			'puerta-rebatir'       => $t( 'puertas', __( 'Puerta de rebatir (1 hoja c/ zócalo)', 'cj-aluminios-b2b' ), 'puerta', 90, 205 ),
			'fijo-banderola'       => $t( 'puertas', __( 'Paño fijo + banderola superior', 'cj-aluminios-b2b' ), 'fijo-banderola', 120, 200 ),
		);
	}

	/** Grupos del selector de tipologías (optgroup): clave => etiqueta. */
	public static function grupos(): array {
		return array(
			'corredizas' => __( 'Corredizas', 'cj-aluminios-b2b' ),
			'abrir'      => __( 'De abrir / proyectantes', 'cj-aluminios-b2b' ),
			'puertas'    => __( 'Puertas y fijos', 'cj-aluminios-b2b' ),
		);
	}

	/**
	 * Líneas: cómo cambia el dibujo según la línea. 'marco' y 'hoja' son el
	 * ancho visto de frente (cm) de cada perfil; es una representación
	 * proporcional para el simulador, no una cota de fabricación.
	 */
	public static function lineas(): array {
		return array(
			'herrero' => array(
				'nombre'  => __( 'Herrero', 'cj-aluminios-b2b' ),
				'detalle' => __( 'Perfil liviano y recto, uniones a 90°.', 'cj-aluminios-b2b' ),
				'marco'   => 3.2,
				'hoja'    => 4.2,
				'inglete' => false,
				'perfil'  => 'recto',
			),
			'modena'  => array(
				'nombre'  => __( 'Modena', 'cj-aluminios-b2b' ),
				'detalle' => __( 'Perfil de líneas curvas, uniones a 45°.', 'cj-aluminios-b2b' ),
				'marco'   => 4.6,
				'hoja'    => 6.2,
				'inglete' => true,
				'perfil'  => 'curvo',
			),
			'a30-new' => array(
				'nombre'  => __( 'A30 New', 'cj-aluminios-b2b' ),
				'detalle' => __( 'Perfil robusto de alta prestación, uniones a 45°.', 'cj-aluminios-b2b' ),
				'marco'   => 5.8,
				'hoja'    => 7.8,
				'inglete' => true,
				'perfil'  => 'escalonado',
			),
		);
	}

	/**
	 * Ambientes de muestra (ilustraciones SVG del plugin). 'zona' es dónde
	 * arranca la abertura: [x, y, ancho] como fracción del lienzo.
	 */
	public static function ambientes(): array {
		$url = static function ( string $archivo ): string {
			$ruta = 'assets/img/ambientes/' . $archivo;
			return add_query_arg( 'ver', CJB2B_Frontend::version_archivo( CJB2B_PATH . $ruta ), CJB2B_URL . $ruta );
		};

		return array(
			'living'  => array(
				'nombre' => __( 'Living moderno', 'cj-aluminios-b2b' ),
				'url'    => $url( 'living-moderno.svg' ),
				'zona'   => array( 0.33, 0.14, 0.34 ),
			),
			'galeria' => array(
				'nombre' => __( 'Galería / quincho', 'cj-aluminios-b2b' ),
				'url'    => $url( 'galeria-quincho.svg' ),
				'zona'   => array( 0.36, 0.26, 0.30 ),
			),
			'frente'  => array(
				'nombre' => __( 'Frente de obra', 'cj-aluminios-b2b' ),
				'url'    => $url( 'frente-obra.svg' ),
				'zona'   => array( 0.30, 0.30, 0.32 ),
			),
		);
	}

	/* ------------------------------------------------------------- Assets */

	public function registrar_script(): void {
		$archivo = CJB2B_PATH . 'assets/js/cj-simulador-2d.js';

		wp_register_script(
			self::SCRIPT_HANDLE,
			CJB2B_URL . 'assets/js/cj-simulador-2d.js',
			array(),
			CJB2B_Frontend::version_archivo( $archivo ),
			true
		);
	}

	private function localizar_script( string $tratamiento ): void {
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'cjb2bSimulador',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE ),
				'accionCotizar' => self::ACCION_COTIZAR,
				'maxBytes'      => self::FOTO_MAX_BYTES,
				'medidaMin'     => self::MEDIDA_MIN_CM,
				'medidaMax'     => self::MEDIDA_MAX_CM,
				'tratamientos'  => CJB2B_Tratamientos_Catalog::para_js(),
				'tipologias'    => self::tipologias(),
				'lineas'        => self::lineas(),
				'ambientes'     => self::ambientes(),
				'inicial'       => array(
					'tratamiento' => $tratamiento,
					'ambiente'    => 'living',
				),
				'textos'        => array(
					'fotoTipo'     => __( 'Ese archivo no es una imagen. Elegí una foto JPG, PNG o WebP.', 'cj-aluminios-b2b' ),
					'fotoPeso'     => __( 'La foto supera los 15 MB. Probá con una más liviana.', 'cj-aluminios-b2b' ),
					'fotoSubiendo' => __( 'Procesando foto…', 'cj-aluminios-b2b' ),
					'fotoLista'    => __( 'Foto cargada: arrastrá las esquinas para ajustar la abertura.', 'cj-aluminios-b2b' ),
					'fotoError'    => __( 'No se pudo abrir la foto. Probá con otra imagen JPG o PNG.', 'cj-aluminios-b2b' ),
					'fotoHeic'     => __( 'Este formato de foto (HEIC) no es compatible directamente. Sacá una captura de pantalla de la foto o elegí una imagen JPG/PNG.', 'cj-aluminios-b2b' ),
					'fotoBoton'    => __( 'Elegir foto de tu obra', 'cj-aluminios-b2b' ),
					'fotoCambiar'  => __( 'Cambiar foto', 'cj-aluminios-b2b' ),
					'ambiente'     => __( 'Ambiente de muestra cargado.', 'cj-aluminios-b2b' ),
					'enviando'     => __( 'Armando la cotización…', 'cj-aluminios-b2b' ),
					'error'        => __( 'No se pudo completar la operación. Probá de nuevo en unos segundos.', 'cj-aluminios-b2b' ),
					'archivo'      => __( 'simulador-cj-aluminios', 'cj-aluminios-b2b' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------- Shortcode */

	public function render_shortcode( $atts = array() ): string {
		// Estado inicial desde la URL (solo lectura: no requiere nonce).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tratamiento = isset( $_GET['tratamiento'] ) ? sanitize_title( wp_unslash( $_GET['tratamiento'] ) ) : '';

		if ( null === CJB2B_Tratamientos_Catalog::tratamiento( $tratamiento ) ) {
			$tratamiento = CJB2B_Tratamientos_Catalog::slug_default();
		}

		// En temas de bloques el contenido se arma antes de wp_enqueue_scripts:
		// el script tiene que estar registrado para poder pasarle los datos.
		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			$this->registrar_script();
		}

		wp_enqueue_script( self::SCRIPT_HANDLE );
		$this->localizar_script( $tratamiento );

		$tipologias = self::tipologias();
		$lineas     = self::lineas();
		$primera    = reset( $tipologias );
		$actual     = CJB2B_Tratamientos_Catalog::tratamiento( $tratamiento );
		$nombre     = '';
		$telefono   = '';

		if ( is_user_logged_in() ) {
			$usuario  = wp_get_current_user();
			$empresa  = (string) get_user_meta( $usuario->ID, 'billing_company', true );
			$nombre   = '' !== $empresa ? $empresa : $usuario->display_name;
			$telefono = (string) get_user_meta( $usuario->ID, 'billing_phone', true );
		}

		ob_start();
		?>
		<div class="cj-b2b cj-sim alignwide" data-cj-sim>
			<div class="cj-sim__lienzo">
				<div class="cj-sim__marco">
					<canvas class="cj-sim__canvas" data-cj-sim-canvas width="1200" height="800" tabindex="0" role="img"
						aria-label="<?php esc_attr_e( 'Vista previa de la abertura sobre la foto. Con el foco acá, las flechas del teclado mueven la abertura.', 'cj-aluminios-b2b' ); ?>"></canvas>
				</div>
				<p class="cj-sim__ayuda" data-cj-sim-estado role="status" aria-live="polite">
					<?php esc_html_e( 'Arrastrá la abertura para ubicarla y usá los tiradores de las esquinas para ajustarla.', 'cj-aluminios-b2b' ); ?>
				</p>

				<div class="cj-sim__acciones">
					<button type="button" class="cj-btn cj-btn--ghost" data-cj-sim-descargar><?php esc_html_e( 'Descargar Vista Previa (.png)', 'cj-aluminios-b2b' ); ?></button>
					<button type="button" class="cj-btn cj-btn--whatsapp" data-cj-sim-abrir-cotizar aria-expanded="false" aria-controls="cj-sim-cotizar"><?php esc_html_e( 'Cotizar esta Abertura por WhatsApp', 'cj-aluminios-b2b' ); ?></button>
				</div>

				<div class="cj-wa cj-sim__cotizar" id="cj-sim-cotizar" data-cj-sim-cotizar hidden>
					<form data-cj-sim-form novalidate>
						<p class="cj-wa__titulo"><?php esc_html_e( 'Cotizar esta abertura', 'cj-aluminios-b2b' ); ?></p>
						<p class="cj-wa__intro" data-cj-sim-resumen></p>

						<p class="cj-campo">
							<label for="cj-sim-nombre"><?php esc_html_e( 'Nombre / Empresa', 'cj-aluminios-b2b' ); ?> *</label>
							<input type="text" id="cj-sim-nombre" name="cj_nombre" value="<?php echo esc_attr( $nombre ); ?>" maxlength="100" autocomplete="name" required />
						</p>
						<p class="cj-campo">
							<label for="cj-sim-telefono"><?php esc_html_e( 'Teléfono', 'cj-aluminios-b2b' ); ?> *</label>
							<input type="tel" id="cj-sim-telefono" name="cj_telefono" value="<?php echo esc_attr( $telefono ); ?>" maxlength="30" autocomplete="tel" inputmode="tel" required />
						</p>
						<p class="cj-campo">
							<label for="cj-sim-observaciones"><?php esc_html_e( 'Observaciones (opcional)', 'cj-aluminios-b2b' ); ?></label>
							<textarea id="cj-sim-observaciones" name="cj_observaciones" maxlength="300" rows="2"></textarea>
						</p>

						<?php // Honeypot: las personas no lo ven ni lo completan; los bots sí. ?>
						<p class="cj-hp" aria-hidden="true">
							<label for="cj-sim-website">Website</label>
							<input type="text" id="cj-sim-website" name="cj_website" value="" tabindex="-1" autocomplete="off" />
						</p>

						<p class="cj-aviso cj-aviso--error" data-cj-sim-error role="alert" hidden></p>

						<button type="submit" class="cj-btn cj-btn--whatsapp" data-cj-sim-enviar><?php esc_html_e( 'Enviar cotización por WhatsApp', 'cj-aluminios-b2b' ); ?></button>
						<p class="cj-wa__legal"><?php esc_html_e( 'Descargá la vista previa y adjuntala en el chat: nos ayuda a cotizar más rápido. Medidas aproximadas, a confirmar en obra.', 'cj-aluminios-b2b' ); ?></p>
					</form>

					<div class="cj-wa__exito" data-cj-sim-exito hidden>
						<p class="cj-wa__titulo" data-cj-sim-exito-titulo tabindex="-1"><?php esc_html_e( 'Cotización lista', 'cj-aluminios-b2b' ); ?></p>
						<p><?php esc_html_e( 'Falta un paso: enviá el mensaje por WhatsApp y adjuntá la vista previa descargada.', 'cj-aluminios-b2b' ); ?></p>
						<a class="cj-btn cj-btn--whatsapp" data-cj-sim-link href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir WhatsApp y enviar', 'cj-aluminios-b2b' ); ?></a>
					</div>
				</div>
			</div>

			<div class="cj-sim__panel">
				<fieldset class="cj-sim__grupo">
					<legend><span class="cj-sim__paso">1</span> <?php esc_html_e( 'Ambiente', 'cj-aluminios-b2b' ); ?></legend>
					<div class="cj-sim__ambientes">
						<?php foreach ( self::ambientes() as $clave => $ambiente ) : ?>
							<button type="button" class="cj-sim__ambiente" data-cj-sim-ambiente="<?php echo esc_attr( $clave ); ?>" aria-pressed="<?php echo 'living' === $clave ? 'true' : 'false'; ?>">
								<img src="<?php echo esc_url( $ambiente['url'] ); ?>" alt="" width="120" height="80" loading="lazy" />
								<span><?php echo esc_html( $ambiente['nombre'] ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
					<div class="cj-campo cj-sim__subir">
						<span class="cj-sim__label"><?php esc_html_e( 'O usá una foto de tu obra', 'cj-aluminios-b2b' ); ?></span>
						<?php
						// El input va DENTRO del label: el toque abre el selector nativo
						// sin JS de por medio (clave en iOS y en el Photo Picker de
						// Android). Sin "capture" para que ofrezca galería y cámara.
						?>
						<label class="cj-btn cj-btn--ghost cj-sim__foto-boton">
							<input type="file" class="cj-sim__foto-input" data-cj-sim-foto accept="image/*" />
							<span data-cj-sim-foto-texto><?php esc_html_e( 'Elegir foto de tu obra', 'cj-aluminios-b2b' ); ?></span>
						</label>
						<span class="cj-sim__nota"><?php esc_html_e( 'JPG, PNG o WebP, hasta 15 MB. La foto se procesa en tu dispositivo: no se sube ni se guarda.', 'cj-aluminios-b2b' ); ?></span>
					</div>
				</fieldset>

				<fieldset class="cj-sim__grupo">
					<legend><span class="cj-sim__paso">2</span> <?php esc_html_e( 'Abertura', 'cj-aluminios-b2b' ); ?></legend>
					<p class="cj-campo">
						<label for="cj-sim-tipologia"><?php esc_html_e( 'Tipología', 'cj-aluminios-b2b' ); ?></label>
						<select id="cj-sim-tipologia" data-cj-sim-tipologia>
							<?php foreach ( self::grupos() as $grupo => $etiqueta ) : ?>
								<optgroup label="<?php echo esc_attr( $etiqueta ); ?>">
									<?php foreach ( $tipologias as $clave => $tipologia ) : ?>
										<?php if ( $grupo === $tipologia['grupo'] ) : ?>
											<option value="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $tipologia['nombre'] ); ?></option>
										<?php endif; ?>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
					</p>

					<div class="cj-campo" role="group" aria-labelledby="cj-sim-linea-label">
						<span class="cj-sim__label" id="cj-sim-linea-label"><?php esc_html_e( 'Línea', 'cj-aluminios-b2b' ); ?></span>
						<div class="cj-sim__lineas">
							<?php $i = 0; ?>
							<?php foreach ( $lineas as $clave => $linea ) : ?>
								<button type="button" class="cj-chip<?php echo 1 === $i ? ' is-active' : ''; ?>" data-cj-sim-linea="<?php echo esc_attr( $clave ); ?>" aria-pressed="<?php echo 1 === $i ? 'true' : 'false'; ?>"><?php echo esc_html( $linea['nombre'] ); ?></button>
								<?php ++$i; ?>
							<?php endforeach; ?>
						</div>
						<span class="cj-sim__nota cj-sim__nota--fuerte" data-cj-sim-linea-detalle><?php echo esc_html( $lineas['modena']['detalle'] ); ?></span>
					</div>

					<div class="cj-sim__medidas">
						<p class="cj-campo">
							<label for="cj-sim-ancho"><?php esc_html_e( 'Ancho aprox. (cm)', 'cj-aluminios-b2b' ); ?></label>
							<input type="number" id="cj-sim-ancho" data-cj-sim-ancho min="<?php echo esc_attr( (string) self::MEDIDA_MIN_CM ); ?>" max="<?php echo esc_attr( (string) self::MEDIDA_MAX_CM ); ?>" step="1" inputmode="numeric" value="<?php echo esc_attr( (string) $primera['ancho'] ); ?>" />
						</p>
						<p class="cj-campo">
							<label for="cj-sim-alto"><?php esc_html_e( 'Alto aprox. (cm)', 'cj-aluminios-b2b' ); ?></label>
							<input type="number" id="cj-sim-alto" data-cj-sim-alto min="<?php echo esc_attr( (string) self::MEDIDA_MIN_CM ); ?>" max="<?php echo esc_attr( (string) self::MEDIDA_MAX_CM ); ?>" step="1" inputmode="numeric" value="<?php echo esc_attr( (string) $primera['alto'] ); ?>" />
						</p>
					</div>
				</fieldset>

				<fieldset class="cj-sim__grupo">
					<legend><span class="cj-sim__paso">3</span> <?php esc_html_e( 'Tratamiento / color', 'cj-aluminios-b2b' ); ?></legend>
					<p class="cj-sim__elegido">
						<span class="cj-muted"><?php esc_html_e( 'Elegido:', 'cj-aluminios-b2b' ); ?></span>
						<strong data-cj-sim-tratamiento-nombre><?php echo esc_html( CJB2B_Tratamientos_Catalog::nombre_completo( $tratamiento ) ); ?></strong>
					</p>
					<?php foreach ( CJB2B_Tratamientos_Catalog::por_familia() as $clave => $grupo ) : ?>
						<div class="cj-sim__familia" role="group" aria-label="<?php echo esc_attr( CJB2B_Tratamientos_Catalog::familias()[ $clave ]['nombre'] ); ?>">
							<span class="cj-sim__label"><?php echo esc_html( CJB2B_Tratamientos_Catalog::familias()[ $clave ]['nombre'] ); ?></span>
							<div class="cj-sim__colores">
								<?php foreach ( $grupo as $slug => $datos ) : ?>
									<button type="button" class="cj-sim__color<?php echo $slug === $tratamiento ? ' is-active' : ''; ?>"
										data-cj-sim-tratamiento="<?php echo esc_attr( $slug ); ?>"
										data-nombre="<?php echo esc_attr( CJB2B_Tratamientos_Catalog::nombre_completo( $slug ) ); ?>"
										aria-pressed="<?php echo $slug === $tratamiento ? 'true' : 'false'; ?>"
										title="<?php echo esc_attr( $datos['nombre'] ); ?>"
										aria-label="<?php echo esc_attr( $datos['nombre'] ); ?>"
										style="<?php echo esc_attr( CJB2B_Tratamientos_Catalog::estilo_muestra( $datos ) ); ?>"></button>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</fieldset>

				<fieldset class="cj-sim__grupo">
					<legend><span class="cj-sim__paso">4</span> <?php esc_html_e( 'Ajuste sobre la foto', 'cj-aluminios-b2b' ); ?></legend>
					<div class="cj-opciones cj-sim__modos">
						<label class="cj-opcion">
							<input type="radio" name="cj_sim_modo" value="tamano" data-cj-sim-modo checked />
							<?php esc_html_e( 'Mover y redimensionar', 'cj-aluminios-b2b' ); ?>
						</label>
						<label class="cj-opcion">
							<input type="radio" name="cj_sim_modo" value="perspectiva" data-cj-sim-modo />
							<?php esc_html_e( 'Perspectiva (4 esquinas)', 'cj-aluminios-b2b' ); ?>
						</label>
					</div>
					<span class="cj-sim__nota"><?php esc_html_e( 'En "Perspectiva" cada esquina se mueve sola, para calzar la abertura en paredes en ángulo.', 'cj-aluminios-b2b' ); ?></span>

					<p class="cj-campo cj-sim__rango">
						<label for="cj-sim-vidrio"><?php esc_html_e( 'Transparencia del vidrio', 'cj-aluminios-b2b' ); ?></label>
						<input type="range" id="cj-sim-vidrio" data-cj-sim-vidrio min="0" max="100" step="5" value="60" />
					</p>

					<p class="cj-campo cj-sim__rango">
						<label for="cj-sim-luz"><?php esc_html_e( 'Luz / contraste de ambiente', 'cj-aluminios-b2b' ); ?></label>
						<input type="range" id="cj-sim-luz" data-cj-sim-luz min="0" max="100" step="5" value="50" aria-describedby="cj-sim-luz-nota" />
						<span class="cj-sim__nota" id="cj-sim-luz-nota"><?php esc_html_e( 'A la izquierda, interior oscuro; a la derecha, fachada a pleno sol.', 'cj-aluminios-b2b' ); ?></span>
					</p>

					<button type="button" class="cj-btn cj-btn--ghost" data-cj-sim-reset><?php esc_html_e( 'Enderezar y centrar', 'cj-aluminios-b2b' ); ?></button>
				</fieldset>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* --------------------------------------------------------- Cotización */

	public function ajax_cotizar(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$error = __( 'No se pudo armar la cotización.', 'cj-aluminios-b2b' );

		// Honeypot: si viene completo es un bot. Respuesta genérica, sin pistas.
		if ( ! empty( $_POST['cj_website'] ) ) {
			wp_send_json_error( array( 'mensaje' => $error ), 400 );
		}

		if ( CJB2B_Frontend::limite_superado( 'simcot', self::LIMITE_COTIZAR ) ) {
			wp_send_json_error( array( 'mensaje' => __( 'Enviaste demasiadas cotizaciones seguidas. Esperá unos minutos y probá de nuevo.', 'cj-aluminios-b2b' ) ), 429 );
		}

		$tipologia     = isset( $_POST['tipologia'] ) ? sanitize_title( wp_unslash( $_POST['tipologia'] ) ) : '';
		$linea         = isset( $_POST['linea'] ) ? sanitize_title( wp_unslash( $_POST['linea'] ) ) : '';
		$tratamiento   = isset( $_POST['tratamiento'] ) ? sanitize_title( wp_unslash( $_POST['tratamiento'] ) ) : '';
		$ancho         = isset( $_POST['ancho'] ) ? absint( wp_unslash( $_POST['ancho'] ) ) : 0;
		$alto          = isset( $_POST['alto'] ) ? absint( wp_unslash( $_POST['alto'] ) ) : 0;
		$nombre        = isset( $_POST['cj_nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['cj_nombre'] ) ) : '';
		$telefono      = isset( $_POST['cj_telefono'] ) ? sanitize_text_field( wp_unslash( $_POST['cj_telefono'] ) ) : '';
		$observaciones = isset( $_POST['cj_observaciones'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cj_observaciones'] ) ) : '';

		$nombre        = CJB2B_Frontend::recortar( $nombre, 100 );
		$telefono      = CJB2B_Frontend::recortar( (string) preg_replace( '/[^0-9+()\-\s]/', '', $telefono ), 30 );
		$observaciones = CJB2B_Frontend::recortar( $observaciones, 300 );

		$tipologias = self::tipologias();
		$lineas     = self::lineas();

		if ( ! isset( $tipologias[ $tipologia ], $lineas[ $linea ] ) || null === CJB2B_Tratamientos_Catalog::tratamiento( $tratamiento ) ) {
			wp_send_json_error( array( 'mensaje' => $error ), 400 );
		}

		foreach ( array( $ancho, $alto ) as $medida ) {
			if ( $medida < self::MEDIDA_MIN_CM || $medida > self::MEDIDA_MAX_CM ) {
				wp_send_json_error(
					array(
						'mensaje' => sprintf(
							/* translators: 1: mínimo en cm, 2: máximo en cm */
							__( 'Revisá las medidas: el ancho y el alto van entre %1$d y %2$d cm.', 'cj-aluminios-b2b' ),
							self::MEDIDA_MIN_CM,
							self::MEDIDA_MAX_CM
						),
					),
					400
				);
			}
		}

		if ( strlen( $nombre ) < 2 ) {
			wp_send_json_error( array( 'mensaje' => __( 'Completá tu nombre o el de tu empresa.', 'cj-aluminios-b2b' ) ), 400 );
		}

		if ( strlen( (string) preg_replace( '/\D+/', '', $telefono ) ) < 6 ) {
			wp_send_json_error( array( 'mensaje' => __( 'Completá un teléfono válido (con código de área).', 'cj-aluminios-b2b' ) ), 400 );
		}

		CJB2B_Frontend::registrar_intento( 'simcot', self::LIMITE_VENTANA );

		$lineas_msj   = array();
		$lineas_msj[] = '*Cotización de abertura* - Simulador CJ Aluminios';
		$lineas_msj[] = 'Cliente / Empresa: ' . $nombre;
		$lineas_msj[] = 'Teléfono: ' . $telefono;

		if ( is_user_logged_in() ) {
			$lineas_msj[] = 'Lista B2B: ' . CJB2B_Frontend::rol_texto();
		}

		$lineas_msj[] = '';
		$lineas_msj[] = '*Abertura:*';
		$lineas_msj[] = 'Tipología: ' . $tipologias[ $tipologia ]['nombre'];
		$lineas_msj[] = 'Línea: ' . $lineas[ $linea ]['nombre'];
		$lineas_msj[] = 'Tratamiento: ' . CJB2B_Tratamientos_Catalog::nombre_completo( $tratamiento );
		$lineas_msj[] = sprintf( 'Medidas aprox.: %1$d x %2$d cm (ancho x alto)', $ancho, $alto );

		if ( '' !== $observaciones ) {
			$lineas_msj[] = '';
			$lineas_msj[] = 'Observaciones: ' . $observaciones;
		}

		$lineas_msj[] = '';
		$lineas_msj[] = '_Adjunto la vista previa del simulador._';

		wp_send_json_success(
			array(
				'url' => CJB2B_Frontend::whatsapp_url( implode( "\n", $lineas_msj ) ),
			)
		);
	}
}
