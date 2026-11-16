/**
 * Pedido / cotización por WhatsApp desde el carrito.
 */
( function () {
	'use strict';

	var cfg = window.cjb2bWhatsApp;
	var raiz = document.querySelector( '[data-cj-wa]' );

	if ( ! cfg || ! raiz ) {
		return;
	}

	var form = raiz.querySelector( '[data-cj-wa-form]' );
	var boton = raiz.querySelector( '[data-cj-wa-enviar]' );
	var errorEl = raiz.querySelector( '[data-cj-wa-error]' );
	var direccion = raiz.querySelector( '[data-cj-wa-direccion]' );
	var exito = raiz.querySelector( '[data-cj-wa-exito]' );
	var textoBoton = boton.textContent;
	var enviando = false;

	function mostrarError( texto ) {
		errorEl.textContent = texto;
		errorEl.hidden = false;
	}

	form.addEventListener( 'change', function ( evento ) {
		if ( 'cj_entrega' === evento.target.name ) {
			direccion.hidden = 'envio' !== evento.target.value;
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
		boton.disabled = true;
		boton.textContent = cfg.textos.enviando;

		var cuerpo = new URLSearchParams( new FormData( form ) );
		cuerpo.append( 'action', cfg.accion );

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

			var titulo = raiz.querySelector( '[data-cj-wa-exito-titulo]' );
			titulo.textContent = json.data.titulo;
			raiz.querySelector( '[data-cj-wa-link]' ).href = json.data.url;

			form.hidden = true;
			exito.hidden = false;
			titulo.focus();

			// El pedido ya se registró y el carrito quedó vacío: se oculta el
			// listado viejo para que no se reenvíe por error.
			document.querySelectorAll( '.woocommerce-cart-form, .cart_totals > h2, .cart_totals > table, .wp-block-woocommerce-cart' ).forEach( function ( el ) {
				el.style.display = 'none';
			} );

			// Si el navegador bloquea la ventana nueva, queda el botón visible.
			window.open( json.data.url, '_blank', 'noopener' );
		} ).catch( function ( error ) {
			mostrarError( ( error && error.message && 'Failed to fetch' !== error.message ) ? error.message : cfg.textos.error );
			enviando = false;
			boton.disabled = false;
			boton.textContent = textoBoton;
		} );
	} );
}() );
