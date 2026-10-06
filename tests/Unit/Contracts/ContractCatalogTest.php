<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\ContractCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContractCatalogTest extends TestCase {

	public function test_explicit_versions_and_classifications_do_not_promote_internal_contracts(): void {
		$internal = self::declaration();
		$stable = self::declaration( 'merchant.quote', 1, 'stable' );
		$experimental = self::declaration( 'merchant.quote', 2, 'experimental' );
		$catalog = new ContractCatalog( [ $internal, $stable, $experimental ] );

		self::assertSame( [ $internal, $stable, $experimental ], $catalog->entries() );
		self::assertSame( $internal, $catalog->entry( 'internal.errors', 1 ) );
		$catalog->assert_export_classified( 'internal.errors', 1 );
		$catalog->assert_public_export( 'merchant.quote', 1 );
		$catalog->assert_public_export( 'merchant.quote', 2 );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Internal contract cannot be publicly exported.' );
		$catalog->assert_public_export( 'internal.errors', 1 );
	}

	public function test_empty_catalog_does_not_infer_classification_from_existing_namespace(): void {
		$catalog = new ContractCatalog();
		self::assertSame( [], $catalog->entries() );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Contract is not classified.' );
		$catalog->assert_export_classified( 'cetech-delivery-engine.v1', 1 );
	}

	#[DataProvider( 'missing_fields' )]
	public function test_every_declaration_field_is_required( string $field ): void {
		$entry = self::declaration();
		unset( $entry[ $field ] );
		$this->expectException( \InvalidArgumentException::class );
		new ContractCatalog( [ $entry ] );
	}

	public static function missing_fields(): array {
		return array_map( static fn( string $field ): array => [ $field ], array_keys( self::declaration() ) );
	}

	#[DataProvider( 'invalid_fields' )]
	public function test_invalid_metadata_is_refused_with_safe_exception( string $field, mixed $value ): void {
		$entry = self::declaration();
		$entry[ $field ] = $value;
		try {
			new ContractCatalog( [ $entry ] );
			self::fail( 'Invalid declaration was accepted.' );
		} catch ( \InvalidArgumentException $error ) {
			self::assertStringNotContainsString( 'private-marker', $error->getMessage() );
			self::assertStringNotContainsString( 'SELECT', $error->getMessage() );
			self::assertNull( $error->getPrevious() );
		}
	}

	public static function invalid_fields(): array {
		return [
			[ 'classification', null ],
			[ 'classification', 'public' ],
			[ 'classification', 'SELECT private-marker FROM secrets' ],
			[ 'version', '1' ],
			[ 'version', 0 ],
			[ 'surface', '' ],
			[ 'surface', str_repeat( 'a', 129 ) ],
			[ 'input_schema', [ 'private-marker' => 'SELECT secrets' ] ],
			[ 'authorization', '' ],
			[ 'compatibility', "private-marker\nSELECT secrets" ],
			[ 'cost_budget_owner', null ],
			[ 'callers', [] ],
			[ 'callers', [ 'shopper', 'shopper' ] ],
			[ 'callers', [ 'role' => 'admin' ] ],
			[ 'callers', [ 'SELECT private-marker' ] ],
		];
	}

	public function test_unknown_fields_and_non_list_catalog_are_rejected(): void {
		$entry = self::declaration();
		$entry['private-marker'] = 'SELECT secrets';
		foreach ( [ [ $entry ], [ 'invented_export' => self::declaration() ], [ null ] ] as $entries ) {
			try {
				new ContractCatalog( $entries );
				self::fail( 'Unknown catalog structure was accepted.' );
			} catch ( \InvalidArgumentException $error ) {
				self::assertStringNotContainsString( 'private-marker', $error->getMessage() );
			}
		}
	}

	public function test_duplicate_surface_version_is_rejected_even_when_metadata_differs(): void {
		$entry = self::declaration();
		$second = $entry;
		$second['classification'] = 'stable';
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Duplicate contract version.' );
		new ContractCatalog( [ $entry, $second ] );
	}

	public function test_valid_deprecation_names_replacement_window_and_reviewed_consumer_fixture(): void {
		$old = self::declaration( 'merchant.quote', 1, 'stable' );
		$old['deprecation'] = self::deprecation();
		$new = self::declaration( 'merchant.quote', 2, 'stable' );
		$catalog = new ContractCatalog( [ $new, $old ] );
		self::assertSame( self::deprecation(), $catalog->entry( 'merchant.quote', 1 )['deprecation'] );
		self::assertSame( 'stable', $catalog->entry( 'merchant.quote', 2 )['classification'] );
	}

	#[DataProvider( 'invalid_deprecations' )]
	public function test_invalid_deprecation_declarations_are_refused( mixed $deprecation ): void {
		$old = self::declaration( 'merchant.quote', 1, 'stable' );
		$old['deprecation'] = $deprecation;
		$this->expectException( \InvalidArgumentException::class );
		new ContractCatalog( [ $old, self::declaration( 'merchant.quote', 2, 'stable' ) ] );
	}

	public static function invalid_deprecations(): array {
		$valid = self::deprecation();
		$without_window = $valid;
		unset( $without_window['compatibility_window'] );
		return [
			[ true ], [ [] ], [ $without_window ],
			[ array_replace( $valid, [ 'replacement_surface' => 'missing.surface' ] ) ],
			[ array_replace( $valid, [ 'replacement_version' => 1 ] ) ],
			[ array_replace( $valid, [ 'replacement_version' => '2' ] ) ],
			[ array_replace( $valid, [ 'compatibility_window' => '' ] ) ],
			[ array_replace( $valid, [ 'reviewed_consumers' => [] ] ) ],
			[ array_replace( $valid, [ 'reviewed_consumers' => [ 'fixture', 'fixture' ] ] ) ],
			[ $valid + [ 'unreviewed_private_field' => 'private-marker' ] ],
		];
	}

	public function test_stable_contract_cannot_be_deprecated_to_experimental_replacement(): void {
		$old = self::declaration( 'merchant.quote', 1, 'stable' );
		$old['deprecation'] = self::deprecation();
		$this->expectException( \InvalidArgumentException::class );
		new ContractCatalog( [ $old, self::declaration( 'merchant.quote', 2, 'experimental' ) ] );
	}

	public function test_backwards_replacement_and_cross_surface_cycle_are_refused(): void {
		$old = self::declaration( 'merchant.quote', 2, 'stable' );
		$old['deprecation'] = array_replace( self::deprecation(), [ 'replacement_version' => 1 ] );
		$first = self::declaration( 'first.contract', 1, 'stable' );
		$second = self::declaration( 'second.contract', 1, 'stable' );
		$first['deprecation'] = array_replace( self::deprecation(), [ 'replacement_surface' => 'second.contract', 'replacement_version' => 1 ] );
		$second['deprecation'] = array_replace( self::deprecation(), [ 'replacement_surface' => 'first.contract', 'replacement_version' => 1 ] );
		foreach ( [ [ self::declaration( 'merchant.quote', 1, 'stable' ), $old ], [ $first, $second ] ] as $entries ) {
			try {
				new ContractCatalog( $entries );
				self::fail( 'Invalid replacement chain was accepted.' );
			} catch ( \InvalidArgumentException $error ) {
				self::assertSame( 'Invalid contract deprecation replacement.', $error->getMessage() );
			}
		}
	}

	public function test_catalog_metadata_is_immutable_across_caller_arrays(): void {
		$entry = self::declaration();
		$catalog = new ContractCatalog( [ $entry ] );
		$entry['callers'][0] = 'another-caller';
		$copy = $catalog->entries();
		$copy[0]['classification'] = 'stable';
		$copy[0]['callers'][] = 'another-caller';
		self::assertSame( self::declaration(), $catalog->entry( 'internal.errors', 1 ) );
	}

	public function test_catalog_detaches_external_scalar_and_nested_references(): void {
		$classification = 'stable';
		$caller = 'internal-service';
		$window = 'owner-reviewed-release-window';
		$consumer = 'fixture.consumer.v1-to-v2';
		$old = self::declaration( 'merchant.quote', 1, 'stable' );
		$old['classification'] =& $classification;
		$old['callers'][0] =& $caller;
		$old['deprecation'] = self::deprecation();
		$old['deprecation']['compatibility_window'] =& $window;
		$old['deprecation']['reviewed_consumers'][0] =& $consumer;
		$catalog = new ContractCatalog( [ $old, self::declaration( 'merchant.quote', 2, 'stable' ) ] );
		$classification = 'internal';
		$caller = 'unapproved-caller';
		$window = 'unreviewed-window';
		$consumer = 'unreviewed-consumer';
		$expected = self::declaration( 'merchant.quote', 1, 'stable' );
		$expected['deprecation'] = self::deprecation();
		self::assertSame( $expected, $catalog->entry( 'merchant.quote', 1 ) );
	}

	private static function declaration( string $surface = 'internal.errors', int $version = 1, string $classification = 'internal' ): array {
		return [
			'surface' => $surface, 'version' => $version, 'classification' => $classification,
			'owner' => 'contract-service', 'callers' => [ 'internal-service' ],
			'input_schema' => 'schema.empty.v1', 'output_schema' => 'schema.error.v1',
			'authorization' => 'caller-owned-authority', 'projection' => 'internal-only',
			'compatibility' => 'explicit-version-policy', 'deprecation' => null,
			'cost_budget_owner' => 'contract-service',
		];
	}

	private static function deprecation(): array {
		return [
			'replacement_surface' => 'merchant.quote', 'replacement_version' => 2,
			'compatibility_window' => 'owner-reviewed-release-window',
			'reviewed_consumers' => [ 'fixture.consumer.v1-to-v2' ],
		];
	}
}
