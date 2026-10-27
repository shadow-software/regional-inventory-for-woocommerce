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

$rifw_autoload = RIFW_PATH . 'vendor/autoload.php';
if ( is_readable( $rifw_autoload ) ) {
	require_once $rifw_autoload;
}

// Always register plugin PSR-4 fallback. Production installs sometimes ship a
// readable vendor/autoload.php that does not map RegionalInventory\\ (partial
// vendor tree, stale dump), which skipped this loader and fatally broke boot.
spl_autoload_register(
	static function ( string $class ): void {
		if ( strpos( $class, 'RegionalInventory\\' ) !== 0 ) {
			return;
		}
		$rel = str_replace( 'RegionalInventory\\', '', $class );
		$rel = str_replace( '\\', '/', $rel );
		$file = RIFW_PATH . 'includes/' . $rel . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once RIFW_PATH . 'includes/Installer.php';
register_activation_hook( __FILE__, array( \RegionalInventory\Installer::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\RegionalInventory\Plugin::instance()->boot();
	},
	20
);
