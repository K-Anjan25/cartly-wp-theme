<?php
/**
 * Mobile bottom tab bar — the core site jobs, one thumb-tap away.
 * Wireframe 06-A.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

$cartly_tabs = array(
	array(
		'label'  => __( 'Home', 'cartly' ),
		'url'    => home_url( '/' ),
		'icon'   => 'grid',
		'active' => is_front_page(),
	),
	array(
		'label'  => __( 'Blog', 'cartly' ),
		'url'    => cartly_shop_url(),
		'icon'   => 'grid',
		'active' => is_home(),
	),
	array(
		'label'  => __( 'Search', 'cartly' ),
		'url'    => '#',
		'icon'   => 'search',
		'active' => is_search(),
		'attr'   => ' data-cartly-open-drawer',
	),
	array(
		'label'  => __( 'Menu', 'cartly' ),
		'url'    => '#',
		'icon'   => 'menu',
		'active' => false,
		'attr'   => ' data-cartly-open-drawer',
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
					</span>
					<?php echo esc_html( $cartly_tab['label'] ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
