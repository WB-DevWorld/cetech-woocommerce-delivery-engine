<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuotePrivateSection;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProjection;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProjectionResult;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProjectionTarget;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProjectionAdminTest extends TestCase {
	private function header( array $overrides = [] ): QuoteHeader {
		return QuoteHeader::from_array( array_replace( [ 'format_version' => 1, 'quote_id' => '23456789-abcd-4abc-8def-1234567890ab',
			'owner' => [ 'site_id' => 7, 'kind' => 'guest', 'principal_hash' => hash( 'sha256', 'private-guest' ),
				'session_hash' => hash( 'sha256', 'private-session' ), 'key_epoch' => 'fixture_epoch_1' ],
			'profile' => 'fixture_v1', 'profile_version' => 1, 'purpose' => 'checkout',
			'material_digest' => hash( 'sha256', 'private-material' ), 'body_digest' => hash( 'sha256', 'private-body' ),
			'namespace_hashes' => [ 'issue' => hash( 'sha256', 'issue' ), 'accept' => hash( 'sha256', 'accept' ), 'invalidate' => hash( 'sha256', 'invalidate' ) ],
			'acceptance_handle_hash' => hash( 'sha256', 'private-handle' ), 'created_at' => '2026-10-07 05:00:00.000000',
			'expires_at' => '2026-10-07 05:05:00.000000', 'revision' => 1 ], $overrides ) );
	}

	private function denied( mixed $result, string $code = 'not_authorized' ): ContractError {
		self::assertInstanceOf( ContractError::class, $result ); self::assertSame( $code, $result->code );
		self::assertSame( [], $result->parameters ); self::assertSame( [], $result->field_violations );
		self::assertStringNotContainsString( 'private-sentinel', json_encode( $result->to_array(), JSON_THROW_ON_ERROR ) );
		return $result;
	}

	public static function non_grants(): iterable {
		foreach ( [ false, 1, 'true', 'administrator', null, [], new \stdClass() ] as $value ) { yield [ $value ]; }
	}

	#[DataProvider( 'non_grants' )]
	public function test_denial_and_truthy_role_claims_precede_all_header_and_section_loading( mixed $grant ): void {
		$target = QuoteProjectionTarget::from_header( $this->header() ); $loads = 0;
		$load = static function () use ( &$loads ): never { ++$loads; throw new RuntimeException( 'private-sentinel' ); };
		$this->denied( QuoteProjection::for_admin( $target, RequestContext::create(), static fn() => $grant, $load, [ 'cost' => $load ] ) );
		self::assertSame( 0, $loads );
	}

	public function test_authorizer_exception_is_denied_without_loading_or_exception_disclosure(): void {
		$loads = 0;
		$this->denied( QuoteProjection::for_admin( QuoteProjectionTarget::from_header( $this->header() ), RequestContext::create(),
			static fn() => throw new RuntimeException( 'private-sentinel' ), static function () use ( &$loads ): void { ++$loads; } ) );
		self::assertSame( 0, $loads );
	}

	public function test_header_only_explanation_has_no_invented_lifecycle_state_or_private_facts(): void {
		$header = $this->header(); $target = QuoteProjectionTarget::from_header( $header ); $observed = [];
		$result = QuoteProjection::for_admin( $target, RequestContext::create(),
			static function ( QuoteProjectionTarget $passed, string $capability, string $purpose ) use ( $target, &$observed ): bool {
				self::assertSame( $target, $passed ); $observed[] = [ $capability, $purpose ]; return true;
			}, static fn() => $header );
		self::assertInstanceOf( QuoteProjectionResult::class, $result ); self::assertSame( 'admin', $result->purpose() );
		self::assertSame( array_fill( 0, 3, [ 'view_delivery_diagnostics', 'quote_explanation' ] ), $observed );
		$fields = $result->fields();
		self::assertSame( [ 'contract_version', 'site_id', 'quote_id', 'profile', 'profile_version', 'header_revision', 'created_at', 'expires_at', 'sections', 'correlation_id' ], array_keys( $fields ) );
		self::assertSame( [], $fields['sections'] ); self::assertSame( 7, $fields['site_id'] ); self::assertSame( 1, $fields['header_revision'] );
		foreach ( [ 'state', 'status', 'currently_applicable', 'owner', 'body_digest', 'material_digest', 'namespace_hashes', 'acceptance_handle_hash' ] as $absent ) { self::assertArrayNotHasKey( $absent, $fields ); }
		foreach ( [ $header->owner()->digest(), $header->body_digest(), $header->material_digest(), ...array_values( $header->namespace_hashes() ) ] as $private ) {
			self::assertStringNotContainsString( $private, json_encode( $fields, JSON_THROW_ON_ERROR ) );
		}
	}

	public static function sections(): iterable { foreach ( [ 'cost', 'origin', 'rate' ] as $section ) { yield [ $section ]; } }

	#[DataProvider( 'sections' )]
	public function test_diagnostics_grant_never_implies_private_section_authority( string $section ): void {
		$header = $this->header(); $loads = 0;
		$this->denied( QuoteProjection::for_admin( QuoteProjectionTarget::from_header( $header ), RequestContext::create(),
			static fn( QuoteProjectionTarget $target, string $capability ): bool => 'view_delivery_diagnostics' === $capability,
			static fn() => $header, [ $section => static function () use ( &$loads ): never { ++$loads; throw new RuntimeException( 'private-sentinel' ); } ] ) );
		self::assertSame( 0, $loads );
	}

	public function test_all_sections_are_independent_typed_and_loaded_only_after_their_exact_grants(): void {
		$header = $this->header(); $target = QuoteProjectionTarget::from_header( $header ); $last_grant = [];
		$authorizer = static function ( QuoteProjectionTarget $passed, string $capability, string $purpose ) use ( $target, &$last_grant ): bool {
			self::assertTrue( $passed->equals( $target ) ); $last_grant = [ $capability, $purpose ]; return true;
		};
		$cost = QuotePrivateSection::cost_known( $target, QuoteMoney::from_array( [ 'amount' => '8123.4567', 'currency' => 'USD', 'precision' => 4 ] ), 'fixture_cost', 2 );
		$origin = QuotePrivateSection::origin_recorded( $target, 891237, 891238, 891239 );
		$rate = QuotePrivateSection::rate_recorded( $target, 891240, 891241, hash( 'sha256', 'private-policy' ), 'fixture_v1', 1 );
		$loaders = [];
		foreach ( [ 'cost' => [ $cost, 'view_private_delivery_costs' ], 'origin' => [ $origin, 'view_private_origins' ], 'rate' => [ $rate, 'manage_delivery_rate_cards' ] ] as $section => [ $facts, $capability ] ) {
			$loaders[$section] = static function ( QuoteProjectionTarget $passed ) use ( $target, $section, $facts, $capability, &$last_grant ): QuotePrivateSection {
				self::assertSame( [ $capability, 'quote_' . $section ], $last_grant ); self::assertSame( $target, $passed ); return $facts;
			};
		}
		$result = QuoteProjection::for_admin( $target, RequestContext::create(), $authorizer, static fn() => $header, $loaders );
		self::assertInstanceOf( QuoteProjectionResult::class, $result );
		self::assertSame( [ 'cost' => $cost->private_fields(), 'origin' => $origin->private_fields(), 'rate' => $rate->private_fields() ], $result->fields()['sections'] );
	}

	public function test_revoked_current_object_scope_during_header_load_prevents_every_private_load(): void {
		$header = $this->header(); $authorized = true; $private_loads = 0;
		$result = QuoteProjection::for_admin( QuoteProjectionTarget::from_header( $header ), RequestContext::create(),
			static function () use ( &$authorized ): bool { return $authorized; },
			static function () use ( &$authorized, $header ): QuoteHeader { $authorized = false; return $header; },
			[ 'cost' => static function () use ( &$private_loads ): void { ++$private_loads; } ] );
		$this->denied( $result ); self::assertSame( 0, $private_loads );
	}

	#[DataProvider( 'sections' )]
	public function test_revoked_section_right_during_load_hides_even_valid_private_facts( string $section ): void {
		$header = $this->header(); $target = QuoteProjectionTarget::from_header( $header ); $authorized = true;
		$this->denied( QuoteProjection::for_admin( $target, RequestContext::create(),
			static function ( QuoteProjectionTarget $target, string $capability ) use ( &$authorized ): bool { return 'view_delivery_diagnostics' === $capability || $authorized; },
			static fn() => $header, [ $section => static function () use ( &$authorized, $target, $section ): QuotePrivateSection {
				$authorized = false; return QuotePrivateSection::unavailable( $target, $section, 'unknown' );
			} ] ) );
	}

	public function test_revoked_previously_loaded_section_right_before_final_disclosure_hides_every_section(): void {
		$header = $this->header(); $target = QuoteProjectionTarget::from_header( $header ); $cost_allowed = true;
		$this->denied( QuoteProjection::for_admin( $target, RequestContext::create(),
			static function ( QuoteProjectionTarget $target, string $capability ) use ( &$cost_allowed ): bool { return 'view_private_delivery_costs' !== $capability || $cost_allowed; },
			static fn() => $header, [
				'cost' => static fn() => QuotePrivateSection::unavailable( $target, 'cost', 'unknown' ),
				'origin' => static function () use ( &$cost_allowed, $target ): QuotePrivateSection { $cost_allowed = false; return QuotePrivateSection::origin_recorded( $target, 9, 10, 11 ); },
			] ) );
	}

	public function test_every_exact_identity_dimension_is_checked_before_private_loading(): void {
		$header = $this->header(); $original = $header->private_facts(); $variants = [];
		foreach ( [ 'body_digest', 'material_digest', 'acceptance_handle_hash' ] as $key ) { $variants[$key] = [ $key => hash( 'sha256', 'different-' . $key ) ]; }
		$variants['id'] = [ 'quote_id' => '12345678-abcd-4abc-8def-1234567890ab' ];
		$variants['profile'] = [ 'profile' => 'other_fixture' ]; $variants['version'] = [ 'profile_version' => 2 ]; $variants['purpose'] = [ 'purpose' => 'estimate' ];
		foreach ( [ 'site_id' => 8, 'kind' => 'customer', 'principal_hash' => hash( 'sha256', 'other-principal' ), 'session_hash' => hash( 'sha256', 'other-session' ), 'key_epoch' => 'epoch_other' ] as $key => $value ) {
			$variants[$key] = [ 'owner' => array_replace( $original['owner'], [ $key => $value ] ) ];
		}
		$variants['namespace'] = [ 'namespace_hashes' => array_replace( $original['namespace_hashes'], [ 'accept' => hash( 'sha256', 'other-accept' ) ] ) ];
		$variants['interval'] = [ 'created_at' => '2026-10-07 05:00:01.000000', 'expires_at' => '2026-10-07 05:05:01.000000' ];
		$private_loads = 0;
		foreach ( $variants as $key => $override ) {
			if ( in_array( $key, [ 'profile', 'version' ], true ) ) {
				// Q01's finite header codec rejects unsupported profiles before a
				// typed target can exist; an actual loader failure remains safe.
				$this->denied( QuoteProjection::for_admin( QuoteProjectionTarget::from_header( $header ), RequestContext::create(), static fn() => true,
					fn() => $this->header( $override ), [ 'cost' => static function () use ( &$private_loads ): void { ++$private_loads; } ] ), 'temporarily_unavailable' );
				continue;
			}
			$other = $this->header( $override );
			self::assertFalse( QuoteProjectionTarget::from_header( $header )->matches_header( $other ), $key );
			$this->denied( QuoteProjection::for_admin( QuoteProjectionTarget::from_header( $header ), RequestContext::create(), static fn() => true,
				static fn() => $other, [ 'cost' => static function () use ( &$private_loads ): void { ++$private_loads; } ] ) );
		}
		self::assertSame( 0, $private_loads );
	}

	public function test_missing_header_raw_array_and_wrong_section_types_never_become_explanations(): void {
		$header = $this->header(); $target = QuoteProjectionTarget::from_header( $header );
		foreach ( [ null, [], [ 'body' => [ 'renamed' => [ 'private-sentinel' ] ] ], new \stdClass() ] as $raw ) {
			$this->denied( QuoteProjection::for_admin( $target, RequestContext::create(), static fn() => true, static fn() => $raw ) );
			$this->denied( QuoteProjection::for_admin( $target, RequestContext::create(), static fn() => true, static fn() => $header, [ 'cost' => static fn() => $raw ] ) );
		}
	}

	public function test_wrong_section_target_and_kind_are_denied_even_with_current_capability(): void {
		$header = $this->header(); $target = QuoteProjectionTarget::from_header( $header );
		foreach ( [ QuotePrivateSection::unavailable( $target, 'origin', 'unknown' ),
			QuotePrivateSection::unavailable( QuoteProjectionTarget::from_header( $this->header( [ 'body_digest' => hash( 'sha256', 'foreign' ) ] ) ), 'cost', 'unknown' ) ] as $wrong ) {
			$this->denied( QuoteProjection::for_admin( $target, RequestContext::create(), static fn() => true, static fn() => $header, [ 'cost' => static fn() => $wrong ] ) );
		}
	}

	public function test_unknown_renamed_nested_section_declarations_refuse_before_header_load(): void {
		$loads = 0; $target = QuoteProjectionTarget::from_header( $this->header() );
		$load = static function () use ( &$loads ): void { ++$loads; };
		foreach ( [ [ 'renamed_cost' => $load ], [ 'cost' => [ 'loader' => $load ] ], [ 0 => $load ], [ 'cost' => 'private-sentinel' ],
			[ 'cost' => $load, 'origin' => $load, 'rate' => $load, 'address' => $load ] ] as $declaration ) {
			$this->denied( QuoteProjection::for_admin( $target, RequestContext::create(), static fn() => true, $load, $declaration ), 'invalid_input' );
		}
		self::assertSame( 0, $loads );
	}

	public function test_sensitive_loader_exceptions_are_sanitized_and_revocation_remains_denied(): void {
		$header = $this->header(); $target = QuoteProjectionTarget::from_header( $header ); $request = RequestContext::create();
		$throw = static fn() => throw new RuntimeException( 'private-sentinel {"address":["token","supplier","cost"]}' );
		$this->denied( QuoteProjection::for_admin( $target, $request, static fn() => true, $throw ), 'temporarily_unavailable' );
		$this->denied( QuoteProjection::for_admin( $target, $request, static fn() => true, static fn() => $header, [ 'cost' => $throw ] ), 'temporarily_unavailable' );
		$allowed = true;
		$this->denied( QuoteProjection::for_admin( $target, $request, static function () use ( &$allowed ): bool { return $allowed; },
			static function () use ( &$allowed ): never { $allowed = false; throw new RuntimeException( 'private-sentinel' ); } ) );
	}

	public function test_private_carriers_reject_generic_json_and_explicit_exports_are_detached(): void {
		$header = $this->header(); $target = QuoteProjectionTarget::from_header( $header ); $section = QuotePrivateSection::origin_recorded( $target, 891237, 891238, null );
		$result = QuoteProjection::for_admin( $target, RequestContext::create(), static fn() => true, static fn() => $header, [ 'origin' => static fn() => $section ] );
		self::assertInstanceOf( QuoteProjectionResult::class, $result );
		foreach ( [ $target, $section, $result ] as $private ) {
			try { json_encode( [ 'nested' => [ 'renamed' => $private ] ], JSON_THROW_ON_ERROR ); self::fail( 'Private JSON must refuse.' ); }
			catch ( LogicException $error ) { self::assertStringNotContainsString( '891237', $error->getMessage() ); self::assertStringNotContainsString( $header->body_digest(), $error->getMessage() ); }
		}
		$copy = $result->fields(); $copy['sections']['origin']['supplier_id'] = 999; $copy['sections']['origin']['new_address'] = 'private-sentinel';
		self::assertSame( 891237, $result->fields()['sections']['origin']['supplier_id'] ); self::assertArrayNotHasKey( 'new_address', $result->fields()['sections']['origin'] );
		$copy = $section->private_fields(); $copy['origin_id'] = 999; self::assertSame( 891238, $section->private_fields()['origin_id'] );
	}

	public function test_section_schema_has_finite_reasons_ids_provider_and_digest_without_extensions(): void {
		$target = QuoteProjectionTarget::from_header( $this->header() );
		$invalid = [ static fn() => QuotePrivateSection::unavailable( $target, 'address', 'unknown' ),
			static fn() => QuotePrivateSection::unavailable( $target, 'cost', 'private-sentinel' ),
			static fn() => QuotePrivateSection::origin_recorded( $target, 0, 1, null ),
			static fn() => QuotePrivateSection::origin_recorded( $target, 1, -1, null ),
			static fn() => QuotePrivateSection::rate_recorded( $target, 1, 2, 'private-sentinel', 'fixture_v1', 1 ),
			static fn() => QuotePrivateSection::rate_recorded( $target, 1, 2, hash( 'sha256', 'policy' ), 'private-sentinel', 1 ),
			static fn() => QuotePrivateSection::cost_known( $target, QuoteMoney::from_array( [ 'amount' => '1', 'currency' => 'USD', 'precision' => 4 ] ), 'fixture_v1', 0 ) ];
		foreach ( $invalid as $construct ) {
			try { $construct(); self::fail( 'Invalid private section must refuse.' ); }
			catch ( InvalidArgumentException $error ) { self::assertSame( 'Invalid private quote section.', $error->getMessage() ); }
		}
		self::assertSame( [ 'status' => 'unavailable', 'reason_code' => 'unknown' ], QuotePrivateSection::unavailable( $target, 'cost', 'unknown' )->private_fields() );
	}
}
