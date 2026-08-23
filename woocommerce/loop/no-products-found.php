<?php
/**
 * Empty product loop.
 *
 * Overrides woocommerce/templates/loop/no-products-found.php.
 *
 * @package Cartly
 * @version 7.8.0
 */

defined( 'ABSPATH' ) || exit;

cartly_empty_state(
	__( 'No products found', 'cartly' ),
	__( 'Nothing matches those filters. Try loosening them, or browse the whole catalog.', 'cartly' ),
	'grid',
	'<a class="primary-button" href="' . esc_url( cartly_shop_url() ) . '">' . esc_html__( 'Browse the catalog', 'cartly' ) . '</a>'
);
