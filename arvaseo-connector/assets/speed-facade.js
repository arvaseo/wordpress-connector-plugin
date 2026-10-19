/**
 * Arva SEO Connector: click-to-play videos. The YouTube player loads when someone presses play.
 */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.arvaseo-yt__play' ) : null;
		if ( ! button ) {
			return;
		}
		var box = button.parentNode;
		var src = box.getAttribute( 'data-arvaseo-embed' );
		if ( ! src || src.indexOf( 'https://www.youtube-nocookie.com/embed/' ) !== 0 ) {
			return;
		}
		var frame = document.createElement( 'iframe' );
		frame.setAttribute( 'src', src );
		frame.setAttribute( 'title', box.getAttribute( 'data-arvaseo-title' ) || 'YouTube video' );
		frame.setAttribute( 'allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share' );
		frame.setAttribute( 'allowfullscreen', '' );
		frame.setAttribute( 'referrerpolicy', 'strict-origin-when-cross-origin' );
		box.replaceChildren( frame );
		box.classList.add( 'is-playing' );
		frame.focus();
	} );
}() );
