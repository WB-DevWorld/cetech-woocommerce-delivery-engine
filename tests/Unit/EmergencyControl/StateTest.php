<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlCommand;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateTest extends TestCase {
	public function test_absence_is_unrecorded_enabled_revision_one(): void {
		$s = EmergencyControlState::absent( 1 ); self::assertSame( 'enabled', $s->state ); self::assertSame( 1, $s->revision ); self::assertSame( 0, $s->row_id ); self::assertFalse( $s->initialized ); self::assertNull( $s->actor_user_id ); self::assertNull( $s->changed_at_epoch ); self::assertSame( '', $s->original_bytes() ); self::assertSame( [ 'state' => 'enabled', 'revision' => 1 ], $s->epoch() );
	}
	public function test_physical_codec_keeps_exact_noncanonical_opened_bytes(): void {
		$json = EmergencyControlState::record_json( 1, 'checkout_suspended', 2, 'operator_pause', 7, 1791331200 ); $spaced = " \n" . $json . " \t"; $s = EmergencyControlState::from_physical( 1, 33, $spaced );
		self::assertSame( 33, $s->row_id ); self::assertSame( 2, $s->revision ); self::assertTrue( $s->initialized ); self::assertSame( $spaced, $s->original_bytes() ); self::assertSame( 7, $s->actor_user_id ); self::assertSame( 1791331200, $s->changed_at_epoch ); self::assertFalse( $s->enabled() );
	}
	#[DataProvider( 'invalid_json' )]
	public function test_unknown_malformed_or_oversized_records_refuse( string $json ): void { $this->expectException( \InvalidArgumentException::class ); EmergencyControlState::from_physical( 1, 1, $json ); }
	public static function invalid_json(): array {
		$valid = [ 'format_version' => 1, 'site_id' => 1, 'state' => 'checkout_suspended', 'revision' => 2, 'reason_code' => 'operator_pause', 'actor_user_id' => 7, 'changed_at_epoch' => 1791331200 ]; $out = [];
		foreach ( [ 'format_version' => 2, 'site_id' => 2, 'state' => 'unknown', 'revision' => 1, 'reason_code' => 'resume_verified', 'actor_user_id' => 0, 'changed_at_epoch' => 0 ] as $field => $value ) { $out[$field] = [ json_encode( array_replace( $valid, [ $field => $value ] ), JSON_THROW_ON_ERROR ) ]; }
		foreach ( [ 'string_revision' => [ 'revision' => '2' ], 'float_revision' => [ 'revision' => 2.0 ], 'nested' => [ 'reason_code' => [ 'operator_pause' ] ], 'bool_actor' => [ 'actor_user_id' => true ], 'unknown' => [ 'private' => 'RAW_QUERY_TOKEN' ] ] as $name => $change ) { $out[$name] = [ json_encode( array_replace( $valid, $change ), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ]; }
		$json = json_encode( $valid, JSON_THROW_ON_ERROR ); $out['duplicate'] = [ str_replace( '"format_version":1', '"format_version":1,"format_version":1', $json ) ]; $out['escaped_duplicate'] = [ str_replace( '"format_version":1', '"format_version":1,"format_\\u0076ersion":1', $json ) ]; $out['too_large'] = [ str_repeat( ' ', 2049 ) . $json ]; $out['deep'] = [ '{"reason_code":' . str_repeat( '[', 20 ) . '0' . str_repeat( ']', 20 ) . '}' ]; $out['list'] = [ '[' . $json . ']' ]; $out['null'] = [ 'null' ]; $out['invalid_utf8'] = [ '{"state":"' . chr( 255 ) . '"}' ]; $out['missing'] = [ '{}' ];
		return $out;
	}
	public function test_original_preconditions_and_semantics_are_in_intent_but_token_is_not(): void {
		$absent = EmergencyControlState::absent( 1 ); $payload = EmergencyControlCommand::payload( $absent, 'checkout_suspended', 'operator_pause' ); $a = EmergencyControlCommand::from_payload( EmergencyControlCommand::identity( 1, 7, 'first-token' ), $payload ); $b = EmergencyControlCommand::from_payload( EmergencyControlCommand::identity( 1, 7, 'second-token' ), $payload );
		self::assertSame( $a->intent()->fingerprint(), $b->intent()->fingerprint() ); $different = EmergencyControlCommand::from_payload( $a->identity, array_replace( $payload, [ 'reason_code' => 'incident_pause' ] ) ); self::assertNotSame( $a->intent()->fingerprint(), $different->intent()->fingerprint() ); self::assertNotSame( $a->identity->namespace_digest(), $b->identity->namespace_digest() );
	}
	public function test_absent_precondition_cannot_hide_physical_bytes(): void { $p = EmergencyControlCommand::payload( EmergencyControlState::absent( 1 ), 'enabled', 'resume_verified' ); $p['opened_bytes'] = '{}'; $this->expectException( \InvalidArgumentException::class ); EmergencyControlCommand::from_payload( EmergencyControlCommand::identity( 1, 7, 'token' ), $p ); }
	public function test_foreign_authority_is_not_an_administrative_identity(): void { $this->expectException( \InvalidArgumentException::class ); EmergencyControlCommand::actor( new OperationIdentity( 1, 'shopper', 'wp-user:7', EmergencyControlCommand::OPERATION, 1, EmergencyControlCommand::TARGET, 'token' ) ); }
	public function test_implicit_json_disclosure_is_refused(): void { $this->expectException( \LogicException::class ); json_encode( EmergencyControlState::absent( 1 ), JSON_THROW_ON_ERROR ); }
}
