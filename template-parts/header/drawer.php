<?php
/**
 * Mobile drawer — search, primary nav, categories, account, scheme toggle.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="cartly-drawer-backdrop fixed inset-0 z-[60] hidden bg-black/50 backdrop-blur-sm lg:hidden"
	data-cartly-drawer-backdrop hidden></div>

<div id="cartly-drawer"
	class="cartly-drawer fixed inset-y-0 left-0 z-[70] hidden w-[19rem] max-w-[85vw] -translate-x-full flex-col bg-paper transition-transform duration-200 lg:hidden"
	data-cartly-drawer role="dialog" aria-modal="true"
	aria-label="<?php esc_attr_e( 'Site menu', 'cartly' ); ?>" hidden>

	<div class="flex items-center justify-between px-5 py-4">
		<?php cartly_branding(); ?>
		<button type="button" class="icon-button" data-cartly-close-drawer
			aria-label="<?php esc_attr_e( 'Close menu', 'cartly' ); ?>">
			<?php cartly_icon( 'close' ); ?>
		</button>
	</div>

	<form role="search" method="get" class="relative px-5 pb-4" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<span class="pointer-events-none absolute left-8 top-2.5 text-ink-muted"><?php cartly_icon( 'search', 18 ); ?></span>
		<label class="screen-reader-text" for="cartly-drawer-search"><?php esc_html_e( 'Search', 'cartly' ); ?></label>
		<input id="cartly-drawer-search" type="search" name="s" class="input-control pl-10"
			placeholder="<?php esc_attr_e( 'Search…', 'cartly' ); ?>">
	</form>

	<nav class="flex-1 overflow-y-auto border-t border-line px-3 py-3" aria-label="<?php esc_attr_e( 'Mobile', 'cartly' ); ?>">

		<?php if ( has_nav_menu( 'primary' ) ) : ?>
			<p class="eyebrow px-3 pb-2"><?php esc_html_e( 'Menu', 'cartly' ); ?></p>
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'container'      => false,
					'depth'          => 1,
					'items_wrap'     => '<ul class="mb-4 space-y-0.5">%3$s</ul>',
					'link_before'    => '',
					'fallback_cb'    => false,
					'link_after'     => '',
					'walker'         => new Cartly_Drawer_Walker(),
				)
			);
			?>
		<?php endif; ?>

		<?php
		$cartly_cats = get_categories(
			array(
				'hide_empty' => true,
				'number'     => 12,
			)
		);
		if ( ! empty( $cartly_cats ) && ! is_wp_error( $cartly_cats ) ) :
			?>
			<p class="eyebrow px-3 pb-2 pt-2"><?php esc_html_e( 'Categories', 'cartly' ); ?></p>
			<div class="flex flex-wrap gap-2 px-3">
				<?php foreach ( $cartly_cats as $cartly_cat ) : ?>
					<a href="<?php echo esc_url( get_category_link( $cartly_cat ) ); ?>" class="chip">
						<?php echo esc_html( $cartly_cat->name ); ?>
					</a>
				<?php endforeach; ?>
			</div>
			<?php
		endif;
		?>
	</nav>

	<div class="space-y-2 border-t border-line p-4">
		<?php cartly_scheme_toggle( true ); ?>

		<a href="<?php echo esc_url( cartly_shop_url() ); ?>" class="secondary-button w-full">
			<?php esc_html_e( 'Read the blog', 'cartly' ); ?>
		</a>
	</div>
</div>
