<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\FieldEditViewModel;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationEditViewModel;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Presentation\Admin\StaffDeliveryCustomizeView;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class StaffDeliveryCustomizeRefinementTest extends TestCase {

	protected function setUp(): void {
		FulfilmentProfileRegistry::reset_for_tests();
	}

	public function test_no_javascript_forms_keep_existing_guards_and_accessible_editable_values(): void {
		$xpath = $this->render();
		self::assertSame( 2, $xpath->query( '//form[@method="post"]' )->length );
		foreach ( [ 'expected_revision' => '7', 'expected_scope_row_id' => '91' ] as $name => $value ) {
			self::assertSame( 2, $xpath->query( '//input[@name="' . $name . '" and @value="' . $value . '"]' )->length );
		}
		self::assertSame( 1, $xpath->query( '//input[@name="request_token" and @value="save-token"]' )->length );
		self::assertSame( 1, $xpath->query( '//input[@name="request_token" and @value="reset-token"]' )->length );
		self::assertSame( 2, $xpath->query( '//input[@name="cetech_de_nonce"]' )->length );
		self::assertSame( 0, $xpath->query( '//div[contains(@class,"cetech-de-customize-override")][@hidden]' )->length );
		self::assertSame( 0, $xpath->query( '//div[contains(@class,"cetech-de-customize-override")]//input[@name][@disabled]' )->length );
		foreach ( $xpath->query( '//div[contains(@class,"cetech-de-customize-override")]//select | //div[contains(@class,"cetech-de-customize-override")]//input[@type="text"]' ) as $control ) {
			$id = $control->getAttribute( 'id' );
			self::assertNotSame( '', $id );
			self::assertSame( 1, $xpath->query( '//label[@for="' . $id . '"]' )->length );
			foreach ( explode( ' ', $control->getAttribute( 'aria-describedby' ) ) as $help_id ) {
				self::assertSame( 1, $xpath->query( '//*[@id="' . $help_id . '"]' )->length );
			}
		}
	}

	public function test_off_page_selections_are_retained_without_per_id_reads_or_fabricated_names(): void {
		$xpath = $this->render();
		$selection = $xpath->query( '//input[@name="fields[delivery_offer_ids][members][]" and @value="601"]' )->item( 0 );
		self::assertNotNull( $selection );
		self::assertTrue( $selection->hasAttribute( 'checked' ) );
		self::assertStringContainsString( 'outside the loaded list', $selection->parentNode->textContent );
		self::assertStringContainsString( '2 delivery options (some labels are outside the loaded list)', $xpath->query( '//*[@id="cetech_de_customize_delivery_offer_ids_state"]' )->item( 0 )->textContent );
		self::assertSame( 0, $xpath->query( '//input[@type="search"][@name]' )->length );
	}

	public function test_inactive_draft_fulfilment_does_not_hide_options_for_known_inheritance(): void {
		$xpath = $this->render( 'inherit', [ ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'inherit', 'value' => 'international_fulfilment' ] ] );
		self::assertSame( 1, $xpath->query( '//fieldset[@data-field="fulfilment_availability"][@data-inherited-fulfilment="in_store"]' )->length );
		self::assertSame( 1, $xpath->query( '//p[@data-route="local_delivery"][not(@hidden)]' )->length );
		self::assertSame( 1, $xpath->query( '//p[@data-route="air"][@hidden]' )->length );
		self::assertSame( 1, $xpath->query( '//fieldset[@data-field="pickup_location_id"]' )->length );
	}

	public function test_unknown_parent_after_saved_override_does_not_claim_an_inherited_profile(): void {
		$xpath = $this->render( 'override', [ ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'inherit', 'value' => 'international_fulfilment' ] ] );
		self::assertSame( 0, $xpath->query( '//fieldset[@data-field="fulfilment_availability"][@data-inherited-fulfilment]' )->length );
		$inherit = $xpath->query( '//fieldset[@data-field="fulfilment_availability"]//input[@value="inherit"]' )->item( 0 );
		self::assertSame( 'Use Site-wide Default', trim( $inherit->parentNode->textContent ) );
		self::assertSame( 2, $xpath->query( '//p[contains(@class,"cetech-de-compatible-option")][not(@hidden)]' )->length );
		self::assertSame( 0, $xpath->query( '//fieldset[@data-field="pickup_location_id"]' )->length );
		self::assertStringContainsString( 'Inherited delivery and pickup details are confirmed after saving.', $xpath->query( '//body' )->item( 0 )->textContent );
	}

	/** @param array<string, mixed> $draft */
	private function render( string $fulfilment_mode = 'inherit', array $draft = [] ): DOMXPath {
		$offers = new class implements DeliveryOfferRepositoryInterface {
			public function findById( int $id ): ?array { throw new \LogicException( 'Rendering must not issue per-selection reads.' ); }
			public function findByCode( string $code ): ?array { return null; }
			public function save( array $data ): int { throw new \LogicException( 'Read-only render.' ); }
			public function list( array $criteria = [] ): array {
				TestCase::assertSame( [ 'limit' => 200 ], $criteria );
				return [
					[ 'id' => 11, 'route' => 'local_delivery', 'public_label' => 'Local delivery' ],
					[ 'id' => 12, 'route' => 'air', 'public_label' => 'Air shipping' ],
				];
			}
			public function softDelete( int $id ): bool { throw new \LogicException( 'Read-only render.' ); }
			public function hardDelete( int $id ): bool { throw new \LogicException( 'Read-only render.' ); }
			public function count_all(): int { return 201; }
		};
		$profile = 'override' === $fulfilment_mode ? 'international_fulfilment' : 'in_store';
		$fields = [
			$this->field( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, $fulfilment_mode, $profile, [ 'in_store' => 'In Store', 'international_fulfilment' => 'International' ] ),
			$this->field( ConfigurationFieldKey::FULFILMENT_CHOICE, 'inherit', 'delivery', [ 'delivery' => 'Delivery' ] ),
			$this->field( ConfigurationFieldKey::ESTIMATED_DELIVERY, 'override', '10 business days' ),
			$this->field( ConfigurationFieldKey::PICKUP_LOCATION_ID, 'inherit', '51', null, [ 51 => 'Accra store' ] ),
			$this->field( ConfigurationFieldKey::DELIVERY_OFFER_IDS, 'replace', null, null, [ 11 => 'Local delivery' ], true ),
		];
		$model = new ScopedConfigurationEditViewModel( 'product', 42, 'default', 'Default', null, 'Product <42>', null, 7, false, null, [], $fields, [], [], [ 'scope_row_id' => 91 ], '', '' );
		ob_start();
		( new StaffDeliveryCustomizeView( $offers ) )->render( $model, [ 'save_token' => 'save-token', 'reset_token' => 'reset-token', 'fields' => $draft ] );
		$html = (string) ob_get_clean();
		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return new DOMXPath( $dom );
	}

	/** @param array<int|string, string>|null $enum @param array<int|string, string>|null $selectors */
	private function field( string $key, string $mode, mixed $value, ?array $enum = null, ?array $selectors = null, bool $collection = false ): FieldEditViewModel {
		return new FieldEditViewModel(
			field_key: $key, label: $key, description: '', is_collection: $collection, value_type: 'string', allowed_modes: $collection ? [ 'inherit', 'add', 'remove', 'replace' ] : [ 'inherit', 'override' ], mode_labels: [], current_mode: $mode, configured_value: $value, inherited_value: $value, effective_value: $value, effective_state: 'valid', effective_state_label: 'Ready', effective_state_tone: 'success', provenance_label: 'Site-wide default', provenance_lines: [], validation_messages: [], entity_kind: null, selector_options: $selectors, enum_options: $enum, configured_members: $collection ? [ 11, 601 ] : [], inherited_members: [], effective_members: $collection ? [ 11, 601 ] : []
		);
	}
}
