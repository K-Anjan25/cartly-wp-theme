<?php
/**
 * PayKaro bridge bootstrap.
 *
 * The Node bridge (serve.mjs) passes the HTTP request context as a JSON string
 * in argv[1]. We translate it into PHP superglobals, then run the real app.
 *
 * The app writes its response as a JSON object (status, type, location,
 * cookies, body) to stdout, which serve.mjs parses and turns back into HTTP.
 */

error_reporting( 0 );
ini_set( 'display_errors', '0' );

// Tells public/index.php that serve.mjs will turn its JSON envelope into HTTP,
// so it must not emit headers/body itself.
define( 'PAYKARO_BRIDGE', true );

$raw = isset( $argv[1] ) ? $argv[1] : '';
$ctx = json_decode( (string) $raw, true );
$ctx = is_array( $ctx ) ? $ctx : array();

$_SERVER['REQUEST_METHOD'] = $ctx['method'] ?? 'GET';
$_SERVER['REQUEST_URI']    = $ctx['uri'] ?? '/';
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../public/index.php';
$_SERVER['HTTP_HOST']      = $ctx['host'] ?? 'localhost';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

$_GET     = is_array( $ctx['query'] ?? null ) ? $ctx['query'] : array();
$_POST    = is_array( $ctx['post'] ?? null ) ? $ctx['post'] : array();
$_COOKIE  = is_array( $ctx['cookies'] ?? null ) ? $ctx['cookies'] : array();
$_REQUEST = array_merge( $_GET, $_POST );

require __DIR__ . '/../public/index.php';
