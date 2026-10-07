<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Presentation\Admin\EmergencyControlSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmergencyControlSettingsTest extends TestCase {

	private function form(): array {
		return [ 'opened_row_id' => '0', 'opened_revision' => '1', 'opened_bytes' => '', 'desired_state' => 'checkout_suspended', 'reason_code' => 'incident_pause', 'request_token' => RequestContext::create()->request_id ];
	}

	public function test_original_empty_row_envelope_survives_transport_and_retry_exactly(): void {
		$form = $this->form();
		$parsed = EmergencyControlSettings::parse_form( $form );
		self::assertSame( [ 'opened_row_id' => 0, 'opened_revision' => 1, 'opened_bytes' => '', 'desired_state' => 'checkout_suspended', 'reason_code' => 'incident_pause', 'request_token' => $form['request_token'] ], $parsed );
		self::assertSame( $parsed, EmergencyControlSettings::parse_form( $form ) );
	}

	public function test_exact_previous_bytes_remain_a_precondition_not_a_new_current_value(): void {
		$form = $this->form();
		$form['opened_row_id'] = '83';
		$form['opened_revision'] = '9';
		$bytes = '{"format_version":1,"site_id":1,"state":"checkout_suspended","revision":9,"reason_code":"maintenance_pause","actor_user_id":4,"changed_at_epoch":1770000000}';
		$form['opened_bytes'] = base64_encode( $bytes );
		$form['desired_state'] = 'enabled';
		$form['reason_code'] = 'resume_verified';
		$parsed = EmergencyControlSettings::parse_form( $form );
		self::assertSame( $bytes, $parsed['opened_bytes'] );
		self::assertSame( 83, $parsed['opened_row_id'] );
		self::assertSame( 9, $parsed['opened_revision'] );
		self::assertSame( 'enabled', $parsed['desired_state'] );
	}

	public function test_caller_site_principal_capability_and_outcome_are_not_transport_authority(): void {
		$original = $this->form();
		$attempt = $original + [ 'site_id' => '999', 'actor_user_id' => '999', 'authority' => 'administrator', 'principal' => 'wp-user:999', 'accepted' => '1', 'publication_pending' => '0', 'private' => [ 'address' => 'never store or echo this' ] ];
		self::assertSame( EmergencyControlSettings::parse_form( $original ), EmergencyControlSettings::parse_form( $attempt ) );
	}

	#[DataProvider( 'invalid_form_values' )]
	public function test_invalid_transport_cannot_supply_partial_or_coerced_preconditions( string $key, mixed $value ): void {
		$form = $this->form();
		$form[$key] = $value;
		$this->expectException( \InvalidArgumentException::class );
		EmergencyControlSettings::parse_form( $form );
	}

	public static function invalid_form_values(): array {
		return [
			'array identity' => [ 'opened_row_id', [ '0' ] ], 'integer identity' => [ 'opened_row_id', 0 ],
			'negative identity' => [ 'opened_row_id', '-1' ], 'leading zero' => [ 'opened_row_id', '00' ],
			'overflow identity' => [ 'opened_row_id', '9223372036854775808' ], 'overflow revision' => [ 'opened_revision', '9223372036854775808' ],
			'float revision' => [ 'opened_revision', '1.0' ], 'exponent revision' => [ 'opened_revision', '1e3' ],
			'missing original bytes' => [ 'opened_bytes', null ], 'invalid encoding' => [ 'opened_bytes', '%' ],
			'oversized decoded state' => [ 'opened_bytes', base64_encode( str_repeat( 'x', 2049 ) ) ],
			'coerced state' => [ 'desired_state', 1 ], 'undefined cohort' => [ 'desired_state', 'enabled_for_half' ],
			'unknown reason' => [ 'reason_code', 'private arbitrary text' ], 'resume reason on pause' => [ 'reason_code', 'resume_verified' ],
			'unknown token' => [ 'request_token', 'token from somebody else' ], 'nested token' => [ 'request_token', [ 'private' => 'x' ] ],
		];
	}
}
