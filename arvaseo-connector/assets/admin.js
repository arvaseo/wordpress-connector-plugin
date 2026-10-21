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
	// Health → Security scan and Deep scan: the step, bar and detail, from the progress check.
	var deep = document.querySelector( '[data-arvaseo-deep-running]' );
	var showProgress = function ( box, now ) {
		if ( ! box || ! now ) {
			return;
		}
		var label = box.querySelector( '[data-arvaseo-progress-label]' );
		var bar = box.querySelector( '[data-arvaseo-progress-bar]' );
		var detail = box.querySelector( '[data-arvaseo-progress-detail]' );
		if ( label && now.label ) {
			label.textContent = now.label;
		}
		if ( bar ) {
			// A known percent while sending; otherwise the bar moves without one.
			if ( typeof now.percent === 'number' ) {
				bar.value = now.percent;
				bar.textContent = now.percent + '%';
			} else {
				bar.removeAttribute( 'value' );
				bar.textContent = '';
			}
		}
		if ( detail && now.detail ) {
			detail.textContent = now.detail;
		}
	};
	var showDeep = function ( now ) {
		showProgress( deep, now );
	};
	// Health → Security: while a scan runs, show its progress, and reload when it's done.
	var running = document.querySelector( '[data-arvaseo-security-running]' );
	if ( running && strings.ajaxUrl && strings.securityNonce ) {
		var check = function () {
			var body = new window.FormData();
			body.append( 'action', 'arvaseo_connector_security_progress' );
			body.append( '_ajax_nonce', strings.securityNonce );
			window.fetch( strings.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( reply ) {
					if ( ! reply || ! reply.success ) {
						return;
					}
					if ( ! reply.data.running ) {
						window.location.reload();
						return;
					}
					showProgress( running, reply.data.scan_now );
					showDeep( reply.data.deep_now );
					window.setTimeout( check, 5000 );
				} )
				.catch( function () {
					window.setTimeout( check, 15000 );
				} );
		};
		window.setTimeout( check, 3000 );
	}
	// Health → Deep scan: the same check (it also moves the deep scan on), reloading when it's done.
	if ( deep && ! running && strings.ajaxUrl && strings.securityNonce ) {
		var checkDeep = function () {
			var body = new window.FormData();
			body.append( 'action', 'arvaseo_connector_security_progress' );
			body.append( '_ajax_nonce', strings.securityNonce );
			window.fetch( strings.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( reply ) {
					if ( ! reply || ! reply.success ) {
						return;
					}
					if ( ! reply.data.deep ) {
						window.location.reload();
						return;
					}
					showDeep( reply.data.deep_now );
					window.setTimeout( checkDeep, 5000 );
				} )
				.catch( function () {
					window.setTimeout( checkDeep, 15000 );
				} );
		};
		window.setTimeout( checkDeep, 3000 );
	}
} )();
