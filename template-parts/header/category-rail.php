<?php
/**
 * Persistent category rail — one tap instead of a dropdown.
 * Uses the `categories` menu if set, otherwise WooCommerce product categories.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

$cartly_show_rail = is_front_page() || is_home() || is_search()
	|| ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() ) );

/**
 * Filter whether the category rail renders on the current view.
 *
 * @param bool $cartly_show_rail Whether to show the rail.
 */
if ( ! apply_filters( 'cartly_show_category_rail', $cartly_show_rail ) ) {
	return;
}

if ( has_nav_menu( 'categories' ) ) :
	?>
	<div class="sticky top-16 z-40 border-b border-line bg-paper/85 backdrop-blur-md">
		<div class="page-shell no-scrollbar flex h-12 items-center gap-2 overflow-x-auto">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'categories',
					'container'      => false,
					'depth'          => 1,
					'items_wrap'     => '<ul class="flex items-center gap-2">%3$s</ul>',
					'walker'         => new Cartly_Chip_Walker(),
					'fallback_cb'    => false,
				)
			);
			?>
		</div>
	</div>
	<?php
	return;
endif;

if ( ! taxonomy_exists( 'product_cat' ) ) {
	return;
}

$cartly_terms = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
		'number'     => 12,
		'parent'     => 0,
	)
);

if ( is_wp_error( $cartly_terms ) || empty( $cartly_terms ) ) {
	return;
}

$cartly_current = ( is_product_category() && is_object( get_queried_object() ) ) ? get_queried_object_id() : 0;
?>
<div class="sticky top-16 z-40 border-b border-line bg-paper/85 backdrop-blur-md">
	<div class="page-shell no-scrollbar flex h-12 items-center gap-2 overflow-x-auto">
		<ul class="flex items-center gap-2">
			<li class="list-none">
				<a href="<?php echo esc_url( cartly_shop_url() ); ?>"
					class="chip <?php echo $cartly_current ? '' : 'chip-ink'; ?>">
					<?php esc_html_e( 'All', 'cartly' ); ?>
				</a>
			</li>
			<?php foreach ( $cartly_terms as $cartly_term ) : ?>
				<li class="list-none">
					<a href="<?php echo esc_url( get_term_link( $cartly_term ) ); ?>"
						class="chip <?php echo ( $cartly_current === $cartly_term->term_id ) ? 'chip-ink' : ''; ?>">
						<?php echo esc_html( $cartly_term->name ); ?>
						<span class="<?php echo ( $cartly_current === $cartly_term->term_id ) ? 'text-oncontrast/60' : 'text-ink-muted'; ?>">
							<?php echo esc_html( (string) $cartly_term->count ); ?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</div>
