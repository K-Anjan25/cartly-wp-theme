<?php
/**
 * Blog sidebar.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_active_sidebar( 'sidebar-1' ) ) {
	return;
}
?>
<aside class="space-y-4 lg:sticky lg:top-24 lg:h-fit" aria-label="<?php esc_attr_e( 'Sidebar', 'cartly' ); ?>">
	<?php dynamic_sidebar( 'sidebar-1' ); ?>
</aside>
