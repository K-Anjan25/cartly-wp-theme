<?php
/**
 * Storefront hero — the ink block from wireframe 02.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

if ( ! get_theme_mod( 'cartly_hero_enabled', true ) ) {
	return;
}

$cartly_eyebrow   = get_theme_mod( 'cartly_hero_eyebrow', __( 'New season', 'cartly' ) );
$cartly_title     = get_theme_mod( 'cartly_hero_title', __( 'Everything you', 'cartly' ) );
$cartly_title_alt = get_theme_mod( 'cartly_hero_title_alt', __( 'need, one cart.', 'cartly' ) );
$cartly_text      = get_theme_mod( 'cartly_hero_text', __( 'A catalog you can actually search, a checkout that does not fight you, and rewards that stack.', 'cartly' ) );
$cartly_cta       = get_theme_mod( 'cartly_hero_cta_label', __( 'Shop the catalog', 'cartly' ) );
$cartly_cta_url   = get_theme_mod( 'cartly_hero_cta_url', '' ) ?: cartly_shop_url();
$cartly_cta2      = get_theme_mod( 'cartly_hero_cta2_label', __( 'View deals', 'cartly' ) );
$cartly_cta2_url  = get_theme_mod( 'cartly_hero_cta2_url', '' );
$cartly_image_id  = (int) get_theme_mod( 'cartly_hero_image', 0 );
?>
<section class="page-shell mb-10">
	<div class="grid gap-4 lg:grid-cols-[1.25fr_1fr]">

		<div class="grain relative overflow-hidden rounded-xl2 bg-contrast px-7 py-10 text-oncontrast sm:px-12 sm:py-16">
			<?php if ( $cartly_eyebrow ) : ?>
				<p class="eyebrow !text-accent"><?php echo esc_html( $cartly_eyebrow ); ?></p>
			<?php endif; ?>

			<h1 class="mt-4 font-heading text-4xl font-extrabold leading-[1.05] tracking-tight sm:text-5xl lg:text-6xl">
				<?php echo esc_html( $cartly_title ); ?>
				<?php if ( $cartly_title_alt ) : ?>
					<br>
					<span class="font-display font-normal italic text-accent"><?php echo esc_html( $cartly_title_alt ); ?></span>
				<?php endif; ?>
			</h1>

			<?php if ( $cartly_text ) : ?>
				<p class="mt-5 max-w-md text-sm leading-relaxed text-ink-muted sm:text-base">
					<?php echo esc_html( $cartly_text ); ?>
				</p>
			<?php endif; ?>

			<div class="mt-8 flex flex-wrap gap-3">
				<?php if ( $cartly_cta ) : ?>
					<a class="accent-button" href="<?php echo esc_url( $cartly_cta_url ); ?>">
						<?php echo esc_html( $cartly_cta ); ?>
						<?php cartly_icon( 'arrow', 17 ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $cartly_cta2 && $cartly_cta2_url ) : ?>
					<a class="inline-flex items-center justify-center gap-2 rounded-sm border border-white/25 px-4 py-2.5 text-sm font-semibold text-oncontrast no-underline transition hover:bg-white/10"
						href="<?php echo esc_url( $cartly_cta2_url ); ?>">
						<?php echo esc_html( $cartly_cta2 ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>

		<div class="relative hidden overflow-hidden rounded-xl2 border border-line bg-paper lg:block">
			<?php if ( $cartly_image_id ) : ?>
				<?php
				echo wp_get_attachment_image(
					$cartly_image_id,
					'large',
					false,
					array( 'class' => 'h-full w-full object-cover' )
				);
				?>
			<?php else : ?>
				<div class="flex h-full flex-col justify-center gap-4 p-10">
					<p class="eyebrow"><?php esc_html_e( 'Featured', 'cartly' ); ?></p>
					<p class="font-heading text-2xl font-bold leading-snug text-ink">
						<?php esc_html_e( 'Fresh stock lands every week.', 'cartly' ); ?>
					</p>
					<p class="text-sm text-ink-soft">
						<?php esc_html_e( 'Set a hero image in Customizer → Storefront hero.', 'cartly' ); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
		<?php
		$cartly_trust = array(
			array( 'truck', __( 'Free shipping', 'cartly' ), __( 'On qualifying orders', 'cartly' ) ),
			array( 'refresh', __( '7-day returns', 'cartly' ), __( 'No-questions refunds', 'cartly' ) ),
			array( 'shield', __( 'Secure checkout', 'cartly' ), __( 'Cards · UPI · COD', 'cartly' ) ),
			array( 'bolt', __( 'Fast dispatch', 'cartly' ), __( 'Ships within 24h', 'cartly' ) ),
		);

		foreach ( $cartly_trust as $cartly_item ) :
			?>
			<div class="panel flex items-center gap-3 px-4 py-3.5">
				<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-soft text-brand">
					<?php cartly_icon( $cartly_item[0], 18 ); ?>
				</span>
				<div class="min-w-0">
					<p class="truncate text-sm font-bold text-ink"><?php echo esc_html( $cartly_item[1] ); ?></p>
					<p class="truncate text-xs text-ink-muted"><?php echo esc_html( $cartly_item[2] ); ?></p>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</section>
