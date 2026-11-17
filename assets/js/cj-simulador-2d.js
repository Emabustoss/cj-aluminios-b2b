/**
 * Simulador visual 2D de aberturas (Fase 3).
 *
 * 1. Dibuja la abertura "de frente" en un lienzo auxiliar (textura), con el
 *    perfilado de la línea elegida y el material del tratamiento.
 * 2. La deforma en perspectiva hacia las 4 esquinas que acomoda el usuario
 *    (malla de triángulos con homografía) y la compone sobre la foto.
 *
 * Rendimiento: la foto se reduce una sola vez a un búfer en memoria, los
 * eventos táctiles solo guardan la posición y el dibujo corre en un único
 * requestAnimationFrame (bandera needsRender). Mientras se arrastra se dibuja
 * en calidad rápida y al soltar en calidad completa.
 *
 * Todo corre en el navegador: la foto del usuario nunca se guarda.
 */
( function () {
	'use strict';

	var cfg = window.cjb2bSimulador;
	var raiz = document.querySelector( '[data-cj-sim]' );

	if ( ! cfg || ! raiz ) {
		return;
	}

	function q( selector ) {
		return raiz.querySelector( selector );
	}

	function qa( selector ) {
		return Array.prototype.slice.call( raiz.querySelectorAll( selector ) );
	}

	var canvas = q( '[data-cj-sim-canvas]' );
	var ctx = canvas.getContext( '2d' );
	var capa = document.createElement( 'canvas' );
	var capaCtx = capa.getContext( '2d' );
	var textura = document.createElement( 'canvas' );
	var texCtx = textura.getContext( '2d' );

	// El vidrio va en su propia textura y capa, siempre opacas: la
	// transparencia se aplica una sola vez al componer. Deformar píxeles
	// semitransparentes triángulo a triángulo deja una grilla visible.
	var texturaVidrio = document.createElement( 'canvas' );
	var vidCtx = texturaVidrio.getContext( '2d' );
	var capaVidrio = document.createElement( 'canvas' );
	var capaVidrioCtx = capaVidrio.getContext( '2d' );
	var estadoEl = q( '[data-cj-sim-estado]' );

	var MALLA = 14;        // Subdivisión de la deformación en calidad completa.
	var MALLA_RAPIDA = 7;  // Mientras se arrastra.
	var TEXTURA_MAX = 720;
	var LIENZO_MIN = 900;
	var LIENZO_MAX = 1280; // Lado mayor del búfer de la foto.
	var RADIO_MOUSE = 16;  // Radio de agarre de las esquinas, en px de pantalla.
	var RADIO_TACTIL = 28;

	var tipologiaInicial = Object.keys( cfg.tipologias )[ 0 ];

	var estado = {
		fondo: null, // Canvas en memoria con la foto ya escalada.
		tipologia: tipologiaInicial,
		linea: 'modena',
		tratamiento: cfg.inicial.tratamiento,
		ancho: cfg.tipologias[ tipologiaInicial ].ancho,
		alto: cfg.tipologias[ tipologiaInicial ].alto,
		vidrio: 0.6,
		luz: 0.5,
		modo: 'tamano',
		quad: null,
		deformado: false,
		guias: true
	};

	/* ------------------------------------------------------------ Color */

	function rgb( hex ) {
		var n = parseInt( String( hex ).replace( '#', '' ), 16 );
		return [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ];
	}

	function rgba( c, alfa ) {
		return 'rgba(' + c[ 0 ] + ',' + c[ 1 ] + ',' + c[ 2 ] + ',' + alfa + ')';
	}

	function aclarar( c, f ) {
		return c.map( function ( v ) {
			return Math.round( f >= 0 ? v + ( 255 - v ) * f : v * ( 1 + f ) );
		} );
	}

	function luminancia( c ) {
		return ( 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ] ) / 255;
	}

	/** Generador pseudoaleatorio con semilla: la veta de un color es siempre la misma. */
	function azar( texto ) {
		var semilla = 2166136261;
		var i;

		for ( i = 0; i < texto.length; i++ ) {
			semilla ^= texto.charCodeAt( i );
			semilla = Math.imul( semilla, 16777619 );
		}

		return function () {
			semilla += 0x6D2B79F5;
			var t = semilla;
			t = Math.imul( t ^ ( t >>> 15 ), t | 1 );
			t ^= t + Math.imul( t ^ ( t >>> 7 ), t | 61 );
			return ( ( t ^ ( t >>> 14 ) ) >>> 0 ) / 4294967296;
		};
	}

	/* -------------------------------------------------------- Materiales */

	var BRILLO = { brillante: 1.7, semimate: 1, microtex: 0.55, anodizado: 1.35, veteado: 0.6 };
	var materiales = {};

	/**
	 * Textura repetible del tratamiento: color pleno (pintados), cepillado
	 * metálico (anodizados) o veta procedural (símil madera). Se genera con
	 * la fibra horizontal y una copia girada para los perfiles verticales.
	 * También define el color de los herrajes que combina con el acabado.
	 */
	function material( slug ) {
		if ( materiales[ slug ] ) {
			return materiales[ slug ];
		}

		var t = cfg.tratamientos[ slug ];
		var T = 256;
		var h = document.createElement( 'canvas' );
		var c = h.getContext( '2d' );
		var rnd = azar( slug );
		var base = rgb( t.hex );
		var oscuro = luminancia( base ) < 0.45;
		var herraje;
		var i, x, y;

		h.width = T;
		h.height = T;
		c.fillStyle = t.hex;
		c.fillRect( 0, 0, T, T );

		if ( 'madera' === t.familia ) {
			var veta = t.veta ? rgb( t.veta ) : aclarar( base, -0.3 );

			for ( i = 0; i < 14; i++ ) {
				c.fillStyle = rgba( rnd() < 0.5 ? veta : aclarar( base, 0.25 ), 0.08 + rnd() * 0.14 );
				c.fillRect( 0, rnd() * T, T, 6 + rnd() * 26 );
			}

			for ( i = 0; i < 90; i++ ) {
				var y0 = rnd() * T;
				var amplitud = 1 + rnd() * 5;
				var ciclos = 1 + Math.floor( rnd() * 3 );
				var fase = rnd() * Math.PI * 2;

				c.strokeStyle = rgba( veta, 0.18 + rnd() * 0.5 );
				c.lineWidth = 0.6 + rnd() * 1.6;
				c.beginPath();

				// Ciclos enteros: la veta empalma al repetirse a lo largo.
				for ( x = 0; x <= T; x += 8 ) {
					y = y0 + amplitud * Math.sin( fase + ( x / T ) * Math.PI * 2 * ciclos );
					if ( 0 === x ) {
						c.moveTo( x, y );
					} else {
						c.lineTo( x, y );
					}
				}

				c.stroke();
			}

			// Sobre madera, herrajes negros mate.
			herraje = [ '#3d3d40', '#141415' ];
		} else if ( 'anodizados' === t.familia ) {
			for ( i = 0; i < 170; i++ ) {
				c.fillStyle = rgba( rnd() < 0.5 ? [ 255, 255, 255 ] : [ 0, 0, 0 ], 0.03 + rnd() * 0.06 );
				c.fillRect( 0, rnd() * T, T, 0.5 + rnd() );
			}

			// Sobre anodizado, herrajes del mismo tono metálico.
			herraje = [ rgba( aclarar( base, 0.38 ), 1 ), rgba( aclarar( base, -0.28 ), 1 ) ];
		} else {
			if ( 'microtex' === t.terminacion ) {
				for ( i = 0; i < 2600; i++ ) {
					c.fillStyle = rgba( rnd() < 0.5 ? [ 255, 255, 255 ] : [ 0, 0, 0 ], 0.05 + rnd() * 0.1 );
					c.fillRect( rnd() * T, rnd() * T, 1.3, 1.3 );
				}
			}

			// Pintados: negro sobre colores oscuros, acero sobre claros.
			herraje = oscuro ? [ '#55565a', '#121213' ] : [ '#eceef0', '#9a9da2' ];
		}

		var v = document.createElement( 'canvas' );
		var cv = v.getContext( '2d' );

		v.width = T;
		v.height = T;
		cv.translate( T, 0 );
		cv.rotate( Math.PI / 2 );
		cv.drawImage( h, 0, 0 );

		materiales[ slug ] = {
			h: h,
			v: v,
			brillo: BRILLO[ t.terminacion ] || 1,
			oscuro: oscuro,
			herraje: herraje
		};

		return materiales[ slug ];
	}

	/* ------------------------------------------------ Dibujo de perfiles */

	/**
	 * Sombreado a lo ancho de un perfil, del borde exterior (0) al interior
	 * (1): [ posición, 1 = luz / 0 = sombra, intensidad ]. Es lo que distingue
	 * a simple vista el perfilado de cada línea.
	 */
	var PERFILES = {
		recto: [ [ 0, 1, 0.2 ], [ 0.1, 1, 0.04 ], [ 0.5, 1, 0 ], [ 0.9, 0, 0.08 ], [ 1, 0, 0.26 ] ],
		curvo: [ [ 0, 0, 0.14 ], [ 0.28, 1, 0.3 ], [ 0.52, 1, 0.05 ], [ 0.8, 0, 0.14 ], [ 1, 0, 0.3 ] ],
		escalonado: [ [ 0, 1, 0.22 ], [ 0.36, 1, 0.04 ], [ 0.38, 0, 0.3 ], [ 0.45, 1, 0.16 ], [ 0.6, 1, 0.02 ], [ 0.9, 0, 0.08 ], [ 1, 0, 0.3 ] ]
	};

	function trazar( c, pts ) {
		c.beginPath();
		pts.forEach( function ( p, i ) {
			if ( 0 === i ) {
				c.moveTo( p[ 0 ], p[ 1 ] );
			} else {
				c.lineTo( p[ 0 ], p[ 1 ] );
			}
		} );
		c.closePath();
	}

	function rect( x, y, w, h ) {
		return [ [ x, y ], [ x + w, y ], [ x + w, y + h ], [ x, y + h ] ];
	}

	function linea( c, x1, y1, x2, y2, estilo, grosor ) {
		c.beginPath();
		c.moveTo( x1, y1 );
		c.lineTo( x2, y2 );
		c.strokeStyle = estilo;
		c.lineWidth = grosor || 1;
		c.stroke();
	}

	/** Un tramo de perfil: material + sombreado del perfilado + luz de ambiente + filo. */
	function pieza( c, pts, sentido, g0, g1, mat, perfil, tono ) {
		var xs = pts.map( function ( p ) { return p[ 0 ]; } );
		var ys = pts.map( function ( p ) { return p[ 1 ]; } );
		var bx = Math.min.apply( null, xs );
		var by = Math.min.apply( null, ys );
		var bw = Math.max.apply( null, xs ) - bx;
		var bh = Math.max.apply( null, ys ) - by;
		var g = c.createLinearGradient( g0[ 0 ], g0[ 1 ], g1[ 0 ], g1[ 1 ] );
		var brillo = mat.brillo * ( 0.55 + estado.luz * 0.9 );
		var ambiente = estado.luz - 0.5;

		c.save();
		trazar( c, pts );
		c.clip();

		c.fillStyle = c.createPattern( 'h' === sentido ? mat.h : mat.v, 'repeat' );
		c.fillRect( bx, by, bw, bh );

		PERFILES[ perfil ].forEach( function ( p ) {
			g.addColorStop( p[ 0 ], p[ 1 ] ? 'rgba(255,255,255,' + Math.min( 0.9, p[ 2 ] * brillo ) + ')' : 'rgba(0,0,0,' + p[ 2 ] + ')' );
		} );
		c.fillStyle = g;
		c.fillRect( bx, by, bw, bh );

		if ( tono ) {
			c.fillStyle = tono > 0 ? 'rgba(255,255,255,' + tono + ')' : 'rgba(0,0,0,' + ( -tono ) + ')';
			c.fillRect( bx, by, bw, bh );
		}

		// Luz de ambiente: aclara a pleno sol, oscurece en interiores.
		if ( ambiente ) {
			c.fillStyle = ambiente > 0 ? 'rgba(255,255,255,' + ( ambiente * 0.34 ) + ')' : 'rgba(0,0,0,' + ( -ambiente * 0.5 ) + ')';
			c.fillRect( bx, by, bw, bh );
		}

		c.restore();

		trazar( c, pts );
		c.strokeStyle = mat.oscuro ? 'rgba(255,255,255,0.14)' : 'rgba(0,0,0,0.3)';
		c.lineWidth = 1;
		c.stroke();
	}

	/**
	 * Biselado de un bastidor: filo de luz arriba/izquierda y de sombra
	 * abajo/derecha en el canto exterior, lo inverso en el rebaje interior
	 * (el perfil hace sombra hacia el vidrio) y el corte de cada unión.
	 */
	function biselar( c, x, y, w, h, t, tb, inglete, mat ) {
		var x2 = x + w, y2 = y + h;
		var ix = x + t, iy = y + t, ix2 = x2 - t, iy2 = y2 - tb;
		var luz = 'rgba(255,255,255,' + ( ( mat.oscuro ? 0.2 : 0.5 ) * ( 0.5 + estado.luz ) ) + ')';
		var sombra = 'rgba(0,0,0,' + ( 0.3 + estado.luz * 0.25 ) + ')';
		var grosor = Math.max( 1, t * 0.06 );
		var juntas = inglete
			? [ [ x, y, ix, iy ], [ x2, y, ix2, iy ], [ x, y2, ix, iy2 ], [ x2, y2, ix2, iy2 ] ]
			: [ [ ix, y, ix, iy ], [ ix2, y, ix2, iy ], [ ix, iy2, ix, y2 ], [ ix2, iy2, ix2, y2 ] ];

		c.save();
		c.lineCap = 'round';

		// Uniones: corte oscuro y, pegado, un hilo de luz (canto del inglete).
		juntas.forEach( function ( j ) {
			linea( c, j[ 0 ], j[ 1 ], j[ 2 ], j[ 3 ], 'rgba(0,0,0,0.45)', 1 );
			linea( c, j[ 0 ] + 1, j[ 1 ], j[ 2 ] + 1, j[ 3 ], luz, 0.8 );
		} );

		// Canto exterior.
		linea( c, x + 1, y2 - 1, x + 1, y + 1, luz, grosor );
		linea( c, x + 1, y + 1, x2 - 1, y + 1, luz, grosor );
		linea( c, x2 - 1, y + 1, x2 - 1, y2 - 1, sombra, grosor );
		linea( c, x2 - 1, y2 - 1, x + 1, y2 - 1, sombra, grosor );

		// Rebaje interior.
		linea( c, ix, iy2, ix, iy, sombra, grosor );
		linea( c, ix, iy, ix2, iy, sombra, grosor );
		linea( c, ix2, iy, ix2, iy2, luz, grosor );
		linea( c, ix2, iy2, ix, iy2, luz, grosor );

		c.restore();
	}

	/**
	 * Bastidor de 4 perfiles. t = ancho visto del perfil, tb = ancho del
	 * travesaño inferior (zócalo). Uniones a 45° (inglete) o a 90°.
	 */
	function marco( c, x, y, w, h, t, tb, lin, mat ) {
		var x2 = x + w;
		var y2 = y + h;
		var p = lin.perfil;

		if ( lin.inglete ) {
			pieza( c, [ [ x, y ], [ x2, y ], [ x2 - t, y + t ], [ x + t, y + t ] ], 'h', [ 0, y ], [ 0, y + t ], mat, p, 0.05 );
			pieza( c, [ [ x, y2 ], [ x2, y2 ], [ x2 - t, y2 - tb ], [ x + t, y2 - tb ] ], 'h', [ 0, y2 ], [ 0, y2 - tb ], mat, p, -0.07 );
			pieza( c, [ [ x, y ], [ x + t, y + t ], [ x + t, y2 - tb ], [ x, y2 ] ], 'v', [ x, 0 ], [ x + t, 0 ], mat, p, 0.03 );
			pieza( c, [ [ x2, y ], [ x2 - t, y + t ], [ x2 - t, y2 - tb ], [ x2, y2 ] ], 'v', [ x2, 0 ], [ x2 - t, 0 ], mat, p, -0.05 );
		} else {
			pieza( c, [ [ x + t, y ], [ x2 - t, y ], [ x2 - t, y + t ], [ x + t, y + t ] ], 'h', [ 0, y ], [ 0, y + t ], mat, p, 0.05 );
			pieza( c, [ [ x + t, y2 - tb ], [ x2 - t, y2 - tb ], [ x2 - t, y2 ], [ x + t, y2 ] ], 'h', [ 0, y2 ], [ 0, y2 - tb ], mat, p, -0.07 );
			pieza( c, [ [ x, y ], [ x + t, y ], [ x + t, y2 ], [ x, y2 ] ], 'v', [ x, 0 ], [ x + t, 0 ], mat, p, 0.03 );
			pieza( c, [ [ x2 - t, y ], [ x2, y ], [ x2, y2 ], [ x2 - t, y2 ] ], 'v', [ x2, 0 ], [ x2 - t, 0 ], mat, p, -0.05 );
		}

		biselar( c, x, y, w, h, t, tb, lin.inglete, mat );
	}

	/** Opacidad final del vidrio según el slider de transparencia. */
	function opacidadVidrio() {
		return 0.92 - estado.vidrio * 0.8;
	}

	/**
	 * Oscurece una zona en las dos texturas, solo donde ya hay algo dibujado
	 * (source-atop): no deja píxeles semitransparentes sueltos.
	 * estilo: color, o función( ctx ) que devuelve un degradado.
	 */
	function velo( x, y, w, h, estilo ) {
		[ texCtx, vidCtx ].forEach( function ( c ) {
			c.save();
			c.globalCompositeOperation = 'source-atop';
			c.fillStyle = 'function' === typeof estilo ? estilo( c ) : estilo;
			c.fillRect( x, y, w, h );
			c.restore();
		} );
	}

	/**
	 * Vidrio con reflejos diagonales y sombra del marco. Se dibuja opaco en
	 * la textura de vidrio (el primer parámetro se ignora).
	 */
	function vidrio( c, x, y, w, h ) {
		if ( w <= 0 || h <= 0 ) {
			return;
		}

		c = vidCtx;

		var opacidad = 1;
		var reflejo = 0.42 * ( 0.45 + estado.luz * 1.1 );
		var tono = 0.78 + estado.luz * 0.44; // Vidrio más claro a pleno sol.
		var g = c.createLinearGradient( 0, y, 0, y + h );
		var sesgo = h * 0.35;

		function color( r, v, a, alfa ) {
			return 'rgba(' + Math.min( 255, Math.round( r * tono ) ) + ',' + Math.min( 255, Math.round( v * tono ) ) + ',' + Math.min( 255, Math.round( a * tono ) ) + ',' + alfa + ')';
		}

		function banda( bx, bw, alfa ) {
			var gb = c.createLinearGradient( 0, y, 0, y + h );
			gb.addColorStop( 0, 'rgba(255,255,255,' + Math.min( 0.85, alfa ) + ')' );
			gb.addColorStop( 1, 'rgba(255,255,255,' + Math.min( 0.85, alfa ) * 0.15 + ')' );
			trazar( c, [ [ bx + sesgo, y ], [ bx + sesgo + bw, y ], [ bx + bw, y + h ], [ bx, y + h ] ] );
			c.fillStyle = gb;
			c.fill();
		}

		c.save();
		c.beginPath();
		c.rect( x, y, w, h );
		c.clip();

		g.addColorStop( 0, color( 178, 209, 227, opacidad ) );
		g.addColorStop( 0.55, color( 134, 170, 192, opacidad ) );
		g.addColorStop( 1, color( 104, 138, 160, opacidad ) );
		c.fillStyle = g;
		c.fillRect( x, y, w, h );

		banda( x + w * 0.02, w * 0.17, reflejo );
		banda( x + w * 0.27, w * 0.06, reflejo * 0.6 );
		banda( x + w * 0.55, w * 0.24, reflejo * 0.42 );

		var borde = Math.min( 16, Math.min( w, h ) * 0.09 );
		var gs = c.createLinearGradient( 0, y, 0, y + borde );
		gs.addColorStop( 0, 'rgba(0,0,0,0.34)' );
		gs.addColorStop( 1, 'rgba(0,0,0,0)' );
		c.fillStyle = gs;
		c.fillRect( x, y, w, borde );

		gs = c.createLinearGradient( x, 0, x + borde, 0 );
		gs.addColorStop( 0, 'rgba(0,0,0,0.26)' );
		gs.addColorStop( 1, 'rgba(0,0,0,0)' );
		c.fillStyle = gs;
		c.fillRect( x, y, borde, h );

		c.restore();
	}

	var patronMalla = null;

	/** Tela mosquitera: velo oscuro + trama fina, sobre la textura de vidrio. */
	function malla( c, x, y, w, h ) {
		c = vidCtx;

		if ( ! patronMalla ) {
			patronMalla = document.createElement( 'canvas' );
			patronMalla.width = 5;
			patronMalla.height = 5;

			var cm = patronMalla.getContext( '2d' );
			cm.fillStyle = 'rgba(28,30,33,0.5)';
			cm.fillRect( 0, 0, 5, 1 );
			cm.fillRect( 0, 0, 1, 5 );
		}

		c.save();
		c.fillStyle = 'rgba(40,44,48,0.18)';
		c.fillRect( x, y, w, h );
		c.fillStyle = c.createPattern( patronMalla, 'repeat' );
		c.fillRect( x, y, w, h );
		c.restore();
	}

	/**
	 * Hoja: vidrio + bastidor. Opciones:
	 *  - atras:     hoja de atrás en una corrediza (se oscurece apenas).
	 *  - palillos:  [ verticales, horizontales ] de vidrio repartido.
	 *  - travesano: travesaño intermedio, como fracción del alto (0 = sin).
	 */
	function hoja( c, x, y, w, h, t, tb, lin, mat, o ) {
		var p = Math.max( 3, t * 0.3 );
		var i, px, py;

		o = o || {};

		vidrio( c, x + t * 0.9, y + t * 0.9, w - t * 1.8, h - t * 0.9 - tb * 0.9 );

		if ( o.palillos ) {
			for ( i = 1; i <= o.palillos[ 0 ]; i++ ) {
				px = x + t + ( w - t * 2 ) * i / ( o.palillos[ 0 ] + 1 ) - p / 2;
				pieza( c, rect( px, y + t - 1, p, h - t - tb + 2 ), 'v', [ px, 0 ], [ px + p, 0 ], mat, 'curvo', 0 );
			}

			for ( i = 1; i <= o.palillos[ 1 ]; i++ ) {
				py = y + t + ( h - t - tb ) * i / ( o.palillos[ 1 ] + 1 ) - p / 2;
				pieza( c, rect( x + t - 1, py, w - t * 2 + 2, p ), 'h', [ 0, py ], [ 0, py + p ], mat, 'curvo', 0 );
			}
		}

		if ( o.travesano ) {
			py = y + h * o.travesano - t / 2;
			pieza( c, rect( x + t - 1, py, w - t * 2 + 2, t ), 'h', [ 0, py ], [ 0, py + t ], mat, lin.perfil, 0 );
			linea( vidCtx, x + t, py + t + 1, x + w - t, py + t + 1, 'rgba(0,0,0,0.28)', 2 );
		}

		marco( c, x, y, w, h, t, tb, lin, mat );

		if ( o.atras ) {
			velo( x, y, w, h, 'rgba(0,0,0,0.08)' );
		}
	}

	/* ----------------------------------------------------------- Herrajes */

	/** Pieza metálica redondeada, con degradado del acabado y sombra propia. */
	function herraje( c, x, y, w, h, mat ) {
		var r = Math.min( w, h ) * 0.32;
		var g = w >= h ? c.createLinearGradient( 0, y, 0, y + h ) : c.createLinearGradient( x, 0, x + w, 0 );

		g.addColorStop( 0, mat.herraje[ 0 ] );
		g.addColorStop( 1, mat.herraje[ 1 ] );

		c.save();
		c.beginPath();
		c.moveTo( x + r, y );
		c.arcTo( x + w, y, x + w, y + h, r );
		c.arcTo( x + w, y + h, x, y + h, r );
		c.arcTo( x, y + h, x, y, r );
		c.arcTo( x, y, x + w, y, r );
		c.closePath();
		c.shadowColor = 'rgba(0,0,0,0.5)';
		c.shadowBlur = 3;
		c.shadowOffsetX = 1;
		c.shadowOffsetY = 1.5;
		c.fillStyle = g;
		c.fill();
		c.shadowColor = 'transparent';
		c.strokeStyle = 'rgba(0,0,0,0.42)';
		c.lineWidth = 1;
		c.stroke();
		c.restore();
	}

	/** Tirador / cierre lateral de una hoja corrediza. */
	function tirador( c, cx, cy, t, mat ) {
		herraje( c, cx - t * 0.15, cy - t * 0.85, t * 0.3, t * 1.7, mat );
		herraje( c, cx - t * 0.07, cy - t * 0.5, t * 0.14, t, mat );
	}

	/** Manija de ventana: base + palanca hacia abajo. */
	function manija( c, cx, cy, t, mat ) {
		herraje( c, cx - t * 0.17, cy - t * 0.65, t * 0.34, t * 1.3, mat );
		herraje( c, cx - t * 0.1, cy - t * 0.1, t * 0.2, t * 1.5, mat );
	}

	/** Manija horizontal (banderola), centrada en un travesaño. */
	function manijaHorizontal( c, cx, cy, t, mat ) {
		herraje( c, cx - t * 0.65, cy - t * 0.16, t * 1.3, t * 0.32, mat );
		herraje( c, cx - t * 0.1, cy - t * 0.1, t * 1.5, t * 0.2, mat );
	}

	/** Picaporte de puerta: bocallave + palanca + cilindro. */
	function picaporte( c, cx, cy, t, mat ) {
		herraje( c, cx - t * 0.21, cy - t * 1.15, t * 0.42, t * 2.3, mat );
		herraje( c, cx - t * 1.45, cy - t * 0.62, t * 1.6, t * 0.24, mat );
		c.beginPath();
		c.arc( cx, cy + t * 0.55, t * 0.1, 0, Math.PI * 2 );
		c.fillStyle = 'rgba(0,0,0,0.7)';
		c.fill();
	}

	/* ------------------------------------------------- Tipologías (dibujo) */

	/**
	 * Corredizas de 2, 3 o 4 hojas. En 2 y 3 hojas las hojas se cruzan una
	 * a una; en 4, las dos centrales van adelante y se encuentran al medio.
	 */
	function corredizas( c, W, H, tm, th, lin, mat, n, zocalo, mosquitera ) {
		var inset = tm * 0.8;
		var iw = W - inset * 2;
		var hy = inset;
		var hh = H - inset * 2;
		var cy = H / 2;
		var hojas = [];
		var a, i;

		if ( 4 === n ) {
			a = ( iw + th * 2 ) / 4;
			hojas = [
				{ x: inset, atras: true },
				{ x: inset + a - th, atras: false },
				{ x: inset + a * 2 - th, atras: false },
				{ x: inset + a * 3 - th * 2, atras: true }
			];
		} else {
			a = ( iw + th * ( n - 1 ) ) / n;
			for ( i = 0; i < n; i++ ) {
				hojas.push( { x: inset + i * ( a - th ), atras: 3 === n ? 1 !== i : 0 === i } );
			}
		}

		hojas.forEach( function ( hj ) {
			if ( hj.atras ) {
				hoja( c, hj.x, hy, a, hh, th, zocalo, lin, mat, { atras: true } );
			}
		} );

		// Sombra que cada hoja de adelante proyecta sobre la vecina de atrás.
		hojas.forEach( function ( hj, k ) {
			var s = th * 0.32;
			var gs;

			if ( hj.atras ) {
				return;
			}

			if ( hojas[ k - 1 ] && hojas[ k - 1 ].atras ) {
				velo( hj.x - s, hy, s, hh, function ( cx ) {
					gs = cx.createLinearGradient( hj.x - s, 0, hj.x, 0 );
					gs.addColorStop( 0, 'rgba(0,0,0,0)' );
					gs.addColorStop( 1, 'rgba(0,0,0,0.34)' );
					return gs;
				} );
			}

			if ( hojas[ k + 1 ] && hojas[ k + 1 ].atras ) {
				velo( hj.x + a, hy, s, hh, function ( cx ) {
					gs = cx.createLinearGradient( hj.x + a, 0, hj.x + a + s, 0 );
					gs.addColorStop( 0, 'rgba(0,0,0,0.34)' );
					gs.addColorStop( 1, 'rgba(0,0,0,0)' );
					return gs;
				} );
			}
		} );

		hojas.forEach( function ( hj ) {
			if ( ! hj.atras ) {
				hoja( c, hj.x, hy, a, hh, th, zocalo, lin, mat );
			}
		} );

		if ( mosquitera ) {
			// Hoja mosquitera superpuesta sobre la primera hoja: bastidor fino + tela.
			var tmq = th * 0.6;
			malla( c, hojas[ 0 ].x + tmq * 0.8, hy + tmq * 0.8, a - tmq * 1.6, hh - tmq * 1.6 );
			marco( c, hojas[ 0 ].x, hy, a, hh, tmq, tmq, lin, mat );
		}

		marco( c, 0, 0, W, H, tm, tm, lin, mat );

		// Tiradores.
		if ( mosquitera ) {
			tirador( c, hojas[ 0 ].x + a - th * 0.3, cy, th * 0.7, mat );
			tirador( c, hojas[ 1 ].x + a - th / 2, cy, th, mat );
		} else if ( 2 === n ) {
			tirador( c, hojas[ 0 ].x + th / 2, cy, th, mat );
			tirador( c, hojas[ 1 ].x + a - th / 2, cy, th, mat );
		} else if ( 3 === n ) {
			tirador( c, hojas[ 1 ].x + th / 2, cy, th, mat );
			tirador( c, hojas[ 1 ].x + a - th / 2, cy, th, mat );
		} else {
			tirador( c, hojas[ 2 ].x - th / 2, cy, th, mat );
			tirador( c, hojas[ 2 ].x + th / 2, cy, th, mat );
		}
	}

	/**
	 * Dibuja la abertura completa, de frente, en la textura. La escala sale
	 * de las medidas en cm, así el espesor de los perfiles queda proporcionado.
	 */
	function componer() {
		var tip = cfg.tipologias[ estado.tipologia ];
		var lin = cfg.lineas[ estado.linea ];
		var mat = material( estado.tratamiento );
		var razon = estado.ancho / estado.alto;
		var W = razon >= 1 ? TEXTURA_MAX : Math.round( TEXTURA_MAX * razon );
		var H = razon >= 1 ? Math.round( TEXTURA_MAX / razon ) : TEXTURA_MAX;
		var c = texCtx;

		textura.width = Math.max( 40, W );
		textura.height = Math.max( 40, H );
		texturaVidrio.width = textura.width;
		texturaVidrio.height = textura.height;
		W = textura.width;
		H = textura.height;

		var escala = W / estado.ancho;
		var menor = Math.min( W, H );
		var tm = Math.min( menor * 0.16, Math.max( 5, lin.marco * escala ) );
		var th = Math.min( menor * 0.2, Math.max( 6, lin.hoja * escala ) );
		var inset = tm * 0.55;
		var hx = inset, hy = inset, hw = W - inset * 2, hh = H - inset * 2;
		var a, yb, umbral;

		switch ( tip.dibujo ) {
			case 'fijo':
				vidrio( c, tm * 0.9, tm * 0.9, W - tm * 1.8, H - tm * 1.8 );
				marco( c, tm * 0.92, tm * 0.92, W - tm * 1.84, H - tm * 1.84, th * 0.3, th * 0.3, lin, mat );
				marco( c, 0, 0, W, H, tm, tm, lin, mat );
				break;

			case 'corrediza':
				corredizas( c, W, H, tm, th, lin, mat, tip.hojas, th, false );
				break;

			case 'mosquitera':
				corredizas( c, W, H, tm, th, lin, mat, 2, th, true );
				break;

			case 'balcon':
				corredizas( c, W, H, tm, th, lin, mat, 2, th * 1.5, false );
				break;

			case 'banderola':
				// Bisagras arriba, manija al centro del travesaño inferior.
				marco( c, 0, 0, W, H, tm, tm, lin, mat );
				hoja( c, hx, hy, hw, hh, th, th, lin, mat );
				herraje( c, hx + hw * 0.14, hy - th * 0.12, th * 0.9, th * 0.24, mat );
				herraje( c, hx + hw * 0.86 - th * 0.9, hy - th * 0.12, th * 0.9, th * 0.24, mat );
				manijaHorizontal( c, W / 2, hy + hh - th / 2, th, mat );
				break;

			case 'repartido':
				// Dos hojas de abrir con palillos; manija en el encuentro central.
				a = hw / 2;
				marco( c, 0, 0, W, H, tm, tm, lin, mat );
				hoja( c, hx, hy, a, hh, th, th, lin, mat, { palillos: [ 1, 2 ] } );
				hoja( c, hx + a, hy, a, hh, th, th, lin, mat, { palillos: [ 1, 2 ] } );
				herraje( c, hx - th * 0.12, hy + hh * 0.14, th * 0.24, th * 0.9, mat );
				herraje( c, hx - th * 0.12, hy + hh * 0.86 - th * 0.9, th * 0.24, th * 0.9, mat );
				herraje( c, hx + hw - th * 0.12, hy + hh * 0.14, th * 0.24, th * 0.9, mat );
				herraje( c, hx + hw - th * 0.12, hy + hh * 0.86 - th * 0.9, th * 0.24, th * 0.9, mat );
				manija( c, hx + a + th / 2, H / 2, th, mat );
				break;

			case 'puerta':
				// Marco perimetral con umbral bajo, hoja con zócalo reforzado y travesaño medio.
				umbral = tm * 0.4;
				hh = H - inset - umbral * 0.7;
				marco( c, 0, 0, W, H, tm, umbral, lin, mat );
				hoja( c, hx, hy, hw, hh, th, th * 2.2, lin, mat, { travesano: 0.52 } );
				herraje( c, hx - th * 0.12, hy + hh * 0.1, th * 0.24, th * 0.9, mat );
				herraje( c, hx - th * 0.12, hy + hh * 0.5 - th * 0.45, th * 0.24, th * 0.9, mat );
				herraje( c, hx - th * 0.12, hy + hh * 0.9 - th * 0.9, th * 0.24, th * 0.9, mat );
				picaporte( c, hx + hw - th / 2, hy + hh * 0.52, th, mat );
				break;

			case 'fijo-banderola':
				// Travesaño horizontal: banderola arriba (30 %), paño fijo abajo.
				yb = Math.round( H * 0.3 );
				vidrio( c, tm * 0.9, yb + tm * 0.4, W - tm * 1.8, H - yb - tm * 1.3 );
				marco( c, tm * 0.92, yb + tm * 0.42, W - tm * 1.84, H - yb - tm * 1.34, th * 0.3, th * 0.3, lin, mat );
				marco( c, 0, 0, W, H, tm, tm, lin, mat );
				pieza( c, rect( tm - 1, yb - tm / 2, W - tm * 2 + 2, tm ), 'h', [ 0, yb - tm / 2 ], [ 0, yb + tm / 2 ], mat, lin.perfil, 0 );
				linea( vidCtx, tm, yb + tm / 2 + 1, W - tm, yb + tm / 2 + 1, 'rgba(0,0,0,0.3)', 2 );
				hh = yb - tm * 0.5 - inset + tm * 0.45;
				hoja( c, hx, hy, hw, hh, th, th, lin, mat );
				herraje( c, hx + hw * 0.14, hy - th * 0.12, th * 0.9, th * 0.24, mat );
				herraje( c, hx + hw * 0.86 - th * 0.9, hy - th * 0.12, th * 0.9, th * 0.24, mat );
				manijaHorizontal( c, W / 2, hy + hh - th / 2, th, mat );
				break;

			default:
				// Abrir / oscilobatiente: una hoja que pisa el marco, bisagras a la izquierda.
				marco( c, 0, 0, W, H, tm, tm, lin, mat );
				hoja( c, hx, hy, hw, hh, th, th, lin, mat );
				herraje( c, hx - th * 0.12, hy + hh * 0.14, th * 0.24, th * 0.9, mat );
				herraje( c, hx - th * 0.12, hy + hh * 0.86 - th * 0.9, th * 0.24, th * 0.9, mat );
				manija( c, hx + hw - th / 2, H / 2, th, mat );
		}
	}

	/* ------------------------------------------- Deformación en perspectiva */

	/** Homografía del cuadrado unidad al cuadrilátero [TL, TR, BR, BL]. */
	function homografia( p ) {
		var x0 = p[ 0 ][ 0 ], y0 = p[ 0 ][ 1 ];
		var x1 = p[ 1 ][ 0 ], y1 = p[ 1 ][ 1 ];
		var x2 = p[ 2 ][ 0 ], y2 = p[ 2 ][ 1 ];
		var x3 = p[ 3 ][ 0 ], y3 = p[ 3 ][ 1 ];
		var dx1 = x1 - x2, dx2 = x3 - x2, sx = x0 - x1 + x2 - x3;
		var dy1 = y1 - y2, dy2 = y3 - y2, sy = y0 - y1 + y2 - y3;
		var den = dx1 * dy2 - dx2 * dy1;
		var g = den ? ( sx * dy2 - dx2 * sy ) / den : 0;
		var h = den ? ( dx1 * sy - sx * dy1 ) / den : 0;
		var a = x1 - x0 + g * x1, b = x3 - x0 + h * x3;
		var d = y1 - y0 + g * y1, e = y3 - y0 + h * y3;

		return function ( u, v ) {
			var w = g * u + h * v + 1;
			return [ ( a * u + b * v + x0 ) / w, ( d * u + e * v + y0 ) / w ];
		};
	}

	/**
	 * Agranda un triángulo desplazando cada lado d píxeles hacia afuera.
	 * (Mover los vértices desde el centro no alcanza: en la diagonal de cada
	 * celda el lado casi no se corre y queda una línea más clara.)
	 */
	function expandir( pts, d ) {
		var giro = ( pts[ 1 ][ 0 ] - pts[ 0 ][ 0 ] ) * ( pts[ 2 ][ 1 ] - pts[ 0 ][ 1 ] ) - ( pts[ 2 ][ 0 ] - pts[ 0 ][ 0 ] ) * ( pts[ 1 ][ 1 ] - pts[ 0 ][ 1 ] ) > 0 ? 1 : -1;
		var normales = pts.map( function ( a, i ) {
			var b = pts[ ( i + 1 ) % 3 ];
			var ex = b[ 0 ] - a[ 0 ], ey = b[ 1 ] - a[ 1 ];
			var largo = Math.sqrt( ex * ex + ey * ey ) || 1;
			return [ giro * ey / largo, -giro * ex / largo ];
		} );

		return pts.map( function ( p, i ) {
			var na = normales[ ( i + 2 ) % 3 ], nb = normales[ i ];
			var k = Math.max( 0.2, 1 + na[ 0 ] * nb[ 0 ] + na[ 1 ] * nb[ 1 ] );
			return [ p[ 0 ] + d * ( na[ 0 ] + nb[ 0 ] ) / k, p[ 1 ] + d * ( na[ 1 ] + nb[ 1 ] ) / k ];
		} );
	}

	/** Dibuja un triángulo de la imagen con una transformación afín. */
	function triangulo( c, img, s0, s1, s2, d0, d1, d2 ) {
		var sx1 = s1[ 0 ] - s0[ 0 ], sy1 = s1[ 1 ] - s0[ 1 ];
		var sx2 = s2[ 0 ] - s0[ 0 ], sy2 = s2[ 1 ] - s0[ 1 ];
		var den = sx1 * sy2 - sx2 * sy1;

		if ( ! den ) {
			return;
		}

		var dx1 = d1[ 0 ] - d0[ 0 ], dy1 = d1[ 1 ] - d0[ 1 ];
		var dx2 = d2[ 0 ] - d0[ 0 ], dy2 = d2[ 1 ] - d0[ 1 ];
		var a = ( dx1 * sy2 - dx2 * sy1 ) / den;
		var b = ( dy1 * sy2 - dy2 * sy1 ) / den;
		var cc = ( dx2 * sx1 - dx1 * sx2 ) / den;
		var d = ( dy2 * sx1 - dy1 * sx2 ) / den;
		var e = d0[ 0 ] - a * s0[ 0 ] - cc * s0[ 1 ];
		var f = d0[ 1 ] - b * s0[ 0 ] - d * s0[ 1 ];

		// El recorte se agranda 1 px por lado para que no queden rendijas
		// entre triángulos. Las texturas son opacas, así que el solape entre
		// vecinos no se nota.
		c.save();
		trazar( c, expandir( [ d0, d1, d2 ], 1 ) );
		c.clip();
		c.setTransform( a, b, cc, d, e, f );
		c.drawImage( img, 0, 0 );
		c.restore();
	}

	function deformar( c, img, quad, n ) {
		var H = homografia( quad );
		var w = img.width, h = img.height;
		var i, j, u0, u1, v0, v1, p00, p10, p11, p01;

		for ( j = 0; j < n; j++ ) {
			for ( i = 0; i < n; i++ ) {
				u0 = i / n;
				u1 = ( i + 1 ) / n;
				v0 = j / n;
				v1 = ( j + 1 ) / n;
				p00 = H( u0, v0 );
				p10 = H( u1, v0 );
				p11 = H( u1, v1 );
				p01 = H( u0, v1 );

				triangulo( c, img, [ u0 * w, v0 * h ], [ u1 * w, v0 * h ], [ u1 * w, v1 * h ], p00, p10, p11 );
				triangulo( c, img, [ u0 * w, v0 * h ], [ u1 * w, v1 * h ], [ u0 * w, v1 * h ], p00, p11, p01 );
			}
		}
	}

	/* ---------------------------------------------------------- Geometría */

	function copiar( quad ) {
		return quad.map( function ( p ) {
			return [ p[ 0 ], p[ 1 ] ];
		} );
	}

	function centro( quad ) {
		return [
			( quad[ 0 ][ 0 ] + quad[ 1 ][ 0 ] + quad[ 2 ][ 0 ] + quad[ 3 ][ 0 ] ) / 4,
			( quad[ 0 ][ 1 ] + quad[ 1 ][ 1 ] + quad[ 2 ][ 1 ] + quad[ 3 ][ 1 ] ) / 4
		];
	}

	function area( quad ) {
		var s = 0, i, a, b;

		for ( i = 0; i < 4; i++ ) {
			a = quad[ i ];
			b = quad[ ( i + 1 ) % 4 ];
			s += a[ 0 ] * b[ 1 ] - b[ 0 ] * a[ 1 ];
		}

		return s / 2;
	}

	/** Convexo, en el mismo sentido de giro y con un tamaño mínimo. */
	function valido( quad ) {
		var i, a, b, c, cruz;

		for ( i = 0; i < 4; i++ ) {
			a = quad[ i ];
			b = quad[ ( i + 1 ) % 4 ];
			c = quad[ ( i + 2 ) % 4 ];
			cruz = ( b[ 0 ] - a[ 0 ] ) * ( c[ 1 ] - b[ 1 ] ) - ( b[ 1 ] - a[ 1 ] ) * ( c[ 0 ] - b[ 0 ] );

			if ( cruz <= 0 ) {
				return false;
			}
		}

		return area( quad ) > canvas.width * canvas.height * 0.002;
	}

	function dentro( p, quad ) {
		var i, a, b;

		for ( i = 0; i < 4; i++ ) {
			a = quad[ i ];
			b = quad[ ( i + 1 ) % 4 ];

			if ( ( b[ 0 ] - a[ 0 ] ) * ( p[ 1 ] - a[ 1 ] ) - ( b[ 1 ] - a[ 1 ] ) * ( p[ 0 ] - a[ 0 ] ) < 0 ) {
				return false;
			}
		}

		return true;
	}

	/** Rectángulo con la proporción de las medidas en cm. */
	function rectangulo( cx, cy, ancho ) {
		var alto = ancho * estado.alto / estado.ancho;
		var maxAlto = canvas.height * 0.86;
		var maxAncho = canvas.width * 0.9;

		if ( alto > maxAlto ) {
			ancho *= maxAlto / alto;
			alto = maxAlto;
		}

		if ( ancho > maxAncho ) {
			alto *= maxAncho / ancho;
			ancho = maxAncho;
		}

		return [
			[ cx - ancho / 2, cy - alto / 2 ],
			[ cx + ancho / 2, cy - alto / 2 ],
			[ cx + ancho / 2, cy + alto / 2 ],
			[ cx - ancho / 2, cy + alto / 2 ]
		];
	}

	/** Factor entre píxeles del lienzo y píxeles en pantalla. */
	function factor() {
		var ancho = canvas.getBoundingClientRect().width;
		return ancho ? canvas.width / ancho : 1;
	}

	/* -------------------------------------------------------------- Render */

	var arrastre = null;
	var needsRender = false;
	var cuadro = 0;

	/**
	 * Los eventos solo levantan la bandera; el dibujo corre a lo sumo una
	 * vez por cuadro, así no se acumulan eventos táctiles sin dibujar.
	 */
	function pedirRender() {
		needsRender = true;

		if ( ! cuadro ) {
			cuadro = window.requestAnimationFrame( tick );
		}
	}

	function tick() {
		cuadro = 0;

		if ( ! needsRender ) {
			return;
		}

		needsRender = false;
		procesarArrastre();
		render();
	}

	/** Dibuja solo la sombra de la capa (la capa se dibuja fuera del lienzo). */
	function sombra( alfa, desenfoque, dx, dy ) {
		var lejos = canvas.width * 2;

		ctx.save();
		ctx.shadowColor = 'rgba(0,0,0,' + alfa + ')';
		ctx.shadowBlur = desenfoque;
		ctx.shadowOffsetX = lejos + dx;
		ctx.shadowOffsetY = dy;
		ctx.drawImage( capa, -lejos, 0 );
		ctx.restore();
	}

	function render() {
		var W = canvas.width, H = canvas.height;
		var f = factor();
		var quad = estado.quad;
		var rapido = !! arrastre;

		ctx.clearRect( 0, 0, W, H );

		if ( estado.fondo ) {
			// Búfer ya escalado al tamaño del lienzo: copia 1:1, sin reescalar.
			ctx.drawImage( estado.fondo, 0, 0 );
		} else {
			ctx.fillStyle = '#e4e4e7';
			ctx.fillRect( 0, 0, W, H );
		}

		if ( ! quad ) {
			return;
		}

		var malla2 = rapido ? MALLA_RAPIDA : MALLA;

		// 1) Vidrio opaco deformado; 2) se pasa a la capa con su transparencia;
		// 3) encima, los perfiles (opacos).
		capaVidrioCtx.clearRect( 0, 0, W, H );
		deformar( capaVidrioCtx, texturaVidrio, quad, malla2 );

		capaCtx.clearRect( 0, 0, W, H );
		capaCtx.globalAlpha = opacidadVidrio();
		capaCtx.drawImage( capaVidrio, 0, 0 );
		capaCtx.globalAlpha = 1;
		deformar( capaCtx, textura, quad, malla2 );

		if ( rapido ) {
			// Mientras se arrastra: una sola sombra liviana.
			ctx.save();
			ctx.shadowColor = 'rgba(0,0,0,0.4)';
			ctx.shadowBlur = W * 0.006;
			ctx.shadowOffsetX = W * 0.003;
			ctx.shadowOffsetY = W * 0.006;
			ctx.drawImage( capa, 0, 0 );
			ctx.restore();
		} else {
			// Sombra proyectada (amplia y suave) + sombra de contacto (pegada
			// al perfil). A más luz de ambiente, sombras más marcadas.
			sombra( 0.26 + estado.luz * 0.24, W * 0.016, W * 0.005, W * 0.009 );
			sombra( 0.34 + estado.luz * 0.26, W * 0.003, W * 0.0008, W * 0.0018 );
			ctx.drawImage( capa, 0, 0 );
		}

		if ( ! estado.guias ) {
			return;
		}

		ctx.save();
		trazar( ctx, quad );
		ctx.lineWidth = 2 * f;
		ctx.strokeStyle = 'rgba(0,0,0,0.55)';
		ctx.stroke();
		ctx.setLineDash( [ 7 * f, 6 * f ] );
		ctx.strokeStyle = '#ffffff';
		ctx.stroke();
		ctx.setLineDash( [] );

		quad.forEach( function ( p ) {
			ctx.beginPath();
			if ( 'perspectiva' === estado.modo ) {
				ctx.rect( p[ 0 ] - 8 * f, p[ 1 ] - 8 * f, 16 * f, 16 * f );
			} else {
				ctx.arc( p[ 0 ], p[ 1 ], 9 * f, 0, Math.PI * 2 );
			}
			ctx.fillStyle = '#ffffff';
			ctx.fill();
			ctx.lineWidth = 2.5 * f;
			ctx.strokeStyle = '#18181b';
			ctx.stroke();
		} );
		ctx.restore();
	}

	function redibujar() {
		componer();
		pedirRender();
		actualizarResumen();
	}

	/* -------------------------------------------------------------- Fondo */

	function decir( texto ) {
		estadoEl.textContent = texto;
	}

	/**
	 * Copia la imagen a un canvas del tamaño pedido. Si hay que achicar
	 * mucho (foto de celular), baja de a mitades para no perder nitidez.
	 */
	function escalar( img, iw, ih, W, H ) {
		var origen = img;
		var cw = iw, ch = ih;
		var paso, cp;

		while ( cw / 2 > W ) {
			cw = Math.round( cw / 2 );
			ch = Math.round( ch / 2 );
			paso = document.createElement( 'canvas' );
			paso.width = cw;
			paso.height = ch;
			cp = paso.getContext( '2d' );
			cp.imageSmoothingQuality = 'high';
			cp.drawImage( origen, 0, 0, cw, ch );
			origen = paso;
		}

		var bufer = document.createElement( 'canvas' );
		var cb = bufer.getContext( '2d' );

		bufer.width = W;
		bufer.height = H;
		cb.imageSmoothingQuality = 'high';
		cb.drawImage( origen, 0, 0, W, H );

		return bufer;
	}

	var cargaActual = 0;

	function cargarFondo( url, zona, hecho, fallo ) {
		var img = new Image();
		var id = ++cargaActual;

		img.decoding = 'async';

		img.onload = function () {
			// Si mientras tanto se pidió otro fondo, esta carga ya no vale.
			if ( id !== cargaActual ) {
				return;
			}

			var iw = img.naturalWidth || 1200;
			var ih = img.naturalHeight || 800;
			var mayor = Math.max( iw, ih );
			// Lado mayor a 1280 px como máximo; las fotos chicas se llevan a un mínimo usable.
			var k = Math.min( LIENZO_MAX / mayor, Math.max( 1, LIENZO_MIN / iw ) );
			var W = Math.max( 1, Math.round( iw * k ) );
			var H = Math.max( 1, Math.round( ih * k ) );

			canvas.width = W;
			canvas.height = H;
			capa.width = W;
			capa.height = H;
			capaVidrio.width = W;
			capaVidrio.height = H;
			estado.fondo = escalar( img, iw, ih, W, H );
			estado.deformado = false;

			if ( zona ) {
				estado.quad = rectangulo( 0, 0, zona[ 2 ] * W );
				var ancho = estado.quad[ 1 ][ 0 ] - estado.quad[ 0 ][ 0 ];
				var alto = estado.quad[ 3 ][ 1 ] - estado.quad[ 0 ][ 1 ];
				estado.quad = rectangulo( zona[ 0 ] * W + ancho / 2, zona[ 1 ] * H + alto / 2, ancho );
			} else {
				estado.quad = rectangulo( W / 2, H / 2, W * 0.36 );
			}

			pedirRender();

			if ( hecho ) {
				hecho();
			}
		};

		img.onerror = function () {
			if ( id !== cargaActual ) {
				return;
			}

			if ( fallo ) {
				fallo();
			}
		};

		img.src = url;
	}

	function marcarAmbiente( clave ) {
		qa( '[data-cj-sim-ambiente]' ).forEach( function ( boton ) {
			var activo = boton.getAttribute( 'data-cj-sim-ambiente' ) === clave;
			boton.setAttribute( 'aria-pressed', activo ? 'true' : 'false' );
			boton.classList.toggle( 'is-active', activo );
		} );
	}

	function cargarAmbiente( clave, avisar ) {
		var ambiente = cfg.ambientes[ clave ];

		if ( ! ambiente ) {
			return;
		}

		cargarFondo( ambiente.url, ambiente.zona, function () {
			marcarAmbiente( clave );
			if ( avisar ) {
				decir( cfg.textos.ambiente );
			}
		}, function () {
			decir( cfg.textos.fotoError );
		} );
	}

	qa( '[data-cj-sim-ambiente]' ).forEach( function ( boton ) {
		boton.addEventListener( 'click', function () {
			cargarAmbiente( boton.getAttribute( 'data-cj-sim-ambiente' ), true );
		} );
	} );

	/* ------------------------------------------------- Foto de obra propia */

	/*
	 * Todo pasa en el navegador: la foto nunca se sube. Así no hay espera de
	 * red (antes se congelaba en "Verificando la foto…" con conexiones lentas)
	 * y funciona igual con el Photo Picker de Android y con la galería de iOS.
	 */
	var inputFoto = q( '[data-cj-sim-foto]' );
	var botonFoto = inputFoto ? inputFoto.closest( 'label' ) : null;
	var textoFoto = q( '[data-cj-sim-foto-texto]' );
	var ultimaFoto = null; // Último File procesado (evita repetir con "change" + "input").

	function esHeic( archivo ) {
		return /hei[cf]/i.test( archivo.type || '' ) || /\.hei[cf]$/i.test( archivo.name || '' );
	}

	function cargandoFoto( si ) {
		if ( botonFoto ) {
			botonFoto.classList.toggle( 'is-cargando', si );
			botonFoto.setAttribute( 'aria-busy', si ? 'true' : 'false' );
		}
	}

	/** Lee el archivo como data: URL (respaldo si la URL de objeto falla). */
	function leerComoDataUrl( archivo, listo, fallo ) {
		if ( ! window.FileReader ) {
			fallo();
			return;
		}

		var lector = new FileReader();

		lector.onload = function () {
			listo( String( lector.result || '' ) );
		};

		lector.onerror = fallo;

		try {
			lector.readAsDataURL( archivo );
		} catch ( e ) {
			fallo();
		}
	}

	function fotoLista( archivo ) {
		cargandoFoto( false );
		marcarAmbiente( '' );
		if ( textoFoto ) {
			textoFoto.textContent = cfg.textos.fotoCambiar;
		}
		decir( cfg.textos.fotoLista );
		// Recién ahora se limpia el input: elegir la misma foto otra vez vuelve a disparar "change".
		inputFoto.value = '';
	}

	function fotoFallida( archivo ) {
		cargandoFoto( false );
		inputFoto.value = '';
		ultimaFoto = null;
		decir( esHeic( archivo ) || ! archivo.type ? cfg.textos.fotoHeic : cfg.textos.fotoError );
	}

	/** Intenta con URL de objeto; si no se puede decodificar, reintenta con FileReader. */
	function abrirFoto( archivo ) {
		var url = '';

		function conDataUrl() {
			leerComoDataUrl( archivo, function ( dataUrl ) {
				if ( ! dataUrl ) {
					fotoFallida( archivo );
					return;
				}

				cargarFondo( dataUrl, null, function () {
					fotoLista( archivo );
				}, function () {
					fotoFallida( archivo );
				} );
			}, function () {
				fotoFallida( archivo );
			} );
		}

		try {
			url = ( window.URL && URL.createObjectURL ) ? URL.createObjectURL( archivo ) : '';
		} catch ( e ) {
			url = '';
		}

		if ( ! url ) {
			conDataUrl();
			return;
		}

		cargarFondo( url, null, function () {
			URL.revokeObjectURL( url );
			fotoLista( archivo );
		}, function () {
			URL.revokeObjectURL( url );
			conDataUrl();
		} );
	}

	function alElegirFoto( evento ) {
		var lista = ( evento && evento.target && evento.target.files ) || inputFoto.files;
		// Se guarda la referencia al File antes de tocar el input o el DOM.
		var archivo = lista && lista[ 0 ] ? lista[ 0 ] : null;

		if ( ! archivo || archivo === ultimaFoto ) {
			return;
		}

		ultimaFoto = archivo;

		// Algunos selectores de Android entregan el archivo sin tipo: se intenta igual.
		if ( archivo.type && 0 !== archivo.type.indexOf( 'image/' ) ) {
			ultimaFoto = null;
			inputFoto.value = '';
			decir( cfg.textos.fotoTipo );
			return;
		}

		if ( archivo.size > cfg.maxBytes ) {
			ultimaFoto = null;
			inputFoto.value = '';
			decir( cfg.textos.fotoPeso );
			return;
		}

		cargandoFoto( true );
		decir( cfg.textos.fotoSubiendo );

		// Un respiro para que se pinte el estado antes de decodificar la foto.
		window.setTimeout( function () {
			abrirFoto( archivo );
		}, 30 );
	}

	if ( inputFoto ) {
		inputFoto.addEventListener( 'change', alElegirFoto );
		// Respaldo: algunos navegadores móviles avisan por "input" y no por "change".
		inputFoto.addEventListener( 'input', alElegirFoto );
	}

	/* ------------------------------------------------------- Interacción */

	function posicion( evento ) {
		var r = canvas.getBoundingClientRect();
		return [
			( evento.clientX - r.left ) * canvas.width / r.width,
			( evento.clientY - r.top ) * canvas.height / r.height
		];
	}

	/** Esquina más cercana dentro del radio de agarre (mayor con el dedo). */
	function esquinaCerca( p, tactil ) {
		var radio = ( tactil ? RADIO_TACTIL : RADIO_MOUSE ) * factor();
		var mejor = -1;
		var minimo = radio * radio;

		estado.quad.forEach( function ( e, i ) {
			var d = ( e[ 0 ] - p[ 0 ] ) * ( e[ 0 ] - p[ 0 ] ) + ( e[ 1 ] - p[ 1 ] ) * ( e[ 1 ] - p[ 1 ] );
			if ( d <= minimo ) {
				minimo = d;
				mejor = i;
			}
		} );

		return mejor;
	}

	function mover( quad0, dx, dy ) {
		var c = centro( quad0 );

		// El centro de la abertura no puede salir de la foto.
		dx = Math.max( -c[ 0 ], Math.min( canvas.width - c[ 0 ], dx ) );
		dy = Math.max( -c[ 1 ], Math.min( canvas.height - c[ 1 ], dy ) );

		return quad0.map( function ( p ) {
			return [ p[ 0 ] + dx, p[ 1 ] + dy ];
		} );
	}

	/** Escala pareja desde un punto fijo: conserva proporción y perspectiva. */
	function escalarQuad( quad0, ancla, s ) {
		return quad0.map( function ( p ) {
			return [ ancla[ 0 ] + ( p[ 0 ] - ancla[ 0 ] ) * s, ancla[ 1 ] + ( p[ 1 ] - ancla[ 1 ] ) * s ];
		} );
	}

	function aplicar( quad ) {
		if ( valido( quad ) ) {
			estado.quad = quad;
			return true;
		}

		return false;
	}

	/** Lleva la abertura a la última posición del puntero (una vez por cuadro). */
	function procesarArrastre() {
		if ( ! arrastre || ! arrastre.actual ) {
			return;
		}

		var dx = arrastre.actual[ 0 ] - arrastre.inicio[ 0 ];
		var dy = arrastre.actual[ 1 ] - arrastre.inicio[ 1 ];
		var q0 = arrastre.quad0;

		arrastre.actual = null;

		if ( 'mover' === arrastre.tipo ) {
			aplicar( mover( q0, dx, dy ) );
			return;
		}

		if ( 'perspectiva' === estado.modo ) {
			var libre = copiar( q0 );
			libre[ arrastre.i ] = [ q0[ arrastre.i ][ 0 ] + dx, q0[ arrastre.i ][ 1 ] + dy ];

			if ( aplicar( libre ) ) {
				estado.deformado = true;
			}
			return;
		}

		var ancla = q0[ ( arrastre.i + 2 ) % 4 ];
		var vx = q0[ arrastre.i ][ 0 ] - ancla[ 0 ];
		var vy = q0[ arrastre.i ][ 1 ] - ancla[ 1 ];
		var s = ( ( vx + dx ) * vx + ( vy + dy ) * vy ) / ( vx * vx + vy * vy || 1 );

		aplicar( escalarQuad( q0, ancla, Math.max( 0.1, Math.min( 8, s ) ) ) );
	}

	canvas.addEventListener( 'pointerdown', function ( evento ) {
		if ( ! estado.quad ) {
			return;
		}

		var p = posicion( evento );
		var i = esquinaCerca( p, 'mouse' !== evento.pointerType );

		if ( i >= 0 ) {
			arrastre = { tipo: 'esquina', i: i, inicio: p, quad0: copiar( estado.quad ), actual: null };
		} else if ( dentro( p, estado.quad ) ) {
			arrastre = { tipo: 'mover', inicio: p, quad0: copiar( estado.quad ), actual: null };
		}

		if ( arrastre ) {
			evento.preventDefault();
			canvas.classList.add( 'is-dragging' );

			try {
				// Sigue el arrastre aunque el dedo salga del lienzo.
				canvas.setPointerCapture( evento.pointerId );
			} catch ( e ) {
				// Puntero ya inactivo: el arrastre funciona igual dentro del lienzo.
			}
		}
	} );

	canvas.addEventListener( 'pointermove', function ( evento ) {
		if ( ! estado.quad ) {
			return;
		}

		var p = posicion( evento );

		if ( ! arrastre ) {
			if ( 'mouse' === evento.pointerType ) {
				canvas.style.cursor = esquinaCerca( p, false ) >= 0 ? 'pointer' : ( dentro( p, estado.quad ) ? 'move' : 'default' );
			}
			return;
		}

		// Solo se guarda la posición: el cálculo y el dibujo van en el próximo cuadro.
		arrastre.actual = p;
		pedirRender();
	} );

	function soltar( evento ) {
		if ( ! arrastre ) {
			return;
		}

		procesarArrastre();
		arrastre = null;
		canvas.classList.remove( 'is-dragging' );

		try {
			if ( canvas.hasPointerCapture( evento.pointerId ) ) {
				canvas.releasePointerCapture( evento.pointerId );
			}
		} catch ( e ) {
			// Nada que liberar.
		}

		// Al soltar se vuelve a dibujar en calidad completa.
		pedirRender();
	}

	canvas.addEventListener( 'pointerup', soltar );
	canvas.addEventListener( 'pointercancel', soltar );

	// Teclado: flechas mueven, + y - cambian el tamaño.
	canvas.addEventListener( 'keydown', function ( evento ) {
		if ( ! estado.quad ) {
			return;
		}

		var paso = ( evento.shiftKey ? 20 : 4 ) * factor();
		var teclas = { ArrowLeft: [ -paso, 0 ], ArrowRight: [ paso, 0 ], ArrowUp: [ 0, -paso ], ArrowDown: [ 0, paso ] };

		if ( teclas[ evento.key ] ) {
			evento.preventDefault();
			aplicar( mover( estado.quad, teclas[ evento.key ][ 0 ], teclas[ evento.key ][ 1 ] ) );
			pedirRender();
		} else if ( '+' === evento.key || '=' === evento.key || '-' === evento.key ) {
			evento.preventDefault();
			aplicar( escalarQuad( estado.quad, centro( estado.quad ), '-' === evento.key ? 0.96 : 1.04 ) );
			pedirRender();
		}
	} );

	/* ------------------------------------------------------------ Panel */

	var selTipologia = q( '[data-cj-sim-tipologia]' );
	var inputAncho = q( '[data-cj-sim-ancho]' );
	var inputAlto = q( '[data-cj-sim-alto]' );

	/** Si el usuario no ajustó la perspectiva, la abertura toma la nueva proporción. */
	function reproporcionar() {
		if ( ! estado.quad || estado.deformado ) {
			return;
		}

		var c = centro( estado.quad );
		var nuevo = rectangulo( c[ 0 ], c[ 1 ], estado.quad[ 1 ][ 0 ] - estado.quad[ 0 ][ 0 ] );
		var alto = nuevo[ 3 ][ 1 ] - nuevo[ 0 ][ 1 ];
		var margen = canvas.height * 0.03;

		// Una tipología más alta (puerta) no tiene que salirse de la foto.
		var cy = Math.max( alto / 2 + margen, Math.min( canvas.height - alto / 2 - margen, c[ 1 ] ) );

		estado.quad = mover( nuevo, 0, cy - c[ 1 ] );
	}

	function medida( input, respaldo ) {
		var valor = parseInt( input.value, 10 );

		if ( isNaN( valor ) ) {
			valor = respaldo;
		}

		valor = Math.max( cfg.medidaMin, Math.min( cfg.medidaMax, valor ) );
		input.value = valor;

		return valor;
	}

	selTipologia.addEventListener( 'change', function () {
		var tip = cfg.tipologias[ selTipologia.value ];

		if ( ! tip ) {
			return;
		}

		estado.tipologia = selTipologia.value;
		estado.ancho = tip.ancho;
		estado.alto = tip.alto;
		inputAncho.value = tip.ancho;
		inputAlto.value = tip.alto;
		reproporcionar();
		redibujar();
	} );

	[ inputAncho, inputAlto ].forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			estado.ancho = medida( inputAncho, estado.ancho );
			estado.alto = medida( inputAlto, estado.alto );
			reproporcionar();
			redibujar();
		} );
	} );

	qa( '[data-cj-sim-linea]' ).forEach( function ( boton ) {
		boton.addEventListener( 'click', function () {
			estado.linea = boton.getAttribute( 'data-cj-sim-linea' );

			qa( '[data-cj-sim-linea]' ).forEach( function ( otro ) {
				otro.classList.toggle( 'is-active', otro === boton );
				otro.setAttribute( 'aria-pressed', otro === boton ? 'true' : 'false' );
			} );

			q( '[data-cj-sim-linea-detalle]' ).textContent = cfg.lineas[ estado.linea ].detalle;
			redibujar();
		} );
	} );

	qa( '[data-cj-sim-tratamiento]' ).forEach( function ( boton ) {
		boton.addEventListener( 'click', function () {
			estado.tratamiento = boton.getAttribute( 'data-cj-sim-tratamiento' );

			qa( '[data-cj-sim-tratamiento]' ).forEach( function ( otro ) {
				otro.classList.toggle( 'is-active', otro === boton );
				otro.setAttribute( 'aria-pressed', otro === boton ? 'true' : 'false' );
			} );

			q( '[data-cj-sim-tratamiento-nombre]' ).textContent = boton.getAttribute( 'data-nombre' );
			redibujar();
		} );
	} );

	qa( '[data-cj-sim-modo]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			if ( radio.checked ) {
				estado.modo = radio.value;
				pedirRender();
			}
		} );
	} );

	/** Sliders que cambian la textura: se recompone una vez por cuadro. */
	function deslizador( selector, clave ) {
		var pendiente = false;

		q( selector ).addEventListener( 'input', function ( evento ) {
			estado[ clave ] = Math.max( 0, Math.min( 1, parseInt( evento.target.value, 10 ) / 100 ) );

			if ( pendiente ) {
				return;
			}

			pendiente = true;
			window.requestAnimationFrame( function () {
				pendiente = false;
				componer();
				pedirRender();
			} );
		} );
	}

	deslizador( '[data-cj-sim-vidrio]', 'vidrio' );
	deslizador( '[data-cj-sim-luz]', 'luz' );

	q( '[data-cj-sim-reset]' ).addEventListener( 'click', function () {
		estado.deformado = false;
		estado.quad = rectangulo( canvas.width / 2, canvas.height / 2, canvas.width * 0.36 );
		pedirRender();
	} );

	/* ---------------------------------------------------------- Descarga */

	q( '[data-cj-sim-descargar]' ).addEventListener( 'click', function () {
		var f = factor();

		estado.guias = false;
		render();

		// Marca de agua discreta solo en el archivo exportado.
		ctx.save();
		ctx.font = '600 ' + Math.round( canvas.width * 0.016 ) + 'px system-ui, sans-serif';
		ctx.textAlign = 'right';
		ctx.textBaseline = 'bottom';
		ctx.shadowColor = 'rgba(0,0,0,0.7)';
		ctx.shadowBlur = 4 * f;
		ctx.fillStyle = 'rgba(255,255,255,0.92)';
		ctx.fillText( 'CJ Aluminios · vista ilustrativa', canvas.width - canvas.width * 0.015, canvas.height - canvas.width * 0.012 );
		ctx.restore();

		canvas.toBlob( function ( blob ) {
			estado.guias = true;
			render();

			if ( ! blob ) {
				decir( cfg.textos.error );
				return;
			}

			var url = URL.createObjectURL( blob );
			var enlace = document.createElement( 'a' );

			enlace.href = url;
			enlace.download = cfg.textos.archivo + '-' + estado.tratamiento + '.png';
			document.body.appendChild( enlace );
			enlace.click();
			enlace.remove();
			window.setTimeout( function () {
				URL.revokeObjectURL( url );
			}, 1500 );
		}, 'image/png' );
	} );

	/* -------------------------------------------------------- Cotización */

	var panelCotizar = q( '[data-cj-sim-cotizar]' );
	var botonAbrir = q( '[data-cj-sim-abrir-cotizar]' );
	var form = q( '[data-cj-sim-form]' );
	var botonEnviar = q( '[data-cj-sim-enviar]' );
	var errorEl = q( '[data-cj-sim-error]' );
	var exito = q( '[data-cj-sim-exito]' );
	var textoEnviar = botonEnviar.textContent;
	var enviando = false;

	function actualizarResumen() {
		var activo = q( '[data-cj-sim-tratamiento].is-active' );

		q( '[data-cj-sim-resumen]' ).textContent = [
			cfg.tipologias[ estado.tipologia ].nombre,
			'Línea ' + cfg.lineas[ estado.linea ].nombre,
			activo ? activo.getAttribute( 'data-nombre' ) : cfg.tratamientos[ estado.tratamiento ].nombre,
			estado.ancho + ' × ' + estado.alto + ' cm'
		].join( ' · ' );
	}

	botonAbrir.addEventListener( 'click', function () {
		var abrir = panelCotizar.hidden;

		panelCotizar.hidden = ! abrir;
		botonAbrir.setAttribute( 'aria-expanded', abrir ? 'true' : 'false' );

		if ( abrir ) {
			// Si ya se envió una, se vuelve a mostrar el formulario para otra abertura.
			form.hidden = false;
			exito.hidden = true;
			actualizarResumen();
			form.querySelector( 'input[name="cj_nombre"]' ).focus();
		}
	} );

	form.addEventListener( 'submit', function ( evento ) {
		evento.preventDefault();

		if ( enviando ) {
			return;
		}

		errorEl.hidden = true;

		if ( ! form.checkValidity() ) {
			form.reportValidity();
			return;
		}

		enviando = true;
		botonEnviar.disabled = true;
		botonEnviar.textContent = cfg.textos.enviando;

		var cuerpo = new URLSearchParams( new FormData( form ) );
		cuerpo.append( 'action', cfg.accionCotizar );
		cuerpo.append( 'nonce', cfg.nonce );
		cuerpo.append( 'tipologia', estado.tipologia );
		cuerpo.append( 'linea', estado.linea );
		cuerpo.append( 'tratamiento', estado.tratamiento );
		cuerpo.append( 'ancho', estado.ancho );
		cuerpo.append( 'alto', estado.alto );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: cuerpo
		} ).then( function ( respuesta ) {
			return respuesta.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.mensaje ) || cfg.textos.error );
			}

			q( '[data-cj-sim-link]' ).href = json.data.url;
			form.hidden = true;
			exito.hidden = false;
			q( '[data-cj-sim-exito-titulo]' ).focus();

			// Si el navegador bloquea la ventana nueva, queda el botón visible.
			window.open( json.data.url, '_blank', 'noopener' );
		} ).catch( function ( error ) {
			errorEl.textContent = ( error && error.message && 'Failed to fetch' !== error.message ) ? error.message : cfg.textos.error;
			errorEl.hidden = false;
		} ).then( function () {
			enviando = false;
			botonEnviar.disabled = false;
			botonEnviar.textContent = textoEnviar;
		} );
	} );

	/* ------------------------------------------------------------ Inicio */

	componer();
	actualizarResumen();
	cargarAmbiente( cfg.inicial.ambiente, false );
	window.addEventListener( 'resize', pedirRender );
}() );
