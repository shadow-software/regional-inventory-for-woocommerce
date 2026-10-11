<?php
/**
 * Plugin bootstrap.
 *
 * @package RegionalInventory
 */

declare(strict_types=1);

namespace RegionalInventory;

final class Plugin {

	private static ?self $instance = null;

	private ?ReservationService $service = null;

	private ?WooAdapter $woo = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( get_option( 'rifw_db_version' ) !== RIFW_VERSION ) {
			Installer::activate();
		}
		global $wpdb;
		$repo          = new WpdbInventoryRepository( $wpdb );
		$this->service = new ReservationService( $repo );
		$this->woo     = new WooAdapter( $this->service );
		$this->woo->hooks();
		if ( is_admin() ) {
			( new Admin( $this->service ) )->hooks();
		}
	}

	public function service(): ?ReservationService {
		return $this->service;
	}
}
