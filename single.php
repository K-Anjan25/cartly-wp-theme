<?php
/**
 * Single post.
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

			<nav aria-label="<?php esc_attr_e( 'Breadcrumb', 'cartly' ); ?>" class="mb-4 text-xs font-semibold text-ink-muted">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="text-ink-muted no-underline hover:text-ink"><?php esc_html_e( 'Home', 'cartly' ); ?></a>
				<span aria-hidden="true" class="mx-1">›</span>
				<span class="text-ink"><?php the_title(); ?></span>
			</nav>

			<header class="page-header">
				<h1 class="page-title"><?php the_title(); ?></h1>
				<div class="mt-3"><?php cartly_post_meta(); ?></div>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="mb-8 overflow-hidden rounded-lg border border-line">
					<?php the_post_thumbnail( 'large', array( 'class' => 'w-full object-cover' ) ); ?>
				</figure>
			<?php endif; ?>

			<div class="entry-content"><?php the_content(); ?></div>

			<?php
			$cartly_tags = get_the_tag_list( '', '' );
			if ( $cartly_tags ) :
				?>
				<div class="mt-8 flex flex-wrap gap-2 [&_a]:chip [&_a]:no-underline">
					<?php echo wp_kses_post( $cartly_tags ); ?>
				</div>
			<?php endif; ?>
		</article>

		<nav class="mx-auto mt-10 grid max-w-3xl gap-3 sm:grid-cols-2" aria-label="<?php esc_attr_e( 'Post navigation', 'cartly' ); ?>">
			<?php
			$cartly_prev = get_previous_post();
			$cartly_next = get_next_post();

			if ( $cartly_prev ) :
				?>
				<a href="<?php echo esc_url( get_permalink( $cartly_prev ) ); ?>" class="panel p-4 no-underline transition hover:border-ink-faint">
					<span class="eyebrow"><?php esc_html_e( 'Previous', 'cartly' ); ?></span>
					<span class="mt-1 block font-heading text-sm font-bold text-ink"><?php echo esc_html( get_the_title( $cartly_prev ) ); ?></span>
				</a>
			<?php endif; ?>

			<?php if ( $cartly_next ) : ?>
				<a href="<?php echo esc_url( get_permalink( $cartly_next ) ); ?>" class="panel p-4 text-right no-underline transition hover:border-ink-faint sm:col-start-2">
					<span class="eyebrow"><?php esc_html_e( 'Next', 'cartly' ); ?></span>
					<span class="mt-1 block font-heading text-sm font-bold text-ink"><?php echo esc_html( get_the_title( $cartly_next ) ); ?></span>
				</a>
			<?php endif; ?>
		</nav>

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
