<?php
/**
 * Text-only adapter for the bundled JavaScript minifier.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Vendor\Minify\JS;

defined( 'ABSPATH' ) || exit;

/** No caller input can trigger the upstream file loader. */
final class InlineJsMinifier extends JS {

	/**
	 * The only filesystem reads allowed are the library's bundled grammar tables.
	 *
	 * @param mixed $path Path.
	 * @return bool
	 */
	protected function canImportFile( $path ) {
		return false;
	}
}
