<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand as Command, PolicyAssignmentMap as Map, PolicyAssignmentState as State};
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentDomainFixture as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyAssignmentCommandTest extends TestCase {
	public function test_original_intent_survives_export_and_new_token_without_reconstructing_current_state(): void {
		$data = F::payload(); $command = F::command( $data ); $copy = F::command( $command->payload(), 'fresh-token' );
		self::assertInstanceOf( OperationCommand::class, $command );
		self::assertSame( $data, $command->payload() ); self::assertSame( $command->intent()->fingerprint(), $copy->intent()->fingerprint() );
		self::assertNotSame( $command->identity->namespace_digest(), $copy->identity->namespace_digest() );
		self::assertSame( 'opaque:staff', $command->identity->principal ); self::assertSame( 9, $command->actor_user_id() );
		$changed = $data; $changed['reason'] = 'A distinct original reason';
		self::assertNotSame( $command->intent()->fingerprint(), F::command( $changed )->intent()->fingerprint() );
		$changed = $command->payload(); $changed['coordinate']['scope_id'] = 99;
		self::assertSame( 33, $command->coordinate()['scope_id'] );
	}
	public function test_exact_selected_slots_patch_both_maps_without_losing_other_scopes_or_offers(): void {
		$returns = Map::empty( 1, 'return_policy_v1' )->with_instruction( 0, F::slot( 101 ) )->with_instruction( 7, F::slot( 102 ) )->with_instruction( 9, [ 'mode' => 'disabled' ] );
		$refunds = Map::empty( 1, 'refund_policy_v1' )->with_instruction( 0, [ 'mode' => 'disabled' ] )->with_instruction( 7, F::slot( 201 ) );
		$before = F::state( returns: $returns, refunds: $refunds ); $data = F::payload( $before, 7 );
		$data['instructions']['return_policy_v1'] = F::slot( 103 ); $data['reviewed']['return_policy_v1'] = F::witness( 103 );
		$command = F::command( $data ); $after = $command->desired( '2026-10-24 12:00:01.000000', $before->row_id() );
		self::assertFalse( $command->no_change() ); self::assertSame( 2, $after->revision() ); self::assertSame( $before->generation(), $after->generation() );
		self::assertSame( F::slot( 101 ), $after->map( 'return_policy_v1' )->facts()['default'] );
		self::assertSame( [ 'mode' => 'disabled' ], $after->map( 'return_policy_v1' )->instruction( 9 )['instruction'] );
		self::assertSame( F::slot( 103 ), $after->map( 'return_policy_v1' )->instruction( 7 )['instruction'] );
		self::assertSame( [ 'mode' => 'inherit' ], $after->map( 'refund_policy_v1' )->facts()['offers']->{'7'} );
		self::assertSame( [ 'mode' => 'disabled' ], $after->map( 'refund_policy_v1' )->facts()['default'] );
		self::assertSame( $before->original_bytes(), $command->opened()->original_bytes() );
		self::assertSame( $command->desired_bytes( '2026-10-24 12:00:01.000000' ), $after->original_bytes() );
	}
	public function test_absent_creation_uses_real_row_identity_and_no_change_preserves_absence_or_original_audit(): void {
		$data = F::payload(); $command = F::command( $data );
		self::assertSame( 0, $command->opened()->row_id() ); self::assertSame( 1, $command->desired( F::NOW, 72 )->revision() );
		self::assertSame( 72, $command->desired( F::NOW, 72 )->row_id() );
		$data['instructions']['return_policy_v1'] = [ 'mode' => 'inherit' ]; $empty = F::command( $data );
		self::assertTrue( $empty->no_change() ); self::assertSame( '', $empty->desired_bytes( F::NOW ) ); self::assertSame( 0, $empty->desired( F::NOW, 0 )->row_id() );
		$state = F::state(); $same = F::payload( $state ); $same['instructions']['return_policy_v1'] = [ 'mode' => 'inherit' ];
		$noop = F::command( $same ); self::assertTrue( $noop->no_change() ); self::assertSame( $state->original_bytes(), $noop->desired_bytes( F::NOW ) );
	}
	public function test_unchanged_assigned_slot_still_requires_a_reviewed_current_publication(): void {
		$state = F::state( returns: Map::empty( 1, 'return_policy_v1' )->with_instruction( 0, F::slot() ) );
		$data = F::payload( $state ); $data['instructions']['return_policy_v1'] = F::slot();
		$this->expectException( \InvalidArgumentException::class ); F::command( $data );
	}
	#[DataProvider( 'bad_payloads' )]
	public function test_original_command_refuses_ambiguous_or_forged_frames( \Closure $change ): void {
		$data = F::payload(); $change( $data );
		$this->expectException( \InvalidArgumentException::class ); Command::from_array( F::identity( F::payload() ), $data );
	}
	public static function bad_payloads(): array {
		$assigned = static function( &$d ): void { $d['instructions']['return_policy_v1'] = F::slot(); $d['reviewed']['return_policy_v1'] = F::witness(); };
		return [
			'extra authority' => [ static function( &$d ) { $d['authority'] = 'attacker'; } ],
			'wrong format' => [ static function( &$d ) { $d['format'] = 2; } ],
			'foreign site' => [ static function( &$d ) { $d['coordinate']['site_id'] = 2; } ],
			'offer negative' => [ static function( &$d ) { $d['offer_id'] = -1; } ],
			'offer target drift' => [ static function( &$d ) { $d['offer_id'] = 1; } ],
			'row without bytes' => [ static function( &$d ) { $d['opened']['row_id'] = 1; } ],
			'bytes without row' => [ static function( &$d ) { $d['opened']['bytes'] = F::state()->original_bytes(); } ],
			'row string' => [ static function( &$d ) { $d['opened']['row_id'] = '0'; } ],
			'bad generation' => [ static function( &$d ) { $d['generation'] = 'new'; } ],
			'actor zero' => [ static function( &$d ) { $d['actor_user_id'] = 0; } ],
			'blank reason' => [ static function( &$d ) { $d['reason'] = ' '; } ],
			'invalid UTF8' => [ static function( &$d ) { $d['reason'] = "\xff"; } ],
			'control reason' => [ static function( &$d ) { $d['reason'] = "reason\n"; } ],
			'long reason' => [ static function( &$d ) { $d['reason'] = str_repeat( 'x', 1001 ); } ],
			'missing family' => [ static function( &$d ) { unset( $d['instructions']['refund_policy_v1'] ); } ],
			'foreign family' => [ static function( &$d ) { $d['instructions']['promise'] = [ 'mode' => 'inherit' ]; } ],
			'unsupported mode' => [ static function( &$d ) { $d['instructions']['return_policy_v1'] = [ 'mode' => 'current' ]; } ],
			'extra unassigned witness' => [ static function( &$d ) { $d['reviewed']['return_policy_v1'] = F::witness(); } ],
			'missing assigned witness' => [ static function( &$d ) { $d['instructions']['return_policy_v1'] = F::slot(); } ],
			'wrong reviewed logical' => [ static function( &$d ) use ( $assigned ) { $assigned( $d ); $d['reviewed']['return_policy_v1']['policy_uuid'] = F::uuid( 102 ); } ],
			'bad reviewed version' => [ static function( &$d ) use ( $assigned ) { $assigned( $d ); $d['reviewed']['return_policy_v1']['version_uuid'] = 'x'; } ],
			'bad reviewed hash' => [ static function( &$d ) use ( $assigned ) { $assigned( $d ); $d['reviewed']['return_policy_v1']['content_hash'] = str_repeat( 'z', 64 ); } ],
			'string guard revision' => [ static function( &$d ) use ( $assigned ) { $assigned( $d ); $d['reviewed']['return_policy_v1']['guard_revision'] = '10'; } ],
			'zero logical revision' => [ static function( &$d ) use ( $assigned ) { $assigned( $d ); $d['reviewed']['return_policy_v1']['logical_revision'] = 0; } ],
			'foreign witness field' => [ static function( &$d ) use ( $assigned ) { $assigned( $d ); $d['reviewed']['return_policy_v1']['family'] = 'refund_policy_v1'; } ],
		];
	}
	public function test_generation_row_and_revision_are_original_preconditions(): void {
		$state = F::state(); $data = F::payload( $state ); $command = F::command( $data );
		foreach ( [ static fn() => $command->desired( F::NOW, 51 ),
			static function() use ( $data ) { $data['generation'] = F::uuid( 902 ); return F::command( $data ); } ] as $change ) {
			try { $change(); self::fail( 'Original frame changed.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		}
		$advanced = F::payload( F::state( revision: 2 ) );
		self::assertNotSame( $command->intent()->fingerprint(), F::command( $advanced )->intent()->fingerprint() );
	}
	#[DataProvider( 'wrong_identities' )]
	public function test_identity_site_operation_version_and_target_are_exact( int $site, string $operation, int $version, string $target ): void {
		$identity = new OperationIdentity( $site, 'opaque-authority', 'opaque-principal', $operation, $version, $target, 'token' );
		$this->expectException( \InvalidArgumentException::class ); Command::from_array( $identity, F::payload() );
	}
	public static function wrong_identities(): array {
		return [ [ 2, Command::OPERATION, 1, 'policy-assignment:product:33:offer:0' ], [ 1, 'rule.publish', 1, 'policy-assignment:product:33:offer:0' ],
			[ 1, Command::OPERATION, 2, 'policy-assignment:product:33:offer:0' ], [ 1, Command::OPERATION, 1, 'policy-assignment:product:34:offer:0' ] ];
	}
	public function test_complete_escaped_original_budget_is_checked_without_truncating_or_using_cache(): void {
		$returns = Map::empty( 1, 'return_policy_v1' ); $refunds = Map::empty( 1, 'refund_policy_v1' );
		for ( $i = 0; $i < 32; ++$i ) { $returns = $returns->with_instruction( PHP_INT_MAX - $i, F::slot( 100 + $i ) ); $refunds = $refunds->with_instruction( PHP_INT_MAX - $i, F::slot( 200 + $i ) ); }
		$state = F::state( returns: $returns, refunds: $refunds ); $data = F::payload( $state, PHP_INT_MAX );
		$command = F::command( $data ); self::assertSame( $data, $command->payload() );
		self::assertLessThanOrEqual( 16384, strlen( json_encode( $command->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	}
	public function test_assignment_clock_cannot_reverse_opened_state_even_for_no_change(): void {
		$state = F::state(); $changed = F::command( F::payload( $state ) ); $data = F::payload( $state );
		$data['instructions']['return_policy_v1'] = [ 'mode' => 'inherit' ]; $same = F::command( $data );
		self::assertTrue( $same->no_change() );
		foreach ( [ $changed, $same ] as $command ) {
			try { $command->desired_bytes( F::START ); self::fail( 'Clock reversal accepted.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
			self::assertIsString( $command->desired_bytes( F::NOW ) );
		}
	}
}
