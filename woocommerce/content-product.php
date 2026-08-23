<?php
/**
 * Product card — the loop item.
 *
 * Overrides woocommerce/templates/content-product.php.
 * Anatomy from design/wireframes/07-component-sheet.svg: cover · badge stack
 * (top-left) · stock warning (bottom-left, only when it matters) · brand
 * eyebrow · 2-line name · rating · price row · docked add-to-cart bar.
 *
 * @package Cartly
 * @version 3.6.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( empty( $product ) || ! $product->is_visible() ) {
	return;
}

$cartly_percent = cartly_discount_percent( $product );
$cartly_terms   = get_the_terms( $product->get_id(), 'product_cat' );
$cartly_eyebrow = ( ! is_wp_error( $cartly_terms ) && ! empty( $cartly_terms ) ) ? $cartly_terms[0]->name : get_bloginfo( 'name' );
?>
<li <?php wc_product_class( 'group relative flex h-full flex-col overflow-hidden rounded-lg border border-line bg-paper transition duration-200 hover:-translate-y-1 hover:border-ink-faint hover:shadow-lift', $product ); ?>>

	<div class="relative aspect-[4/3] overflow-hidden bg-sunken">
		<a href="<?php the_permalink(); ?>" class="block h-full w-full no-underline" aria-label="<?php echo esc_attr( $product->get_name() ); ?>">
			<?php
			echo wp_kses_post(
				$product->get_image(
					'woocommerce_thumbnail',
					array( 'class' => 'h-full w-full object-cover transition duration-500 group-hover:scale-[1.06]' )
				)
			);
			?>
		</a>

		<div class="pointer-events-none absolute left-2.5 top-2.5 flex flex-col items-start gap-1.5">
			<?php if ( $product->is_on_sale() ) : ?>
				<span class="badge-sale">
					<?php echo $cartly_percent ? esc_html( '−' . $cartly_percent . '%' ) : esc_html__( 'Sale', 'cartly' ); ?>
				</span>
			<?php endif; ?>
			<?php if ( $product->is_featured() ) : ?>
				<span class="badge-sale !bg-contrast"><?php esc_html_e( 'Featured', 'cartly' ); ?></span>
			<?php endif; ?>
		</div>

		<div class="pointer-events-none absolute bottom-2.5 left-2.5">
			<?php cartly_stock_badge( $product ); ?>
		</div>
	</div>

	<div class="flex flex-1 flex-col gap-1 p-3.5 pb-14 sm:p-4 sm:pb-16">
		<p class="text-eyebrow truncate font-bold uppercase text-ink-muted"><?php echo esc_html( $cartly_eyebrow ); ?></p>

		<h2 class="line-clamp-2 font-heading text-sm font-semibold leading-snug sm:text-[0.9375rem]">
			<a href="<?php the_permalink(); ?>" class="text-ink no-underline"><?php echo esc_html( $product->get_name() ); ?></a>
		</h2>

		<?php if ( wc_review_ratings_enabled() && $product->get_rating_count() ) : ?>
			<div class="flex items-center gap-1.5 text-xs text-ink-soft">
				<?php echo wp_kses_post( wc_get_rating_html( $product->get_average_rating(), $product->get_rating_count() ) ); ?>
				<span class="text-ink-muted">(<?php echo esc_html( (string) $product->get_rating_count() ); ?>)</span>
			</div>
		<?php endif; ?>

		<div class="mt-auto flex items-baseline gap-2 pt-2 [&_del]:text-xs [&_del]:text-ink-muted [&_ins]:no-underline">
			<span class="price-text text-base sm:text-lg"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
		</div>
	</div>

	<div class="absolute inset-x-0 bottom-0 p-2.5">
		<?php
		/**
		 * The button classes come from cartly_loop_add_to_cart_args(), so Woo's
		 * AJAX add-to-cart keeps working.
		 */
		woocommerce_template_loop_add_to_cart();
		?>
	</div>
</li>
