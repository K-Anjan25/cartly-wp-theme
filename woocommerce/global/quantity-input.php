<?php
/**
 * Quantity input.
 *
 * Overrides woocommerce/templates/global/quantity-input.php.
 *
 * @package Cartly
 * @version 9.1.0
 */

defined( 'ABSPATH' ) || exit;

/* phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited */
if ( $max_value && $min_value === $max_value ) {
	?>
	<div class="quantity hidden">
		<input type="hidden" id="<?php echo esc_attr( $input_id ); ?>" class="qty" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $min_value ); ?>" />
	</div>
	<?php
} else {
	/* translators: %s: Quantity. */
	$label = ! empty( $args['product_name'] ) ? sprintf( esc_html__( '%s quantity', 'cartly' ), wp_strip_all_tags( $args['product_name'] ) ) : esc_html__( 'Quantity', 'cartly' );
	?>
	<div class="quantity inline-flex h-11 items-center rounded-sm border border-line bg-paper">
		<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_attr( $label ); ?></label>
		<input
			type="<?php echo esc_attr( $type ); ?>"
			id="<?php echo esc_attr( $input_id ); ?>"
			class="<?php echo esc_attr( join( ' ', (array) $classes ) ); ?> !h-9 !w-16 !border-0 !bg-transparent text-center text-sm font-bold text-ink"
			name="<?php echo esc_attr( $input_name ); ?>"
			value="<?php echo esc_attr( $input_value ); ?>"
			aria-label="<?php esc_attr_e( 'Product quantity', 'cartly' ); ?>"
			size="4"
			min="<?php echo esc_attr( $min_value ); ?>"
			max="<?php echo esc_attr( 0 < $max_value ? $max_value : '' ); ?>"
			<?php if ( ! empty( $step ) ) : ?>
				step="<?php echo esc_attr( $step ); ?>"
			<?php endif; ?>
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			inputmode="<?php echo esc_attr( $inputmode ); ?>"
			autocomplete="<?php echo esc_attr( isset( $autocomplete ) ? $autocomplete : 'on' ); ?>"
		/>
	</div>
	<?php
}
