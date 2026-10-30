<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogActionManifest;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTarget;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\InMemoryCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvExportService;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvMapper;
use CetechDeliveryEngine\Application\Bulk\Portability\EntityCodeResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryPickupLocationRepository;
use PHPUnit\Framework\TestCase;

/**
 * COR-021, decision D4: the catalog CSV is versioned, carries site-wide defaults and every scope
 * slice with its modes, reports what it skips, and keeps private columns out unless asked for.
 */
final class CatalogCsvRoundTripTest extends TestCase {

	private const IN_STORE = 'in_store';

	private InMemoryScopedConfigurationRepository $scopes;
	private InMemoryDeliveryOfferRepository $offers;
	private InMemoryPickupLocationRepository $pickups;
	private InMemoryCatalogTargetQuery $targets;
	private EntityCodeResolver $codes;

	protected function setUp(): void {
		$this->scopes = new InMemoryScopedConfigurationRepository();
		$this->offers = new InMemoryDeliveryOfferRepository();
		$this->offers->seed( 1, [ 'internal_code' => 'STD', 'route' => 'local_delivery', 'status' => 'active', 'public_label' => 'Standard' ] );
		$this->offers->seed( 2, [ 'internal_code' => 'EXP', 'route' => 'local_delivery', 'status' => 'active', 'public_label' => 'Express' ] );
		$this->pickups = new InMemoryPickupLocationRepository();
		$this->pickups->seed( 7, [ 'internal_code' => 'MAIN', 'status' => RecordStatus::Active->value, 'location_name' => 'Main showroom' ] );
		$this->codes   = new EntityCodeResolver( $this->offers, null, null, null, $this->pickups );
		$this->targets = new InMemoryCatalogTargetQuery();
		$this->targets->add( new CatalogTarget( CatalogTargetDefinition::TARGET_PRODUCT, 101, 'LAMP' ) );
		$this->targets->add( new CatalogTarget( CatalogTargetDefinition::TARGET_PRODUCT, 201, 'CHAIR' ) );
		$this->targets->add( new CatalogTarget( CatalogTargetDefinition::TARGET_VARIATION, 202, 'CHAIR-OAK', 201 ) );
	}

	public function test_export_carries_the_version_site_wide_defaults_and_every_slice_with_its_modes(): void {
		$this->seed_catalog();

		$csv  = $this->export( false );
		$rows = $this->rows_by_key( $csv );

		$header = explode( ',', strtok( $csv, "\n" ) );
		self::assertSame( 'de_format_version', $header[0] );
		self::assertNotContains( 'de_supplier_code', $header, 'private columns are left out by default' );
		self::assertContains( 'de_pickup_location_mode', $header );

		$root = $rows['global|'];
		self::assertSame( 'replace', $root['de_delivery_options_mode'], 'the site-wide root travels as a global row with a blank slice' );
		self::assertSame( '100', $root['de_priority_value'] );

		$global = $rows['global|' . self::IN_STORE];
		self::assertSame( CatalogCsvMapper::FORMAT_VERSION, $global['de_format_version'] );
		self::assertSame( '', $global['sku'] );
		self::assertSame( 'override', $global['de_eta_mode'] );
		self::assertSame( '3–6 business days', $global['de_eta_value'] );
		self::assertSame( 'replace', $global['de_delivery_options_mode'] );
		self::assertSame( 'STD', $global['de_delivery_option_codes'] );

		$lamp = $rows['product|101|'];
		self::assertSame( 'LAMP', $lamp['sku'] );
		self::assertSame( 'override', $lamp['de_priority_mode'] );
		self::assertSame( '0', $lamp['de_priority_value'], 'explicit zero stays zero' );
		self::assertSame( 'disable', $lamp['de_pickup_location_mode'] );
		self::assertSame( '', $lamp['de_pickup_location_code'] );
		self::assertSame( 'add', $lamp['de_delivery_options_mode'] );
		self::assertSame( 'EXP', $lamp['de_delivery_option_codes'] );
		self::assertSame( 'inherit', $lamp['de_eta_mode'] );

		$lamp_store = $rows['product|101|' . self::IN_STORE];
		self::assertSame( 'override', $lamp_store['de_eta_mode'] );
		self::assertSame( 'Next day', $lamp_store['de_eta_value'] );

		$oak = $rows['variation|202|'];
		self::assertSame( 'CHAIR', $oak['sku'] );
		self::assertSame( 'CHAIR-OAK', $oak['variation_sku'] );
		self::assertSame( 'override', $oak['de_pickup_location_mode'] );
		self::assertSame( 'MAIN', $oak['de_pickup_location_code'] );

		self::assertArrayHasKey( 'product|201|', $rows, 'a product without saved settings still gets its default-slice row' );
		self::assertSame( 'inherit', $rows['product|201|']['de_priority_mode'] );
	}

	public function test_private_columns_are_written_only_on_request(): void {
		$this->seed_catalog();

		$header = explode( ',', strtok( $this->export( true ), "\n" ) );
		self::assertContains( 'de_supplier_code', $header );
		self::assertContains( 'de_origin_mode', $header );
	}

	public function test_an_export_re_imports_to_the_same_saved_instructions(): void {
		$this->seed_catalog();
		$csv = $this->export( true );

		$parsed = ( new CatalogCsvMapper() )->parse_csv( $csv );
		self::assertTrue( $parsed['ok'], implode( ' ', $parsed['errors'] ) );
		self::assertSame( CatalogCsvMapper::FORMAT_VERSION, $parsed['format_version'] );

		$fresh   = new InMemoryScopedConfigurationRepository();
		$mutator = new CatalogScopeMutator( $fresh, new EffectiveConfigurationValidator(), null, null, $this->codes );
		foreach ( $parsed['rows'] as $row ) {
			$target_id = (int) ( $row['variation_id'] > 0 ? $row['variation_id'] : $row['product_id'] );
			$parent    = 'variation' === $row['target_type'] ? $this->targets->parent_product_id( $target_id ) : null;
			$outcome   = $mutator->process(
				(string) $row['target_type'],
				$target_id,
				$parent,
				CatalogActionManifest::from_array( [ 'field_actions' => $row['actions'] ] ),
				false,
				null,
				(string) $row['slice']
			);
			self::assertNotSame( 'failed', $outcome['outcome'], (string) $outcome['error_summary'] );
		}

		foreach ( [
			[ ConfigurationScopeType::Global, 0, '' ],
			[ ConfigurationScopeType::Global, 0, self::IN_STORE ],
			[ ConfigurationScopeType::Product, 101, '' ],
			[ ConfigurationScopeType::Product, 101, self::IN_STORE ],
			[ ConfigurationScopeType::Variation, 202, '' ],
		] as [ $type, $id, $slice ] ) {
			$original = $this->scopes->findByScopeAndSlice( $type, $id, $slice );
			$restored = $fresh->findByScopeAndSlice( $type, $id, $slice );
			self::assertNotNull( $restored, "$type->value $id '$slice' was restored" );
			self::assertSame( $this->instructions( $original ), $this->instructions( $restored ), "$type->value $id '$slice' instructions round-trip" );
		}
	}

	public function test_unknown_format_version_is_refused_and_legacy_files_are_still_read(): void {
		$mapper = new CatalogCsvMapper();

		$refused = $mapper->parse_csv( "de_format_version,sku,de_priority_mode,de_priority_value\n9,LAMP,override,5\n" );
		self::assertFalse( $refused['ok'] );
		self::assertSame( [], $refused['rows'] );
		self::assertStringContainsString( 'Unsupported catalog CSV format version "9"', $refused['errors'][0] );

		$legacy = $mapper->parse_csv( "sku,de_priority_mode,de_priority_value\nLAMP,override,5\n" );
		self::assertTrue( $legacy['ok'] );
		self::assertSame( CatalogCsvMapper::LEGACY_FORMAT_VERSION, $legacy['format_version'] );
		self::assertSame( 'product', $legacy['rows'][0]['scope'] );
	}

	public function test_malformed_rows_are_reported_with_their_line_and_skipped(): void {
		$mapper = new CatalogCsvMapper();
		$csv    = "de_format_version,sku,product_id,de_scope,de_slice,de_priority_mode,de_priority_value\n"
			. "2,LAMP,,product,,override,'-5\n"
			. "2,,12a,product,,override,5\n"
			. "2,CHAIR,,product,nowhere,override,5\n"
			. "2,,,global,,override,5\n"
			. "2,,,global,in_store,override,7\n"
			. "2,,,global,,override,x\n";

		$parsed = $mapper->parse_csv( $csv );
		self::assertFalse( $parsed['ok'] );
		self::assertCount( 6, $parsed['rows'], 'every row is kept in the report' );
		self::assertTrue( $parsed['rows'][0]['ok'] );
		self::assertSame( '-5', $parsed['rows'][0]['actions'][0]['value'], 'the export protection quote is removed again' );
		self::assertStringContainsString( 'Line 3: product_id must be a whole number', implode( "\n", $parsed['rows'][1]['errors'] ) );
		self::assertStringContainsString( 'Line 4: unknown de_slice "nowhere"', implode( "\n", $parsed['rows'][2]['errors'] ) );
		self::assertTrue( $parsed['rows'][3]['ok'], 'a global row with a blank slice addresses the site-wide root' );
		self::assertSame( '', $parsed['rows'][3]['slice'] );
		self::assertTrue( $parsed['rows'][4]['ok'] );
		self::assertSame( 'global', $parsed['rows'][4]['target_type'] );
		self::assertSame( self::IN_STORE, $parsed['rows'][4]['slice'] );
		self::assertSame( $parsed['errors'], $parsed['report'] );
	}

	public function test_unknown_codes_fail_the_row_instead_of_being_dropped(): void {
		$this->seed_global_root();
		$mutator = new CatalogScopeMutator( $this->scopes, new EffectiveConfigurationValidator(), null, null, $this->codes );

		$offers = $mutator->process( 'product', 101, null, CatalogActionManifest::from_array( [ 'field_actions' => [
			[ 'field_key' => ConfigurationFieldKey::DELIVERY_OFFER_IDS, 'action' => 'replace', 'members' => [ 'STD', 'NOPE' ] ],
		] ] ), false );
		self::assertSame( 'failed', $offers['outcome'] );
		self::assertSame( 'unresolved_reference', $offers['error_code'] );
		self::assertStringContainsString( 'NOPE', (string) $offers['error_summary'] );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' ) );

		$pickup = $mutator->process( 'product', 101, null, CatalogActionManifest::from_array( [ 'field_actions' => [
			[ 'field_key' => ConfigurationFieldKey::PICKUP_LOCATION_ID, 'action' => 'set_override', 'value' => 'GHOST' ],
		] ] ), false );
		self::assertSame( 'failed', $pickup['outcome'] );
		self::assertSame( 'unresolved_reference', $pickup['error_code'] );

		$ok = $mutator->process( 'product', 101, null, CatalogActionManifest::from_array( [ 'field_actions' => [
			[ 'field_key' => ConfigurationFieldKey::PICKUP_LOCATION_ID, 'action' => 'set_override', 'value' => 'MAIN' ],
		] ] ), false );
		self::assertNotSame( 'failed', $ok['outcome'], (string) $ok['error_summary'] );
		self::assertSame( 7, $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' )?->scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ]->value );
	}

	public function test_global_rows_write_the_site_wide_root_or_the_default_of_a_fulfilment_type(): void {
		$this->seed_global_root();
		$mutator = new CatalogScopeMutator( $this->scopes, new EffectiveConfigurationValidator(), null, null, $this->codes );
		$actions = [ 'field_actions' => [ [ 'field_key' => ConfigurationFieldKey::ESTIMATED_DELIVERY, 'action' => 'set_override', 'value' => '2–3 business days' ] ] ];

		$root = $mutator->process( 'global', 0, null, CatalogActionManifest::from_array( $actions ), false );
		self::assertNotSame( 'failed', $root['outcome'], (string) $root['error_summary'] );
		self::assertSame( '2–3 business days', $this->scopes->getGlobalConfiguration()?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value );
		self::assertSame( [ 1 ], $this->scopes->getGlobalConfiguration()?->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members, 'other root instructions are kept' );

		$written = $mutator->process( 'global', 0, null, CatalogActionManifest::from_array( $actions ), false, null, self::IN_STORE );
		self::assertNotSame( 'failed', $written['outcome'], (string) $written['error_summary'] );
		self::assertSame( '2–3 business days', $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Global, 0, self::IN_STORE )?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value );
	}

	// ---- helpers ----------------------------------------------------------------------------------

	private function seed_global_root(): void {
		$this->scopes->ensureGlobalScope();
		$this->scopes->saveScopedConfiguration( new ScopedConfiguration(
			ConfigurationScope::global(),
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InStore->value ),
				ConfigurationFieldKey::FULFILMENT_CHOICE       => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_CHOICE, FulfilmentChoice::Delivery->value ),
				ConfigurationFieldKey::PRIORITY                => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 100 ),
			],
			[ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ) ]
		) );
	}

	private function seed_catalog(): void {
		$this->seed_global_root();
		$this->scopes->saveScopedConfiguration( new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Global, 0, self::IN_STORE, null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InStore->value ),
				ConfigurationFieldKey::FULFILMENT_CHOICE       => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_CHOICE, FulfilmentChoice::Delivery->value ),
				ConfigurationFieldKey::ESTIMATED_DELIVERY      => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, '3–6 business days' ),
			],
			[ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ) ]
		) );
		$this->scopes->saveScopedConfiguration( new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Product, 101, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[
				ConfigurationFieldKey::PRIORITY           => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 0 ),
				ConfigurationFieldKey::PICKUP_LOCATION_ID => ScalarFieldInstruction::disable( ConfigurationFieldKey::PICKUP_LOCATION_ID ),
			],
			[ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::add( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 2 ] ) ]
		) );
		$this->scopes->saveScopedConfiguration( new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Product, 101, self::IN_STORE, null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[ ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, 'Next day' ) ],
			[]
		) );
		$this->scopes->saveScopedConfiguration( new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Variation, 202, '', 201, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[ ConfigurationFieldKey::PICKUP_LOCATION_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::PICKUP_LOCATION_ID, 7 ) ],
			[]
		) );
	}

	private function export( bool $include_private ): string {
		$service = new CatalogCsvExportService(
			$this->targets,
			$this->scopes,
			new EffectiveConfigurationResolver( $this->scopes, new EffectiveConfigurationValidator(), new PassthroughFulfilmentConstraintService() ),
			$this->codes
		);
		$handle = fopen( 'php://temp', 'r+' );
		self::assertNotFalse( $handle );
		$service->write_stream( $handle, CatalogTargetDefinition::from_array( [ 'scope' => BulkTargetScope::EntireCatalog->value, 'entire_catalog_confirmed' => true ] ), $include_private );
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * @return array<string, array<string, string>> keyed "scope|id|slice" (global rows: "global|slice").
	 */
	private function rows_by_key( string $csv ): array {
		$lines  = array_values( array_filter( explode( "\n", $csv ), static fn ( string $line ): bool => '' !== trim( $line ) ) );
		$header = str_getcsv( array_shift( $lines ) );
		$rows   = [];
		foreach ( $lines as $line ) {
			$cells = str_getcsv( $line );
			$row   = [];
			foreach ( $header as $i => $name ) {
				$row[ (string) $name ] = (string) ( $cells[ $i ] ?? '' );
			}
			$key = 'global' === $row['de_scope']
				? 'global|' . $row['de_slice']
				: $row['de_scope'] . '|' . ( 'variation' === $row['de_scope'] ? $row['variation_id'] : $row['product_id'] ) . '|' . $row['de_slice'];
			$rows[ $key ] = $row;
		}

		return $rows;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function instructions( ?ScopedConfiguration $configuration ): array {
		self::assertNotNull( $configuration );
		$out = [ 'scalars' => [], 'collections' => [] ];
		foreach ( $configuration->scalars as $key => $instruction ) {
			$out['scalars'][ $key ] = [ $instruction->mode->value, $instruction->value ];
		}
		foreach ( $configuration->collections as $key => $instruction ) {
			$out['collections'][ $key ] = [ $instruction->mode->value, $instruction->members ];
		}
		ksort( $out['scalars'] );
		ksort( $out['collections'] );

		return $out;
	}
}
