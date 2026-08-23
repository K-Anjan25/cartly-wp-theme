<?php
/**
 * Seeder for the Cartly theme preview (WordPress Playground, no WooCommerce).
 *
 * The sandbox can only reach the npm registry and the GitHub API, so the
 * WooCommerce plugin zip (which redirects to objects.githubusercontent.com)
 * cannot be downloaded here. This seeds everything the theme renders WITHOUT
 * WooCommerce: the shell, hero, trust strip, category rail, post cards, single
 * post, search, 404 and dark mode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once '/wordpress/wp-load.php';
}

if ( get_option( 'cartly_seeded' ) ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

update_option( 'blogname', 'Cartly' );
update_option( 'blogdescription', 'Everything you need, one cart.' );
update_option( 'permalink_structure', '/%postname%/' );

switch_theme( 'cartly' );

/* ------------------------------------------------------------ customizer -- */
set_theme_mod( 'cartly_announcement_text', 'Free shipping over ₹999' );
set_theme_mod( 'cartly_announcement_highlight', 'Flash sale live' );
set_theme_mod( 'cartly_hero_eyebrow', 'New season · 2026' );
set_theme_mod( 'cartly_hero_title', 'Everything you' );
set_theme_mod( 'cartly_hero_title_alt', 'need, one cart.' );
set_theme_mod( 'cartly_hero_text', 'A catalog you can actually search, a checkout that does not fight you, and rewards that stack. This preview runs the real theme on real WordPress.' );
set_theme_mod( 'cartly_hero_cta_label', 'Read the journal' );
set_theme_mod( 'cartly_hero_cta2_label', 'About Cartly' );
set_theme_mod( 'cartly_footer_blurb', 'Everything you need, one cart. This demo runs the Cartly WordPress theme on WordPress 6.5 via WordPress Playground.' );

/**
 * Sideload a generated image.
 *
 * @param string $slug  File slug.
 * @param string $title Title.
 * @return int
 */
function cartly_seed_image( $slug, $title ) {
	$src = '/demo-img/' . $slug . '.png';
	if ( ! file_exists( $src ) ) {
		return 0;
	}
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return 0;
	}
	$dest = trailingslashit( $uploads['path'] ) . $slug . '.png';
	copy( $src, $dest );

	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => $title,
			'post_status'    => 'inherit',
		),
		$dest
	);
	if ( ! is_wp_error( $id ) ) {
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
	}
	return (int) $id;
}

$hero_id = cartly_seed_image( 'headphones', 'Hero' );
if ( $hero_id ) {
	set_theme_mod( 'cartly_hero_image', $hero_id );
}

/* ------------------------------------------------------------ categories -- */
$cat_ids = array();
foreach ( array( 'Electronics', 'Home', 'Fashion', 'Beauty', 'Kitchen', 'Sports' ) as $name ) {
	$term = term_exists( $name, 'category' );
	if ( ! $term ) {
		$term = wp_insert_term( $name, 'category' );
	}
	if ( ! is_wp_error( $term ) ) {
		$cat_ids[ $name ] = (int) $term['term_id'];
	}
}

/* ----------------------------------------------------------------- posts -- */
$posts = array(
	array( 'Studio Pro Headphones', 'headphones', 'Electronics', 'Closed-back studio cans with a 40mm driver, replaceable pads and a braided cable that will outlive the headphones.' ),
	array( 'Linen Throw Blanket', 'blanket', 'Home', 'Stonewashed European linen that gets softer every wash. A generous 130 × 180 cm.' ),
	array( 'Trail Runner 3', 'runners', 'Sports', 'Grippy lugged outsole, drainage ports and a rock plate. Built for wet trails.' ),
	array( 'Ceramic Pour-Over', 'pourover', 'Kitchen', 'Single-piece ceramic dripper with a spiral rib pattern for an even extraction.' ),
	array( 'Merino Crew Sweater', 'sweater', 'Fashion', '19.5 micron merino, fully fashioned shoulders, no itch. Machine washable cold.' ),
	array( 'Desk Lamp Arc', 'lamp', 'Home', 'Weighted base, stepless dimming, 2700–5000K. Casts light where you need it.' ),
	array( 'Vitamin C Serum', 'serum', 'Beauty', '15% L-ascorbic acid with ferulic acid, in amber glass so it actually stays potent.' ),
	array( 'Cast Iron Skillet', 'skillet', 'Kitchen', 'Pre-seasoned 26cm skillet. Oven, grill and campfire safe. Improves with age.' ),
);

foreach ( $posts as $i => $row ) {
	list( $title, $slug, $cat, $excerpt ) = $row;

	$content  = '<p>' . $excerpt . '</p>';
	$content .= '<h2>Why we stock it</h2><p>Every product on Cartly clears the same three tests: it has to be repairable, honestly priced, and worth owning for more than a season.</p>';
	$content .= '<blockquote><p>The cheapest product is the one you only buy once.</p></blockquote>';
	$content .= '<h3>In the box</h3><ul><li>The thing itself</li><li>A two-year warranty card</li><li>Recyclable packaging</li></ul>';
	$content .= '<p>Shipping is free over ₹999 and every order can be returned within seven days.</p>';

	$post_id = wp_insert_post(
		array(
			'post_title'    => $title,
			'post_excerpt'  => $excerpt,
			'post_content'  => $content,
			'post_status'   => 'publish',
			'post_date'     => gmdate( 'Y-m-d H:i:s', time() - $i * 86400 ),
			'post_category' => isset( $cat_ids[ $cat ] ) ? array( $cat_ids[ $cat ] ) : array(),
		)
	);

	if ( $post_id && ! is_wp_error( $post_id ) ) {
		$img = cartly_seed_image( $slug, $title );
		if ( $img ) {
			set_post_thumbnail( $post_id, $img );
		}
		wp_set_post_tags( $post_id, array( 'cartly', strtolower( $cat ) ) );
	}
}

/* ----------------------------------------------------------------- pages -- */
$about = wp_insert_post(
	array(
		'post_title'   => 'About',
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => '<p>Cartly is a demo store rendered by the <strong>Cartly WordPress theme</strong> — a port of the Cartly 2.0 design system.</p><h2>The design</h2><p>Quiet canvas, loud actions: violet does the acting, lime does the shouting, and everything else is greyscale so photography carries the page.</p><ul><li>Hairlines instead of shadows at rest</li><li>Inter Tight for headings, Instrument Serif for the hero</li><li>First-class dark mode driven by CSS custom properties</li></ul><p>Try the sun/moon toggle in the header, and resize to a phone width to see the bottom tab bar.</p>',
	)
);

/* ----------------------------------------------------------------- menus -- */
$primary = wp_create_nav_menu( 'Primary' );
if ( ! is_wp_error( $primary ) ) {
	wp_update_nav_menu_item( $primary, 0, array( 'menu-item-title' => 'Shop', 'menu-item-url' => home_url( '/' ), 'menu-item-status' => 'publish' ) );
	wp_update_nav_menu_item( $primary, 0, array( 'menu-item-title' => 'Journal', 'menu-item-url' => home_url( '/' ), 'menu-item-status' => 'publish' ) );
	if ( $about && ! is_wp_error( $about ) ) {
		wp_update_nav_menu_item(
			$primary,
			0,
			array(
				'menu-item-title'     => 'About',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $about,
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			)
		);
	}
}

$rail = wp_create_nav_menu( 'Category rail' );
if ( ! is_wp_error( $rail ) ) {
	foreach ( $cat_ids as $name => $term_id ) {
		wp_update_nav_menu_item(
			$rail,
			0,
			array(
				'menu-item-title'     => $name,
				'menu-item-object'    => 'category',
				'menu-item-object-id' => $term_id,
				'menu-item-type'      => 'taxonomy',
				'menu-item-status'    => 'publish',
			)
		);
	}
}

$footer1 = wp_create_nav_menu( 'Footer shop' );
if ( ! is_wp_error( $footer1 ) ) {
	foreach ( array( 'Electronics', 'Home', 'Fashion' ) as $name ) {
		if ( isset( $cat_ids[ $name ] ) ) {
			wp_update_nav_menu_item(
				$footer1,
				0,
				array(
					'menu-item-title'     => $name,
					'menu-item-object'    => 'category',
					'menu-item-object-id' => $cat_ids[ $name ],
					'menu-item-type'      => 'taxonomy',
					'menu-item-status'    => 'publish',
				)
			);
		}
	}
}

$footer2 = wp_create_nav_menu( 'Footer support' );
if ( ! is_wp_error( $footer2 ) ) {
	foreach ( array( 'About' => $about ) as $label => $pid ) {
		if ( $pid && ! is_wp_error( $pid ) ) {
			wp_update_nav_menu_item( $footer2, 0, array( 'menu-item-title' => $label, 'menu-item-object' => 'page', 'menu-item-object-id' => $pid, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
		}
	}
	wp_update_nav_menu_item( $footer2, 0, array( 'menu-item-title' => 'Search', 'menu-item-url' => home_url( '/?s=linen' ), 'menu-item-status' => 'publish' ) );
	wp_update_nav_menu_item( $footer2, 0, array( 'menu-item-title' => '404 example', 'menu-item-url' => home_url( '/nope/' ), 'menu-item-status' => 'publish' ) );
}

set_theme_mod(
	'nav_menu_locations',
	array(
		'primary'    => is_wp_error( $primary ) ? 0 : $primary,
		'categories' => is_wp_error( $rail ) ? 0 : $rail,
		'footer-1'   => is_wp_error( $footer1 ) ? 0 : $footer1,
		'footer-2'   => is_wp_error( $footer2 ) ? 0 : $footer2,
	)
);

/* --------------------------------------------------------------- widgets -- */
update_option( 'widget_categories', array( 2 => array( 'title' => 'Categories', 'count' => 1 ), '_multiwidget' => 1 ) );
update_option( 'widget_search', array( 2 => array( 'title' => 'Search' ), '_multiwidget' => 1 ) );
update_option( 'widget_recent-posts', array( 2 => array( 'title' => 'Recent', 'number' => 5 ), '_multiwidget' => 1 ) );
update_option(
	'sidebars_widgets',
	array(
		'wp_inactive_widgets' => array(),
		'sidebar-1'           => array( 'search-2', 'categories-2', 'recent-posts-2' ),
		'shop-sidebar'        => array( 'categories-2' ),
	)
);

update_option( 'cartly_seeded', 1 );
flush_rewrite_rules();

echo "Cartly demo seeded\n";
