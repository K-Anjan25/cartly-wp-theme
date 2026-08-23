<?php
/**
 * Comments.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
	return;
}
?>
<section id="comments" class="comments-area">

	<?php if ( have_comments() ) : ?>
		<h2 class="mb-5 font-heading text-lg font-bold text-ink">
			<?php
			$cartly_count = get_comments_number();
			printf(
				/* translators: %d: comment count */
				esc_html( _n( '%d comment', '%d comments', $cartly_count, 'cartly' ) ),
				(int) $cartly_count
			);
			?>
		</h2>

		<ol class="comment-list">
			<?php
			wp_list_comments(
				array(
					'style'       => 'ol',
					'short_ping'  => true,
					'avatar_size' => 40,
				)
			);
			?>
		</ol>

		<?php
		the_comments_pagination(
			array(
				'prev_text' => esc_html__( 'Previous', 'cartly' ),
				'next_text' => esc_html__( 'Next', 'cartly' ),
				'class'     => 'cartly-pagination mt-8 flex justify-center gap-2',
			)
		);
		?>
	<?php endif; ?>

	<?php if ( ! comments_open() && get_comments_number() ) : ?>
		<p class="mt-6 text-sm text-ink-muted"><?php esc_html_e( 'Comments are closed.', 'cartly' ); ?></p>
	<?php endif; ?>

	<?php
	comment_form(
		array(
			'class_container'    => 'comment-respond panel mt-8 p-5 sm:p-6',
			'title_reply_before' => '<h3 class="mb-4 font-heading text-base font-bold text-ink">',
			'title_reply_after'  => '</h3>',
			'comment_notes_before' => '<p class="mb-4 text-xs text-ink-muted">' . esc_html__( 'Your email address will not be published.', 'cartly' ) . '</p>',
		)
	);
	?>
</section>
