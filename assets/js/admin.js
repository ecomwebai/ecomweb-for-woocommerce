( function () {
	'use strict';

	// Forms that ask first (Disconnect).
	Array.prototype.forEach.call( document.querySelectorAll( 'form[data-confirm]' ), function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	// Connected page: while a copy runs, poll its status. Each answer rewrites "Copying X of Y"
	// and the counts; a finished copy reloads the page. Failed polls (an expired nonce, a
	// logged-out admin, the network) back off from 5 s to a 60 s cap, and a note says so.
	var cfg = window.ecomwebWoo;
	var copy = document.getElementById( 'ecomweb-copy' );
	if ( cfg && copy && 'running' === copy.getAttribute( 'data-status' ) ) {
		var progress = document.getElementById( 'ecomweb-progress' );
		var counts = document.getElementById( 'ecomweb-counts' );
		var note = document.getElementById( 'ecomweb-poll-note' );
		var failures = 0;

		// Fills %1$d, %2$d… (or %d) from values.
		var format = function ( text, values ) {
			var next = 0;
			return String( text ).replace( /%(?:(\d+)\$)?d/g, function ( match, n ) {
				var value = n ? values[ n - 1 ] : values[ next++ ];
				return String( parseInt( value, 10 ) || 0 );
			} );
		};

		var schedule = function () {
			window.setTimeout( tick, Math.min( 60000, 5000 * Math.pow( 2, failures ) ) );
		};

		var failed = function () {
			failures++;
			if ( note ) {
				note.textContent = cfg.i18n.pollFailed;
				note.hidden = false;
			}
			schedule();
		};

		var show = function ( data ) {
			var c = data.counts || {};
			if ( progress ) {
				progress.textContent = format( cfg.i18n.progress, [ data.done, data.total ] );
			}
			if ( counts ) {
				counts.textContent = format( cfg.i18n.counts, [ c.created, c.updated, c.skipped, c.failed ] );
			}
			if ( note ) {
				note.hidden = true;
			}
		};

		var tick = function () {
			var body = new FormData();
			body.append( 'action', 'ecomweb_copy_status' );
			body.append( 'nonce', cfg.nonce );
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( res ) {
					if ( ! res || ! res.success || ! res.data ) {
						failed();
						return;
					}
					if ( 'running' !== res.data.status ) {
						window.location.reload();
						return;
					}
					failures = 0;
					show( res.data );
					schedule();
				} )
				.catch( failed );
		};
		schedule();
	}
} )();

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
