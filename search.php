<?php
/**
 * Search results.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

get_header();

global $wp_query;
$cartly_found = (int) $wp_query->found_posts;
?>

<div class="page-shell">
	<?php
	cartly_page_header(
		array(
			'eyebrow'  => __( 'Search', 'cartly' ),
			/* translators: %s: search term */
			'title'    => sprintf( esc_html__( 'Results for “%s”', 'cartly' ), get_search_query() ),
			'subtitle' => sprintf(
				/* translators: %d: result count */
				esc_html( _n( '%d result found.', '%d results found.', $cartly_found, 'cartly' ) ),
				$cartly_found
			),
		)
	);
	?>

	<div class="panel mb-6 p-4">
		<?php get_search_form(); ?>
	</div>

	<?php if ( have_posts() ) : ?>
		<div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
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
			__( 'No results', 'cartly' ),
			__( 'Nothing matched that search. Try a shorter or more general term.', 'cartly' ),
			'search',
			'<a class="primary-button" href="' . esc_url( cartly_shop_url() ) . '">' . esc_html__( 'Browse the blog', 'cartly' ) . '</a>'
		);
		?>
	<?php endif; ?>
</div>

<?php
get_footer();
