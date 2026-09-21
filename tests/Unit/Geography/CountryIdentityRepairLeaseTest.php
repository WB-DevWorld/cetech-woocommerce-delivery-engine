<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Geography\CountryIdentityReconciler;
use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Infrastructure\Persistence\WordPressOptionCasStore;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use PHPUnit\Framework\TestCase;

/**
 * Issue #35 owner-fenced renewable repair lease (geo-country.4).
 */
final class CountryIdentityRepairLeaseTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_wc']            = $this->woo_stub();
		$GLOBALS['cetech_de_test_options']       = [];
		$GLOBALS['cetech_de_test_cache_deletes'] = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cetech_de_test_wc'], $GLOBALS['cetech_de_test_options'], $GLOBALS['cetech_de_test_cache_deletes'] );
		parent::tearDown();
	}

	public function test_a_initial_add_option_exactly_one_owner_wins(): void {
		$a     = $this->stack();
		$b     = $this->stack_from( $a );
		$calls = 0;
		$nested = null;
		$a['reconciler']->before_repair_all = static function () use ( $b, &$nested, &$calls ): void {
			++$calls;
			$nested = $b['reconciler']->maybe_repair();
		};
		$first = $a['reconciler']->maybe_repair();
		self::assertFalse( (bool) ( $first['skipped'] ?? true ) );
		self::assertSame( 1, $calls );
		self::assertIsArray( $nested );
		self::assertTrue( (bool) ( $nested['skipped'] ?? false ) );
		self::assertSame( 'locked', $nested['reason'] ?? '' );
		self::assertFalse( array_key_exists( CountryIdentityReconciler::LOCK_OPTION_KEY, $GLOBALS['cetech_de_test_options'] ?? [] ) );
	}

	public function test_b_active_lock_second_caller_receives_locked(): void {
		$clock = 1_000;
		$now   = static function () use ( &$clock ): int {
			return $clock;
		};
		$a = $this->stack( $now );
		$b = $this->stack_from( $a, $now );
		$a['reconciler']->before_repair_all = static function () use ( $b ): void {
			$result = $b['reconciler']->maybe_repair();
			self::assertTrue( (bool) ( $result['skipped'] ?? false ) );
			self::assertSame( 'locked', $result['reason'] ?? '' );
		};
		self::assertFalse( (bool) ( $a['reconciler']->maybe_repair()['skipped'] ?? true ) );
	}

	public function test_c_stale_takeover_replaces_genuinely_stale_l0(): void {
		$now = 2_000;
		$this->store_lock(
			[
				'owner'       => 'stale',
				'expires_at'  => $now - 1,
				'acquired_at' => $now - 120,
				'revision'    => 1,
			]
		);
		$stack  = $this->stack( static fn (): int => $now );
		$result = $stack['reconciler']->maybe_repair();
		self::assertFalse( (bool) ( $result['skipped'] ?? true ) );
		self::assertSame( 1, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		self::assertFalse( array_key_exists( CountryIdentityReconciler::LOCK_OPTION_KEY, $GLOBALS['cetech_de_test_options'] ?? [] ) );
	}

	public function test_d_stale_read_race_second_cas_on_l0_fails(): void {
		$l0 = [
			'owner'       => 'stale',
			'expires_at'  => 10,
			'acquired_at' => 1,
			'revision'    => 1,
		];
		$la = [
			'owner'       => 'a',
			'expires_at'  => 1060,
			'acquired_at' => 1000,
			'revision'    => 1,
		];
		$lb = [
			'owner'       => 'b',
			'expires_at'  => 1060,
			'acquired_at' => 1000,
			'revision'    => 1,
		];
		$cas = new WordPressOptionCasStore();
		self::assertTrue( $cas->add( CountryIdentityReconciler::LOCK_OPTION_KEY, $l0 ) );
		$observed_a = $cas->get( CountryIdentityReconciler::LOCK_OPTION_KEY );
		$observed_b = $cas->get( CountryIdentityReconciler::LOCK_OPTION_KEY );
		self::assertTrue( $cas->compare_and_swap( CountryIdentityReconciler::LOCK_OPTION_KEY, $observed_a, $la ) );
		self::assertFalse( $cas->compare_and_swap( CountryIdentityReconciler::LOCK_OPTION_KEY, $observed_b, $lb ) );
		self::assertFalse( $cas->compare_and_delete( CountryIdentityReconciler::LOCK_OPTION_KEY, $l0 ) );
		$stored = $cas->get( CountryIdentityReconciler::LOCK_OPTION_KEY );
		self::assertIsArray( $stored );
		self::assertSame( 'a', $stored['owner'] ?? '' );
	}

	public function test_e_two_stale_contenders_only_one_reaches_repair_all(): void {
		$now = 3_000;
		$this->store_lock(
			[
				'owner'       => 'stale',
				'expires_at'  => $now - 5,
				'acquired_at' => $now - 90,
				'revision'    => 1,
			]
		);
		$clock = $now;
		$tick  = static function () use ( &$clock ): int {
			return $clock;
		};
		$a = $this->stack( $tick );
		$b = $this->stack_from( $a, $tick );
		$calls = 0;
		$nested = null;
		$a['reconciler']->after_observe_lock = static function () use ( $b, &$nested ): void {
			$nested = $b['reconciler']->maybe_repair();
		};
		$a['reconciler']->before_repair_all = static function () use ( &$calls ): void {
			++$calls;
		};
		$b['reconciler']->before_repair_all = static function () use ( &$calls ): void {
			++$calls;
		};
		$first = $a['reconciler']->maybe_repair();
		self::assertSame( 1, $calls );
		$skipped = ( ( $first['skipped'] ?? false ) ? 1 : 0 ) + ( ( $nested['skipped'] ?? false ) ? 1 : 0 );
		$ran     = ( ( $first['skipped'] ?? true ) ? 0 : 1 ) + ( ( $nested['skipped'] ?? true ) ? 0 : 1 );
		self::assertSame( 1, $ran );
		self::assertSame( 1, $skipped );
	}

	public function test_f_owner_a_cannot_delete_lock_owned_by_b(): void {
		$cas = new WordPressOptionCasStore();
		$b   = [
			'owner'       => 'b',
			'expires_at'  => 2000,
			'acquired_at' => 1000,
			'revision'    => 1,
		];
		$a   = [
			'owner'       => 'a',
			'expires_at'  => 2000,
			'acquired_at' => 1000,
			'revision'    => 1,
		];
		self::assertTrue( $cas->add( CountryIdentityReconciler::LOCK_OPTION_KEY, $b ) );
		self::assertFalse( $cas->compare_and_delete( CountryIdentityReconciler::LOCK_OPTION_KEY, $a ) );
		$stored = $cas->get( CountryIdentityReconciler::LOCK_OPTION_KEY );
		self::assertIsArray( $stored );
		self::assertSame( 'b', $stored['owner'] ?? '' );
	}

	public function test_g_owner_renewal_extends_expiry(): void {
		$clock = 1_000;
		$now   = static function () use ( &$clock ): int {
			return $clock;
		};
		$stack = $this->stack( $now );
		$stack['boot']->bootstrap_country( 'US' );
		$seen  = [];
		$stack['reconciler']->after_repair_one = static function () use ( &$clock, &$seen ): void {
			$seen[] = get_option( CountryIdentityReconciler::LOCK_OPTION_KEY, [] );
			$clock   = 1_050;
		};
		$stack['reconciler']->maybe_repair();
		self::assertGreaterThanOrEqual( 2, count( $seen ) );
		$after_renew = $seen[1];
		self::assertIsArray( $after_renew );
		self::assertSame( 1_110, (int) ( $after_renew['expires_at'] ?? 0 ) );
	}

	public function test_h_long_repair_renewal_keeps_contender_locked_past_original_ttl(): void {
		$clock = 1_000;
		$now   = static function () use ( &$clock ): int {
			return $clock;
		};
		$a = $this->stack( $now );
		$a['boot']->bootstrap_country( 'US' );
		$a['boot']->bootstrap_country( 'GB' );
		$a['boot']->bootstrap_country( 'IM' );
		$b = $this->stack_from( $a, $now );
		$locked = null;
		$n      = 0;
		$a['reconciler']->after_repair_one = static function () use ( &$clock, $b, &$locked, &$n ): void {
			++$n;
			if ( 1 === $n ) {
				$clock = 1_050;
				return;
			}
			if ( 2 === $n ) {
				$clock  = 1_070;
				$locked = $b['reconciler']->maybe_repair();
				return;
			}
			$clock = 1_120;
		};
		$result = $a['reconciler']->maybe_repair();
		self::assertFalse( (bool) ( $result['skipped'] ?? true ) );
		self::assertIsArray( $locked );
		self::assertTrue( (bool) ( $locked['skipped'] ?? false ) );
		self::assertSame( 'locked', $locked['reason'] ?? '' );
		self::assertGreaterThan( 1_060, $clock );
	}

	public function test_i_lost_ownership_does_not_persist_revision(): void {
		$stack = $this->stack();
		$stack['reconciler']->after_repair_one = static function ( CountryIdentityReconciler $reconciler ): void {
			$current = $reconciler->cas->get( CountryIdentityReconciler::LOCK_OPTION_KEY );
			$other   = [
				'owner'       => 'intruder',
				'expires_at'  => time() + 60,
				'acquired_at' => time(),
				'revision'    => 1,
			];
			$reconciler->cas->compare_and_swap( CountryIdentityReconciler::LOCK_OPTION_KEY, $current, $other );
		};
		$result = $stack['reconciler']->maybe_repair();
		self::assertTrue( (bool) ( $result['skipped'] ?? false ) );
		self::assertSame( 'lease_lost', $result['reason'] ?? '' );
		self::assertSame( 0, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		$stored = get_option( CountryIdentityReconciler::LOCK_OPTION_KEY, false );
		self::assertIsArray( $stored );
		self::assertSame( 'intruder', $stored['owner'] ?? '' );
	}

	public function test_j_abandoned_renewed_lease_can_be_recovered_after_expiry(): void {
		$clock = 5_000;
		$now   = static function () use ( &$clock ): int {
			return $clock;
		};
		$this->store_lock(
			[
				'owner'       => 'abandoned',
				'expires_at'  => 4_000,
				'acquired_at' => 3_000,
				'revision'    => 1,
			]
		);
		$stack  = $this->stack( $now );
		$result = $stack['reconciler']->maybe_repair();
		self::assertFalse( (bool) ( $result['skipped'] ?? true ) );
		self::assertSame( 1, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		self::assertFalse( array_key_exists( CountryIdentityReconciler::LOCK_OPTION_KEY, $GLOBALS['cetech_de_test_options'] ?? [] ) );
	}

	public function test_k_exception_leaves_revision_incomplete_and_releases_owner_lock(): void {
		$stack = $this->stack();
		$stack['reconciler']->before_repair_all = static function (): void {
			throw new \RuntimeException( 'simulated repair failure' );
		};
		try {
			$stack['reconciler']->maybe_repair();
			self::fail( 'Expected repair failure.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'simulated repair failure', $e->getMessage() );
		}
		self::assertSame( 0, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		self::assertFalse( array_key_exists( CountryIdentityReconciler::LOCK_OPTION_KEY, $GLOBALS['cetech_de_test_options'] ?? [] ) );
		$stack['reconciler']->before_repair_all = null;
		$retry = $stack['reconciler']->maybe_repair();
		self::assertFalse( (bool) ( $retry['skipped'] ?? true ) );
		self::assertSame( 1, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
	}

	public function test_l_malformed_stale_lock_cas_does_not_delete_replaced_value(): void {
		$cas = new WordPressOptionCasStore();
		self::assertTrue( $cas->add( CountryIdentityReconciler::LOCK_OPTION_KEY, 'not-a-lock' ) );
		$observed_a = $cas->get( CountryIdentityReconciler::LOCK_OPTION_KEY );
		$observed_b = $cas->get( CountryIdentityReconciler::LOCK_OPTION_KEY );
		$la         = [
			'owner'       => 'a',
			'expires_at'  => 80,
			'acquired_at' => 20,
			'revision'    => 1,
		];
		$lb = [
			'owner'       => 'b',
			'expires_at'  => 80,
			'acquired_at' => 20,
			'revision'    => 1,
		];
		self::assertTrue( $cas->compare_and_swap( CountryIdentityReconciler::LOCK_OPTION_KEY, $observed_a, $la ) );
		self::assertFalse( $cas->compare_and_swap( CountryIdentityReconciler::LOCK_OPTION_KEY, $observed_b, $lb ) );
		self::assertFalse( $cas->compare_and_delete( CountryIdentityReconciler::LOCK_OPTION_KEY, 'not-a-lock' ) );
		$stored = $cas->get( CountryIdentityReconciler::LOCK_OPTION_KEY );
		self::assertIsArray( $stored );
		self::assertSame( 'a', $stored['owner'] ?? '' );

		$GLOBALS['cetech_de_test_options'] = [];
		update_option( CountryIdentityReconciler::LOCK_OPTION_KEY, 'legacy-string' );
		$stack  = $this->stack();
		$result = $stack['reconciler']->maybe_repair();
		self::assertFalse( (bool) ( $result['skipped'] ?? true ) );
		self::assertSame( 1, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
	}

	public function test_delayed_contender_rechecks_revision_under_lease_and_skips_repair_all(): void {
		$a     = $this->stack();
		$b     = $this->stack_from( $a );
		$calls = 0;
		$a['reconciler']->before_repair_all = static function () use ( &$calls ): void {
			++$calls;
		};
		$b['reconciler']->before_repair_all = static function () use ( &$calls ): void {
			++$calls;
		};
		$b_held_revision = null;
		$b_held_lock     = false;
		$b['reconciler']->before_lock_acquire = static function () use ( $a ): void {
			self::assertSame( 0, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
			$a['reconciler']->maybe_repair();
			self::assertSame( 1, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
			self::assertFalse( array_key_exists( CountryIdentityReconciler::LOCK_OPTION_KEY, $GLOBALS['cetech_de_test_options'] ?? [] ) );
		};
		$b['reconciler']->after_lock_acquire = static function () use ( &$b_held_revision, &$b_held_lock ): void {
			$b_held_revision = (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 );
			$b_held_lock     = array_key_exists( CountryIdentityReconciler::LOCK_OPTION_KEY, $GLOBALS['cetech_de_test_options'] ?? [] );
		};
		$result = $b['reconciler']->maybe_repair();
		self::assertTrue( (bool) ( $result['skipped'] ?? false ) );
		self::assertSame( 'revision_complete', $result['reason'] ?? '' );
		self::assertSame( [], $result['results'] ?? null );
		self::assertSame( 1, $calls );
		self::assertSame( 1, $b_held_revision );
		self::assertTrue( $b_held_lock );
		self::assertSame( 1, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		self::assertFalse( array_key_exists( CountryIdentityReconciler::LOCK_OPTION_KEY, $GLOBALS['cetech_de_test_options'] ?? [] ) );
		self::assertSame( 0, $b['reconciler']->source_scan_count );
		self::assertSame( 'Ghana', $a['geo']->locations->find_country( 'GH' )?->canonical_name );
	}

	public function test_post_acquire_recheck_still_allows_revision_bump_one_to_two(): void {
		$stack = $this->stack();
		$stack['reconciler']->maybe_repair( 1 );
		$calls = 0;
		$stack['reconciler']->before_repair_all = static function () use ( &$calls ): void {
			++$calls;
		};
		$held = null;
		$stack['reconciler']->after_lock_acquire = static function () use ( &$held ): void {
			$held = (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 );
		};
		$bumped = $stack['reconciler']->maybe_repair( 2 );
		self::assertSame( 1, $held );
		self::assertFalse( (bool) ( $bumped['skipped'] ?? true ) );
		self::assertSame( 1, $calls );
		self::assertSame( 2, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		$again = $stack['reconciler']->maybe_repair( 2 );
		self::assertTrue( (bool) ( $again['skipped'] ?? false ) );
		self::assertSame( 'revision_complete', $again['reason'] ?? '' );
		self::assertSame( 1, $calls );
	}

	/**
	 * @return array{
	 *   geo:GhanaGeographyFixture,
	 *   packs:InMemoryGeographyPackRepository,
	 *   boot:WooCommerceGeographyBootstrap,
	 *   reconciler:CountryIdentityReconciler
	 * }
	 */
	private function stack( ?\Closure $now = null ): array {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$boot  = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );

		return [
			'geo'        => $geo,
			'packs'      => $packs,
			'boot'       => $boot,
			'reconciler' => new CountryIdentityReconciler(
				$geo->locations,
				$geo->locations,
				$geo->locations,
				$packs,
				$boot,
				new GeoNamesGazetteerParser(),
				$now
			),
		];
	}

	/**
	 * @param array{geo:GhanaGeographyFixture,packs:InMemoryGeographyPackRepository,boot:WooCommerceGeographyBootstrap,reconciler:CountryIdentityReconciler} $base
	 * @return array{geo:GhanaGeographyFixture,packs:InMemoryGeographyPackRepository,boot:WooCommerceGeographyBootstrap,reconciler:CountryIdentityReconciler}
	 */
	private function stack_from( array $base, ?\Closure $now = null ): array {
		return [
			'geo'        => $base['geo'],
			'packs'      => $base['packs'],
			'boot'       => $base['boot'],
			'reconciler' => new CountryIdentityReconciler(
				$base['geo']->locations,
				$base['geo']->locations,
				$base['geo']->locations,
				$base['packs'],
				$base['boot'],
				new GeoNamesGazetteerParser(),
				$now
			),
		];
	}

	/**
	 * @param array<string, mixed> $lease
	 */
	private function store_lock( array $lease ): void {
		add_option( CountryIdentityReconciler::LOCK_OPTION_KEY, $lease, '', false );
	}

	private function woo_stub(): object {
		return (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [
						'GH' => 'Ghana',
						'US' => 'United States (US)',
						'GB' => 'United Kingdom (UK)',
						'IM' => 'Isle of Man',
					];
				}
				public function get_states( string $country ): array {
					unset( $country );

					return [];
				}
			},
		];
	}
}
