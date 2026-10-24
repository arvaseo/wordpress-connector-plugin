<?php
/**
 * Text-only adapter for the bundled CSS minifier. Imports and local file discovery are disabled.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Vendor\Minify\CSS;

defined( 'ABSPATH' ) || exit;

/** Minifies one validated stylesheet while retaining its URL base. */
final class InlineCssMinifier extends CSS {

	/**
	 * Already-read CSS: never hand an unchecked path to the upstream file loader.
	 *
	 * @param string $text   CSS text.
	 * @param string $source Original public URL.
	 */
	public function __construct( string $text, string $source ) {
		$text                  = str_starts_with( $text, "\xEF\xBB\xBF" ) ? substr( $text, 3 ) : $text;
		$this->data[ $source ] = str_replace( array( "\r\n", "\r" ), "\n", $text );
	}

	/**
	 * Keep @import as a browser request; do not read the named file.
	 *
	 * @param mixed $source  Original source.
	 * @param mixed $content CSS.
	 * @param mixed $parents Parent imports.
	 * @return string
	 */
	protected function combineImports( $source, $content, $parents ) {
		return (string) $content;
	}

	/**
	 * Never inline image or font files.
	 *
	 * @param mixed $source  Original source.
	 * @param mixed $content CSS.
	 * @return string
	 */
	protected function importFiles( $source, $content ) {
		return (string) $content;
	}

	/**
	 * No implicit file reads, including a CSS string that happens to name a local file.
	 *
	 * @param mixed $path Path.
	 * @return bool
	 */
	protected function canImportFile( $path ) {
		return false;
	}

	/**
	 * Preserve fragments and absolute schemes in addition to upstream's URL exclusions.
	 *
	 * @param mixed $path URL value.
	 * @return bool
	 */
	protected function canImportByPath( $path ) {
		return 0 === preg_match( '/^(?:#|[a-z][a-z0-9+.-]*:|\/)/i', (string) $path );
	}
}
