<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Destination\WooCommerceCountryCatalog;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Presentation\Admin\AdminFormHelper;
use CetechDeliveryEngine\Presentation\Admin\Validation\PickupLocationValidator;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryPickupLocationRepository;
use PHPUnit\Framework\TestCase;

final class PostRc7PickupLocationAdminR1Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WooCommerceCountryCatalog::override_for_tests(
			[
				'GH' => 'Ghana',
				'NG' => 'Nigeria',
				'GB' => 'United Kingdom (UK)',
			]
		);
	}

	protected function tearDown(): void {
		WooCommerceCountryCatalog::override_for_tests( null );
		parent::tearDown();
	}

	public function test_blank_reference_code_generates_from_location_name(): void {
		$repository = new InMemoryPickupLocationRepository();
		$result     = $this->save_location( $repository, $this->input( [ 'location_name' => 'CETECH Accra Store', 'code' => '' ] ) );

		self::assertSame( [], $result['errors'] );
		self::assertSame( 'cetech-accra-store', $result['code'] );
		self::assertNotNull( $repository->findByCode( 'cetech-accra-store' ) );
	}

	public function test_explicit_valid_code_is_retained(): void {
		$result = $this->save_location(
			new InMemoryPickupLocationRepository(),
			$this->input( [ 'location_name' => 'CETECH Accra Store', 'code' => 'accra-main' ] )
		);

		self::assertSame( [], $result['errors'] );
		self::assertSame( 'accra-main', $result['code'] );
	}

	public function test_duplicate_code_is_rejected_and_retains_entered_values(): void {
		$repository = new InMemoryPickupLocationRepository();
		$first      = $this->save_location( $repository, $this->input( [ 'location_name' => 'First', 'code' => 'shared-code' ] ) );
		$dup_input  = $this->input(
			[
				'location_name' => 'Second Store',
				'code'          => 'shared-code',
				'city'          => 'Accra',
			]
		);
		$dup = $this->save_location( $repository, $dup_input );

		self::assertSame( [], $first['errors'] );
		self::assertNotSame( [], $dup['errors'] );
		self::assertStringContainsString( 'already exists', implode( ' ', $dup['errors'] ) );
		self::assertSame( 'Second Store', $dup['retained']['location_name'] );
		self::assertSame( 'shared-code', $dup['retained']['code'] );
		self::assertSame( 'Accra', $dup['retained']['city'] );
	}

	public function test_invalid_code_is_rejected_and_retains_entered_values(): void {
		$input  = $this->input( [ 'location_name' => 'CETECH Accra Store', 'code' => '!!!' ] );
		$result = $this->save_location( new InMemoryPickupLocationRepository(), $input );

		self::assertNotSame( [], $result['errors'] );
		self::assertSame( 'CETECH Accra Store', $result['retained']['location_name'] );
		self::assertSame( '!!!', $result['retained']['code'] );
	}

	public function test_rename_preserves_established_reference_code(): void {
		$repository = new InMemoryPickupLocationRepository();
		$created    = $this->save_location(
			$repository,
			$this->input( [ 'location_name' => 'CETECH Accra Store', 'code' => 'accra-main' ] )
		);
		$renamed    = $this->save_location(
			$repository,
			$this->input(
				[
					'id'            => $created['id'],
					'location_name' => 'CETECH Accra Flagship',
					'code'          => '',
				]
			)
		);

		self::assertSame( [], $renamed['errors'] );
		self::assertSame( 'accra-main', $renamed['code'] );
		$row = $repository->findById( $created['id'] );
		self::assertIsArray( $row );
		self::assertSame( 'accra-main', $row['internal_code'] );
		self::assertSame( 'CETECH Accra Flagship', $row['location_name'] );
	}

	public function test_country_selector_stores_iso2_not_label(): void {
		$from_code  = $this->save_location(
			new InMemoryPickupLocationRepository(),
			$this->input( [ 'country_code' => 'GH' ] )
		);
		$from_label = $this->save_location(
			new InMemoryPickupLocationRepository(),
			$this->input( [ 'country_code' => 'Ghana' ] )
		);

		self::assertSame( [], $from_code['errors'] );
		self::assertSame( 'GH', $from_code['country'] );
		self::assertSame( [], $from_label['errors'] );
		self::assertSame( 'GH', $from_label['country'] );
		self::assertNotSame( 'Ghana', $from_label['country'] );
	}

	public function test_readiness_estimate_is_persisted_separately_from_instructions(): void {
		$repository = new InMemoryPickupLocationRepository();
		$result     = $this->save_location(
			$repository,
			$this->input(
				[
					'readiness_estimate'         => '1–2 business days',
					'public_pickup_instructions' => 'Collect from the CETECH Store',
				]
			)
		);

		self::assertSame( [], $result['errors'] );
		$row = $repository->findById( $result['id'] );
		self::assertIsArray( $row );
		self::assertSame( '1–2 business days', $row['readiness_estimate'] );
		self::assertSame( 'Collect from the CETECH Store', $row['public_pickup_instructions'] );
	}

	public function test_prepare_reference_code_runs_before_validation(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/PickupLocationsPage.php' );
		$prepare  = strpos( $source, 'AdminFormHelper::prepare_reference_code' );
		$validate = strpos( $source, '->validate(' );
		self::assertNotFalse( $prepare );
		self::assertNotFalse( $validate );
		self::assertLessThan( $validate, $prepare );
		self::assertStringContainsString( 'open_entity_form', $source );
		self::assertStringContainsString( 'country_select_field', $source );
		self::assertStringContainsString( 'readiness_estimate', $source );
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function input( array $overrides ): array {
		return array_merge(
			[
				'id'                         => 0,
				'code'                       => '',
				'location_name'              => 'CETECH Accra Store',
				'address_line_1'             => '12 Independence Ave',
				'address_line_2'             => '',
				'city'                       => 'Accra',
				'region'                     => '',
				'country_code'               => 'GH',
				'postcode'                   => '',
				'contact_phone'              => '',
				'contact_email'              => '',
				'public_opening_hours'       => '',
				'public_pickup_instructions' => '',
				'readiness_estimate'         => '1–2 business days',
				'status'                     => RecordStatus::Active->value,
			],
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array{errors: array<string, string>, code: string, id: int, country: string, retained: array<string, mixed>}
	 */
	private function save_location( InMemoryPickupLocationRepository $repository, array $input ): array {
		$retained = $input;
		$input    = AdminFormHelper::prepare_reference_code(
			$input,
			(string) ( $input['location_name'] ?? '' ),
			static function ( string $candidate ) use ( $repository, $input ): bool {
				$existing = $repository->findByCode( $candidate );

				return null !== $existing && (int) ( $existing['id'] ?? 0 ) !== (int) ( $input['id'] ?? 0 );
			},
			static function ( int $id ) use ( $repository ): string {
				$row = $repository->findById( $id );

				return is_array( $row ) ? (string) ( $row['internal_code'] ?? '' ) : '';
			},
			'pickup-location'
		);

		$validator = new PickupLocationValidator();
		$errors    = $validator->validate( $input, isset( $input['id'] ) ? (int) $input['id'] : null );
		$code      = AdminFormHelper::sanitize_code( (string) ( $input['code'] ?? '' ) );
		$id        = (int) ( $input['id'] ?? 0 );
		$taken     = '' !== $code ? $repository->findByCode( $code ) : null;
		if ( null !== $taken && (int) ( $taken['id'] ?? 0 ) !== $id ) {
			$errors['code'] = 'A pickup location with this code already exists.';
		}

		if ( [] !== $errors ) {
			return [
				'errors'   => $errors,
				'code'     => (string) ( $retained['code'] ?? $code ),
				'id'       => $id,
				'country'  => (string) ( $input['country_code'] ?? '' ),
				'retained' => $retained,
			];
		}

		$saved_id = $repository->save(
			[
				'id'                         => $id,
				'internal_code'              => $code,
				'location_name'              => (string) $input['location_name'],
				'public_address'             => $validator->encode_public_address( $input ),
				'public_opening_hours'       => (string) ( $input['public_opening_hours'] ?? '' ),
				'public_pickup_instructions' => (string) ( $input['public_pickup_instructions'] ?? '' ),
				'readiness_estimate'         => (string) ( $input['readiness_estimate'] ?? '' ),
				'status'                     => (string) $input['status'],
			]
		);
		$saved = $repository->findById( $saved_id );
		$address = $validator->decode_public_address( is_array( $saved ) ? (string) ( $saved['public_address'] ?? '' ) : null );

		return [
			'errors'   => [],
			'code'     => $code,
			'id'       => $saved_id,
			'country'  => $address['country_code'],
			'retained' => $input,
		];
	}
}
