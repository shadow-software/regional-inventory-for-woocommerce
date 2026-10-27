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
		if ( ! $product instanceof \WC_Product || ! $product->managing_stock() || ! $this->use_pool_stock() ) {
			return $in_stock;
		}
		list( $id, $vid ) = $this->balance_ids_for_product( $product );
		return $this->service->available( $id, $vid, $this->active_pool() ) > 0;
	}

	/**
	 * @param mixed       $qty     Qty.
	 * @param \WC_Product $product Product.
	 */
	public function filter_stock_qty( $qty, $product ) {
		if ( ! $product instanceof \WC_Product || ! $product->managing_stock() || ! $this->use_pool_stock() ) {
			return $qty;
		}
		list( $id, $vid ) = $this->balance_ids_for_product( $product );
		return $this->service->available( $id, $vid, $this->active_pool() );
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
		list( $product_id, $variation_id ) = $this->balance_ids( (int) $product_id, (int) $variation_id );
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
		list( $product_id, $variation_id ) = $this->balance_ids( (int) $product_id, (int) $variation_id );
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
			list( $id, $vid ) = $this->balance_ids_for_product( $product );
			$this->service->set_on_hand(
				$id,
				$vid,
				$pool,
				$this->service->snapshot( $id, $vid, $pool )['on_hand'] + (int) $item->get_quantity()
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
			list( $id, $vid ) = $this->balance_ids_for_product( $product );
			$qty  = abs( (int) $item->get_quantity() );
			$snap = $this->service->snapshot( $id, $vid, $pool );
			$this->service->set_on_hand( $id, $vid, $pool, $snap['on_hand'] + $qty );
		}
	}

	/**
	 * @param bool      $can_reduce Can reduce.
	 * @param \WC_Order $order      Order.
	 */
	public function block_global_reduce( $can_reduce, $order ) {
		return false;
	}

	/**
	 * Language copies sell the same vials. Balances live on the English product.
	 *
	 * @return array{0:int,1:int}
	 */
	public function balance_ids_for_product( \WC_Product $product ): array {
		if ( $product->is_type( 'variation' ) ) {
			return $this->balance_ids( (int) $product->get_parent_id(), (int) $product->get_id() );
		}
		return $this->balance_ids( (int) $product->get_id(), 0 );
	}

	/**
	 * @return array{0:int,1:int} Parent id, variation id (0 for a simple product).
	 */
	public function balance_ids( int $product_id, int $variation_id ): array {
		if ( $variation_id > 0 ) {
			$variation_id = $this->canonical_post_id( $variation_id );
			$parent       = (int) wp_get_post_parent_id( $variation_id );
			if ( $parent > 0 ) {
				$product_id = $parent;
			} else {
				$product_id = $this->canonical_post_id( $product_id );
			}
			return array( $product_id, $variation_id );
		}
		return array( $this->canonical_post_id( $product_id ), 0 );
	}

	public function canonical_post_id( int $post_id ): int {
		if ( $post_id < 1 ) {
			return $post_id;
		}
		$meta = (int) get_post_meta( $post_id, '_aura_stock_canonical', true );
		if ( $meta > 0 ) {
			return $meta;
		}
		if ( ! function_exists( 'pll_get_post' ) ) {
			return $post_id;
		}
		$lang = 'en';
		if ( function_exists( 'pll_default_language' ) ) {
			$default = pll_default_language( 'slug' );
			if ( is_string( $default ) && '' !== $default ) {
				$lang = $default;
			}
		}
		$canonical = pll_get_post( $post_id, $lang );
		$canonical = is_numeric( $canonical ) ? (int) $canonical : 0;
		return $canonical > 0 ? $canonical : $post_id;
	}

	/**
	 * Admin screens show the stored WooCommerce quantity. The storefront and
	 * admin-ajax add-to-cart use the regional pool.
	 */
	private function use_pool_stock(): bool {
		if ( function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return false;
		}
		return true;
	}

	private function holder_key(): string {
		if ( isset( WC()->session ) ) {
			return 'sess_' . WC()->session->get_customer_id();
		}
		return 'anon_' . wp_generate_uuid4();
	}
}
