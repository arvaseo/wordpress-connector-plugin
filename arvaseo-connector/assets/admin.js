/* Arva SEO Connector admin: confirmations, copy-to-clipboard and the Speed filter. No data leaves the page. */
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

	// Speed: show all fixes, or only those the test asks for, on, being tried or off. Without
	// JavaScript every fix shows and the filter stays hidden.
	var filter = document.querySelector( '[data-arvaseo-filter]' );
	if ( filter ) {
		var buttons = filter.querySelectorAll( 'button[data-filter]' );
		var apply = function ( value ) {
			document.querySelectorAll( '.arvaseo-fix[data-state]' ).forEach( function ( row ) {
				var show = 'all' === value || ( 'asked' === value ? '1' === row.getAttribute( 'data-asked' ) : row.getAttribute( 'data-state' ) === value );
				row.hidden = ! show;
			} );
			document.querySelectorAll( '[data-arvaseo-group]' ).forEach( function ( group ) {
				var empty = ! group.querySelector( '.arvaseo-fix:not([hidden])' );
				var head = document.querySelector( '[data-arvaseo-group-head="' + group.getAttribute( 'data-arvaseo-group' ) + '"]' );
				group.hidden = empty;
				if ( head ) {
					head.hidden = empty;
				}
			} );
			buttons.forEach( function ( button ) {
				button.setAttribute( 'aria-pressed', button.getAttribute( 'data-filter' ) === value ? 'true' : 'false' );
			} );
		};
		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				apply( button.getAttribute( 'data-filter' ) );
			} );
		} );
		filter.hidden = false;
		// A link to one fix (#arvaseo-fix-…) always shows it.
		window.addEventListener( 'hashchange', function () {
			if ( window.location.hash.indexOf( '#arvaseo-fix-' ) === 0 ) {
				apply( 'all' );
			}
		} );
	}
} )();
