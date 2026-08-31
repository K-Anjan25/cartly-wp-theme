<?php
/**
 * Cartly theme bootstrap.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

define( 'CARTLY_VERSION', '2.0.0' );
define( 'CARTLY_DIR', get_template_directory() );
define( 'CARTLY_URI', get_template_directory_uri() );

require_once CARTLY_DIR . '/inc/setup.php';
require_once CARTLY_DIR . '/inc/enqueue.php';
require_once CARTLY_DIR . '/inc/template-tags.php';
require_once CARTLY_DIR . '/inc/nav-walker.php';
require_once CARTLY_DIR . '/inc/customizer.php';
