<?php
declare(strict_types=1);

namespace RegionalInventory\Tests;

use PHPUnit\Framework\TestCase;
use RegionalInventory\InMemoryInventoryRepository;
use RegionalInventory\ReservationService;

final class ReservationServiceTest extends TestCase {

	private ReservationService $svc;
	private InMemoryInventoryRepository $repo;
	private int $now = 1_700_000_000;

	protected function setUp(): void {
		$this->repo = new InMemoryInventoryRepository();
		$this->svc  = new ReservationService(
			$this->repo,
			ReservationService::DEFAULT_TTL_SECONDS,
			fn (): int => $this->now
		);
	}

	public function test_available_equals_on_hand_minus_reserved(): void {
		$this->svc->set_on_hand( 10, 0, 'eu', 5 );
		$id = $this->svc->reserve( 10, 0, 'eu', 2, 'cart-a' );
		$this->assertNotNull( $id );
		$snap = $this->svc->snapshot( 10, 0, 'eu' );
		$this->assertSame( 5, $snap['on_hand'] );
		$this->assertSame( 2, $snap['reserved'] );
		$this->assertSame( 3, $snap['available'] );
	}

	public function test_pools_are_isolated(): void {
		$this->svc->set_on_hand( 10, 0, 'eu', 1 );
		$this->svc->set_on_hand( 10, 0, 'asia', 9 );
		$this->assertTrue( $this->svc->can_fulfill( 10, 0, 'asia', 9 ) );
		$this->assertFalse( $this->svc->can_fulfill( 10, 0, 'eu', 2 ) );
		$this->assertNull( $this->svc->reserve( 10, 0, 'eu', 2, 'x' ) );
		$this->assertNotNull( $this->svc->reserve( 10, 0, 'asia', 9, 'y' ) );
		$this->assertSame( 0, $this->svc->available( 10, 0, 'asia' ) );
		$this->assertSame( 1, $this->svc->available( 10, 0, 'eu' ) );
	}

	public function test_last_unit_race_second_reserve_fails(): void {
		$this->svc->set_on_hand( 42, 0, 'americas', 1 );
		$a = $this->svc->reserve( 42, 0, 'americas', 1, 'ca-cart' );
		$b = $this->svc->reserve( 42, 0, 'americas', 1, 'py-cart' );
		$this->assertNotNull( $a );
		$this->assertNull( $b );
		$this->assertSame( 0, $this->svc->available( 42, 0, 'americas' ) );
	}

	public function test_commit_decrements_on_hand_and_clears_reservation(): void {
		$this->svc->set_on_hand( 7, 0, 'eu', 3 );
		$id = $this->svc->reserve( 7, 0, 'eu', 1, 'order' );
		$this->assertTrue( $this->svc->commit( $id ) );
		$snap = $this->svc->snapshot( 7, 0, 'eu' );
		$this->assertSame( 2, $snap['on_hand'] );
		$this->assertSame( 0, $snap['reserved'] );
		$this->assertSame( 2, $snap['available'] );
		$this->assertSame( 'committed', $this->repo->status( $id ) );
	}

	public function test_release_returns_availability_without_changing_on_hand(): void {
		$this->svc->set_on_hand( 8, 0, 'asia', 4 );
		$id = $this->svc->reserve( 8, 0, 'asia', 3, 'tmp' );
		$this->assertTrue( $this->svc->release( $id ) );
		$snap = $this->svc->snapshot( 8, 0, 'asia' );
		$this->assertSame( 4, $snap['on_hand'] );
		$this->assertSame( 4, $snap['available'] );
	}

	public function test_expired_reservation_frees_availability(): void {
		$this->svc->set_on_hand( 9, 0, 'eu', 1 );
		$id = $this->svc->reserve( 9, 0, 'eu', 1, 'slow' );
		$this->assertNotNull( $id );
		$this->now += ReservationService::DEFAULT_TTL_SECONDS + 1;
		$this->assertSame( 1, $this->svc->available( 9, 0, 'eu' ) );
		$this->assertSame( 'expired', $this->repo->status( $id ) );
	}

	public function test_unknown_pool_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->svc->set_on_hand( 1, 0, 'mars', 1 );
	}

	public function test_order_path_math_eu_and_asia_parallel(): void {
		// Simulate PL (eu) and TH (asia) each buying 1 of the same SKU with 1 unit each pool.
		$this->svc->set_on_hand( 100, 0, 'eu', 1 );
		$this->svc->set_on_hand( 100, 0, 'asia', 1 );
		$pl = $this->svc->reserve( 100, 0, 'eu', 1, 'pl' );
		$th = $this->svc->reserve( 100, 0, 'asia', 1, 'th' );
		$this->assertNotNull( $pl );
		$this->assertNotNull( $th );
		$this->assertTrue( $this->svc->commit( $pl ) );
		$this->assertTrue( $this->svc->commit( $th ) );
		$this->assertSame( 0, $this->svc->snapshot( 100, 0, 'eu' )['on_hand'] );
		$this->assertSame( 0, $this->svc->snapshot( 100, 0, 'asia' )['on_hand'] );
		// Americas untouched.
		$this->svc->set_on_hand( 100, 0, 'americas', 5 );
		$this->assertSame( 5, $this->svc->available( 100, 0, 'americas' ) );
	}

	public function test_refund_restore_math(): void {
		$this->svc->set_on_hand( 55, 0, 'americas', 2 );
		$id = $this->svc->reserve( 55, 0, 'americas', 2, 'ca' );
		$this->svc->commit( $id );
		// Refund 1 unit back.
		$snap = $this->svc->snapshot( 55, 0, 'americas' );
		$this->svc->set_on_hand( 55, 0, 'americas', $snap['on_hand'] + 1 );
		$this->assertSame( 1, $this->svc->available( 55, 0, 'americas' ) );
	}
}
