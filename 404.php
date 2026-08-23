<?php
/**
 * 404.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="page-shell">
	<div class="grain overflow-hidden rounded-xl2 bg-contrast px-6 py-16 text-center text-oncontrast sm:px-10 sm:py-24">
		<p class="eyebrow !text-accent"><?php esc_html_e( 'Error 404', 'cartly' ); ?></p>
		<h1 class="mt-4 font-heading text-4xl font-extrabold tracking-tight sm:text-5xl">
			<?php esc_html_e( 'This page took a wrong turn.', 'cartly' ); ?>
		</h1>
		<p class="mx-auto mt-4 max-w-md text-sm text-ink-muted">
			<?php esc_html_e( 'The link may be old, or the page may have moved. Try a search, or head back to the shop.', 'cartly' ); ?>
		</p>
		<div class="mx-auto mt-8 flex max-w-md flex-col justify-center gap-3 sm:flex-row">
			<a class="accent-button" href="<?php echo esc_url( cartly_shop_url() ); ?>">
				<?php esc_html_e( 'Back to the shop', 'cartly' ); ?>
			</a>
			<a class="inline-flex items-center justify-center gap-2 rounded-sm border border-white/25 px-4 py-2.5 text-sm font-semibold text-oncontrast no-underline transition hover:bg-white/10"
				href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Go home', 'cartly' ); ?>
			</a>
		</div>
	</div>
</div>

<?php
get_footer();
