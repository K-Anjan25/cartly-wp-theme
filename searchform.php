<?php
/**
 * Search form.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;
?>
<form role="search" method="get" class="flex gap-2" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="screen-reader-text" for="cartly-s-<?php echo esc_attr( wp_unique_id() ); ?>">
		<?php esc_html_e( 'Search for:', 'cartly' ); ?>
	</label>
	<input type="search" class="input-control" name="s" value="<?php echo esc_attr( get_search_query() ); ?>"
		placeholder="<?php esc_attr_e( 'Search…', 'cartly' ); ?>">
	<button type="submit" class="primary-button shrink-0"><?php esc_html_e( 'Search', 'cartly' ); ?></button>
</form>
