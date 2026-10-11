<?php
/**
 * Minimal admin: seed / inspect pool balances.
 *
 * @package RegionalInventory
 */

declare(strict_types=1);

namespace RegionalInventory;

final class Admin {

	private ReservationService $service;

	public function __construct( ReservationService $service ) {
		$this->service = $service;
	}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Regional Inventory', 'regional-inventory-for-woocommerce' ),
			__( 'Regional Inventory', 'regional-inventory-for-woocommerce' ),
			'manage_woocommerce',
			'rifw-inventory',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( isset( $_POST['rifw_seed'] ) && check_admin_referer( 'rifw_seed' ) ) {
			$product_id = (int) ( $_POST['product_id'] ?? 0 );
			$pool       = sanitize_key( (string) ( $_POST['pool'] ?? 'asia' ) );
			$qty        = (int) ( $_POST['qty'] ?? 0 );
			try {
				$this->service->set_on_hand( $product_id, 0, $pool, $qty );
				echo '<div class="updated"><p>Saved.</p></div>';
			} catch ( \Throwable $e ) {
				echo '<div class="error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
			}
		}
		echo '<div class="wrap"><h1>Regional Inventory</h1>';
		echo '<p>Pools: eu, asia, americas. Active storefront pool is provided by the site via <code>rifw_active_pool</code>.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'rifw_seed' );
		echo '<p><label>Product ID <input name="product_id" type="number" required></label></p>';
		echo '<p><label>Pool <select name="pool"><option>eu</option><option selected>asia</option><option>americas</option></select></label></p>';
		echo '<p><label>On-hand qty <input name="qty" type="number" min="0" required></label></p>';
		submit_button( 'Set on-hand', 'primary', 'rifw_seed' );
		echo '</form></div>';
	}
}
