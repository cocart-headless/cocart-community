<?php
/**
 * This file is designed to be used to load as package NOT a WP plugin!
 *
 * @version 4.9.8
 * @package CoCart
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'COCART_FILE' ) ) {
	define( 'COCART_FILE', __FILE__ );
}

if ( ! defined( 'COCART_SLUG' ) ) {
	define( 'COCART_SLUG', 'cart-rest-api-for-woocommerce' );
}

// Include the main CoCart class.
if ( ! class_exists( 'CoCart', false ) ) {
	include_once untrailingslashit( __DIR__ ) . '/includes/class-cocart.php';
}

/**
 * Returns the main instance of CoCart and only runs if it does not already exists.
 *
 * @since   2.1.0
 * @version 3.0.7
 * @return CoCart
 */
if ( ! function_exists( 'CoCart' ) ) {
	/**
	 * Initialize CoCart.
	 */
	function CoCart() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		return CoCart::init();
	}

	CoCart();
}
