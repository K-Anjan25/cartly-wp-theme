<?php
/**
 * Footer: inverse ink, link columns, payment badges — plus the mobile tab bar.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

$cartly_badges = array_filter( array_map( 'trim', explode( ',', (string) get_theme_mod( 'cartly_footer_badges', 'Visa, Mastercard, UPI, Razorpay, COD' ) ) ) );
$cartly_blurb  = get_theme_mod( 'cartly_footer_blurb', __( 'Everything you need, one cart.', 'cartly' ) );
?>
	</main>

	<footer class="grain mt-auto bg-contrast text-oncontrast">
		<div class="page-shell grid gap-10 py-14 md:grid-cols-[1.4fr_repeat(3,1fr)]">

			<div>
				<p class="font-heading text-lg font-extrabold uppercase tracking-[0.18em]">
					<?php bloginfo( 'name' ); ?>
				</p>
				<?php if ( $cartly_blurb ) : ?>
					<p class="mt-3 max-w-xs text-sm leading-relaxed text-ink-muted">
						<?php echo esc_html( $cartly_blurb ); ?>
					</p>
				<?php endif; ?>

				<?php if ( $cartly_badges ) : ?>
					<div class="mt-5 flex flex-wrap gap-2">
						<?php foreach ( $cartly_badges as $cartly_badge ) : ?>
							<span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[0.625rem] font-semibold text-ink-muted">
								<?php echo esc_html( $cartly_badge ); ?>
							</span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php
			$cartly_columns = array(
				'footer-1' => __( 'Shop', 'cartly' ),
				'footer-2' => __( 'Account', 'cartly' ),
				'footer-3' => __( 'Support', 'cartly' ),
			);

			foreach ( $cartly_columns as $cartly_location => $cartly_label ) :
				if ( ! has_nav_menu( $cartly_location ) ) {
					continue;
				}
				?>
				<div>
					<p class="text-eyebrow font-bold uppercase text-oncontrast"><?php echo esc_html( $cartly_label ); ?></p>
					<?php
					wp_nav_menu(
						array(
							'theme_location' => $cartly_location,
							'container'      => false,
							'depth'          => 1,
							'items_wrap'     => '<ul class="mt-4 space-y-2.5">%3$s</ul>',
							'walker'         => new Cartly_Stack_Walker(),
							'fallback_cb'    => false,
						)
					);
					?>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="border-t border-white/10">
			<div class="page-shell flex flex-col items-center justify-between gap-2 py-5 text-xs text-ink-muted sm:flex-row">
				<span>
					<?php
					printf(
						/* translators: 1: year, 2: site name */
						esc_html__( '© %1$s %2$s. All rights reserved.', 'cartly' ),
						esc_html( gmdate( 'Y' ) ),
						esc_html( get_bloginfo( 'name' ) )
					);
					?>
				</span>
				<span class="font-mono"><?php esc_html_e( 'Built with the Cartly design system', 'cartly' ); ?></span>
			</div>
		</div>
	</footer>

	<?php get_template_part( 'template-parts/footer/mobile-tabbar' ); ?>
</div>

<?php wp_footer(); ?>
</body>
</html>
