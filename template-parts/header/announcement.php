<?php
/**
 * Dismissible announcement strip. Session-scoped, like the React shell.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

$cartly_text = get_theme_mod( 'cartly_announcement_text', __( 'Free shipping over ₹999', 'cartly' ) );

if ( ! $cartly_text ) {
	return;
}

$cartly_highlight = get_theme_mod( 'cartly_announcement_highlight', __( 'Flash sale live', 'cartly' ) );
$cartly_link      = get_theme_mod( 'cartly_announcement_link', '' );
?>
<div class="cartly-announcement relative bg-contrast text-oncontrast" data-cartly-announcement>
	<div class="page-shell flex h-9 items-center justify-center gap-3">
		<p class="truncate text-[0.6875rem] font-semibold tracking-wide sm:text-xs">
			<?php if ( $cartly_link ) : ?>
				<a href="<?php echo esc_url( $cartly_link ); ?>" class="text-oncontrast no-underline hover:underline">
			<?php endif; ?>
			<?php echo esc_html( $cartly_text ); ?>
			<?php if ( $cartly_highlight ) : ?>
				<span class="mx-2 text-ink-muted" aria-hidden="true">·</span>
				<span class="text-accent"><?php echo esc_html( $cartly_highlight ); ?></span>
			<?php endif; ?>
			<?php if ( $cartly_link ) : ?>
				</a>
			<?php endif; ?>
		</p>
		<button type="button"
			class="absolute right-3 text-ink-muted transition hover:text-oncontrast sm:right-6"
			data-cartly-dismiss-announcement
			aria-label="<?php esc_attr_e( 'Dismiss announcement', 'cartly' ); ?>">
			<?php cartly_icon( 'close', 14 ); ?>
		</button>
	</div>
</div>
