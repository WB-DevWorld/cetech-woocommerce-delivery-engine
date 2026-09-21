<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Geography\CountryIdentityReconciler;
use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use PHPUnit\Framework\TestCase;

/**
 * Issue #35 lifecycle: revision gate, lock, clean-root short-circuit, mid-import safety.
 */
final class CountryIdentityRepairLifecycleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_wc']      = $this->woo_stub();
		$GLOBALS['cetech_de_test_options'] = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cetech_de_test_wc'], $GLOBALS['cetech_de_test_options'] );
		parent::tearDown();
	}

	public function test_a_revision_one_runs_when_option_absent(): void {
		$stack    = $this->stack();
		$first    = $stack['reconciler']->maybe_repair();
		self::assertFalse( (bool) ( $first['skipped'] ?? true ) );
		self::assertSame( CountryIdentityReconciler::REPAIR_REVISION, get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		self::assertSame( 'geo-country-identity-v1', CountryIdentityReconciler::REPAIR_TOKEN );
	}

	public function test_b_revision_one_does_not_run_twice(): void {
		$stack = $this->stack();
		$calls = 0;
		$stack['reconciler']->before_repair_all = static function () use ( &$calls ): void {
			++$calls;
		};
		$stack['reconciler']->maybe_repair();
		$second = $stack['reconciler']->maybe_repair();
		self::assertTrue( (bool) ( $second['skipped'] ?? false ) );
		self::assertSame( 'revision_complete', $second['reason'] ?? '' );
		self::assertSame( 1, $calls );
	}

	public function test_c_plugin_version_change_does_not_rerun_revision_one(): void {
		$stack = $this->stack();
		$stack['reconciler']->maybe_repair();
		$calls = 0;
		$stack['reconciler']->before_repair_all = static function () use ( &$calls ): void {
			++$calls;
		};
		update_option( 'cetech_de_fake_plugin_version', '1.0.0-rc.13' );
		$again = $stack['reconciler']->maybe_repair();
		self::assertTrue( (bool) ( $again['skipped'] ?? false ) );
		self::assertSame( 0, $calls );
		self::assertSame( 1, get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
	}

	public function test_d_revision_bump_one_to_two_permits_one_future_run(): void {
		$stack = $this->stack();
		$stack['reconciler']->maybe_repair( 1 );
		$calls = 0;
		$stack['reconciler']->before_repair_all = static function () use ( &$calls ): void {
			++$calls;
		};
		$bumped = $stack['reconciler']->maybe_repair( 2 );
		self::assertFalse( (bool) ( $bumped['skipped'] ?? true ) );
		self::assertSame( 1, $calls );
		self::assertSame( 2, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		$again = $stack['reconciler']->maybe_repair( 2 );
		self::assertTrue( (bool) ( $again['skipped'] ?? false ) );
		self::assertSame( 1, $calls );
	}

	public function test_e_malformed_stored_revision_is_handled_safely(): void {
		foreach ( [ 'not-a-revision', '1.0.0-dev.geo-country.1', [], null, true ] as $raw ) {
			$GLOBALS['cetech_de_test_options'] = [];
			update_option( CountryIdentityReconciler::OPTION_KEY, $raw );
			$stack = $this->stack();
			$result = $stack['reconciler']->maybe_repair();
			self::assertFalse( (bool) ( $result['skipped'] ?? true ), is_string( $raw ) ? $raw : gettype( $raw ) );
			self::assertSame( 1, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		}
	}

	public function test_f_two_concurrent_kickoff_attempts_do_not_both_execute_repair_all(): void {
		$a = $this->stack();
		$b = $this->stack_from( $a );
		$nested = null;
		$a['reconciler']->before_repair_all = static function () use ( $b, &$nested ): void {
			$nested = $b['reconciler']->maybe_repair();
		};
		$first = $a['reconciler']->maybe_repair();
		self::assertFalse( (bool) ( $first['skipped'] ?? true ) );
		self::assertIsArray( $nested );
		self::assertTrue( (bool) ( $nested['skipped'] ?? false ) );
		self::assertSame( 'locked', $nested['reason'] ?? '' );
	}

	public function test_g_active_lock_blocks_duplicate_repair(): void {
		$now = time();
		add_option(
			CountryIdentityReconciler::LOCK_OPTION_KEY,
			[
				'owner'       => 'other',
				'expires_at'  => $now + CountryIdentityReconciler::LOCK_TTL_SECONDS,
				'acquired_at' => $now,
				'revision'    => CountryIdentityReconciler::REPAIR_REVISION,
			],
			'',
			false
		);
		$stack = $this->stack();
		$calls = 0;
		$stack['reconciler']->before_repair_all = static function () use ( &$calls ): void {
			++$calls;
		};
		$result = $stack['reconciler']->maybe_repair();
		self::assertTrue( (bool) ( $result['skipped'] ?? false ) );
		self::assertSame( 'locked', $result['reason'] ?? '' );
		self::assertSame( 0, $calls );
		self::assertSame( 0, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
	}

	public function test_h_stale_lock_expires_and_repair_can_resume(): void {
		$now = 1_700_000_000;
		add_option(
			CountryIdentityReconciler::LOCK_OPTION_KEY,
			[
				'owner'       => 'stale',
				'expires_at'  => $now - 1,
				'acquired_at' => $now - CountryIdentityReconciler::LOCK_TTL_SECONDS - 1,
				'revision'    => CountryIdentityReconciler::REPAIR_REVISION,
			],
			'',
			false
		);
		$stack = $this->stack( static fn (): int => $now );
		$result = $stack['reconciler']->maybe_repair();
		self::assertFalse( (bool) ( $result['skipped'] ?? true ) );
		self::assertSame( 1, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		self::assertFalse( array_key_exists( CountryIdentityReconciler::LOCK_OPTION_KEY, $GLOBALS['cetech_de_test_options'] ?? [] ) );
	}

	public function test_i_and_j_thrown_repair_does_not_mark_revision_and_releases_lock(): void {
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

	public function test_k_clean_country_root_performs_no_source_file_scan(): void {
		$stack = $this->corrupt_stack( false );
		self::assertSame( 0, $stack['reconciler']->source_scan_count );
		$result = $stack['reconciler']->repair_country_code( 'GH' );
		self::assertFalse( (bool) ( $result['changed'] ?? true ) );
		self::assertSame( 'clean', $result['reason'] ?? '' );
		self::assertSame( 0, $stack['reconciler']->source_scan_count );
		self::assertFalse( (bool) ( $result['source_scanned'] ?? true ) );
		if ( is_string( $stack['file'] ) ) {
			unlink( $stack['file'] );
		}
	}

	public function test_l_through_r_corrupted_ghana_repairs_identity_mappings_aliases_and_is_idempotent(): void {
		$stack  = $this->corrupt_stack( true );
		$ghana  = $stack['geo']->ghana;
		$result = $stack['reconciler']->repair_country_code( 'GH' );
		$after  = $stack['geo']->locations->find_by_id( $ghana->id );
		self::assertTrue( (bool) ( $result['changed'] ?? false ) );
		self::assertSame( 1, $stack['reconciler']->source_scan_count );
		self::assertTrue( (bool) ( $result['source_scanned'] ?? false ) );
		self::assertSame( 'Ghana', $after?->canonical_name );
		self::assertSame( 'ghana', $after?->normalized_name );
		self::assertSame( GeographyNameNormalizer::fold_ascii( 'Ghana' ), $after?->ascii_name );
		self::assertSame( 8.1, $after?->latitude );
		self::assertSame( -1.2, $after?->longitude );
		self::assertSame( $ghana->id, $after?->id );
		self::assertSame( $ghana->location_key, $after?->location_key );
		self::assertSame( 'GH', $after?->country_code );
		self::assertSame( $ghana->generation, $after?->generation );
		self::assertNull( $after?->parent_location_id );
		self::assertSame( $ghana->id, $stack['geo']->locations->find_location_id( GeographyProvider::GeoNames, '2300660' ) );
		self::assertNull( $stack['geo']->locations->find_location_id( GeographyProvider::GeoNames, '2302058' ) );
		self::assertContains( 'Ghana', $stack['geo']->locations->list_for_location( $ghana->id ) );
		self::assertContains( 'Gaana', $stack['geo']->locations->list_for_location( $ghana->id ) );
		self::assertNotContains( 'Dagomba', $stack['geo']->locations->list_for_location( $ghana->id ) );
		$again = $stack['reconciler']->repair_country_code( 'GH' );
		self::assertFalse( (bool) ( $again['changed'] ?? true ) );
		self::assertSame( 1, $stack['reconciler']->source_scan_count );
		if ( is_string( $stack['file'] ) ) {
			unlink( $stack['file'] );
		}
	}

	public function test_s_staged_generation_mappings_and_aliases_survive_kickoff_repair(): void {
		$stack = $this->corrupt_stack( true );
		$id    = $stack['geo']->ghana->id;
		$token = 'geonames:GH:next';
		$stack['geo']->locations->upsert( $id, GeographyProvider::GeoNames, '99999', 1, 'next', '', 'A', 'PCLI', [ 'ascii_name' => 'Staged Ghana' ], $token );
		$stack['geo']->locations->add_alias( $id, 'Staged Ghana', 'staged ghana', '', 'alternate', false, $token );
		$pack = $stack['packs']->save(
			[
				'id'               => $stack['pack']->id,
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'status'           => GeographyPackStatus::Importing->value,
				'import_cursor'    => '4242',
				'source_reference' => $stack['file'],
				'progress'         => [
					'active_generation' => 3,
					'target_generation' => 4,
					'target_token'      => $token,
				],
			]
		);
		$stack['reconciler']->maybe_repair();
		$after_pack = $stack['packs']->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Importing, $after_pack?->status );
		self::assertSame( '4242', $after_pack?->import_cursor );
		self::assertSame( 3, $after_pack?->active_generation() );
		self::assertSame( 4, $after_pack?->target_generation() );
		$staged = $stack['geo']->locations->list_mappings_for_location( $id, $token );
		self::assertCount( 1, $staged );
		self::assertSame( '99999', $staged[0]['external_id'] ?? '' );
		self::assertSame( $id, $stack['geo']->locations->find_location_id( GeographyProvider::GeoNames, '99999', $token ) );
		$alias_prop = new \ReflectionProperty( $stack['geo']->locations, 'aliases' );
		$alias_prop->setAccessible( true );
		$alias_rows = $alias_prop->getValue( $stack['geo']->locations );
		$staged_aliases = [];
		foreach ( $alias_rows[ $id ] ?? [] as $row ) {
			if ( $token === ( $row['generation_token'] ?? '' ) ) {
				$staged_aliases[] = $row['alias'];
			}
		}
		self::assertContains( 'Staged Ghana', $staged_aliases );
		$live = $stack['geo']->locations->find_by_id( $id );
		self::assertSame( 'Ghana', $live?->canonical_name );
		if ( is_string( $stack['file'] ) ) {
			unlink( $stack['file'] );
		}
	}

	public function test_t_importer_promotion_still_calls_repair_country_code_after_revision_complete(): void {
		$stack = $this->corrupt_stack( true );
		$stack['reconciler']->maybe_repair();
		self::assertSame( 1, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		$ghana = $stack['geo']->locations->find_by_id( $stack['geo']->ghana->id );
		self::assertSame( 'Ghana', $ghana?->canonical_name );
		$stack['geo']->locations->save(
			new CanonicalLocation(
				$stack['geo']->ghana->id,
				$stack['geo']->ghana->location_key,
				'GH',
				null,
				GeographyLocationType::Country,
				null,
				'Dagomba',
				'ghana',
				'dagomba',
				9.5,
				-0.25,
				RecordStatus::Active,
				$stack['geo']->ghana->ancestry_path,
				$stack['geo']->ghana->generation
			)
		);
		$stack['geo']->locations->upsert( $stack['geo']->ghana->id, GeographyProvider::GeoNames, '2302058', 1, '2019-09-01', '06', 'A', 'PCLH', [ 'ascii_name' => 'Dagomba' ] );
		$importer = new GeoNamesPackImporter(
			$stack['geo']->locations,
			$stack['geo']->locations,
			$stack['geo']->locations,
			$stack['packs'],
			$stack['boot'],
			new GeoNamesGazetteerParser(),
			$stack['reconciler']
		);
		$file = $stack['file'];
		self::assertIsString( $file );
		$this->import_until_ready( $importer, $stack['packs'], $file, 'GH' );
		$after = $stack['geo']->locations->find_country( 'GH' );
		self::assertSame( 'Ghana', $after?->canonical_name );
		self::assertSame( 'ghana', $after?->normalized_name );
		self::assertNull( $stack['geo']->locations->find_location_id( GeographyProvider::GeoNames, '2302058' ) );
		self::assertSame( 1, (int) get_option( CountryIdentityReconciler::OPTION_KEY, 0 ) );
		unlink( $file );
	}

	public function test_u_generic_pcl_territory_keeps_woo_root(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$boot  = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );
		$boot->bootstrap_country( 'IM' );
		$importer = new GeoNamesPackImporter( $geo->locations, $geo->locations, $geo->locations, $packs, $boot, new GeoNamesGazetteerParser() );
		$file     = $this->gazetteer_file(
			[
				$this->row( '3042237', 'Isle of Man', 'Isle of Man', 'A', 'PCL', '', '', '', 'IM' ),
				$this->row( '3042232', 'Douglas', 'Douglas', 'P', 'PPLC', '9782170', '', '', 'IM' ),
			]
		);
		$this->import_until_ready( $importer, $packs, $file, 'IM' );
		$im = $geo->locations->find_country( 'IM' );
		self::assertSame( 'Isle of Man', $im?->canonical_name );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '3042237' ) );
		self::assertNotNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '3042232' ) );
		unlink( $file );
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
	private function stack_from( array $base ): array {
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
				new GeoNamesGazetteerParser()
			),
		];
	}

	/**
	 * @return array{
	 *   geo:GhanaGeographyFixture,
	 *   packs:InMemoryGeographyPackRepository,
	 *   boot:WooCommerceGeographyBootstrap,
	 *   reconciler:CountryIdentityReconciler,
	 *   pack:\CetechDeliveryEngine\Domain\Geography\GeographyPack,
	 *   file:?string
	 * }
	 */
	private function corrupt_stack( bool $corrupt ): array {
		$stack = $this->stack();
		$ghana = $stack['geo']->ghana;
		$file  = $this->gazetteer_file(
			[
				$this->row( '2300660', 'Republic of Ghana', 'Republic of Ghana', 'A', 'PCLI', '00', '', 'Ghana,Gaana', 'GH', '8.1', '-1.2' ),
				$this->row( '2302058', 'Dagomba', 'Dagomba', 'A', 'PCLH', '06', '2302058', 'Dagomba', 'GH', '9.5', '-0.25' ),
			]
		);
		$pack = $stack['packs']->save(
			[
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'status'           => GeographyPackStatus::Ready->value,
				'source_reference' => $file,
				'progress'         => [
					'active_generation' => 3,
					'target_generation' => 3,
				],
			]
		);
		$stack['geo']->locations->upsert( $ghana->id, GeographyProvider::WooCommerce, 'GH', null, 'woocommerce', '', 'A', 'PCLI' );
		$stack['geo']->locations->upsert( $ghana->id, GeographyProvider::GeoNames, '2300660', $pack->id, '2024-09-05', '00', 'A', 'PCLI', [ 'ascii_name' => 'Republic of Ghana' ] );
		$stack['geo']->locations->add_alias( $ghana->id, 'Ghana', 'ghana' );
		$stack['geo']->locations->add_alias( $ghana->id, 'Gaana', 'gaana' );
		if ( $corrupt ) {
			$stack['geo']->locations->save(
				new CanonicalLocation(
					$ghana->id,
					$ghana->location_key,
					'GH',
					null,
					GeographyLocationType::Country,
					null,
					'Dagomba',
					'ghana',
					'dagomba',
					9.5,
					-0.25,
					RecordStatus::Active,
					$ghana->ancestry_path,
					$ghana->generation
				)
			);
			$stack['geo']->locations->upsert( $ghana->id, GeographyProvider::GeoNames, '2302058', $pack->id, '2019-09-01', '06', 'A', 'PCLH', [ 'ascii_name' => 'Dagomba' ] );
			$stack['geo']->locations->add_alias( $ghana->id, 'Dagomba', 'dagomba' );
		}

		$stack['pack'] = $pack;
		$stack['file'] = $file;

		return $stack;
	}

	private function import_until_ready( GeoNamesPackImporter $importer, InMemoryGeographyPackRepository $packs, string $file, string $country ): void {
		$pack   = $importer->begin_dataset( $importer->ensure_pack( $country, $file ), $file, hash_file( 'sha256', $file ) ?: 'sum', '2026.identity' );
		$result = [ 'status' => '' ];
		$guard  = 0;
		while ( GeographyPackStatus::Ready->value !== ( $result['status'] ?? '' ) && $guard < 50 ) {
			$pack = $packs->find_by_id( $pack->id );
			self::assertNotNull( $pack );
			$result = $importer->import_batch( $pack, $file, 20 );
			++$guard;
		}
		self::assertSame( GeographyPackStatus::Ready->value, $result['status'] );
	}

	/**
	 * @param list<string> $lines
	 */
	private function gazetteer_file( array $lines ): string {
		$file = tempnam( sys_get_temp_dir(), 'geolc' );
		self::assertIsString( $file );
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );

		return $file;
	}

	private function row(
		string $id,
		string $name,
		string $ascii,
		string $class,
		string $code,
		string $admin1,
		string $admin2 = '',
		string $alts = '',
		string $country = 'GH',
		string $lat = '5.55',
		string $lon = '-0.2'
	): string {
		$parts     = array_fill( 0, 19, '' );
		$parts[0]  = $id;
		$parts[1]  = $name;
		$parts[2]  = $ascii;
		$parts[3]  = $alts;
		$parts[4]  = $lat;
		$parts[5]  = $lon;
		$parts[6]  = $class;
		$parts[7]  = $code;
		$parts[8]  = $country;
		$parts[10] = $admin1;
		$parts[11] = $admin2;
		$parts[18] = '2024-01-01';

		return implode( "\t", $parts );
	}

	private function woo_stub(): object {
		return (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [
						'GH' => 'Ghana',
						'PR' => 'Puerto Rico',
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
