<?php
/**
 * Reservation service — business rules over a repository.
 *
 * @package RegionalInventory
 */

declare(strict_types=1);

namespace RegionalInventory;

use RegionalInventory\Contracts\InventoryRepositoryInterface;

final class ReservationService {

	public const DEFAULT_TTL_SECONDS = 1800;

	/** @var list<string> */
	public const DEFAULT_POOLS = array( 'eu', 'asia', 'americas' );

	private InventoryRepositoryInterface $repo;

	private int $ttl;

	/** @var callable():int */
	private $clock;

	/**
	 * @param callable():int|null $clock Returns unix timestamp; injectable for tests.
	 */
	public function __construct( InventoryRepositoryInterface $repo, int $ttl = self::DEFAULT_TTL_SECONDS, ?callable $clock = null ) {
		$this->repo  = $repo;
		$this->ttl   = max( 60, $ttl );
		$this->clock = $clock ?? static function (): int {
			return time();
		};
	}

	public static function assert_pool( string $pool ): string {
		$pool = strtolower( trim( $pool ) );
		if ( ! in_array( $pool, self::DEFAULT_POOLS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown inventory pool: ' . $pool );
		}
		return $pool;
	}

	public function available( int $product_id, int $variation_id, string $pool ): int {
		$pool = self::assert_pool( $pool );
		$this->repo->expire_stale( ( $this->clock )() );
		return $this->repo->available( $product_id, $variation_id, $pool );
	}

	public function can_fulfill( int $product_id, int $variation_id, string $pool, int $qty ): bool {
		return $qty > 0 && $this->available( $product_id, $variation_id, $pool ) >= $qty;
	}

	/**
	 * Reserve stock for a cart/order holder.
	 *
	 * @return string|null Reservation id.
	 */
	public function reserve( int $product_id, int $variation_id, string $pool, int $qty, string $holder_key ): ?string {
		$pool = self::assert_pool( $pool );
		if ( $qty < 1 || '' === $holder_key ) {
			return null;
		}
		$now = ( $this->clock )();
		$this->repo->expire_stale( $now );
		return $this->repo->reserve( $product_id, $variation_id, $pool, $qty, $holder_key, $now + $this->ttl );
	}

	public function release( string $reservation_id ): bool {
		return $this->repo->release( $reservation_id );
	}

	public function commit( string $reservation_id ): bool {
		return $this->repo->commit( $reservation_id );
	}

	/**
	 * Seed / admin set.
	 */
	public function set_on_hand( int $product_id, int $variation_id, string $pool, int $qty ): void {
		$pool = self::assert_pool( $pool );
		$this->repo->set_on_hand( $product_id, $variation_id, $pool, $qty );
	}

	/**
	 * Math helper: after commit, available in pool; used by order-path tests.
	 *
	 * @return array{on_hand:int,reserved:int,available:int}
	 */
	public function snapshot( int $product_id, int $variation_id, string $pool ): array {
		$pool = self::assert_pool( $pool );
		$this->repo->expire_stale( ( $this->clock )() );
		return array(
			'on_hand'   => $this->repo->on_hand( $product_id, $variation_id, $pool ),
			'reserved'  => $this->repo->reserved( $product_id, $variation_id, $pool ),
			'available' => $this->repo->available( $product_id, $variation_id, $pool ),
		);
	}
}
