<?php
/**
 * Plugin Name: Mix & Match Box Builder by Wodobo Labs
 * Plugin URI:  https://wodobolabs.com/
 * Description: A flexible storefront builder that augments WooCommerce Mix and Match Products while preserving its native commerce logic. Built by Wodobo Labs, a Wodobo initiative.
 * Version:     1.0.4
 * Author:      Wodobo Labs
 * Author URI:  https://wodobolabs.com/
 * Text Domain: picky-plate-box-builder
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 11.0
 * WC tested up to: 11.1
 */

defined( 'ABSPATH' ) || exit;

define( 'PPBB_VERSION', '1.0.4' );
define( 'PPBB_FILE', __FILE__ );
define( 'PPBB_PATH', plugin_dir_path( __FILE__ ) );
define( 'PPBB_URL', plugin_dir_url( __FILE__ ) );

require_once PPBB_PATH . 'includes/class-ppbb-plugin.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PPBB_FILE, true );
		}
	}
);

PPBB_Plugin::instance();
