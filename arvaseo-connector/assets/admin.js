/* Arva SEO Connector admin: confirmations and copy-to-clipboard. No data leaves the page. */
( function () {
	'use strict';
	var strings = window.arvaseoConnector || {};

	document.querySelectorAll( 'form[data-arvaseo-confirm]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var message = strings[ form.getAttribute( 'data-arvaseo-confirm' ) ];
			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	} );

	document.querySelectorAll( 'input[data-arvaseo-confirm-check]' ).forEach( function ( input ) {
		var initiallyChecked = input.checked;
		input.addEventListener( 'change', function () {
			var message = strings[ input.getAttribute( 'data-arvaseo-confirm-check' ) ];
			if ( input.checked && ! initiallyChecked && message && ! window.confirm( message ) ) {
				input.checked = false;
			}
		} );
	} );

	document.querySelectorAll( '[data-arvaseo-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var field = document.getElementById( button.getAttribute( 'data-arvaseo-copy' ) );
			var status = document.querySelector( '.arvaseo-copied' );
			if ( ! field ) {
				return;
			}
			var done = function () {
				if ( status ) {
					status.textContent = strings.copied || 'Copied.';
				}
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( field.value ).then( done );
			} else {
				field.select();
				document.execCommand( 'copy' );
				done();
			}
		} );
	} );
} )();
