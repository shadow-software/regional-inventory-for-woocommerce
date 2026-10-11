<?php
/**
 * Plugin Name:       Regional Inventory for WooCommerce
 * Plugin URI:        https://github.com/shadow-software/regional-inventory-for-woocommerce
 * Description:       Continent/region inventory pools, atomic reservations, and market-aware stock for WooCommerce.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Shadow Software
 * Author URI:        https://shadowsoftware.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       regional-inventory-for-woocommerce
 * Requires Plugins:  woocommerce
 *
 * @package RegionalInventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RIFW_VERSION', '0.1.0' );
define( 'RIFW_FILE', __FILE__ );
define( 'RIFW_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Bootstrap placeholder — implementation lands behind PHPUnit coverage.
 *
 * @see https://github.com/shadow-software/aura-labs/issues/1
 */
function rifw_bootstrap(): void {
	// Intentionally empty in 0.1.0 scaffold.
}
add_action( 'plugins_loaded', 'rifw_bootstrap' );
