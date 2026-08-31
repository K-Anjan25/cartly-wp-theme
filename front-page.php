<?php
/**
 * Front page: hero + latest posts.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

// A static front page keeps its own content; only the hero is prepended.
if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
	get_header();
	get_template_part( 'template-parts/hero' );

	while ( have_posts() ) :
		the_post();
		if ( trim( (string) get_the_content() ) !== '' ) :
			?>
			<div class="page-shell">
				<div class="entry-content mx-auto max-w-3xl"><?php the_content(); ?></div>
			</div>
			<?php
		endif;
	endwhile;

	get_footer();
	return;
}

get_header();
get_template_part( 'template-parts/hero' );
?>

<?php if ( have_posts() ) : ?>
	<section class="page-shell mt-12">
		<div class="mb-5">
			<p class="eyebrow"><?php esc_html_e( 'Latest', 'cartly' ); ?></p>
			<h2 class="section-title mt-1"><?php esc_html_e( 'From the journal', 'cartly' ); ?></h2>
		</div>
		<div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
			<?php
			$cartly_shown = 0;
			while ( have_posts() && $cartly_shown < 3 ) :
				the_post();
				get_template_part( 'template-parts/content/card' );
				$cartly_shown++;
			endwhile;
			?>
		</div>
	</section>
<?php endif; ?>

<?php
get_footer();
