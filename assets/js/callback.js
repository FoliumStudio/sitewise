/**
 * Sitewise call-back form — vanilla JS AJAX submit.
 *
 * Posts the form to admin-ajax.php with a nonce; renders inline status.
 */
( function () {
	'use strict';

	var cfg = window.SitewiseCallback || {};
	if ( ! cfg.ajaxUrl ) {
		return;
	}

	function onSubmit( e ) {
		e.preventDefault();
		var form = e.currentTarget;
		var btn = form.querySelector( '.sitewise-cb-submit' );
		var status = form.querySelector( '.sitewise-cb-status' );

		status.className = 'sitewise-cb-status';
		status.textContent = cfg.strings.sending;
		btn.disabled = true;

		var body = new FormData( form );
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( res ) {
			return res.json();
		} ).then( function ( data ) {
			if ( data && data.success ) {
				form.reset();
				status.className = 'sitewise-cb-status is-ok';
				status.textContent = ( data.data && data.data.message ) || cfg.strings.thanks;
			} else {
				status.className = 'sitewise-cb-status is-error';
				status.textContent = ( data && data.data && data.data.message ) || cfg.strings.error;
			}
		} ).catch( function () {
			status.className = 'sitewise-cb-status is-error';
			status.textContent = cfg.strings.error;
		} ).then( function () {
			btn.disabled = false;
		} );
	}

	function init() {
		var forms = document.querySelectorAll( '.sitewise-cb-form' );
		for ( var i = 0; i < forms.length; i++ ) {
			forms[ i ].addEventListener( 'submit', onSubmit );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
