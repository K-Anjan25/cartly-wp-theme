<?php
/**
 * Single page.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="page-shell">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'mx-auto max-w-3xl' ); ?>>
			<?php cartly_page_header( array( 'title' => get_the_title() ) ); ?>

			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="mb-8 overflow-hidden rounded-lg border border-line">
					<?php the_post_thumbnail( 'large', array( 'class' => 'w-full object-cover' ) ); ?>
				</figure>
			<?php endif; ?>

			<div class="entry-content">
				<?php
				the_content();
				wp_link_pages(
					array(
						'before' => '<nav class="mt-8 flex gap-2 text-sm font-semibold">',
						'after'  => '</nav>',
					)
				);
				?>
			</div>
		</article>

		<?php
		if ( comments_open() || get_comments_number() ) {
			echo '<div class="mx-auto mt-12 max-w-3xl">';
			comments_template();
			echo '</div>';
		}
	endwhile;
	?>
</div>

<?php
get_footer();
