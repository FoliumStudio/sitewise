/**
 * Sitewise chat widget — vanilla JS, Folium ".sww-*" design.
 *
 * Reads window.SitewiseConfig. Talks to the Cloudflare Worker /chat endpoint.
 * When the Worker reports it can't answer ({fallback:true}), the widget pivots:
 * it offers a call-back form inline in the thread, which submits to the WordPress
 * site's own admin-ajax (same origin) via the call-back module.
 */
( function () {
	'use strict';

	// Config is read in init(): from the mount node's data-sitewise-config (the
	// reliable path — it rides with the markup), falling back to a global if set.
	var cfg = {}, mode = 'chat', ho = { enabled: false };

	var SVG = {
		chat: '<svg class="ic-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.5 7.2L4 20l1-4.5A8 8 0 1 1 21 12Z"/></svg>',
		close: '<svg class="ic-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg>',
		send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12 20 4l-6 16-3-7-7-1Z"/></svg>',
		x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg>',
		phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h3l1.5 5-2 1.5a12 12 0 0 0 5.5 5.5l1.5-2 5 1.5v3a2 2 0 0 1-2.2 2A17 17 0 0 1 4 5.2 2 2 0 0 1 6 3Z"/></svg>'
	};

	var history = [];

	function el( tag, cls ) { var n = document.createElement( tag ); if ( cls ) { n.className = cls; } return n; }

	function escapeHtml( s ) { var d = document.createElement( 'div' ); d.textContent = s; return d.innerHTML; }

	function md( text ) {
		var html = escapeHtml( text );
		html = html.replace( /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>' );
		html = html.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );
		html = html.replace( /\n/g, '<br>' );
		return html;
	}

	function bubble( who, text ) { var b = el( 'div', 'sww-msg ' + who ); b.innerHTML = md( text ); return b; }

	function end( thread ) { thread.scrollTop = thread.scrollHeight; }

	function buildPanel( opts ) {
		var panel = el( 'div', 'sww-panel' );

		var head = el( 'div', 'sww-head' );
		var ava = el( 'div', 'sww-ava' ); ava.textContent = ( cfg.siteName || 'S' ).charAt( 0 ).toUpperCase();
		var ht = el( 'div', 'sww-head-t' );
		var hb = el( 'b' ); hb.textContent = ( mode === 'callback' && ho.strings ) ? ho.strings.title : cfg.strings.title;
		var hs = el( 'span' ); hs.innerHTML = '<span class="d"></span>' + escapeHtml( cfg.siteName || '' );
		ht.appendChild( hb ); ht.appendChild( hs );
		head.appendChild( ava ); head.appendChild( ht );
		if ( opts.floating ) {
			var x = el( 'button', 'x' ); x.innerHTML = SVG.x; x.setAttribute( 'aria-label', 'Close' );
			x.addEventListener( 'click', function () { panel.parentNode.classList.remove( 'is-open' ); } );
			head.appendChild( x );
		}
		panel.appendChild( head );

		// Call-back mode: the panel body is the request-a-call-back form. No
		// chat thread, no composer, no Worker — submits to admin-ajax directly.
		if ( mode === 'callback' ) {
			var body = el( 'div', 'sww-cbbody' );
			if ( ho.strings && ho.strings.intro ) {
				var intro = el( 'div', 'sww-cbintro' ); intro.textContent = ho.strings.intro;
				body.appendChild( intro );
			}
			var card = el( 'div', 'sww-handoff' );
			card.appendChild( cbForm() );
			body.appendChild( card );
			panel.appendChild( body );
			if ( cfg.poweredBy ) { var pbc = el( 'div', 'sww-powered' ); pbc.innerHTML = escapeHtml( cfg.strings.poweredBy ); panel.appendChild( pbc ); }
			return panel;
		}

		var thread = el( 'div', 'sww-thread' );
		panel.appendChild( thread );
		if ( cfg.opening ) { thread.appendChild( bubble( 'bot', cfg.opening ) ); }

		var form = el( 'form', 'sww-composer' );
		var input = el( 'input' ); input.type = 'text'; input.placeholder = cfg.strings.placeholder; input.autocomplete = 'off';
		var send = el( 'button', 'sww-send' ); send.type = 'submit'; send.innerHTML = SVG.send; send.setAttribute( 'aria-label', cfg.strings.send );
		form.appendChild( input ); form.appendChild( send );
		panel.appendChild( form );

		if ( cfg.poweredBy ) { var pb = el( 'div', 'sww-powered' ); pb.innerHTML = escapeHtml( cfg.strings.poweredBy ); panel.appendChild( pb ); }

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var msg = input.value.trim();
			if ( ! msg ) { return; }
			input.value = '';
			ask( msg, thread, send, input );
		} );

		return panel;
	}

	function ask( msg, thread, send, input ) {
		thread.appendChild( bubble( 'user', msg ) ); end( thread );
		var typing = el( 'div', 'sww-typing' ); typing.innerHTML = '<i></i><i></i><i></i>';
		thread.appendChild( typing ); end( thread );
		send.disabled = true; input.disabled = true;

		fetch( cfg.workerUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { site_key: cfg.siteKey, message: msg, history: history.slice( -8 ) } )
		} ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
			return r.json();
		} ).then( function ( data ) {
			typing.remove();
			var answer = ( data && ( data.answer || data.reply || data.text ) ) || cfg.strings.error;
			thread.appendChild( bubble( 'bot', answer ) );
			history.push( { role: 'user', content: msg } );
			history.push( { role: 'assistant', content: answer } );
			if ( data && data.fallback && ho.enabled ) {
				thread.appendChild( buildHandoff() );
			}
			end( thread );
		} ).catch( function () {
			typing.remove();
			thread.appendChild( bubble( 'bot', cfg.strings.error ) );
			end( thread );
		} ).then( function () {
			send.disabled = false; input.disabled = false; input.focus();
		} );
	}

	// Build the call-back request form element (shared by call-back mode and the
	// chat can't-answer pivot). On success it replaces itself with a thank-you.
	function cbForm() {
		var form = el( 'form' );
		form.innerHTML =
			'<input name="cb_name" type="text" placeholder="' + escapeHtml( ho.strings.name ) + '" required>' +
			'<div class="row2">' +
				'<input name="cb_phone" type="tel" placeholder="' + escapeHtml( ho.strings.phone ) + '" required>' +
				'<input name="cb_time" type="text" placeholder="' + escapeHtml( ho.strings.time ) + '">' +
			'</div>' +
			'<input name="cb_email" type="email" placeholder="' + escapeHtml( ho.strings.email ) + '">' +
			'<div class="hp"><input name="cb_website" type="text" tabindex="-1" autocomplete="off"></div>' +
			'<button type="submit">' + SVG.phone + '<span>' + escapeHtml( ho.strings.submit ) + '</span></button>' +
			'<div class="err" role="alert" hidden></div>';

		if ( cfg.contact ) {
			var alt = el( 'div', 'alt' );
			alt.innerHTML = ho.strings.or + ' <a href="' + encodeURI( cfg.contact ) + '" target="_blank" rel="noopener">' + escapeHtml( ho.strings.contact ) + '</a>';
			form.appendChild( alt );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitCb( form );
		} );
		return form;
	}

	// The chat can't-answer pivot: the same form wrapped with its own header.
	function buildHandoff() {
		var wrap = el( 'div', 'sww-handoff' );
		var hd = el( 'div', 'hd' ); hd.innerHTML = SVG.phone + '<span>' + escapeHtml( ho.strings.title ) + '</span>';
		wrap.appendChild( hd );
		wrap.appendChild( cbForm() );
		return wrap;
	}

	function submitCb( form ) {
		var btn = form.querySelector( 'button' );
		var err = form.querySelector( '.err' );
		err.hidden = true;
		btn.disabled = true;

		var body = new FormData( form );
		body.append( 'action', ho.action );
		body.append( 'nonce', ho.nonce );
		// In chat mode, tag the source so the lead shows it came from the assistant.
		if ( mode !== 'callback' ) { body.append( 'cb_message', 'From the on-site assistant.' ); }

		fetch( ho.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data && data.success ) {
					var ok = el( 'div', 'ok' ); ok.textContent = ho.strings.thanks;
					if ( form.parentNode ) { form.parentNode.replaceChild( ok, form ); }
				} else {
					err.hidden = false; err.textContent = ( data && data.data && data.data.message ) || ho.strings.error;
					btn.disabled = false;
				}
			} )
			.catch( function () { err.hidden = false; err.textContent = ho.strings.error; btn.disabled = false; } );
	}

	function mount( node ) {
		var inline = !! node.getAttribute( 'data-sitewise-inline' );
		var root = el( 'div', 'sww-root' + ( inline ? ' sww-inline' : '' ) );
		root.setAttribute( 'data-pos', cfg.position === 'bottom-left' ? 'bl' : 'br' );
		if ( cfg.colour ) { root.style.setProperty( '--sww-accent', cfg.colour ); }

		root.appendChild( buildPanel( { floating: ! inline } ) );

		if ( ! inline ) {
			var launch = el( 'button', 'sww-launch' );
			var label = ( mode === 'callback' && ho.strings ) ? ho.strings.title : cfg.strings.title;
			launch.setAttribute( 'aria-label', label );
			// In call-back mode the launcher is a phone icon; the close icon swaps in when open.
			var openIcon = ( mode === 'callback' ) ? SVG.phone.replace( '<svg ', '<svg class="ic-chat" ' ) : SVG.chat;
			launch.innerHTML = openIcon + SVG.close;
			launch.addEventListener( 'click', function () {
				root.classList.toggle( 'is-open' );
				if ( root.classList.contains( 'is-open' ) ) { var i = root.querySelector( '.sww-composer input, .sww-handoff input' ); if ( i ) { i.focus(); } }
			} );
			root.appendChild( launch );
		}

		node.appendChild( root );
	}

	function readConfig() {
		if ( window.SitewiseConfig && window.SitewiseConfig.mode ) { return window.SitewiseConfig; }
		var node = document.querySelector( '.sitewise-mount[data-sitewise-config]' );
		if ( node ) {
			try { return JSON.parse( node.getAttribute( 'data-sitewise-config' ) ); } catch ( e ) {}
		}
		return null;
	}

	function init() {
		cfg = readConfig();
		if ( ! cfg ) { return; }
		mode = cfg.mode === 'callback' ? 'callback' : 'chat';
		ho = cfg.handoff || { enabled: false };
		// Chat needs a Worker to talk to; call-back mode is self-contained (admin-ajax).
		if ( mode === 'chat' && ! cfg.workerUrl ) { return; }
		if ( mode === 'callback' && ! ( ho && ho.enabled ) ) { return; }

		var mounts = document.querySelectorAll( '.sitewise-mount' );
		for ( var i = 0; i < mounts.length; i++ ) { mount( mounts[ i ] ); }
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else { init(); }
} )();
