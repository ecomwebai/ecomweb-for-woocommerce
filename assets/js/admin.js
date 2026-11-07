( function () {
	'use strict';

	// Confirm screen: the overwrite confirmation shows only while "Overwrite products already copied" is ticked.
	var overwrite = document.getElementById( 'ecomweb-overwrite' );
	var confirmRow = document.getElementById( 'ecomweb-overwrite-confirm' );
	if ( overwrite && confirmRow ) {
		var confirmBox = confirmRow.querySelector( 'input' );
		var sync = function () {
			confirmRow.hidden = ! overwrite.checked;
			confirmBox.required = overwrite.checked;
		};
		overwrite.addEventListener( 'change', sync );
		sync();
	}
} )();

( function () {
	'use strict';

	var cfg = window.ecomwebWoo;
	var button = document.getElementById( 'ecomweb-sign-in' );
	var signedOut = document.getElementById( 'ecomweb-signed-out' );
	var panel = document.getElementById( 'ecomweb-signing-in' );
	if ( ! cfg || ! panel ) {
		return;
	}
	var codeBox = document.getElementById( 'ecomweb-code-box' );
	var codeEl = document.getElementById( 'ecomweb-user-code' );
	var link = document.getElementById( 'ecomweb-open' );
	var statusEl = document.getElementById( 'ecomweb-status' );
	var timer = null;

	function post( action ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( res ) {
			return res.json().catch( function () {
				return { success: false };
			} );
		} );
	}

	function say( text ) {
		statusEl.textContent = text;
	}

	// Back to the button, with a message.
	function reset( text ) {
		window.clearTimeout( timer );
		panel.hidden = true;
		codeBox.hidden = true;
		if ( signedOut ) {
			signedOut.hidden = false;
		}
		if ( button ) {
			button.disabled = false;
		}
		say( text );
		if ( text ) {
			panel.hidden = false;
		}
	}

	function schedule( seconds ) {
		timer = window.setTimeout( poll, Math.max( 1, seconds || 5 ) * 1000 );
	}

	function poll() {
		post( 'ecomweb_device_poll' ).then(
			function ( res ) {
				if ( ! res.success ) {
					reset( res.data && res.data.message ? res.data.message : cfg.i18n.error );
					return;
				}
				var data = res.data;
				if ( 'approved' === data.status ) {
					say( cfg.i18n.approved );
					window.location.reload();
				} else if ( 'denied' === data.status || 'expired' === data.status ) {
					reset( cfg.i18n[ data.status ] );
				} else {
					schedule( data.interval );
				}
			},
			function () {
				schedule( 5 );
			}
		);
	}

	function safeUrl( url ) {
		return /^https?:\/\//i.test( url ) ? url : '';
	}

	function start() {
		button.disabled = true;
		panel.hidden = false;
		codeBox.hidden = true;
		say( cfg.i18n.starting );
		post( 'ecomweb_device_start' ).then(
			function ( res ) {
				if ( ! res.success ) {
					reset( res.data && res.data.message ? res.data.message : cfg.i18n.error );
					return;
				}
				var url = safeUrl( res.data.verification_uri_complete );
				if ( ! url ) {
					reset( cfg.i18n.error );
					return;
				}
				signedOut.hidden = true;
				codeEl.textContent = res.data.user_code;
				link.href = url;
				codeBox.hidden = false;
				say( cfg.i18n.waiting );
				window.open( url, '_blank', 'noopener,noreferrer' );
				schedule( res.data.interval );
			},
			function () {
				reset( cfg.i18n.error );
			}
		);
	}

	if ( button ) {
		button.addEventListener( 'click', start );
	}
	if ( '1' === panel.getAttribute( 'data-resume' ) ) {
		say( cfg.i18n.waiting );
		schedule( 5 );
	}
}() );
