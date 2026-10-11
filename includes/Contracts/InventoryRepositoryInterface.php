<?php
/**
 * Inventory repository contract.
 *
 * @package RegionalInventory
 */

declare(strict_types=1);

namespace RegionalInventory\Contracts;

interface InventoryRepositoryInterface {

	/**
	 * Available (on-hand minus active reservations) for a SKU in a pool.
	 */
	public function available( int $product_id, int $variation_id, string $pool ): int;

	/**
	 * On-hand balance (ignores reservations).
	 */
	public function on_hand( int $product_id, int $variation_id, string $pool ): int;

	/**
	 * Set absolute on-hand quantity (admin / seed).
	 */
	public function set_on_hand( int $product_id, int $variation_id, string $pool, int $qty ): void;

	/**
	 * Adjust on-hand by delta (can be negative). Fails if result would be < 0.
	 *
	 * @return bool True on success.
	 */
	public function adjust_on_hand( int $product_id, int $variation_id, string $pool, int $delta ): bool;

	/**
	 * Atomically reserve qty. Returns reservation id or null if insufficient.
	 */
	public function reserve( int $product_id, int $variation_id, string $pool, int $qty, string $holder_key, int $expires_at ): ?string;

	/**
	 * Release an active reservation back to availability (does not change on-hand).
	 */
	public function release( string $reservation_id ): bool;

	/**
	 * Commit reservation: decrement on-hand and remove reservation.
	 */
	public function commit( string $reservation_id ): bool;

	/**
	 * Expire stale reservations (release holds past expires_at).
	 *
	 * @return int Number released.
	 */
	public function expire_stale( int $now ): int;

	/**
	 * Active reserved qty for a SKU in a pool.
	 */
	public function reserved( int $product_id, int $variation_id, string $pool ): int;
}
