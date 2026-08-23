<?php
/**
 * Mobile bottom tab bar — the five core jobs, one thumb-tap away.
 * Wireframe 06-A.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

$cartly_has_woo = class_exists( 'WooCommerce' );
$cartly_count   = cartly_cart_count();

$cartly_tabs = array(
	array(
		'label'  => __( 'Shop', 'cartly' ),
		'url'    => cartly_shop_url(),
		'icon'   => 'grid',
		'active' => is_front_page() || is_home() || ( function_exists( 'is_shop' ) && is_shop() ),
	),
	array(
		'label'  => __( 'Search', 'cartly' ),
		'url'    => '#',
		'icon'   => 'search',
		'active' => is_search(),
		'attr'   => ' data-cartly-open-drawer',
	),
	array(
		'label'  => __( 'Cart', 'cartly' ),
		'url'    => $cartly_has_woo ? wc_get_cart_url() : home_url( '/' ),
		'icon'   => 'cart',
		'active' => function_exists( 'is_cart' ) && is_cart(),
		'badge'  => $cartly_count,
	),
	array(
		'label'  => __( 'Orders', 'cartly' ),
		'url'    => $cartly_has_woo ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/' ),
		'icon'   => 'receipt',
		'active' => function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'orders' ),
	),
	array(
		'label'  => __( 'You', 'cartly' ),
		'url'    => $cartly_has_woo ? wc_get_page_permalink( 'myaccount' ) : wp_login_url(),
		'icon'   => 'user',
		'active' => function_exists( 'is_account_page' ) && is_account_page(),
	),
);
?>
<nav class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-paper/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md lg:hidden"
	aria-label="<?php esc_attr_e( 'Primary mobile', 'cartly' ); ?>">
	<ul class="mx-auto flex max-w-md items-stretch">
		<?php foreach ( $cartly_tabs as $cartly_tab ) : ?>
			<li class="flex-1 list-none">
				<a href="<?php echo esc_url( $cartly_tab['url'] ); ?>"
					<?php echo isset( $cartly_tab['attr'] ) ? esc_attr( $cartly_tab['attr'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- static attribute string. ?>
					<?php echo $cartly_tab['active'] ? 'aria-current="page"' : ''; ?>
					class="flex h-[3.875rem] w-full flex-col items-center justify-center gap-1 text-[0.625rem] font-semibold no-underline transition <?php echo $cartly_tab['active'] ? 'text-brand' : 'text-ink-muted hover:text-ink'; ?>">
					<span class="relative">
						<?php cartly_icon( $cartly_tab['icon'], 21 ); ?>
						<?php if ( ! empty( $cartly_tab['badge'] ) ) : ?>
							<span class="cartly-cart-count absolute -right-2.5 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand px-1 text-[0.5625rem] font-bold text-oncontrast">
								<?php echo esc_html( (string) $cartly_tab['badge'] ); ?>
							</span>
						<?php endif; ?>
					</span>
					<?php echo esc_html( $cartly_tab['label'] ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
