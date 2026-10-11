<?php
/**
 * In-memory repository for unit tests (no WordPress).
 *
 * @package RegionalInventory
 */

declare(strict_types=1);

namespace RegionalInventory;

use RegionalInventory\Contracts\InventoryRepositoryInterface;

final class InMemoryInventoryRepository implements InventoryRepositoryInterface {

	/** @var array<string,int> key = product:variation:pool */
	private array $on_hand = array();

	/**
	 * @var array<string,array{product_id:int,variation_id:int,pool:string,qty:int,holder_key:string,expires_at:int,status:string}>
	 */
	private array $reservations = array();

	private int $seq = 0;

	private function key( int $product_id, int $variation_id, string $pool ): string {
		return $product_id . ':' . $variation_id . ':' . $pool;
	}

	public function available( int $product_id, int $variation_id, string $pool ): int {
		return max( 0, $this->on_hand( $product_id, $variation_id, $pool ) - $this->reserved( $product_id, $variation_id, $pool ) );
	}

	public function on_hand( int $product_id, int $variation_id, string $pool ): int {
		$k = $this->key( $product_id, $variation_id, $pool );
		return $this->on_hand[ $k ] ?? 0;
	}

	public function set_on_hand( int $product_id, int $variation_id, string $pool, int $qty ): void {
		if ( $qty < 0 ) {
			throw new \InvalidArgumentException( 'on_hand cannot be negative' );
		}
		$this->on_hand[ $this->key( $product_id, $variation_id, $pool ) ] = $qty;
	}

	public function adjust_on_hand( int $product_id, int $variation_id, string $pool, int $delta ): bool {
		$next = $this->on_hand( $product_id, $variation_id, $pool ) + $delta;
		if ( $next < 0 ) {
			return false;
		}
		$this->set_on_hand( $product_id, $variation_id, $pool, $next );
		return true;
	}

	public function reserve( int $product_id, int $variation_id, string $pool, int $qty, string $holder_key, int $expires_at ): ?string {
		if ( $qty < 1 ) {
			return null;
		}
		if ( $this->available( $product_id, $variation_id, $pool ) < $qty ) {
			return null;
		}
		++$this->seq;
		$id = 'r' . $this->seq;
		$this->reservations[ $id ] = array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'pool'         => $pool,
			'qty'          => $qty,
			'holder_key'   => $holder_key,
			'expires_at'   => $expires_at,
			'status'       => 'active',
		);
		return $id;
	}

	public function release( string $reservation_id ): bool {
		if ( ! isset( $this->reservations[ $reservation_id ] ) ) {
			return false;
		}
		if ( 'active' !== $this->reservations[ $reservation_id ]['status'] ) {
			return false;
		}
		$this->reservations[ $reservation_id ]['status'] = 'released';
		return true;
	}

	public function commit( string $reservation_id ): bool {
		if ( ! isset( $this->reservations[ $reservation_id ] ) ) {
			return false;
		}
		$row = $this->reservations[ $reservation_id ];
		if ( 'active' !== $row['status'] ) {
			return false;
		}
		if ( ! $this->adjust_on_hand( $row['product_id'], $row['variation_id'], $row['pool'], -$row['qty'] ) ) {
			return false;
		}
		$this->reservations[ $reservation_id ]['status'] = 'committed';
		return true;
	}

	public function expire_stale( int $now ): int {
		$n = 0;
		foreach ( $this->reservations as $id => $row ) {
			if ( 'active' === $row['status'] && $row['expires_at'] <= $now ) {
				$this->reservations[ $id ]['status'] = 'expired';
				++$n;
			}
		}
		return $n;
	}

	public function reserved( int $product_id, int $variation_id, string $pool ): int {
		$sum = 0;
		foreach ( $this->reservations as $row ) {
			if (
				'active' === $row['status']
				&& $row['product_id'] === $product_id
				&& $row['variation_id'] === $variation_id
				&& $row['pool'] === $pool
			) {
				$sum += $row['qty'];
			}
		}
		return $sum;
	}

	/**
	 * Test helper: reservation status.
	 */
	public function status( string $reservation_id ): ?string {
		return $this->reservations[ $reservation_id ]['status'] ?? null;
	}
}
