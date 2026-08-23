<?php
/**
 * Sticky header: brand · primary nav · centred command search · actions.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

$cartly_cart_count = cartly_cart_count();
?>
<header class="sticky top-0 z-50 border-b border-line bg-paper/90 backdrop-blur-md">
	<div class="page-shell flex h-16 items-center gap-3">

		<button type="button" class="icon-button -ml-2 lg:hidden" data-cartly-open-drawer
			aria-label="<?php esc_attr_e( 'Open menu', 'cartly' ); ?>"
			aria-expanded="false" aria-controls="cartly-drawer">
			<?php cartly_icon( 'menu' ); ?>
		</button>

		<?php cartly_branding(); ?>

		<?php if ( has_nav_menu( 'primary' ) ) : ?>
			<nav class="ml-6 hidden items-center gap-1 lg:flex" aria-label="<?php esc_attr_e( 'Primary', 'cartly' ); ?>">
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'primary',
						'container'      => false,
						'depth'          => 1,
						'items_wrap'     => '<ul class="flex items-center gap-1">%3$s</ul>',
						'walker'         => new Cartly_Nav_Walker(),
						'fallback_cb'    => false,
					)
				);
				?>
			</nav>
		<?php endif; ?>

		<?php
		/* Search is the catalog's front door, so it owns the middle of the
		   header — with a ⌘K shortcut, same as the React shell. */
		?>
		<form role="search" method="get"
			class="relative mx-auto hidden w-full max-w-md items-center md:flex"
			action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<span class="pointer-events-none absolute left-3.5 text-ink-muted"><?php cartly_icon( 'search', 18 ); ?></span>
			<label class="screen-reader-text" for="cartly-header-search"><?php esc_html_e( 'Search', 'cartly' ); ?></label>
			<input id="cartly-header-search" type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>"
				placeholder="<?php esc_attr_e( 'Search products…', 'cartly' ); ?>"
				data-cartly-search
				class="h-10 w-full rounded-full border border-line bg-canvas pl-10 pr-14 text-sm text-ink outline-none transition placeholder:text-ink-muted focus:border-brand focus:bg-paper focus:ring-2 focus:ring-brand/15">
			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<input type="hidden" name="post_type" value="product">
			<?php endif; ?>
			<kbd class="pointer-events-none absolute right-3 hidden rounded border border-line bg-paper px-1.5 py-0.5 font-mono text-[0.625rem] font-semibold text-ink-muted lg:block">⌘K</kbd>
		</form>

		<div class="ml-auto flex items-center gap-1">

			<?php cartly_scheme_toggle(); ?>

			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"
					class="icon-button hidden sm:inline-flex"
					aria-label="<?php esc_attr_e( 'My account', 'cartly' ); ?>">
					<?php cartly_icon( 'user', 20 ); ?>
				</a>

				<a href="<?php echo esc_url( wc_get_cart_url() ); ?>"
					class="icon-button relative"
					data-cartly-cart-link
					aria-label="<?php echo esc_attr( sprintf( /* translators: %d: item count */ __( 'Cart, %d items', 'cartly' ), $cartly_cart_count ) ); ?>">
					<?php cartly_icon( 'cart', 21 ); ?>
					<span class="cartly-cart-count absolute -right-0.5 top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-accent px-1 text-[10px] font-extrabold text-ink<?php echo $cartly_cart_count ? '' : ' hidden'; ?>">
						<?php echo esc_html( (string) $cartly_cart_count ); ?>
					</span>
				</a>
			<?php else : ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="icon-button" aria-label="<?php esc_attr_e( 'Home', 'cartly' ); ?>">
					<?php cartly_icon( 'grid', 20 ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</header>

<?php get_template_part( 'template-parts/header/drawer' );
