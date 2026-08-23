<?php
/**
 * Theme supports, menus, sidebars.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register theme supports and navigation locations.
 */
function cartly_setup() {
	load_theme_textdomain( 'cartly', CARTLY_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/cartly.css' );

	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' )
	);

	add_theme_support(
		'custom-logo',
		array(
			'height'      => 40,
			'width'       => 160,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);

	register_nav_menus(
		array(
			'primary'    => __( 'Primary (header)', 'cartly' ),
			'categories' => __( 'Category rail (storefront)', 'cartly' ),
			'footer-1'   => __( 'Footer column 1', 'cartly' ),
			'footer-2'   => __( 'Footer column 2', 'cartly' ),
			'footer-3'   => __( 'Footer column 3', 'cartly' ),
		)
	);

	// WooCommerce.
	add_theme_support( 'woocommerce', array(
		'thumbnail_image_width' => 480,
		'single_image_width'    => 960,
		'product_grid'          => array(
			'default_rows'    => 3,
			'min_rows'        => 1,
			'default_columns' => 4,
			'min_columns'     => 2,
			'max_columns'     => 4,
		),
	) );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );
}
add_action( 'after_setup_theme', 'cartly_setup' );

/**
 * Content width used by embeds.
 */
function cartly_content_width() {
	$GLOBALS['content_width'] = apply_filters( 'cartly_content_width', 720 );
}
add_action( 'after_setup_theme', 'cartly_content_width', 0 );

/**
 * Widget areas.
 */
function cartly_widgets_init() {
	register_sidebar(
		array(
			'name'          => __( 'Blog sidebar', 'cartly' ),
			'id'            => 'sidebar-1',
			'description'   => __( 'Shown beside posts and archives.', 'cartly' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'Shop sidebar (filters)', 'cartly' ),
			'id'            => 'shop-sidebar',
			'description'   => __( 'Facets on product archives. Drop WooCommerce filter widgets here.', 'cartly' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);
}
add_action( 'widgets_init', 'cartly_widgets_init' );

/**
 * Body classes used by the layout.
 *
 * @param array $classes Existing classes.
 * @return array
 */
function cartly_body_class( $classes ) {
	$classes[] = 'min-h-screen';
	$classes[] = 'bg-canvas';
	$classes[] = 'text-ink';
	$classes[] = 'antialiased';

	if ( ! file_exists( CARTLY_DIR . '/assets/css/cartly.css' ) ) {
		$classes[] = 'cartly-no-stylesheet';
	}

	return $classes;
}
add_filter( 'body_class', 'cartly_body_class' );

/**
 * Paint the stored colour scheme before first paint, so there is no light flash.
 * Mirrors frontend/src/hooks/useColorScheme.ts.
 */
function cartly_color_scheme_script() {
	?>
	<script>
	(function () {
		try {
			var stored = localStorage.getItem('cartly-color-scheme');
			var dark = stored ? stored === 'dark'
				: window.matchMedia('(prefers-color-scheme: dark)').matches;
			document.documentElement.classList.toggle('dark', dark);
			document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
		} catch (e) {}
	})();
	</script>
	<?php
}
add_action( 'wp_head', 'cartly_color_scheme_script', 1 );

/**
 * Excerpt tail.
 *
 * @return string
 */
function cartly_excerpt_more() {
	return '&hellip;';
}
add_filter( 'excerpt_more', 'cartly_excerpt_more' );
