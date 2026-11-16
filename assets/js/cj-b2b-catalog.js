/**
 * Catálogo técnico B2B: filtros por línea/familia, buscador instantáneo
 * y "agregar al pedido" sin recargar la página.
 */
( function () {
	'use strict';

	var cfg = window.cjb2bCatalogo;
	var raiz = document.querySelector( '[data-cj-catalogo]' );

	if ( ! cfg || ! raiz ) {
		return;
	}

	var filas = raiz.querySelector( '[data-cj-filas]' );
	var familias = raiz.querySelector( '[data-cj-familias]' );
	var buscador = raiz.querySelector( '[data-cj-buscar]' );
	var estadoEl = raiz.querySelector( '[data-cj-estado]' );
	var avisoEl = raiz.querySelector( '[data-cj-aviso]' );
	var pie = raiz.querySelector( '[data-cj-pie]' );
	var pedidoBar = raiz.querySelector( '[data-cj-pedido]' );
	var cantidadEl = raiz.querySelector( '[data-cj-cantidad]' );

	var estado = {
		linea: raiz.getAttribute( 'data-linea' ) || '',
		familia: raiz.getAttribute( 'data-familia' ) || '',
		q: buscador ? buscador.value.trim() : '',
		pagina: 1,
		paginas: parseInt( raiz.getAttribute( 'data-paginas' ), 10 ) || 1
	};

	var peticion = null;
	var temporizador = null;
	var avisoTimer = null;

	function post( datos, signal ) {
		var cuerpo = new URLSearchParams();
		Object.keys( datos ).forEach( function ( clave ) {
			cuerpo.append( clave, datos[ clave ] );
		} );
		cuerpo.append( 'nonce', cfg.nonce );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: cuerpo,
			signal: signal
		} ).then( function ( respuesta ) {
			return respuesta.json();
		} );
	}

	function aviso( texto, tipo ) {
		if ( ! avisoEl ) {
			return;
		}
		avisoEl.textContent = texto;
		avisoEl.className = 'cj-aviso cj-aviso--' + ( tipo || 'ok' );
		avisoEl.hidden = false;
		window.clearTimeout( avisoTimer );
		avisoTimer = window.setTimeout( function () {
			avisoEl.hidden = true;
		}, 5000 );
	}

	function actualizarUrl() {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}
		var url = new URL( window.location.href );
		[ [ 'linea', estado.linea ], [ 'familia', estado.familia ], [ 'buscar', estado.q ] ].forEach( function ( par ) {
			if ( par[ 1 ] ) {
				url.searchParams.set( par[ 0 ], par[ 1 ] );
			} else {
				url.searchParams.delete( par[ 0 ] );
			}
		} );
		window.history.replaceState( null, '', url.toString() );
	}

	function marcarLinea() {
		raiz.querySelectorAll( '[data-cj-lineas] [data-linea]' ).forEach( function ( chip ) {
			var activa = chip.getAttribute( 'data-linea' ) === estado.linea;
			chip.classList.toggle( 'is-active', activa );
			chip.setAttribute( 'aria-pressed', activa ? 'true' : 'false' );
		} );
	}

	function cargar( agregar ) {
		if ( peticion ) {
			peticion.abort();
		}
		peticion = new AbortController();

		raiz.classList.add( 'is-loading' );
		estadoEl.textContent = cfg.textos.buscando;

		post( {
			action: cfg.accionBuscar,
			linea: estado.linea,
			familia: estado.familia,
			q: estado.q,
			pagina: estado.pagina
		}, peticion.signal ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( 'respuesta' );
			}

			var d = json.data;

			if ( agregar ) {
				filas.insertAdjacentHTML( 'beforeend', d.html );
			} else {
				filas.innerHTML = d.html;
				familias.innerHTML = d.familias;
			}

			estado.paginas = d.paginas;
			estadoEl.textContent = d.estado;
			pie.hidden = estado.pagina >= estado.paginas;
			raiz.classList.remove( 'is-loading' );
			actualizarUrl();
		} ).catch( function ( error ) {
			if ( error && 'AbortError' === error.name ) {
				return;
			}
			raiz.classList.remove( 'is-loading' );
			estadoEl.textContent = '';
			aviso( cfg.textos.error, 'error' );
		} );
	}

	function recargar() {
		estado.pagina = 1;
		cargar( false );
	}

	// Filtros por línea y familia + "cargar más" + agregar al pedido.
	raiz.addEventListener( 'click', function ( evento ) {
		var chipLinea = evento.target.closest( '[data-cj-lineas] [data-linea]' );
		var chipFamilia = evento.target.closest( '[data-cj-familias] [data-familia]' );
		var mas = evento.target.closest( '[data-cj-mas]' );
		var agregar = evento.target.closest( '[data-cj-agregar]' );

		if ( chipLinea ) {
			estado.linea = chipLinea.getAttribute( 'data-linea' );
			estado.familia = '';
			marcarLinea();
			recargar();
		} else if ( chipFamilia ) {
			estado.familia = chipFamilia.getAttribute( 'data-familia' );
			recargar();
		} else if ( mas ) {
			estado.pagina += 1;
			cargar( true );
		} else if ( agregar ) {
			agregarAlPedido( agregar );
		}
	} );

	// Precio en vivo al cambiar de acabado.
	raiz.addEventListener( 'change', function ( evento ) {
		var select = evento.target.closest( '[data-cj-acabado]' );
		if ( ! select ) {
			return;
		}
		var precio = select.closest( 'tr' ).querySelector( '[data-cj-precio]' );
		var opcion = select.options[ select.selectedIndex ];
		if ( precio && opcion && opcion.getAttribute( 'data-precio' ) ) {
			precio.textContent = opcion.getAttribute( 'data-precio' );
		}
	} );

	if ( buscador ) {
		buscador.addEventListener( 'input', function () {
			window.clearTimeout( temporizador );
			temporizador = window.setTimeout( function () {
				var q = buscador.value.trim();
				if ( q === estado.q ) {
					return;
				}
				estado.q = q;
				recargar();
			}, 250 );
		} );

		// Enter no envía ningún formulario: la búsqueda ya es instantánea.
		buscador.addEventListener( 'keydown', function ( evento ) {
			if ( 'Enter' === evento.key ) {
				evento.preventDefault();
			}
		} );
	}

	function agregarAlPedido( boton ) {
		var fila = boton.closest( 'tr' );
		var select = fila.querySelector( '[data-cj-acabado]' );
		var qty = fila.querySelector( '[data-cj-qty]' );
		var cantidad = Math.max( 1, parseInt( qty.value, 10 ) || 1 );

		boton.disabled = true;
		boton.classList.add( 'is-busy' );
		boton.textContent = cfg.textos.agregando;

		post( {
			action: cfg.accionAgregar,
			product_id: fila.getAttribute( 'data-product-id' ),
			variation_id: select ? select.value : 0,
			cantidad: cantidad
		} ).then( function ( json ) {
			if ( json && json.success ) {
				aviso( json.data.mensaje, 'ok' );
				cantidadEl.textContent = json.data.cantidad;
				pedidoBar.hidden = false;
				qty.value = 1;
			} else {
				aviso( ( json && json.data && json.data.mensaje ) || cfg.textos.error, 'error' );
			}
		} ).catch( function () {
			aviso( cfg.textos.error, 'error' );
		} ).then( function () {
			boton.disabled = false;
			boton.classList.remove( 'is-busy' );
			boton.textContent = cfg.textos.agregar;
		} );
	}
}() );
