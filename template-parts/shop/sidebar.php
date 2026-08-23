<?php
/**
 * Shop sidebar — facets column on product archives.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_active_sidebar( 'shop-sidebar' ) ) {
	// Keep the grid honest when no widgets are configured.
	echo '<div class="hidden lg:block"></div>';
	return;
}
?>
<aside class="hidden lg:block" aria-label="<?php esc_attr_e( 'Product filters', 'cartly' ); ?>">
	<div class="sticky top-[13.5rem] max-h-[calc(100vh-16rem)] space-y-4 overflow-y-auto pb-4">
		<?php dynamic_sidebar( 'shop-sidebar' ); ?>
	</div>
</aside>

<button type="button"
	class="secondary-button fixed bottom-20 right-4 z-40 shadow-pop lg:hidden"
	data-cartly-open-filters>
	<?php esc_html_e( 'Filters', 'cartly' ); ?>
</button>

<div class="cartly-filters fixed inset-x-0 bottom-0 z-[70] hidden max-h-[85vh] translate-y-full flex-col overflow-hidden rounded-t-xl2 border-t border-line bg-paper transition-transform duration-200 lg:hidden"
	data-cartly-filters role="dialog" aria-modal="true"
	aria-label="<?php esc_attr_e( 'Product filters', 'cartly' ); ?>" hidden>
	<div class="flex items-center justify-between border-b border-line px-5 py-4">
		<h2 class="font-heading text-base font-bold"><?php esc_html_e( 'Filters', 'cartly' ); ?></h2>
		<button type="button" class="icon-button" data-cartly-close-filters
			aria-label="<?php esc_attr_e( 'Close filters', 'cartly' ); ?>">
			<?php cartly_icon( 'close' ); ?>
		</button>
	</div>
	<div class="space-y-4 overflow-y-auto p-5">
		<?php dynamic_sidebar( 'shop-sidebar' ); ?>
	</div>
</div>
