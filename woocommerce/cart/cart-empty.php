<?php
/**
 * Empty cart.
 *
 * Overrides woocommerce/templates/cart/cart-empty.php.
 *
 * @package Cartly
 * @version 7.0.1
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_cart_is_empty' );

cartly_empty_state(
	__( 'Your cart is empty', 'cartly' ),
	__( 'Looks like you have not added anything yet. Explore the shop and find something you like.', 'cartly' ),
	'cart',
	'<a class="primary-button" href="' . esc_url( cartly_shop_url() ) . '">' . esc_html__( 'Continue shopping', 'cartly' ) . '</a>'
);
