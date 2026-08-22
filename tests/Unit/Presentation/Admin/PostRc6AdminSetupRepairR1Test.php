<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Domain\Enum\CarrierVisibility;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Presentation\Admin\AdminFormHelper;
use CetechDeliveryEngine\Presentation\Admin\Validation\DeliveryOfferValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationRuleValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationZoneValidator;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;

final class PostRc6AdminSetupRepairR1Test extends TestCase {

	public function test_delivery_option_blank_code_generates_from_name_and_saves(): void {
		$repository = new InMemoryDeliveryOfferRepository();
		$input      = $this->offer_input( [ 'public_label' => 'Same-Day Delivery', 'code' => '' ] );
		$result     = $this->save_offer( $repository, $input );

		self::assertSame( [], $result['errors'] );
		self::assertSame( 'same-day-delivery', $result['code'] );
		self::assertNotNull( $repository->findByCode( 'same-day-delivery' ) );
	}

	public function test_delivery_area_blank_code_generates_from_name_and_saves(): void {
		$repository = new InMemoryDestinationZoneRepository();
		$input      = $this->area_input( [ 'name' => 'Greater Accra', 'code' => '' ] );
		$result     = $this->save_area( $repository, $input );

		self::assertSame( [], $result['errors'] );
		self::assertSame( 'greater-accra', $result['code'] );
		self::assertNotNull( $repository->findByCode( 'greater-accra' ) );
	}

	public function test_explicit_valid_code_is_retained(): void {
		$offers = new InMemoryDeliveryOfferRepository();
		$areas  = new InMemoryDestinationZoneRepository();

		$offer = $this->save_offer(
			$offers,
			$this->offer_input( [ 'public_label' => 'Next Day', 'code' => 'custom_nd' ] )
		);
		$area = $this->save_area(
			$areas,
			$this->area_input( [ 'name' => 'Lagos Metro', 'code' => 'los-metro' ] )
		);

		self::assertSame( [], $offer['errors'] );
		self::assertSame( 'custom_nd', $offer['code'] );
		self::assertSame( [], $area['errors'] );
		self::assertSame( 'los-metro', $area['code'] );
	}

	public function test_duplicate_explicit_code_is_rejected(): void {
		$offers = new InMemoryDeliveryOfferRepository();
		$areas  = new InMemoryDestinationZoneRepository();

		$first_offer = $this->save_offer( $offers, $this->offer_input( [ 'public_label' => 'First', 'code' => 'shared-code' ] ) );
		$dup_offer   = $this->save_offer( $offers, $this->offer_input( [ 'public_label' => 'Second', 'code' => 'shared-code' ] ) );
		$first_area  = $this->save_area( $areas, $this->area_input( [ 'name' => 'First Area', 'code' => 'area-code' ] ) );
		$dup_area    = $this->save_area( $areas, $this->area_input( [ 'name' => 'Second Area', 'code' => 'area-code' ] ) );

		self::assertSame( [], $first_offer['errors'] );
		self::assertNotSame( [], $dup_offer['errors'] );
		self::assertStringContainsString( 'already exists', implode( ' ', $dup_offer['errors'] ) );
		self::assertSame( [], $first_area['errors'] );
		self::assertNotSame( [], $dup_area['errors'] );
		self::assertStringContainsString( 'already exists', implode( ' ', $dup_area['errors'] ) );
	}

	public function test_invalid_explicit_code_is_rejected(): void {
		$offer_errors = ( new DeliveryOfferValidator() )->validate(
			$this->offer_input( [ 'public_label' => 'Same-Day Delivery', 'code' => '!!!' ] )
		);
		$area_errors = ( new DestinationZoneValidator() )->validate(
			$this->area_input( [ 'name' => 'Greater Accra', 'code' => '!!!' ] )
		);

		self::assertArrayHasKey( 'code', $offer_errors );
		self::assertArrayHasKey( 'code', $area_errors );
		self::assertStringContainsString( 'lowercase letters', $offer_errors['code'] );
		self::assertStringContainsString( 'lowercase letters', $area_errors['code'] );
	}

	public function test_existing_generated_code_does_not_change_when_display_name_is_edited(): void {
		$offers = new InMemoryDeliveryOfferRepository();
		$areas  = new InMemoryDestinationZoneRepository();

		$created_offer = $this->save_offer( $offers, $this->offer_input( [ 'public_label' => 'Same-Day Delivery', 'code' => '' ] ) );
		$created_area  = $this->save_area( $areas, $this->area_input( [ 'name' => 'Greater Accra', 'code' => '' ] ) );

		$updated_offer = $this->save_offer(
			$offers,
			$this->offer_input(
				[
					'id'           => $created_offer['id'],
					'public_label' => 'Same Day Express',
					'code'         => '',
				]
			)
		);
		$updated_area = $this->save_area(
			$areas,
			$this->area_input(
				[
					'id'   => $created_area['id'],
					'name' => 'Greater Accra Metro',
					'code' => '',
				]
			)
		);

		self::assertSame( [], $updated_offer['errors'] );
		self::assertSame( 'same-day-delivery', $updated_offer['code'] );
		self::assertSame( [], $updated_area['errors'] );
		self::assertSame( 'greater-accra', $updated_area['code'] );
	}

	public function test_country_rules_store_canonical_iso_codes_and_reject_continent_labels(): void {
		$validator = new DestinationRuleValidator();

		$saved = $validator->validate_and_normalize(
			[
				[
					'rule_type'  => DestinationRuleType::Country->value,
					'rule_value' => 'gb',
					'match_mode' => 'exact',
					'priority'   => 100,
				],
			]
		);
		$africa = $validator->validate_and_normalize(
			[
				[
					'rule_type'  => DestinationRuleType::Country->value,
					'rule_value' => 'Africa',
					'match_mode' => 'exact',
					'priority'   => 100,
				],
			]
		);
		$everywhere = $validator->validate_and_normalize(
			[
				[
					'rule_type'  => DestinationRuleType::Country->value,
					'rule_value' => 'Everywhere',
					'match_mode' => 'exact',
					'priority'   => 100,
				],
			]
		);

		self::assertSame( [], $saved['errors'] );
		self::assertSame( 'GB', $saved['rules'][0]['rule_value'] ?? null );
		self::assertNotSame( [], $africa['errors'] );
		self::assertNotSame( [], $everywhere['errors'] );
		self::assertSame( [], $africa['rules'] );
		self::assertSame( [], $everywhere['rules'] );
	}

	public function test_option_and_area_forms_keep_primary_submit_after_validation_errors(): void {
		$offers = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliveryOffersPage.php' );
		$areas  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DestinationZonesPage.php' );
		$layout = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdminPageLayout.php' );

		foreach ( [ $offers, $areas ] as $source ) {
			$entity = strpos( $source, 'cetech-de-entity-form' );
			$submit = strpos( $source, "'type'  => 'submit'" );
			self::assertNotFalse( $entity );
			self::assertNotFalse( $submit );
			self::assertLessThan( $submit, $entity );
			self::assertStringContainsString( 'stash_form_draft', $source );
			self::assertStringContainsString( 'cetech-de-form-actions', $source );
			self::assertStringContainsString( 'submit_button( $submit )', $source );
		}

		self::assertStringContainsString( "__( 'Back to Delivery Options'", $offers );
		self::assertStringContainsString( "__( 'Create Delivery Option'", $offers );
		self::assertStringContainsString( "__( 'Save Delivery Option'", $offers );
		self::assertStringContainsString( "__( 'Back to Delivery Areas'", $areas );
		self::assertStringContainsString( "__( 'Create Delivery Area'", $areas );
		self::assertStringContainsString( "__( 'Save Delivery Area'", $areas );
		self::assertStringContainsString( "if ( 'submit' === ( \$action['type'] ?? '' ) )", $layout );
	}

	public function test_reference_code_is_prepared_before_validation(): void {
		$offers = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliveryOffersPage.php' );
		$areas  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DestinationZonesPage.php' );

		foreach ( [ $offers, $areas ] as $source ) {
			$prepare  = strpos( $source, 'AdminFormHelper::prepare_reference_code' );
			$validate = strpos( $source, '->validate(' );
			self::assertNotFalse( $prepare );
			self::assertNotFalse( $validate );
			self::assertLessThan( $validate, $prepare );
		}
	}

	public function test_responsive_fulfilment_grids_use_safe_minmax(): void {
		$admin  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/admin/delivery-engine-admin.css' );
		$scoped = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/admin/scoped-configuration.css' );

		self::assertStringContainsString( 'minmax(min(100%, 240px), 1fr)', $admin );
		self::assertStringContainsString( 'minmax(min(100%, 220px), 1fr)', $admin );
		self::assertStringContainsString( 'minmax(min(100%, 220px), 1fr)', $scoped );
		self::assertDoesNotMatchRegularExpression( '/cetech-de-profile-card-grid\s*\{[^}]*minmax\(\s*220px\s*,/', $admin );
		self::assertDoesNotMatchRegularExpression( '/cetech-de-profile-card-grid\s*\{[^}]*minmax\(\s*240px\s*,/', $scoped );
		self::assertStringContainsString( 'clip: rect(0, 0, 0, 0)', $admin );
		self::assertStringNotContainsString( 'display: none', substr( $admin, (int) strpos( $admin, '.cetech-de-choice-card input' ), 220 ) );
	}

	public function test_delivery_area_country_picker_uses_woocommerce_catalog_and_preserves_stored_codes(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DestinationZonesPage.php' );

		self::assertStringContainsString( 'WooCommerceCountryCatalog::options()', $source );
		self::assertStringContainsString( 'data-cetech-de-country-select', $source );
		self::assertStringContainsString( 'Select a country', $source );
		self::assertStringContainsString( '! isset( $countries[ $stored ] )', $source );
		self::assertStringContainsString( 'selected( $stored, $code, false )', $source );
		self::assertStringContainsString( 'not a WooCommerce shipping zone', $source );
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function offer_input( array $overrides ): array {
		return array_merge(
			[
				'id'                   => 0,
				'code'                 => '',
				'public_label'         => 'Standard Delivery',
				'description'          => '',
				'route'                => DeliveryRoute::LocalDelivery->value,
				'carrier_visibility'   => CarrierVisibility::AssignedByStore->value,
				'carrier_display_name' => '',
				'status'               => RecordStatus::Active->value,
				'display_priority'     => 100,
			],
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function area_input( array $overrides ): array {
		return array_merge(
			[
				'id'     => 0,
				'code'   => '',
				'name'   => 'Local Area',
				'status' => RecordStatus::Active->value,
			],
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array{errors: array<string, string>, code: string, id: int}
	 */
	private function save_offer( InMemoryDeliveryOfferRepository $repository, array $input ): array {
		$input = AdminFormHelper::prepare_reference_code(
			$input,
			(string) ( $input['public_label'] ?? '' ),
			static function ( string $candidate ) use ( $repository, $input ): bool {
				$existing = $repository->findByCode( $candidate );

				return null !== $existing && (int) ( $existing['id'] ?? 0 ) !== (int) ( $input['id'] ?? 0 );
			},
			static function ( int $id ) use ( $repository ): string {
				$row = $repository->findById( $id );

				return is_array( $row ) ? (string) ( $row['internal_code'] ?? '' ) : '';
			},
			'delivery-option'
		);

		$errors = ( new DeliveryOfferValidator() )->validate( $input, isset( $input['id'] ) ? (int) $input['id'] : null );
		$code   = AdminFormHelper::sanitize_code( (string) ( $input['code'] ?? '' ) );
		$id     = (int) ( $input['id'] ?? 0 );
		$taken  = $repository->findByCode( $code );
		if ( null !== $taken && (int) ( $taken['id'] ?? 0 ) !== $id ) {
			$errors['code'] = 'A delivery option with this code already exists.';
		}

		if ( [] !== $errors ) {
			return [ 'errors' => $errors, 'code' => $code, 'id' => $id ];
		}

		$saved_id = $repository->save(
			[
				'id'            => $id,
				'internal_code' => $code,
				'public_label'  => (string) $input['public_label'],
			]
		);

		return [ 'errors' => [], 'code' => $code, 'id' => $saved_id ];
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array{errors: array<string, string>, code: string, id: int}
	 */
	private function save_area( InMemoryDestinationZoneRepository $repository, array $input ): array {
		$input = AdminFormHelper::prepare_reference_code(
			$input,
			(string) ( $input['name'] ?? '' ),
			static function ( string $candidate ) use ( $repository, $input ): bool {
				$existing = $repository->findByCode( $candidate );

				return null !== $existing && (int) ( $existing['id'] ?? 0 ) !== (int) ( $input['id'] ?? 0 );
			},
			static function ( int $id ) use ( $repository ): string {
				$row = $repository->findById( $id );

				return is_array( $row ) ? (string) ( $row['internal_code'] ?? '' ) : '';
			},
			'delivery-area'
		);

		$errors = ( new DestinationZoneValidator() )->validate( $input, isset( $input['id'] ) ? (int) $input['id'] : null );
		$code   = AdminFormHelper::sanitize_code( (string) ( $input['code'] ?? '' ) );
		$id     = (int) ( $input['id'] ?? 0 );
		$taken  = $repository->findByCode( $code );
		if ( null !== $taken && (int) ( $taken['id'] ?? 0 ) !== $id ) {
			$errors['code'] = 'A destination zone with this code already exists.';
		}

		if ( [] !== $errors ) {
			return [ 'errors' => $errors, 'code' => $code, 'id' => $id ];
		}

		$saved_id = $repository->save(
			[
				'id'            => $id,
				'internal_code' => $code,
				'internal_name' => (string) $input['name'],
			]
		);

		return [ 'errors' => [], 'code' => $code, 'id' => $saved_id ];
	}
}
