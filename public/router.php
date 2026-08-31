<?php
/**
 * Router for the PHP built-in server:
 *
 *   php -S 0.0.0.0:8080 -t public public/router.php
 *
 * Serves real files under public/ (assets) straight off disk and sends every
 * other path to the front controller, so pretty routes like /invoices work.
 */

$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?: '/';
$file = realpath( __DIR__ . $path );

if (
	$file
	&& is_file( $file )
	&& 0 === strpos( $file, realpath( __DIR__ ) )
	&& '.php' !== substr( $file, -4 )
) {
	return false; // Let the built-in server serve the static file.
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
