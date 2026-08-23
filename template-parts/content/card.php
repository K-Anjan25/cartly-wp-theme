<?php
/**
 * Post card — the blog counterpart of the product card.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'group flex h-full flex-col overflow-hidden rounded-lg border border-line bg-paper transition duration-200 hover:-translate-y-1 hover:border-ink-faint hover:shadow-lift' ); ?>>

	<a href="<?php the_permalink(); ?>" class="block aspect-[4/3] overflow-hidden bg-sunken no-underline" aria-hidden="true" tabindex="-1">
		<?php if ( has_post_thumbnail() ) : ?>
			<?php
			the_post_thumbnail(
				'medium_large',
				array(
					'class'   => 'h-full w-full object-cover transition duration-500 group-hover:scale-[1.06]',
					'loading' => 'lazy',
				)
			);
			?>
		<?php else : ?>
			<span class="flex h-full w-full items-center justify-center text-ink-faint"><?php cartly_icon( 'grid', 34 ); ?></span>
		<?php endif; ?>
	</a>

	<div class="flex flex-1 flex-col gap-2 p-4">
		<?php
		$cartly_cat = get_the_category();
		if ( ! empty( $cartly_cat ) ) :
			?>
			<p class="text-eyebrow truncate font-bold uppercase text-ink-muted"><?php echo esc_html( $cartly_cat[0]->name ); ?></p>
		<?php endif; ?>

		<h2 class="font-heading text-base font-bold leading-snug">
			<a href="<?php the_permalink(); ?>" class="text-ink no-underline hover:text-brand"><?php the_title(); ?></a>
		</h2>

		<p class="line-clamp-3 text-sm text-ink-soft"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 22 ) ); ?></p>

		<div class="mt-auto pt-3"><?php cartly_post_meta(); ?></div>
	</div>
</article>
