<?php
declare(strict_types=1);

namespace RegionalInventory\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pure mapping tests mirroring Aura market router (kept here so inventory
 * math + market→pool contract stay green without loading WordPress).
 */
final class MarketPoolMapTest extends TestCase {

	/** @return array<string,array{pool:string,currency:string,country:string}> */
	private function markets(): array {
		return array(
			'pl' => array( 'pool' => 'eu', 'currency' => 'PLN', 'country' => 'PL' ),
			'th' => array( 'pool' => 'asia', 'currency' => 'THB', 'country' => 'TH' ),
			'ca' => array( 'pool' => 'americas', 'currency' => 'CAD', 'country' => 'CA' ),
			'py' => array( 'pool' => 'americas', 'currency' => 'USD', 'country' => 'PY' ),
		);
	}

	public function test_four_launch_markets_map_to_three_pools(): void {
		$map = $this->markets();
		$this->assertSame( 'eu', $map['pl']['pool'] );
		$this->assertSame( 'asia', $map['th']['pool'] );
		$this->assertSame( 'americas', $map['ca']['pool'] );
		$this->assertSame( 'americas', $map['py']['pool'] );
		$pools = array_unique( array_column( $map, 'pool' ) );
		sort( $pools );
		$this->assertSame( array( 'americas', 'asia', 'eu' ), $pools );
	}

	public function test_coa_client_short_labels(): void {
		$labels = array(
			'eu'       => 'Aura Labs EU',
			'asia'     => 'Aura Labs Asia',
			'americas' => 'Aura Labs Americas',
		);
		foreach ( $labels as $pool => $label ) {
			$this->assertLessThanOrEqual( 20, strlen( $label ), $pool );
			$this->assertStringStartsWith( 'Aura Labs ', $label );
		}
	}

	public function test_host_slug_extraction(): void {
		$cases = array(
			'pl.auralabs.localhost' => 'pl',
			'th.auralabs.localhost:8088' => 'th',
			'ca.staging.auralabs.localhost' => 'ca',
			'py.auralabsxkqkicmcslyynkd2xdzrks667bipczr75tzfbfikn2wixrad.onion' => 'py',
			'staging.auralabs.localhost' => null,
		);
		foreach ( $cases as $host => $expect ) {
			$this->assertSame( $expect, $this->extract_slug( $host ), $host );
		}
	}

	private function extract_slug( string $host ): ?string {
		$host = strtolower( preg_replace( '/:\d+$/', '', $host ) ?? $host );
		if ( preg_match( '/^(pl|th|ca|py)\./', $host, $m ) ) {
			return $m[1];
		}
		return null;
	}
}
