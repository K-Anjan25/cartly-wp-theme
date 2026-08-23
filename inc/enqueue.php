<?php
/**
 * Assets.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end styles and scripts.
 */
function cartly_enqueue_assets() {
	// Google Fonts — the exact families the design system specifies.
	wp_enqueue_style(
		'cartly-fonts',
		'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@500;600;700;800&family=Instrument+Serif:ital@0;1&family=IBM+Plex+Mono:wght@500;600&display=swap',
		array(),
		null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts is versioned by URL.
	);

	$css = CARTLY_DIR . '/assets/css/cartly.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style(
			'cartly-main',
			CARTLY_URI . '/assets/css/cartly.css',
			array( 'cartly-fonts' ),
			(string) filemtime( $css )
		);
	}

	// The theme header stylesheet (required by WordPress, tiny).
	wp_enqueue_style(
		'cartly-style',
		get_stylesheet_uri(),
		array(),
		CARTLY_VERSION
	);

	$js = CARTLY_DIR . '/assets/js/theme.js';
	if ( file_exists( $js ) ) {
		wp_enqueue_script(
			'cartly-theme',
			CARTLY_URI . '/assets/js/theme.js',
			array(),
			(string) filemtime( $js ),
			true
		);
		wp_localize_script(
			'cartly-theme',
			'cartlyI18n',
			array(
				'toLight' => __( 'Switch to light mode', 'cartly' ),
				'toDark'  => __( 'Switch to dark mode', 'cartly' ),
			)
		);
	}

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'cartly_enqueue_assets' );

/**
 * Preconnect to the font CDN.
 *
 * @param array  $urls           URLs to print.
 * @param string $relation_type  Relation.
 * @return array
 */
function cartly_resource_hints( $urls, $relation_type ) {
	if ( 'preconnect' === $relation_type ) {
		$urls[] = array( 'href' => 'https://fonts.googleapis.com' );
		$urls[] = array(
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		);
	}
	return $urls;
}
add_filter( 'wp_resource_hints', 'cartly_resource_hints', 10, 2 );

/**
 * Browser UI colour.
 */
function cartly_theme_color_meta() {
	echo '<meta name="theme-color" content="#0B0B0F">' . "\n";
}
add_action( 'wp_head', 'cartly_theme_color_meta' );

/**
 * Editor styles so the block editor matches the front end.
 */
function cartly_editor_assets() {
	$css = CARTLY_DIR . '/assets/css/cartly.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style(
			'cartly-editor',
			CARTLY_URI . '/assets/css/cartly.css',
			array(),
			(string) filemtime( $css )
		);
	}
}
add_action( 'enqueue_block_editor_assets', 'cartly_editor_assets' );
