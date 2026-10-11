<?php
/**
 * WooCommerce integration — stock authority per active pool.
 *
 * Expects the host site to provide the active pool via the
 * `rifw_active_pool` filter (Aura market router does this).
 *
 * @package RegionalInventory
 */

declare(strict_types=1);

namespace RegionalInventory;

final class WooAdapter {

	private ReservationService $service;

	/** @var array<string,list<string>> holder_key => reservation ids */
	private static array $session_holds = array();

	public function __construct( ReservationService $service ) {
		$this->service = $service;
	}

	public function hooks(): void {
		add_filter( 'woocommerce_product_is_in_stock', array( $this, 'filter_is_in_stock' ), 20, 2 );
		add_filter( 'woocommerce_product_get_stock_quantity', array( $this, 'filter_stock_qty' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_stock_quantity', array( $this, 'filter_stock_qty' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 20, 5 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 20, 6 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'on_cart_item_removed' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_processed' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_store_api_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_cancelled' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'on_order_cancelled' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 20, 2 );
		// Prevent core from also reducing global stock when we are authority.
		add_filter( 'woocommerce_can_reduce_order_stock', array( $this, 'block_global_reduce' ), 20, 2 );
	}

	public function active_pool(): string {
		$pool = apply_filters( 'rifw_active_pool', 'asia' );
		return ReservationService::assert_pool( is_string( $pool ) ? $pool : 'asia' );
	}

	/**
	 * @param bool        $in_stock In stock.
	 * @param \WC_Product $product  Product.
	 */
	public function filter_is_in_stock( $in_stock, $product ) {
		if ( ! $product instanceof \WC_Product || ! $product->managing_stock() ) {
			return $in_stock;
		}
		$id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$vid = $product->is_type( 'variation' ) ? $product->get_id() : 0;
		return $this->service->available( (int) $id, (int) $vid, $this->active_pool() ) > 0;
	}

	/**
	 * @param mixed       $qty     Qty.
	 * @param \WC_Product $product Product.
	 */
	public function filter_stock_qty( $qty, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $qty;
		}
		$id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$vid = $product->is_type( 'variation' ) ? $product->get_id() : 0;
		return $this->service->available( (int) $id, (int) $vid, $this->active_pool() );
	}

	/**
	 * @param bool $passed     Passed.
	 * @param int  $product_id Product.
	 * @param int  $quantity   Qty.
	 * @param int  $variation_id Variation.
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		if ( ! $passed ) {
			return $passed;
		}
		$pool = $this->active_pool();
		if ( ! $this->service->can_fulfill( (int) $product_id, (int) $variation_id, $pool, (int) $quantity ) ) {
			wc_add_notice( __( 'Not enough stock in this region.', 'regional-inventory-for-woocommerce' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * @param string $cart_item_key Key.
	 * @param int    $product_id    Product.
	 * @param int    $quantity      Qty.
	 * @param int    $variation_id  Variation.
	 */
	public function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		$holder = $this->holder_key();
		$id     = $this->service->reserve( (int) $product_id, (int) $variation_id, $this->active_pool(), (int) $quantity, $holder . ':' . $cart_item_key );
		if ( null === $id ) {
			return;
		}
		if ( ! isset( WC()->session ) ) {
			return;
		}
		$holds                       = WC()->session->get( 'rifw_holds', array() );
		$holds[ $cart_item_key ]     = array(
			'id'   => $id,
			'pool' => $this->active_pool(),
		);
		WC()->session->set( 'rifw_holds', $holds );
	}

	/**
	 * @param string   $cart_item_key Key.
	 * @param \WC_Cart $cart          Cart.
	 */
	public function on_cart_item_removed( $cart_item_key, $cart ) {
		if ( ! isset( WC()->session ) ) {
			return;
		}
		$holds = WC()->session->get( 'rifw_holds', array() );
		if ( empty( $holds[ $cart_item_key ]['id'] ) ) {
			return;
		}
		$this->service->release( (string) $holds[ $cart_item_key ]['id'] );
		unset( $holds[ $cart_item_key ] );
		WC()->session->set( 'rifw_holds', $holds );
	}

	/**
	 * @param int $order_id Order.
	 */
	public function on_order_processed( $order_id, $posted_data = array(), $order = null ) {
		$this->commit_holds_for_order( (int) $order_id );
	}

	/**
	 * @param \WC_Order $order Order.
	 */
	public function on_store_api_order( $order ) {
		if ( $order instanceof \WC_Order ) {
			$this->commit_holds_for_order( $order->get_id() );
		}
	}

	private function commit_holds_for_order( int $order_id ): void {
		if ( ! isset( WC()->session ) ) {
			return;
		}
		$holds = WC()->session->get( 'rifw_holds', array() );
		$ids   = array();
		foreach ( $holds as $row ) {
			if ( ! empty( $row['id'] ) ) {
				$this->service->commit( (string) $row['id'] );
				$ids[] = array(
					'id'   => (string) $row['id'],
					'pool' => (string) ( $row['pool'] ?? $this->active_pool() ),
				);
			}
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( '_rifw_reservations', $ids );
			$order->update_meta_data( '_rifw_pool', $this->active_pool() );
			$order->save();
		}
		WC()->session->set( 'rifw_holds', array() );
	}

	/**
	 * @param int $order_id Order.
	 */
	public function on_order_cancelled( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Cancelled before commit: holds already cleared; if committed, restore on-hand.
		$pool = (string) $order->get_meta( '_rifw_pool' );
		if ( '' === $pool ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$vid = $product->is_type( 'variation' ) ? $product->get_id() : 0;
			$this->service->set_on_hand(
				(int) $id,
				(int) $vid,
				$pool,
				$this->service->snapshot( (int) $id, (int) $vid, $pool )['on_hand'] + (int) $item->get_quantity()
			);
		}
	}

	/**
	 * @param int $order_id  Order.
	 * @param int $refund_id Refund.
	 */
	public function on_order_refunded( $order_id, $refund_id ): void {
		// Full path: restore qty from refund line items into order pool.
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}
		$pool = (string) $order->get_meta( '_rifw_pool' );
		if ( '' === $pool ) {
			$pool = $this->active_pool();
		}
		foreach ( $refund->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$vid = $product->is_type( 'variation' ) ? $product->get_id() : 0;
			$qty = abs( (int) $item->get_quantity() );
			$snap = $this->service->snapshot( (int) $id, (int) $vid, $pool );
			$this->service->set_on_hand( (int) $id, (int) $vid, $pool, $snap['on_hand'] + $qty );
		}
	}

	/**
	 * @param bool      $can_reduce Can reduce.
	 * @param \WC_Order $order      Order.
	 */
	public function block_global_reduce( $can_reduce, $order ) {
		return false;
	}

	private function holder_key(): string {
		if ( isset( WC()->session ) ) {
			return 'sess_' . WC()->session->get_customer_id();
		}
		return 'anon_' . wp_generate_uuid4();
	}
}
