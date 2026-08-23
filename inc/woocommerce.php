<?php
/**
 * WooCommerce integration.
 *
 * Most of the design is applied by re-hooking WooCommerce rather than copying
 * its templates, so the theme keeps working across Woo releases. Only the
 * product card and a couple of small partials are overridden outright — see
 * the `woocommerce/` folder.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------- wrappers -- */

remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );

/**
 * Open the Cartly shell around WooCommerce content.
 */
function cartly_woo_wrapper_start() {
	echo '<div class="page-shell">';

	if ( is_shop() || is_product_taxonomy() ) {
		echo '<div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">';
		get_template_part( 'template-parts/shop/sidebar' );
		echo '<div class="min-w-0">';
	}
}
add_action( 'woocommerce_before_main_content', 'cartly_woo_wrapper_start', 10 );

/**
 * Close it.
 */
function cartly_woo_wrapper_end() {
	if ( is_shop() || is_product_taxonomy() ) {
		echo '</div></div>';
	}
	echo '</div>';
}
add_action( 'woocommerce_after_main_content', 'cartly_woo_wrapper_end', 10 );

/* ------------------------------------------------------------ shop header -- */

// Woo's default page title / breadcrumb are replaced by the theme's header.
remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );

/**
 * Hero + page header above the product grid.
 */
function cartly_shop_header() {
	if ( ! is_shop() && ! is_product_taxonomy() ) {
		return;
	}

	if ( is_shop() && ! is_search() ) {
		// The hero lives outside the sidebar grid, so it is printed before it.
		return;
	}

	$term = is_product_taxonomy() ? get_queried_object() : null;

	cartly_page_header(
		array(
			'eyebrow'  => __( 'Catalog', 'cartly' ),
			'title'    => $term ? $term->name : woocommerce_page_title( false ),
			'subtitle' => $term && $term->description ? wp_strip_all_tags( $term->description ) : '',
		)
	);
}
add_action( 'woocommerce_before_shop_loop', 'cartly_shop_header', 5 );

/**
 * Print the storefront hero above the whole shop layout.
 */
function cartly_shop_hero() {
	if ( is_shop() && ! is_search() && ! is_paged() ) {
		get_template_part( 'template-parts/hero' );
	}
}
add_action( 'woocommerce_before_main_content', 'cartly_shop_hero', 5 );

/**
 * Sticky results toolbar: count on the left, sort on the right.
 */
function cartly_shop_toolbar_open() {
	echo '<div class="sticky top-[7rem] z-30 mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-paper/95 p-3 backdrop-blur-md">';
}
add_action( 'woocommerce_before_shop_loop', 'cartly_shop_toolbar_open', 19 );

/**
 * Close the toolbar.
 */
function cartly_shop_toolbar_close() {
	echo '</div>';
}
add_action( 'woocommerce_before_shop_loop', 'cartly_shop_toolbar_close', 31 );

/**
 * Four across, matching `.product-grid`.
 *
 * @return int
 */
function cartly_loop_columns() {
	return 4;
}
add_filter( 'loop_shop_columns', 'cartly_loop_columns', 20 );

/**
 * Products per page.
 *
 * @return int
 */
function cartly_products_per_page() {
	return 12;
}
add_filter( 'loop_shop_per_page', 'cartly_products_per_page', 20 );

/* ------------------------------------------------------------- loop item -- */

// The card is rebuilt in woocommerce/content-product.php, so unhook the
// default pieces that template no longer calls.
remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10 );
remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5 );
remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );
remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );
remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );

/**
 * Add-to-cart button classes so Woo's AJAX button matches the design.
 *
 * @param array  $args    Button args.
 * @param object $product Product.
 * @return array
 */
function cartly_loop_add_to_cart_args( $args, $product ) {
	$args['class'] = 'cartly-add-to-cart flex h-10 w-full items-center justify-center gap-2 rounded-sm bg-contrast text-xs font-bold text-oncontrast no-underline transition hover:bg-brand sm:text-sm '
		. ( $product->is_purchasable() && $product->is_in_stock() ? 'add_to_cart_button ' : '' )
		. ( $product->supports( 'ajax_add_to_cart' ) && $product->is_purchasable() && $product->is_in_stock() ? 'ajax_add_to_cart' : '' );
	return $args;
}
add_filter( 'woocommerce_loop_add_to_cart_args', 'cartly_loop_add_to_cart_args', 10, 2 );

/**
 * Stock badge for a product, in the theme's badge language.
 *
 * @param WC_Product $product Product.
 */
function cartly_stock_badge( $product ) {
	if ( ! $product->is_in_stock() ) {
		echo '<span class="badge-stock-out">' . esc_html__( 'Out of stock', 'cartly' ) . '</span>';
		return;
	}

	$qty = $product->get_stock_quantity();
	if ( null !== $qty && $qty <= 5 ) {
		printf(
			'<span class="badge-stock-low">%s</span>',
			esc_html( sprintf( /* translators: %d: remaining stock */ __( 'Only %d left', 'cartly' ), (int) $qty ) )
		);
	}
}

/**
 * Percentage off, or 0.
 *
 * @param WC_Product $product Product.
 * @return int
 */
function cartly_discount_percent( $product ) {
	$regular = (float) $product->get_regular_price();
	$sale    = (float) $product->get_sale_price();

	if ( $regular <= 0 || $sale <= 0 || $sale >= $regular ) {
		return 0;
	}

	return (int) round( ( ( $regular - $sale ) / $regular ) * 100 );
}

/* ---------------------------------------------------------- single product -- */

/**
 * Wrap the single-product summary so it reads as a buy box.
 */
function cartly_single_summary_open() {
	echo '<div class="cartly-buy-box space-y-4">';
}
add_action( 'woocommerce_single_product_summary', 'cartly_single_summary_open', 1 );

/**
 * Close the buy box and add the trust panel from wireframe 03.
 */
function cartly_single_summary_close() {
	?>
	<div class="panel divide-y divide-line">
		<?php
		$rows = array(
			array( 'truck', __( 'Free delivery on qualifying orders', 'cartly' ), __( 'Express options at checkout', 'cartly' ) ),
			array( 'refresh', __( '7-day returns', 'cartly' ), __( 'Refunded to the original payment method', 'cartly' ) ),
			array( 'shield', __( 'Secure checkout', 'cartly' ), __( 'Cards · UPI · cash on delivery', 'cartly' ) ),
		);

		foreach ( $rows as $row ) :
			?>
			<div class="flex items-start gap-3 p-4">
				<span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-soft text-brand">
					<?php cartly_icon( $row[0], 17 ); ?>
				</span>
				<div class="min-w-0">
					<p class="text-sm font-bold text-ink"><?php echo esc_html( $row[1] ); ?></p>
					<p class="text-xs text-ink-soft"><?php echo esc_html( $row[2] ); ?></p>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	</div>
	<?php
}
add_action( 'woocommerce_single_product_summary', 'cartly_single_summary_close', 100 );

/* ----------------------------------------------------------------- cart -- */

/**
 * Refresh the header cart count over Woo's AJAX fragments.
 *
 * @param array $fragments Fragments.
 * @return array
 */
function cartly_cart_count_fragment( $fragments ) {
	$count = cartly_cart_count();

	ob_start();
	printf(
		'<span class="cartly-cart-count absolute -right-0.5 top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-accent px-1 text-[10px] font-extrabold text-ink%s">%d</span>',
		$count ? '' : ' hidden',
		(int) $count
	);
	$fragments['span.cartly-cart-count'] = ob_get_clean();

	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'cartly_cart_count_fragment' );

/**
 * Woo's default sale flash is replaced by the card's own badge stack.
 */
remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );

/**
 * Ink sale badge on the single product gallery.
 */
function cartly_single_sale_flash() {
	global $product;

	if ( ! $product || ! $product->is_on_sale() ) {
		return;
	}

	$percent = cartly_discount_percent( $product );

	printf(
		'<span class="badge-sale absolute left-4 top-4 z-10 !px-3 !py-1.5 !text-xs">%s</span>',
		$percent ? esc_html( '−' . $percent . '%' ) : esc_html__( 'Sale', 'cartly' )
	);
}
add_action( 'woocommerce_before_single_product_summary', 'cartly_single_sale_flash', 10 );

/**
 * Related products heading in the theme's voice.
 *
 * @param array $args Args.
 * @return array
 */
function cartly_related_args( $args ) {
	$args['posts_per_page'] = 4;
	$args['columns']        = 4;
	return $args;
}
add_filter( 'woocommerce_output_related_products_args', 'cartly_related_args', 20 );
