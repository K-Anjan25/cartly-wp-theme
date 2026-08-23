<?php
/**
 * Fallback template: blog index and any archive without a specific template.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="page-shell">
	<?php
	cartly_page_header(
		array(
			'eyebrow'  => is_home() && ! is_front_page() ? __( 'Journal', 'cartly' ) : '',
			'title'    => is_home() ? ( single_post_title( '', false ) ? single_post_title( '', false ) : get_bloginfo( 'name' ) ) : get_the_archive_title(),
			'subtitle' => is_home() ? get_bloginfo( 'description' ) : wp_strip_all_tags( (string) get_the_archive_description() ),
		)
	);
	?>

	<div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem]">
		<div class="min-w-0">
			<?php if ( have_posts() ) : ?>
				<div class="grid gap-5 sm:grid-cols-2">
					<?php
					while ( have_posts() ) :
						the_post();
						get_template_part( 'template-parts/content/card' );
					endwhile;
					?>
				</div>
				<?php cartly_pagination(); ?>
			<?php else : ?>
				<?php
				cartly_empty_state(
					__( 'Nothing here yet', 'cartly' ),
					__( 'There are no posts to show. Try a search, or head back to the shop.', 'cartly' ),
					'grid',
					'<a class="primary-button" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back home', 'cartly' ) . '</a>'
				);
				?>
			<?php endif; ?>
		</div>

		<?php get_sidebar(); ?>
	</div>
</div>

<?php
get_footer();
