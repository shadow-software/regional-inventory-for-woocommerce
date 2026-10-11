<?php
/**
 * Activation / schema.
 *
 * @package RegionalInventory
 */

declare(strict_types=1);

namespace RegionalInventory;

final class Installer {

	public static function activate(): void {
		global $wpdb;
		WpdbInventoryRepository::install( $wpdb );
		update_option( 'rifw_db_version', RIFW_VERSION );
	}
}
