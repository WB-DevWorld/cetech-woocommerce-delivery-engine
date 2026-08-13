<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\ProvenanceLabelMapper;
use CetechDeliveryEngine\Application\Configuration\Admin\ReasonCodeLabelMapper;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationNotices;
use CetechDeliveryEngine\Domain\Configuration\CollectionMutationStep;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use PHPUnit\Framework\TestCase;

final class ScopedConfigurationAdminUxMappingTest extends TestCase {

	public function test_provenance_labels(): void {
		self::assertSame( 'Site-wide default', ProvenanceLabelMapper::map( 'global' ) );
		self::assertSame( 'Product-specific', ProvenanceLabelMapper::map( 'product' ) );
		self::assertSame( 'Variation-specific', ProvenanceLabelMapper::map( 'variation' ) );
		self::assertSame( 'Turned off for this item', ProvenanceLabelMapper::map( 'explicit_disable' ) );
		self::assertSame( 'Built-in default', ProvenanceLabelMapper::map( 'system_default' ) );
		self::assertSame( 'Currently using: Product-specific', ProvenanceLabelMapper::currently_using( 'product' ) );
	}

	public function test_collection_mutation_summaries_include_replace_empty(): void {
		$lines = ProvenanceLabelMapper::mutation_summaries(
			[
				new CollectionMutationStep( ConfigurationScopeType::Global, CollectionConfigurationMode::Replace, [ 1, 2 ] ),
				new CollectionMutationStep( ConfigurationScopeType::Product, CollectionConfigurationMode::Replace, [] ),
			],
			static fn ( int $id ): string => 'Offer-' . $id
		);

		self::assertSame( 'Site-wide default: Use only Offer-1, Offer-2', $lines[0] );
		self::assertSame( 'Product-specific: Use only no delivery options for this setup', $lines[1] );
	}

	public function test_reason_and_state_mapping(): void {
		self::assertSame( 'Ready', ReasonCodeLabelMapper::state_label( EffectiveFieldState::Valid ) );
		self::assertSame( 'Needs configuration', ReasonCodeLabelMapper::state_label( EffectiveFieldState::Unresolved ) );
		self::assertSame( 'Disabled', ReasonCodeLabelMapper::state_label( EffectiveFieldState::Disabled ) );
		self::assertSame( 'Configuration problem', ReasonCodeLabelMapper::state_label( EffectiveFieldState::Invalid ) );
		self::assertSame( 'success', ReasonCodeLabelMapper::state_tone( EffectiveFieldState::Valid ) );
		self::assertSame( 'warning', ReasonCodeLabelMapper::state_tone( EffectiveFieldState::Unresolved ) );
		self::assertSame( 'error', ReasonCodeLabelMapper::state_tone( EffectiveFieldState::Invalid ) );
		self::assertSame( 'neutral', ReasonCodeLabelMapper::state_tone( EffectiveFieldState::Disabled ) );
		self::assertStringContainsString(
			'No default value has been set',
			ReasonCodeLabelMapper::explain( ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE )
		);
		self::assertStringNotContainsString( 'UNRESOLVED_GLOBAL_VALUE', ReasonCodeLabelMapper::explain( ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE ) );
	}

	public function test_transitional_and_category_notices(): void {
		self::assertStringContainsString( 'Legacy Delivery Rules', ScopedConfigurationNotices::TRANSITIONAL_MESSAGE );
		self::assertStringContainsString( 'New Delivery Settings System', ScopedConfigurationNotices::TRANSITIONAL_MESSAGE );
		self::assertStringNotContainsString( 'pre-cutover', ScopedConfigurationNotices::TRANSITIONAL_MESSAGE );
		self::assertStringContainsString( 'does not check the customer', strtolower( ScopedConfigurationNotices::PREVIEW_LIMITATION_MESSAGE ) );
		self::assertStringContainsString( 'not a shipping price', strtolower( ScopedConfigurationNotices::PREVIEW_LIMITATION_TITLE ) );
		self::assertStringContainsString( 'legacy category rule', strtolower( ScopedConfigurationNotices::CATEGORY_WARNING_MESSAGE ) );
		self::assertStringNotContainsString( 'Stage 5', ScopedConfigurationNotices::CATEGORY_WARNING_MESSAGE );
	}

	public function test_authorization_rejects_get_writes_and_bad_capability_nonce(): void {
		$auth = new ScopedConfigurationAuthorization(
			static fn ( string $cap ): bool => false,
			static fn ( string $action ): bool => false
		);

		$errors = $auth->verify_write( 'manage_delivery_settings', 'nonce', false );
		self::assertStringContainsString( 'GET', $errors[0] );

		$errors = $auth->verify_write( 'manage_delivery_settings', 'nonce', true );
		self::assertCount( 2, $errors );

		$ok = new ScopedConfigurationAuthorization(
			static fn ( string $cap ): bool => true,
			static fn ( string $action ): bool => true
		);
		self::assertSame( [], $ok->verify_write( 'manage_delivery_settings', 'nonce', true ) );
		self::assertSame( [], $ok->verify_preview_read( 'manage_product_delivery_rules' ) );
	}
}
