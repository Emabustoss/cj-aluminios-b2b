/**
 * Simulador visual 2D de aberturas (Fase 3).
 *
 * 1. Dibuja la abertura "de frente" en un lienzo auxiliar (textura), con el
 *    perfilado de la línea elegida y el material del tratamiento.
 * 2. La deforma en perspectiva hacia las 4 esquinas que acomoda el usuario
 *    (malla de triángulos con homografía) y la compone sobre la foto.
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
	var estadoEl = q( '[data-cj-sim-estado]' );

	var MALLA = 14;
	var TEXTURA_MAX = 720;
	var LIENZO_MIN = 900;
	var LIENZO_MAX = 1600;

	var tipologiaInicial = Object.keys( cfg.tipologias )[ 0 ];

	var estado = {
		fondo: null,
		tipologia: tipologiaInicial,
		linea: 'modena',
		tratamiento: cfg.inicial.tratamiento,
		ancho: cfg.tipologias[ tipologiaInicial ].ancho,
		alto: cfg.tipologias[ tipologiaInicial ].alto,
		vidrio: 0.6,
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
		} else if ( 'anodizados' === t.familia ) {
			for ( i = 0; i < 170; i++ ) {
				c.fillStyle = rgba( rnd() < 0.5 ? [ 255, 255, 255 ] : [ 0, 0, 0 ], 0.03 + rnd() * 0.06 );
				c.fillRect( 0, rnd() * T, T, 0.5 + rnd() );
			}
		} else if ( 'microtex' === t.terminacion ) {
			for ( i = 0; i < 2600; i++ ) {
				c.fillStyle = rgba( rnd() < 0.5 ? [ 255, 255, 255 ] : [ 0, 0, 0 ], 0.05 + rnd() * 0.1 );
				c.fillRect( rnd() * T, rnd() * T, 1.3, 1.3 );
			}
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
			oscuro: luminancia( base ) < 0.45
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

	/** Un tramo de perfil: material + sombreado del perfilado + filo. */
	function pieza( c, pts, sentido, g0, g1, mat, perfil, tono ) {
		var xs = pts.map( function ( p ) { return p[ 0 ]; } );
		var ys = pts.map( function ( p ) { return p[ 1 ]; } );
		var bx = Math.min.apply( null, xs );
		var by = Math.min.apply( null, ys );
		var bw = Math.max.apply( null, xs ) - bx;
		var bh = Math.max.apply( null, ys ) - by;
		var g = c.createLinearGradient( g0[ 0 ], g0[ 1 ], g1[ 0 ], g1[ 1 ] );

		c.save();
		trazar( c, pts );
		c.clip();

		c.fillStyle = c.createPattern( 'h' === sentido ? mat.h : mat.v, 'repeat' );
		c.fillRect( bx, by, bw, bh );

		PERFILES[ perfil ].forEach( function ( p ) {
			g.addColorStop( p[ 0 ], p[ 1 ] ? 'rgba(255,255,255,' + Math.min( 0.9, p[ 2 ] * mat.brillo ) + ')' : 'rgba(0,0,0,' + p[ 2 ] + ')' );
		} );
		c.fillStyle = g;
		c.fillRect( bx, by, bw, bh );

		if ( tono ) {
			c.fillStyle = tono > 0 ? 'rgba(255,255,255,' + tono + ')' : 'rgba(0,0,0,' + ( -tono ) + ')';
			c.fillRect( bx, by, bw, bh );
		}

		c.restore();

		trazar( c, pts );
		c.strokeStyle = mat.oscuro ? 'rgba(255,255,255,0.16)' : 'rgba(0,0,0,0.32)';
		c.lineWidth = 1;
		c.stroke();
	}

	/**
	 * Bastidor de 4 perfiles. t = ancho visto del perfil, tb = ancho del
	 * travesaño inferior (zócalo). Uniones a 45° (inglete) o a 90°.
	 */
	function marco( c, x, y, w, h, t, tb, linea, mat ) {
		var x2 = x + w;
		var y2 = y + h;
		var p = linea.perfil;

		if ( linea.inglete ) {
			pieza( c, [ [ x, y ], [ x2, y ], [ x2 - t, y + t ], [ x + t, y + t ] ], 'h', [ 0, y ], [ 0, y + t ], mat, p, 0.05 );
			pieza( c, [ [ x, y2 ], [ x2, y2 ], [ x2 - t, y2 - tb ], [ x + t, y2 - tb ] ], 'h', [ 0, y2 ], [ 0, y2 - tb ], mat, p, -0.07 );
			pieza( c, [ [ x, y ], [ x + t, y + t ], [ x + t, y2 - tb ], [ x, y2 ] ], 'v', [ x, 0 ], [ x + t, 0 ], mat, p, 0.03 );
			pieza( c, [ [ x2, y ], [ x2 - t, y + t ], [ x2 - t, y2 - tb ], [ x2, y2 ] ], 'v', [ x2, 0 ], [ x2 - t, 0 ], mat, p, -0.05 );
			return;
		}

		pieza( c, [ [ x + t, y ], [ x2 - t, y ], [ x2 - t, y + t ], [ x + t, y + t ] ], 'h', [ 0, y ], [ 0, y + t ], mat, p, 0.05 );
		pieza( c, [ [ x + t, y2 - tb ], [ x2 - t, y2 - tb ], [ x2 - t, y2 ], [ x + t, y2 ] ], 'h', [ 0, y2 ], [ 0, y2 - tb ], mat, p, -0.07 );
		pieza( c, [ [ x, y ], [ x + t, y ], [ x + t, y2 ], [ x, y2 ] ], 'v', [ x, 0 ], [ x + t, 0 ], mat, p, 0.03 );
		pieza( c, [ [ x2 - t, y ], [ x2, y ], [ x2, y2 ], [ x2 - t, y2 ] ], 'v', [ x2, 0 ], [ x2 - t, 0 ], mat, p, -0.05 );
	}

	/** Vidrio semitransparente con reflejos diagonales y sombra del marco. */
	function vidrio( c, x, y, w, h ) {
		if ( w <= 0 || h <= 0 ) {
			return;
		}

		var opacidad = 0.92 - estado.vidrio * 0.8;
		var reflejo = 0.1 + opacidad * 0.24;
		var g = c.createLinearGradient( 0, y, 0, y + h );
		var sesgo = h * 0.35;

		function banda( bx, bw, alfa ) {
			var gb = c.createLinearGradient( 0, y, 0, y + h );
			gb.addColorStop( 0, 'rgba(255,255,255,' + alfa + ')' );
			gb.addColorStop( 1, 'rgba(255,255,255,' + alfa * 0.15 + ')' );
			trazar( c, [ [ bx + sesgo, y ], [ bx + sesgo + bw, y ], [ bx + bw, y + h ], [ bx, y + h ] ] );
			c.fillStyle = gb;
			c.fill();
		}

		c.save();
		c.beginPath();
		c.rect( x, y, w, h );
		c.clip();

		g.addColorStop( 0, 'rgba(178,209,227,' + opacidad + ')' );
		g.addColorStop( 0.55, 'rgba(134,170,192,' + opacidad + ')' );
		g.addColorStop( 1, 'rgba(104,138,160,' + opacidad * 0.95 + ')' );
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

	/** Hoja: vidrio + bastidor. Si está "atrás" (corrediza) se oscurece apenas. */
	function hoja( c, x, y, w, h, t, tb, linea, mat, atras ) {
		vidrio( c, x + t * 0.9, y + t * 0.9, w - t * 1.8, h - t * 0.9 - tb * 0.9 );
		marco( c, x, y, w, h, t, tb, linea, mat );

		if ( atras ) {
			c.fillStyle = 'rgba(0,0,0,0.08)';
			c.fillRect( x, y, w, h );
		}
	}

	function herraje( c, x, y, w, h, mat ) {
		var r = Math.min( w, h ) * 0.3;

		c.beginPath();
		c.moveTo( x + r, y );
		c.arcTo( x + w, y, x + w, y + h, r );
		c.arcTo( x + w, y + h, x, y + h, r );
		c.arcTo( x, y + h, x, y, r );
		c.arcTo( x, y, x + w, y, r );
		c.closePath();
		c.fillStyle = mat.oscuro ? '#c9cbcd' : '#27282b';
		c.fill();
		c.strokeStyle = 'rgba(0,0,0,0.35)';
		c.lineWidth = 1;
		c.stroke();
	}

	/**
	 * Dibuja la abertura completa, de frente, en la textura. La escala sale
	 * de las medidas en cm, así el espesor de los perfiles queda proporcionado.
	 */
	function componer() {
		var tip = cfg.tipologias[ estado.tipologia ];
		var linea = cfg.lineas[ estado.linea ];
		var mat = material( estado.tratamiento );
		var razon = estado.ancho / estado.alto;
		var W = razon >= 1 ? TEXTURA_MAX : Math.round( TEXTURA_MAX * razon );
		var H = razon >= 1 ? Math.round( TEXTURA_MAX / razon ) : TEXTURA_MAX;
		var c = texCtx;

		textura.width = Math.max( 40, W );
		textura.height = Math.max( 40, H );
		W = textura.width;
		H = textura.height;

		var escala = W / estado.ancho;
		var menor = Math.min( W, H );
		var tm = Math.min( menor * 0.16, Math.max( 5, linea.marco * escala ) );
		var th = Math.min( menor * 0.2, Math.max( 6, linea.hoja * escala ) );
		var inset, ancho, zocalo, hx, hy, hw, hh;

		if ( 'fijo' === tip.dibujo ) {
			vidrio( c, tm * 0.9, tm * 0.9, W - tm * 1.8, H - tm * 1.8 );
			marco( c, tm * 0.92, tm * 0.92, W - tm * 1.84, H - tm * 1.84, th * 0.3, th * 0.3, linea, mat );
			marco( c, 0, 0, W, H, tm, tm, linea, mat );
			return;
		}

		if ( 'corrediza' === tip.dibujo || 'balcon' === tip.dibujo ) {
			inset = tm * 0.8;
			ancho = ( W - inset * 2 + th ) / 2;
			zocalo = 'balcon' === tip.dibujo ? th * 1.5 : th;
			hy = inset;
			hh = H - inset * 2;
			hx = W - inset - ancho;

			hoja( c, inset, hy, ancho, hh, th, zocalo, linea, mat, true );

			// Sombra que la hoja de adelante proyecta sobre la de atrás.
			var gs = c.createLinearGradient( hx - th * 0.3, 0, hx, 0 );
			gs.addColorStop( 0, 'rgba(0,0,0,0)' );
			gs.addColorStop( 1, 'rgba(0,0,0,0.32)' );
			c.fillStyle = gs;
			c.fillRect( hx - th * 0.3, hy, th * 0.3, hh );

			hoja( c, hx, hy, ancho, hh, th, zocalo, linea, mat, false );
			marco( c, 0, 0, W, H, tm, tm, linea, mat );

			// Cierres laterales.
			herraje( c, inset + th * 0.36, H / 2 - th * 0.8, th * 0.28, th * 1.6, mat );
			herraje( c, W - inset - th * 0.64, H / 2 - th * 0.8, th * 0.28, th * 1.6, mat );
			return;
		}

		// Abrir / oscilobatiente y banderola: una hoja que pisa el marco.
		inset = tm * 0.55;
		hx = inset;
		hy = inset;
		hw = W - inset * 2;
		hh = H - inset * 2;

		marco( c, 0, 0, W, H, tm, tm, linea, mat );
		hoja( c, hx, hy, hw, hh, th, th, linea, mat, false );

		if ( 'banderola' === tip.dibujo ) {
			// Bisagras arriba, manija al centro del travesaño inferior.
			herraje( c, hx + hw * 0.14, hy - th * 0.12, th * 0.9, th * 0.24, mat );
			herraje( c, hx + hw * 0.86 - th * 0.9, hy - th * 0.12, th * 0.9, th * 0.24, mat );
			herraje( c, W / 2 - th * 0.65, hy + hh - th * 0.66, th * 1.3, th * 0.3, mat );
			herraje( c, W / 2 - th * 0.1, hy + hh - th * 0.66, th * 1.5, th * 0.2, mat );
			return;
		}

		// Bisagras a la izquierda, manija a la derecha.
		herraje( c, hx - th * 0.12, hy + hh * 0.14, th * 0.24, th * 0.9, mat );
		herraje( c, hx - th * 0.12, hy + hh * 0.86 - th * 0.9, th * 0.24, th * 0.9, mat );
		herraje( c, hx + hw - th * 0.66, H / 2 - th * 0.65, th * 0.3, th * 1.3, mat );
		herraje( c, hx + hw - th * 0.61, H / 2 - th * 0.1, th * 0.2, th * 1.5, mat );
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

		// El recorte se agranda 0,7 px para que no queden rendijas entre
		// triángulos; se limpia antes de dibujar para que el vidrio
		// semitransparente no se "sume" en los solapes.
		var cx = ( d0[ 0 ] + d1[ 0 ] + d2[ 0 ] ) / 3;
		var cy = ( d0[ 1 ] + d1[ 1 ] + d2[ 1 ] ) / 3;
		var pts = [ d0, d1, d2 ].map( function ( p ) {
			var vx = p[ 0 ] - cx, vy = p[ 1 ] - cy;
			var largo = Math.sqrt( vx * vx + vy * vy ) || 1;
			return [ p[ 0 ] + ( vx / largo ) * 0.7, p[ 1 ] + ( vy / largo ) * 0.7 ];
		} );

		c.save();
		trazar( c, pts );
		c.clip();
		c.clearRect( 0, 0, c.canvas.width, c.canvas.height );
		c.setTransform( a, b, cc, d, e, f );
		c.drawImage( img, 0, 0 );
		c.restore();
	}

	function deformar( c, img, quad ) {
		var H = homografia( quad );
		var w = img.width, h = img.height;
		var i, j, u0, u1, v0, v1, p00, p10, p11, p01;

		for ( j = 0; j < MALLA; j++ ) {
			for ( i = 0; i < MALLA; i++ ) {
				u0 = i / MALLA;
				u1 = ( i + 1 ) / MALLA;
				v0 = j / MALLA;
				v1 = ( j + 1 ) / MALLA;
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

	var pendiente = false;

	function pedirRender() {
		if ( pendiente ) {
			return;
		}

		pendiente = true;
		window.requestAnimationFrame( function () {
			pendiente = false;
			render();
		} );
	}

	function render() {
		var W = canvas.width, H = canvas.height;
		var f = factor();
		var quad = estado.quad;

		ctx.clearRect( 0, 0, W, H );

		if ( estado.fondo ) {
			ctx.drawImage( estado.fondo, 0, 0, W, H );
		} else {
			ctx.fillStyle = '#e4e4e7';
			ctx.fillRect( 0, 0, W, H );
		}

		if ( ! quad ) {
			return;
		}

		capaCtx.clearRect( 0, 0, W, H );
		deformar( capaCtx, textura, quad );

		// Sombra proyectada sobre la pared: la arroja el canal alfa de la capa.
		ctx.save();
		ctx.shadowColor = 'rgba(0,0,0,0.45)';
		ctx.shadowBlur = W * 0.012;
		ctx.shadowOffsetX = W * 0.004;
		ctx.shadowOffsetY = W * 0.007;
		ctx.drawImage( capa, 0, 0 );
		ctx.restore();

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

	function cargarFondo( url, zona, hecho, fallo ) {
		var img = new Image();

		img.onload = function () {
			var iw = img.naturalWidth || 1200;
			var ih = img.naturalHeight || 800;
			var W = Math.max( LIENZO_MIN, Math.min( LIENZO_MAX, iw ) );
			var H = Math.round( W * ih / iw );

			if ( H > LIENZO_MAX ) {
				W = Math.round( W * LIENZO_MAX / H );
				H = LIENZO_MAX;
			}

			canvas.width = W;
			canvas.height = H;
			capa.width = W;
			capa.height = H;
			estado.fondo = img;
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

	var inputFoto = q( '[data-cj-sim-foto]' );

	inputFoto.addEventListener( 'change', function () {
		var archivo = inputFoto.files && inputFoto.files[ 0 ];

		if ( ! archivo ) {
			return;
		}

		// Validación en el navegador (rápida); la del servidor es la que manda.
		if ( cfg.mimes.indexOf( archivo.type ) < 0 ) {
			inputFoto.value = '';
			decir( cfg.textos.fotoTipo );
			return;
		}

		if ( archivo.size > cfg.maxBytes ) {
			inputFoto.value = '';
			decir( cfg.textos.fotoPeso );
			return;
		}

		decir( cfg.textos.fotoSubiendo );
		inputFoto.disabled = true;

		var cuerpo = new FormData();
		cuerpo.append( 'action', cfg.accionFoto );
		cuerpo.append( 'nonce', cfg.nonce );
		cuerpo.append( 'foto', archivo );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: cuerpo
		} ).then( function ( respuesta ) {
			return respuesta.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.mensaje ) || cfg.textos.fotoError );
			}

			var url = URL.createObjectURL( archivo );

			cargarFondo( url, null, function () {
				URL.revokeObjectURL( url );
				marcarAmbiente( '' );
				decir( cfg.textos.fotoLista );
			}, function () {
				URL.revokeObjectURL( url );
				inputFoto.value = '';
				decir( cfg.textos.fotoError );
			} );
		} ).catch( function ( error ) {
			inputFoto.value = '';
			decir( ( error && error.message && 'Failed to fetch' !== error.message ) ? error.message : cfg.textos.fotoError );
		} ).then( function () {
			inputFoto.disabled = false;
		} );
	} );

	/* ------------------------------------------------------- Interacción */

	var arrastre = null;

	function posicion( evento ) {
		var r = canvas.getBoundingClientRect();
		return [
			( evento.clientX - r.left ) * canvas.width / r.width,
			( evento.clientY - r.top ) * canvas.height / r.height
		];
	}

	function esquinaCerca( p ) {
		var radio = 24 * factor();
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
	function escalar( quad0, ancla, s ) {
		return quad0.map( function ( p ) {
			return [ ancla[ 0 ] + ( p[ 0 ] - ancla[ 0 ] ) * s, ancla[ 1 ] + ( p[ 1 ] - ancla[ 1 ] ) * s ];
		} );
	}

	function aplicar( quad ) {
		if ( valido( quad ) ) {
			estado.quad = quad;
			pedirRender();
			return true;
		}

		return false;
	}

	canvas.addEventListener( 'pointerdown', function ( evento ) {
		if ( ! estado.quad ) {
			return;
		}

		var p = posicion( evento );
		var i = esquinaCerca( p );

		if ( i >= 0 ) {
			arrastre = { tipo: 'esquina', i: i, inicio: p, quad0: copiar( estado.quad ) };
		} else if ( dentro( p, estado.quad ) ) {
			arrastre = { tipo: 'mover', inicio: p, quad0: copiar( estado.quad ) };
		}

		if ( arrastre ) {
			evento.preventDefault();
			canvas.setPointerCapture( evento.pointerId );
			canvas.classList.add( 'is-dragging' );
		}
	} );

	canvas.addEventListener( 'pointermove', function ( evento ) {
		if ( ! estado.quad ) {
			return;
		}

		var p = posicion( evento );

		if ( ! arrastre ) {
			canvas.style.cursor = esquinaCerca( p ) >= 0 ? 'pointer' : ( dentro( p, estado.quad ) ? 'move' : 'default' );
			return;
		}

		var dx = p[ 0 ] - arrastre.inicio[ 0 ];
		var dy = p[ 1 ] - arrastre.inicio[ 1 ];
		var q0 = arrastre.quad0;

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

		aplicar( escalar( q0, ancla, Math.max( 0.1, Math.min( 8, s ) ) ) );
	} );

	function soltar( evento ) {
		if ( arrastre ) {
			arrastre = null;
			canvas.classList.remove( 'is-dragging' );

			if ( canvas.hasPointerCapture( evento.pointerId ) ) {
				canvas.releasePointerCapture( evento.pointerId );
			}
		}
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
		} else if ( '+' === evento.key || '=' === evento.key || '-' === evento.key ) {
			evento.preventDefault();
			aplicar( escalar( estado.quad, centro( estado.quad ), '-' === evento.key ? 0.96 : 1.04 ) );
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
		estado.quad = rectangulo( c[ 0 ], c[ 1 ], estado.quad[ 1 ][ 0 ] - estado.quad[ 0 ][ 0 ] );
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

	q( '[data-cj-sim-vidrio]' ).addEventListener( 'input', function ( evento ) {
		estado.vidrio = Math.max( 0, Math.min( 1, parseInt( evento.target.value, 10 ) / 100 ) );
		componer();
		pedirRender();
	} );

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
