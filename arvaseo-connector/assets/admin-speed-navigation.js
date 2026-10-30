/* Speed screen only: local navigation, no requests or setting changes. */
( function () {
	'use strict';
	var root = document.querySelector( '[data-arvaseo-speed]' );
	if ( ! root ) { return; }
	var nav = root.querySelector( '[data-arvaseo-speed-nav]' );
	var container = root.querySelector( '[data-arvaseo-speed-panels]' );
	var select = root.querySelector( '[data-arvaseo-speed-select]' );
	if ( ! nav || ! container || ! select ) { return; }
	var tabs = Array.from( nav.querySelectorAll( '[data-arvaseo-speed-tab]' ) );
	var panels = {};
	tabs.forEach( function ( tab ) {
		var panel = document.getElementById( tab.getAttribute( 'aria-controls' ) );
		if ( panel && container.contains( panel ) ) { panels[ tab.getAttribute( 'data-arvaseo-speed-tab' ) ] = panel; }
	} );
	if ( tabs.length !== Object.keys( panels ).length || ! panels.css ) { return; }

	// Move complete sections once; switching visibility never recreates a form or loses its edits.
	root.querySelectorAll( '[data-arvaseo-speed-section]' ).forEach( function ( section ) {
		var destination = panels[ section.getAttribute( 'data-arvaseo-speed-section' ) ];
		if ( destination ) { destination.appendChild( section ); }
	} );
	var empty = document.createElement( 'p' );
	empty.className = 'arvaseo-hint';
	empty.setAttribute( 'role', 'status' );
	empty.hidden = true;
	// The translated copy stays in the PHP navigation markup.
	empty.textContent = nav.getAttribute( 'data-empty-message' ) || '';
	container.appendChild( empty );
	var filter = root.querySelector( '[data-arvaseo-filter]' );
	var filterBar = root.querySelector( '[data-arvaseo-speed-filter-bar]' );
	var current = 'css';
	var storageKey = 'arvaseoSpeedSection:' + window.location.pathname;
	var syncFilter = function () {
		var rows = Array.from( panels[ current ].querySelectorAll( '.arvaseo-fix[data-state]' ) );
		if ( filterBar ) { filterBar.hidden = rows.length === 0; }
		empty.hidden = rows.length === 0 || rows.some( function ( row ) { return ! row.hidden; } );
		if ( filter ) {
			filter.querySelectorAll( 'button[data-filter]' ).forEach( function ( button ) {
				var kind = button.getAttribute( 'data-filter' );
				var count = rows.filter( function ( row ) {
					return kind === 'all' || ( kind === 'asked' ? row.getAttribute( 'data-asked' ) === '1' : row.getAttribute( 'data-state' ) === kind );
				} ).length;
				var number = button.querySelector( '.arvaseo-seg-n' );
				if ( number ) { number.textContent = String( count ); }
				button.hidden = kind !== 'all' && count === 0 && button.getAttribute( 'aria-pressed' ) !== 'true';
			} );
		}
	};
	var show = function ( key ) {
		if ( ! Object.prototype.hasOwnProperty.call( panels, key ) ) { return; }
		current = key;
		Object.keys( panels ).forEach( function ( name ) { panels[ name ].hidden = name !== key; } );
		tabs.forEach( function ( tab ) {
			var active = tab.getAttribute( 'data-arvaseo-speed-tab' ) === key;
			tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			tab.tabIndex = active ? 0 : -1;
		} );
		select.value = key;
		try { window.sessionStorage.setItem( storageKey, key ); } catch ( e ) {}
		syncFilter();
	};
	var fromHash = function ( hash, fallback ) {
		var id;
		try { id = decodeURIComponent( ( hash || '' ).slice( 1 ) ); } catch ( e ) { id = ''; }
		var target = id ? document.getElementById( id ) : null;
		var panel = target && root.contains( target ) ? target.closest( '[data-arvaseo-speed-panel]' ) : null;
		show( panel ? panel.getAttribute( 'data-arvaseo-speed-panel' ) : fallback );
		if ( panel && target.classList.contains( 'arvaseo-fix' ) && filter ) {
			var all = filter.querySelector( 'button[data-filter="all"]' );
			if ( all ) { all.click(); }
		}
		if ( panel ) { target.scrollIntoView( { block: 'start' } ); }
	};
	var choose = function ( key ) {
		show( key );
		var hash = '#' + panels[ current ].id;
		if ( window.location.hash !== hash ) {
			try { window.history.pushState( null, '', hash ); } catch ( e ) { /* Navigation still works without URL updates. */ }
		}
	};
	tabs.forEach( function ( tab, index ) {
		tab.addEventListener( 'click', function () { choose( tab.getAttribute( 'data-arvaseo-speed-tab' ) ); } );
		tab.addEventListener( 'keydown', function ( event ) {
			var next = index;
			var rtl = document.documentElement.dir === 'rtl';
			if ( event.key === 'ArrowRight' ) { next = ( index + ( rtl ? -1 : 1 ) + tabs.length ) % tabs.length; }
			else if ( event.key === 'ArrowLeft' ) { next = ( index + ( rtl ? 1 : -1 ) + tabs.length ) % tabs.length; }
			else if ( event.key === 'Home' ) { next = 0; }
			else if ( event.key === 'End' ) { next = tabs.length - 1; }
			else { return; }
			event.preventDefault();
			choose( tabs[ next ].getAttribute( 'data-arvaseo-speed-tab' ) );
			tabs[ next ].focus();
		} );
	} );
	select.addEventListener( 'change', function () { choose( select.value ); } );
	root.addEventListener( 'click', function ( event ) {
		if ( filter && filter.contains( event.target ) ) { syncFilter(); }
	} );
	// Reveal the destination before the browser follows an existing local link.
	root.querySelectorAll( 'a[href^="#"]' ).forEach( function ( link ) {
		link.addEventListener( 'click', function () { fromHash( link.getAttribute( 'href' ), current ); } );
	} );
	window.addEventListener( 'hashchange', function () { fromHash( window.location.hash, 'css' ); } );
	window.addEventListener( 'popstate', function () { fromHash( window.location.hash, 'css' ); } );
	var remembered = 'css';
	try { remembered = window.sessionStorage.getItem( storageKey ) || 'css'; } catch ( e ) {}
	container.hidden = false;
	nav.hidden = false;
	fromHash( window.location.hash, Object.prototype.hasOwnProperty.call( panels, remembered ) ? remembered : 'css' );
} )();
