/**
 * Solicitud de cuenta gremio ([cj_alta_b2b]).
 */
( function () {
	'use strict';

	var cfg = window.cjb2bAlta;
	var raiz = document.querySelector( '[data-cj-alta]' );

	if ( ! cfg || ! raiz ) {
		return;
	}

	var form = raiz.querySelector( '[data-cj-alta-form]' );
	var boton = raiz.querySelector( '[data-cj-alta-enviar]' );
	var errorEl = raiz.querySelector( '[data-cj-alta-error]' );
	var exito = raiz.querySelector( '[data-cj-alta-exito]' );
	var textoBoton = boton.textContent;
	var enviando = false;

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

			raiz.querySelector( '[data-cj-alta-link]' ).href = json.data.url;
			form.hidden = true;
			exito.hidden = false;
			raiz.querySelector( '[data-cj-alta-exito-titulo]' ).focus();

			// Si el navegador bloquea la ventana nueva, queda el botón visible.
			window.open( json.data.url, '_blank', 'noopener' );
		} ).catch( function ( error ) {
			errorEl.textContent = ( error && error.message && 'Failed to fetch' !== error.message ) ? error.message : cfg.textos.error;
			errorEl.hidden = false;
			enviando = false;
			boton.disabled = false;
			boton.textContent = textoBoton;
		} );
	} );
}() );
