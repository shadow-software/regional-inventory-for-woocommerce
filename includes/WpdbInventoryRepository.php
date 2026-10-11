<?php
/**
 * $wpdb-backed inventory repository.
 *
 * @package RegionalInventory
 */

declare(strict_types=1);

namespace RegionalInventory;

use RegionalInventory\Contracts\InventoryRepositoryInterface;

final class WpdbInventoryRepository implements InventoryRepositoryInterface {

	/** @var \wpdb */
	private $db;

	private string $balances;

	private string $reservations;

	/**
	 * @param \wpdb $wpdb WordPress DB.
	 */
	public function __construct( $wpdb ) {
		$this->db           = $wpdb;
		$this->balances     = $wpdb->prefix . 'rifw_balances';
		$this->reservations = $wpdb->prefix . 'rifw_reservations';
	}

	public static function install( $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$bal     = $wpdb->prefix . 'rifw_balances';
		$res     = $wpdb->prefix . 'rifw_reservations';

		$sql_bal = "CREATE TABLE {$bal} (
			product_id bigint(20) unsigned NOT NULL,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			pool varchar(32) NOT NULL,
			qty int NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (product_id, variation_id, pool),
			KEY pool (pool)
		) {$charset};";

		$sql_res = "CREATE TABLE {$res} (
			id varchar(64) NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			pool varchar(32) NOT NULL,
			qty int NOT NULL,
			holder_key varchar(191) NOT NULL,
			expires_at int unsigned NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY lookup (product_id, variation_id, pool, status),
			KEY expires (status, expires_at),
			KEY holder (holder_key)
		) {$charset};";

		dbDelta( $sql_bal );
		dbDelta( $sql_res );
	}

	public function available( int $product_id, int $variation_id, string $pool ): int {
		return max( 0, $this->on_hand( $product_id, $variation_id, $pool ) - $this->reserved( $product_id, $variation_id, $pool ) );
	}

	public function on_hand( int $product_id, int $variation_id, string $pool ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$qty = $this->db->get_var(
			$this->db->prepare(
				"SELECT qty FROM {$this->balances} WHERE product_id = %d AND variation_id = %d AND pool = %s",
				$product_id,
				$variation_id,
				$pool
			)
		);
		return null === $qty ? 0 : (int) $qty;
	}

	public function set_on_hand( int $product_id, int $variation_id, string $pool, int $qty ): void {
		if ( $qty < 0 ) {
			throw new \InvalidArgumentException( 'on_hand cannot be negative' );
		}
		$this->db->replace(
			$this->balances,
			array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'pool'         => $pool,
				'qty'          => $qty,
			),
			array( '%d', '%d', '%s', '%d' )
		);
	}

	public function adjust_on_hand( int $product_id, int $variation_id, string $pool, int $delta ): bool {
		$this->db->query( 'START TRANSACTION' );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $this->db->get_row(
				$this->db->prepare(
					"SELECT qty FROM {$this->balances} WHERE product_id = %d AND variation_id = %d AND pool = %s FOR UPDATE",
					$product_id,
					$variation_id,
					$pool
				),
				ARRAY_A
			);
			$current = $row ? (int) $row['qty'] : 0;
			$next    = $current + $delta;
			if ( $next < 0 ) {
				$this->db->query( 'ROLLBACK' );
				return false;
			}
			$this->set_on_hand( $product_id, $variation_id, $pool, $next );
			$this->db->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' );
			return false;
		}
	}

	public function reserve( int $product_id, int $variation_id, string $pool, int $qty, string $holder_key, int $expires_at ): ?string {
		if ( $qty < 1 ) {
			return null;
		}
		$this->db->query( 'START TRANSACTION' );
		try {
			if ( $this->available( $product_id, $variation_id, $pool ) < $qty ) {
				$this->db->query( 'ROLLBACK' );
				return null;
			}
			$id = 'rifw_' . bin2hex( random_bytes( 12 ) );
			$ok = $this->db->insert(
				$this->reservations,
				array(
					'id'           => $id,
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'pool'         => $pool,
					'qty'          => $qty,
					'holder_key'   => $holder_key,
					'expires_at'   => $expires_at,
					'status'       => 'active',
				),
				array( '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s' )
			);
			if ( ! $ok ) {
				$this->db->query( 'ROLLBACK' );
				return null;
			}
			$this->db->query( 'COMMIT' );
			return $id;
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' );
			return null;
		}
	}

	public function release( string $reservation_id ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->reservations} SET status = 'released' WHERE id = %s AND status = 'active'",
				$reservation_id
			)
		);
		return $n > 0;
	}

	public function commit( string $reservation_id ): bool {
		$this->db->query( 'START TRANSACTION' );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $this->db->get_row(
				$this->db->prepare(
					"SELECT * FROM {$this->reservations} WHERE id = %s AND status = 'active' FOR UPDATE",
					$reservation_id
				),
				ARRAY_A
			);
			if ( ! $row ) {
				$this->db->query( 'ROLLBACK' );
				return false;
			}
			if ( ! $this->adjust_on_hand_unlocked( (int) $row['product_id'], (int) $row['variation_id'], (string) $row['pool'], -(int) $row['qty'] ) ) {
				$this->db->query( 'ROLLBACK' );
				return false;
			}
			$this->db->update(
				$this->reservations,
				array( 'status' => 'committed' ),
				array( 'id' => $reservation_id ),
				array( '%s' ),
				array( '%s' )
			);
			$this->db->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' );
			return false;
		}
	}

	/**
	 * Adjust without nested transaction (used inside commit).
	 */
	private function adjust_on_hand_unlocked( int $product_id, int $variation_id, string $pool, int $delta ): bool {
		$current = $this->on_hand( $product_id, $variation_id, $pool );
		$next    = $current + $delta;
		if ( $next < 0 ) {
			return false;
		}
		$this->set_on_hand( $product_id, $variation_id, $pool, $next );
		return true;
	}

	public function expire_stale( int $now ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->reservations} SET status = 'expired' WHERE status = 'active' AND expires_at <= %d",
				$now
			)
		);
		return (int) $n;
	}

	public function reserved( int $product_id, int $variation_id, string $pool ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sum = $this->db->get_var(
			$this->db->prepare(
				"SELECT COALESCE(SUM(qty),0) FROM {$this->reservations}
				 WHERE product_id = %d AND variation_id = %d AND pool = %s AND status = 'active'",
				$product_id,
				$variation_id,
				$pool
			)
		);
		return (int) $sum;
	}
}
