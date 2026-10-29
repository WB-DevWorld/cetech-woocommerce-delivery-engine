<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Application\Configuration\Admin\LegacyCategoryConfigurationInspector;
use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ProvenanceLabelMapper;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationSubmissionParser;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationWriteCommand;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\FieldProvenance;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Presentation\Admin\EffectiveConfigurationPreviewPage;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;
use CetechDeliveryEngine\Tests\Unit\Configuration\Admin\RecordingConfigurationAuditLogger;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use PHPUnit\Framework\TestCase;

/**
 * COR-012, decision D3: (a) inactive scopes are ignored and shown as inactive in the effective-value
 * source; (b) invalid saved collections require an explicit Replace, with the reasons shown until then.
 */
final class ScopedConfigurationSemanticsTest extends TestCase {

	private InMemoryScopedConfigurationRepository $repository;
	private EffectiveConfigurationResolver $resolver;

	protected function setUp(): void {
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->resolver   = new EffectiveConfigurationResolver( $this->repository, new EffectiveConfigurationValidator(), new PassthroughFulfilmentConstraintService() );
		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}
		$GLOBALS['cetech_de_test_wc_products'] = [
			101 => new \WC_Product( [ 'id' => 101, 'type' => 'simple', 'name' => 'Lamp' ] ),
			102 => new \WC_Product( [ 'id' => 102, 'type' => 'simple', 'name' => 'Chair' ] ),
		];
	}

	// ---- (a) inactive scopes ---------------------------------------------------------------------

	public function test_inactive_product_scope_is_ignored_and_named_in_the_source(): void {
		$this->save_global( [ 1, 2 ] );
		$this->save_product( 101, RecordStatus::Inactive, 9, CollectionFieldInstruction::add( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 3 ] ) );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, '' ) );

		$priority = $config->scalar( ConfigurationFieldKey::PRIORITY );
		self::assertSame( 5, $priority?->value, 'the inactive override does not apply' );
		self::assertSame( ConfigurationScopeType::Product, $priority?->provenance->ignored_inactive_scope );
		self::assertSame( 'Site-wide default (product setting is inactive and ignored)', ProvenanceLabelMapper::describe( $priority->provenance ) );

		$offers = $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS );
		self::assertSame( [ 1, 2 ], $offers?->members );
		self::assertSame( EffectiveFieldState::Valid, $offers?->state );
		self::assertSame( ConfigurationScopeType::Product, $offers?->provenance->ignored_inactive_scope );

		$untouched = $config->scalar( ConfigurationFieldKey::SUPPLIER_ID );
		self::assertNull( $untouched?->provenance->ignored_inactive_scope, 'fields the inactive scope did not configure carry no note' );
		self::assertSame( 'Site-wide default', ProvenanceLabelMapper::describe( $untouched->provenance ) );
	}

	public function test_active_product_scope_still_applies_and_resolve_all_lists_only_active_slices(): void {
		$this->save_global( [ 1, 2 ] );
		$this->save_product( 101, RecordStatus::Active, 9 );
		$this->save_product( 101, RecordStatus::Inactive, 1, null, FulfilmentAvailability::InternationalFulfilment->value );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, '' ) );
		self::assertSame( 9, $config->scalar( ConfigurationFieldKey::PRIORITY )?->value );
		self::assertNull( $config->scalar( ConfigurationFieldKey::PRIORITY )?->provenance->ignored_inactive_scope );

		self::assertSame( [ '' ], $this->resolver->resolveAll( 101 )->ordered_slice_keys, 'inactive slices do not create runtime slices' );
	}

	public function test_preview_source_label_keeps_the_base_and_marks_the_ignored_setting(): void {
		self::assertSame( 'Site-wide Default (inactive setting ignored)', EffectiveConfigurationPreviewPage::source_label_for( 'Site-wide default (product setting is inactive and ignored)', false ) );
		self::assertSame( 'Product Settings (inactive setting ignored)', EffectiveConfigurationPreviewPage::source_label_for( 'Product-specific (variation setting is inactive and ignored)', true ) );
		self::assertSame( 'Product-specific', EffectiveConfigurationPreviewPage::source_label_for( 'Product-specific', false ) );
	}

	public function test_provenance_note_keeps_the_original_source(): void {
		$provenance = ( new FieldProvenance( ConfigurationScopeType::Global, 'global', 7 ) )->withIgnoredInactive( ConfigurationScopeType::Variation );

		self::assertSame( ConfigurationScopeType::Global, $provenance->source_scope );
		self::assertSame( 7, $provenance->scope_row_id );
		self::assertSame( 'Site-wide default (variation setting is inactive and ignored)', ProvenanceLabelMapper::describe( $provenance ) );
	}

	// ---- (b) invalid collections require an explicit Replace --------------------------------------

	public function test_add_over_an_invalid_inherited_list_stays_invalid(): void {
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration( ConfigurationScope::global(), $this->required_global_scalars(), [
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::add( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
			] )
		);
		$this->save_product( 101, RecordStatus::Active, null, CollectionFieldInstruction::add( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 2 ] ) );

		$offers = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, '' ) )->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS );

		self::assertSame( EffectiveFieldState::Invalid, $offers?->state, 'a product Add cannot repair an invalid base' );
		self::assertContains( ConfigurationReasonCode::INVALID_COLLECTION_OPERATION, $offers?->reason_codes ?? [] );

		$this->save_product( 101, RecordStatus::Active, null, CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 2 ] ) );
		self::assertSame( EffectiveFieldState::Valid, $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, '' ) )->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->state, 'an explicit Replace repairs it' );
	}

	public function test_inherited_base_is_resolved_without_the_scope_being_edited(): void {
		$this->save_global( [ 1 ] );
		$this->save_product( 101, RecordStatus::Active, null, CollectionFieldInstruction::add( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 2 ] ) );

		$with_product = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, '' ) );
		$base         = $this->resolver->resolve_inherited_base( new EffectiveConfigurationRequest( 101, null, '' ), ConfigurationScopeType::Product );

		self::assertSame( [ 1, 2 ], $with_product->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( [ 1 ], $base->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( EffectiveFieldState::Valid, $base->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->state );
	}

	public function test_save_refuses_add_or_remove_without_a_complete_inherited_list_and_accepts_replace(): void {
		$this->repository->saveScopedConfiguration( new ScopedConfiguration( ConfigurationScope::global(), $this->required_global_scalars(), [] ) );
		$service = $this->service();

		$add = $service->save( $this->command( 101, [ ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'add', 'members' => [ '2' ] ] ] ) );
		self::assertFalse( $add->success );
		self::assertStringContainsString( 'Choose "Use only these options"', implode( ' ', $add->errors ) );
		self::assertStringContainsString( 'No default value has been set', implode( ' ', $add->errors ), 'the reason is shown' );
		self::assertNull( $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' ), 'nothing was written' );

		$remove = $service->save( $this->command( 101, [ ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'remove', 'members' => [ '2' ] ] ] ) );
		self::assertFalse( $remove->success );

		$replace = $service->save( $this->command( 101, [ ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ '2' ] ] ] ) );
		self::assertTrue( $replace->success, implode( '; ', $replace->errors ) );

		$this->save_global( [ 1 ] );
		$add_over_valid = $service->save( $this->command( 102, [ ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'add', 'members' => [ '2' ] ] ] ) );
		self::assertTrue( $add_over_valid->success, implode( '; ', $add_over_valid->errors ) );
	}

	public function test_edit_model_exposes_the_scope_status_for_the_inactive_notice(): void {
		$this->save_global( [ 1 ] );
		$this->save_product( 101, RecordStatus::Inactive, 9 );

		$model = $this->service()->load_edit_model( ConfigurationScopeType::Product, 101, '' );

		self::assertSame( 'inactive', $model->technical_details['status'] ?? null );
		$priority = null;
		foreach ( $model->fields as $field ) {
			if ( ConfigurationFieldKey::PRIORITY === $field->field_key ) {
				$priority = $field;
			}
		}
		self::assertNotNull( $priority );
		self::assertSame( 'Site-wide default (product setting is inactive and ignored)', $priority->provenance_label );
		self::assertSame( 5, $priority->effective_value );
	}

	public function test_editors_require_replace_for_invalid_lists(): void {
		$customize = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/StaffDeliveryCustomizeView.php' );
		$general   = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/ScopedConfigurationPage.php' );

		self::assertStringContainsString( "\$needs_replace = 'invalid' === \$field->effective_state;", $customize );
		self::assertStringContainsString( 'data-cetech-de-needs-replace="1"', $customize );
		self::assertStringContainsString( 'cetech-de-validation-messages', $customize, 'the customize view now shows the reasons' );
		self::assertStringContainsString( "\$needs_replace = \$field->is_collection && 'invalid' === \$field->effective_state;", $general );
		self::assertStringContainsString( 'render_inactive_scope_notice', $general );
	}

	// ---- helpers ----------------------------------------------------------------------------------

	/**
	 * @return array<string, ScalarFieldInstruction>
	 */
	private function required_global_scalars(): array {
		return [
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InStore->value ),
			ConfigurationFieldKey::FULFILMENT_CHOICE       => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_CHOICE, FulfilmentChoice::Delivery->value ),
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID    => ScalarFieldInstruction::override( ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 10 ),
			ConfigurationFieldKey::SUPPLIER_ID             => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 20 ),
			ConfigurationFieldKey::ORIGIN_ID               => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 30 ),
			ConfigurationFieldKey::PRIORITY                => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 5 ),
		];
	}

	/**
	 * @param list<int> $offers
	 */
	private function save_global( array $offers ): void {
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration( ConfigurationScope::global(), $this->required_global_scalars(), [
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, $offers ),
			] )
		);
	}

	private function save_product( int $product_id, RecordStatus $status, ?int $priority, ?CollectionFieldInstruction $offers = null, string $slice = '' ): void {
		$existing = $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, $product_id, $slice );
		$scope    = new ConfigurationScope( $existing?->scope->id, ConfigurationScopeType::Product, $product_id, $slice, null, $status, $existing?->scope->config_version ?? 1, ConfigurationSource::Native, null );
		$scalars  = [];
		if ( null !== $priority ) {
			$scalars[ ConfigurationFieldKey::PRIORITY ] = ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, $priority );
		}
		$collections = null !== $offers ? [ ConfigurationFieldKey::DELIVERY_OFFER_IDS => $offers ] : [];

		$this->repository->saveScopedConfiguration( new ScopedConfiguration( $scope, $scalars, $collections ) );
		$this->resolver->clearMemoization();
	}

	private function service(): ScopedConfigurationAdminService {
		return new ScopedConfigurationAdminService(
			$this->repository,
			$this->resolver,
			new ScopedConfigurationSubmissionParser(),
			new ProductVariationScopeGuard( new ProductTargetResolver( new Requirements() ) ),
			new EntityLabelResolver( new InMemoryDeliveryOfferRepository() ),
			new LegacyCategoryConfigurationInspector(),
			new RecordingConfigurationAuditLogger()
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function command( int $product_id, array $fields ): ScopedConfigurationWriteCommand {
		return new ScopedConfigurationWriteCommand( ConfigurationScopeType::Product, $product_id, '', null, $fields, true, 0, 'd3-' . $product_id . '-' . md5( serialize( $fields ) ), 0 );
	}
}
