<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Persistence;

use CetechDeliveryEngine\Infrastructure\Persistence\WordPressOptionCasStore;
use PHPUnit\Framework\TestCase;

/**
 * Isolated option CAS: successful swap, stale expected-value failure,
 * owner-fenced delete, cache invalidation.
 */
final class WordPressOptionCasStoreTest extends TestCase {

	private WordPressOptionCasStore $store;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options']       = [];
		$GLOBALS['cetech_de_test_cache_deletes'] = [];
		unset( $GLOBALS['wpdb'] );
		$this->store = new WordPressOptionCasStore();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cetech_de_test_options'], $GLOBALS['cetech_de_test_cache_deletes'], $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_add_is_atomic_first_writer_wins(): void {
		self::assertTrue( $this->store->add( 'k', [ 'owner' => 'a' ] ) );
		self::assertFalse( $this->store->add( 'k', [ 'owner' => 'b' ] ) );
		$stored = $this->store->get( 'k' );
		self::assertIsArray( $stored );
		self::assertSame( 'a', $stored['owner'] ?? '' );
	}

	public function test_d_stale_read_race_second_cas_using_l0_fails_and_does_not_delete_la(): void {
		$l0 = [ 'owner' => 'stale', 'expires_at' => 10, 'acquired_at' => 1, 'revision' => 1 ];
		$la = [ 'owner' => 'a', 'expires_at' => 1060, 'acquired_at' => 1000, 'revision' => 1 ];
		$lb = [ 'owner' => 'b', 'expires_at' => 1060, 'acquired_at' => 1000, 'revision' => 1 ];
		self::assertTrue( $this->store->add( 'lock', $l0 ) );
		$observed_a = $this->store->get( 'lock' );
		$observed_b = $this->store->get( 'lock' );
		self::assertTrue( $this->store->compare_and_swap( 'lock', $observed_a, $la ) );
		self::assertFalse( $this->store->compare_and_swap( 'lock', $observed_b, $lb ) );
		self::assertFalse( $this->store->compare_and_delete( 'lock', $l0 ) );
		$stored = $this->store->get( 'lock' );
		self::assertIsArray( $stored );
		self::assertSame( 'a', $stored['owner'] ?? '' );
		self::assertSame( 1060, $stored['expires_at'] ?? 0 );
	}

	public function test_owner_fenced_renewal_and_delete(): void {
		$a = [ 'owner' => 'a', 'expires_at' => 1060, 'acquired_at' => 1000, 'revision' => 1 ];
		$b = [ 'owner' => 'b', 'expires_at' => 1060, 'acquired_at' => 1000, 'revision' => 1 ];
		$renewed = [ 'owner' => 'a', 'expires_at' => 1110, 'acquired_at' => 1000, 'revision' => 1 ];
		self::assertTrue( $this->store->add( 'lock', $a ) );
		self::assertTrue( $this->store->compare_and_swap( 'lock', $a, $renewed ) );
		self::assertFalse( $this->store->compare_and_delete( 'lock', $b ) );
		self::assertFalse( $this->store->compare_and_delete( 'lock', $a ) );
		$stored = $this->store->get( 'lock' );
		self::assertIsArray( $stored );
		self::assertSame( 1110, $stored['expires_at'] ?? 0 );
		self::assertTrue( $this->store->compare_and_delete( 'lock', $renewed ) );
		self::assertFalse( array_key_exists( 'lock', $GLOBALS['cetech_de_test_options'] ) );
	}

	public function test_cas_invalidates_option_cache_groups(): void {
		$l0 = [ 'owner' => 'stale', 'expires_at' => 1, 'acquired_at' => 1, 'revision' => 1 ];
		$la = [ 'owner' => 'a', 'expires_at' => 60, 'acquired_at' => 1, 'revision' => 1 ];
		$this->store->add( 'cetech_de_country_identity_repair_lock', $l0 );
		$GLOBALS['cetech_de_test_cache_deletes'] = [];
		$this->store->compare_and_swap( 'cetech_de_country_identity_repair_lock', $l0, $la );
		$pairs = $GLOBALS['cetech_de_test_cache_deletes'];
		self::assertContains( [ 'cetech_de_country_identity_repair_lock', 'options' ], $pairs );
		self::assertContains( [ 'alloptions', 'options' ], $pairs );
		self::assertContains( [ 'notoptions', 'options' ], $pairs );
	}
}
