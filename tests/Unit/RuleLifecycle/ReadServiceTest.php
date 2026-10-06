<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RuleLifecycle;

use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleReadService;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSnapshot;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleStartMode;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;
use PHPUnit\Framework\TestCase;

/** SQL-shaped owned-session seam. Actual MariaDB proofs live in the integration group. */
abstract class RuleApplicationTestCase extends TestCase {
	protected function rows( RuleProofFamily $profile, bool $scheduled = false ): array {
		$scope = RuleProofEnvelope::scope();
		$created = '2026-10-06 09:00:00.000000'; $updated = '2026-10-06 12:00:00.000000';
		$guard = [ 'id' => 1, 'site_id' => 1, 'family_code' => $profile->family(), 'family_format' => 1, 'policy_hash' => $profile->policy_hash(), 'revision' => 8, 'created_at' => $created, 'updated_at' => $updated ];
		$logical = [ 'id' => 10, 'site_id' => 1, 'family_guard_id' => 1, 'logical_uuid' => RuleProofEnvelope::uuid( 10 ), 'scope_format' => 1,
			'scope_json' => $profile->scope_schema()->encode( $scope, 4096 ), 'scope_hash' => RuleContent::scope_hash( $profile, $scope ), 'revision' => 4,
			'last_version_sequence' => $scheduled ? 2 : 1, 'current_published_version_id' => 100, 'draft_version_id' => null, 'scheduled_version_id' => $scheduled ? 101 : null,
			'created_at' => $created, 'updated_at' => $updated ];
		$payload = [ 'availability' => 'allow' ]; $from = RuleTime::parse( '2026-10-06 10:00:00.000000' );
		$version = [ 'id' => 100, 'site_id' => 1, 'logical_rule_id' => 10, 'version_uuid' => RuleProofEnvelope::uuid( 100 ), 'version_sequence' => 1, 'row_revision' => 2,
			'state' => 'published', 'payload_format' => 1, 'payload_json' => $profile->payload_schema()->encode( $payload, 16384 ), 'content_hash' => RuleContent::hash( $profile, $payload, RuleStartMode::Immediate, $from, null, 0, null ),
			'priority' => 0, 'start_mode' => 'immediate', 'effective_from' => $from->sql(), 'effective_until' => null, 'author_user_id' => 9, 'change_reason' => 'PRIVATE_FIXTURE_REASON',
			'supersedes_version_id' => null, 'scheduled_revision' => null, 'scheduled_logical_revision' => null, 'scheduled_predecessor_row_revision' => null,
			'sealed_at' => $from->sql(), 'scheduled_at' => null, 'published_at' => $from->sql(), 'retired_at' => null, 'created_at' => $created, 'updated_at' => $updated ];
		$versions = [ $version ];
		if ( $scheduled ) {
			$next = $version; $future = RuleTime::parse( '2026-10-06 13:00:00.000000' );
			$next['id'] = 101; $next['version_uuid'] = RuleProofEnvelope::uuid( 101 ); $next['version_sequence'] = 2; $next['state'] = 'scheduled'; $next['start_mode'] = 'at';
			$next['payload_json'] = $profile->payload_schema()->encode( [ 'availability' => 'deny' ], 16384 );
			$next['effective_from'] = $future->sql(); $next['published_at'] = null; $next['scheduled_at'] = '2026-10-06 11:00:00.000000'; $next['sealed_at'] = $next['scheduled_at'];
			$next['supersedes_version_id'] = 100; $next['scheduled_revision'] = 2; $next['scheduled_logical_revision'] = 4; $next['scheduled_predecessor_row_revision'] = 2;
			$next['content_hash'] = RuleContent::hash( $profile, [ 'availability' => 'deny' ], RuleStartMode::At, $future, null, 0, 100 ); $versions[] = $next;
		}
		return [ 'family' => $guard, 'logicals' => [ $logical ], 'versions' => $versions ];
	}

	protected function actor( string $operation = 'rule.publish', string $principal = 'staff:9', string $authority = 'wordpress' ): OperationIdentity {
		return new OperationIdentity( 1, $authority, $principal, $operation, 1, 'server-resolved-fixture-target', 'caller-token' );
	}

	protected function fixture( array $rows, RuleProofFamily $profile, ?callable $on_read = null ): array {
		$stats = (object) [ 'opens' => 0, 'begins' => 0, 'rollbacks' => 0, 'retirements' => 0, 'sql' => [], 'ambient' => false, 'rollback_ok' => true,
			'rows' => $rows, 'due_rows' => [], 'ceiling' => 101, 'fail_due' => false ];
		$builder = function () use ( $stats, $on_read ): OperationSession {
			++$stats->opens; $state = (object) [ 'active' => $stats->ambient, 'retired' => false ];
			$session = $this->createMock( OperationSession::class );
			$session->method( 'site_id' )->willReturn( 1 ); $session->method( 'table_prefix' )->willReturn( 'proof_' );
			$session->method( 'in_transaction' )->willReturnCallback( static fn (): bool => $state->active ); $session->method( 'is_retired' )->willReturnCallback( static fn (): bool => $state->retired );
			$session->method( 'begin' )->willReturnCallback( static function () use ( $stats, $state ): bool { ++$stats->begins; $state->active = true; return true; } );
			$session->method( 'rollback' )->willReturnCallback( static function () use ( $stats, $state ): bool { ++$stats->rollbacks; $state->active = false; return $stats->rollback_ok; } );
			$session->method( 'retire' )->willReturnCallback( static function () use ( $stats, $state ): bool { ++$stats->retirements; $state->active = false; $state->retired = true; return true; } );
			$session->method( 'validate_tables' )->willReturnCallback( static function ( array $tables ): bool { self::assertSame( RuleLifecycleSchema::tables( 'proof_' ), $tables ); return true; } );
			$session->expects( self::never() )->method( 'query' ); $session->expects( self::never() )->method( 'commit' );
			$session->method( 'prepare' )->willReturnCallback( static function ( string $sql, mixed ...$args ): string { $i = 0; return preg_replace_callback( '/%[ds]/', static function ( array $match ) use ( &$i, $args ): string { $value = $args[$i++]; return '%d' === $match[0] ? (string) $value : "'" . str_replace( "'", "''", (string) $value ) . "'"; }, $sql ); } );
			$session->method( 'get_results' )->willReturnCallback( static function ( string $sql ) use ( $stats, $state, $on_read ): array|false {
				self::assertTrue( $state->active ); $stats->sql[] = $sql; if ( null !== $on_read ) { $on_read(); }
				if ( str_contains( $sql, "v.state='scheduled'" ) ) {
					if ( $stats->fail_due ) { return false; }
					preg_match( '/v.id<=(\d+)/', $sql, $ceiling ); preg_match( '/v.id>(\d+)/', $sql, $cursor );
					return array_slice( array_values( array_filter( $stats->due_rows, static fn ( array $row ): bool => $row['id'] <= (int) $ceiling[1] && ( ! isset( $cursor[1] ) || $row['id'] > (int) $cursor[1] ) ) ), 0, 25 );
				}
				return self::joined_family( $stats->rows );
			} );
			$session->method( 'get_row' )->willReturnCallback( static function ( string $sql ) use ( $stats, $state ): ?array {
				self::assertTrue( $state->active ); $stats->sql[] = $sql;
				if ( str_contains( $sql, 'MAX(v.id)' ) ) { return [ 'ceiling' => $stats->ceiling ]; }
				if ( str_contains( $sql, 'p__id' ) ) {
					preg_match( "/v.version_uuid='([^']+)'/", $sql, $match );
					foreach ( $stats->rows['versions'] as $version ) { if ( $version['version_uuid'] === ( $match[1] ?? '' ) ) { return self::joined_version( $stats->rows, $version ); } }
					return null;
				}
				return $stats->rows['family'];
			} );
			return $session;
		};
		$factory = new class( $builder ) implements OperationConnectionFactory { public function __construct( private \Closure $builder ) {} public function open(): OperationSession { return ( $this->builder )(); } };
		$readiness = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} };
		$registry = new RuleFamilyRegistry( [ $profile ] );
		return [ new RuleLifecycleReadService( $registry, $factory, $readiness ), $stats, $factory, $readiness, $registry ];
	}

	private static function aliases( ?array $row, string $suffix, string $key ): array {
		$out = []; foreach ( array_keys( RuleLifecycleSchema::columns( $suffix ) ) as $column ) { $out[$key . '__' . $column] = $row[$column] ?? null; } return $out;
	}
	private static function joined_family( array $rows ): array {
		if ( null === $rows['family'] ) { return []; }
		$out = [];
		foreach ( $rows['logicals'] as $logical ) {
			$versions = array_values( array_filter( $rows['versions'], static fn ( array $row ): bool => $row['logical_rule_id'] === $logical['id'] && ( in_array( $row['state'], [ 'draft', 'published', 'scheduled' ], true ) || in_array( $row['id'], [ $logical['current_published_version_id'], $logical['draft_version_id'], $logical['scheduled_version_id'] ], true ) ) ) );
			foreach ( [] === $versions ? [ null ] : $versions as $version ) {
				$prior = null;
				if ( null !== $version ) { foreach ( $rows['versions'] as $row ) { if ( $row['id'] === $version['supersedes_version_id'] ) { $prior = $row; } } }
				$out[] = self::aliases( $rows['family'], 'rule_family_guards', 'f' ) + self::aliases( $logical, 'logical_rules', 'l' ) + self::aliases( $version, 'rule_versions', 'v' ) + self::aliases( $prior, 'rule_versions', 'p' );
			}
		}
		return [] === $out ? [ self::aliases( $rows['family'], 'rule_family_guards', 'f' ) + self::aliases( null, 'logical_rules', 'l' ) + self::aliases( null, 'rule_versions', 'v' ) + self::aliases( null, 'rule_versions', 'p' ) ] : $out;
	}
	private static function joined_version( array $rows, array $version ): array {
		$logical = null; $prior = null;
		foreach ( $rows['logicals'] as $row ) { if ( $row['id'] === $version['logical_rule_id'] ) { $logical = $row; } }
		foreach ( $rows['versions'] as $row ) { if ( $row['id'] === $version['supersedes_version_id'] ) { $prior = $row; } }
		return self::aliases( $rows['family'], 'rule_family_guards', 'f' ) + self::aliases( $logical, 'logical_rules', 'l' ) + self::aliases( $version, 'rule_versions', 'v' ) + self::aliases( $prior, 'rule_versions', 'p' );
	}
}

final class ReadServiceTest extends RuleApplicationTestCase {
	public function test_capture_uses_one_joined_generation_and_only_rollback(): void {
		$profile = new RuleProofFamily(); [ $reader, $stats ] = $this->fixture( $this->rows( $profile ), $profile );
		$snapshot = $reader->capture( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), RequestContext::create() );
		self::assertInstanceOf( RuleSnapshot::class, $snapshot ); self::assertTrue( $snapshot->complete ); self::assertSame( 8, $snapshot->guard->revision );
		self::assertCount( 1, $stats->sql ); self::assertStringContainsString( 'LEFT JOIN', $stats->sql[0] ); self::assertSame( 1, $stats->rollbacks ); self::assertGreaterThanOrEqual( 1, $stats->retirements );
	}
	public function test_denied_scope_loads_nothing(): void {
		$profile = new RuleProofFamily(); $profile->allowed = false; [ $reader, $stats ] = $this->fixture( $this->rows( $profile ), $profile );
		$error = $reader->capture( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'not_authorized', $error->code ); self::assertSame( 0, $stats->opens );
	}
	public function test_revocation_during_capture_does_not_disclose_snapshot(): void {
		$profile = new RuleProofFamily(); [ $reader ] = $this->fixture( $this->rows( $profile ), $profile, static function () use ( $profile ): void { $profile->allowed = false; } );
		$error = $reader->capture( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'not_authorized', $error->code ); self::assertStringNotContainsString( 'PRIVATE_FIXTURE_REASON', json_encode( $error->to_array(), JSON_THROW_ON_ERROR ) );
	}
	public function test_broad_capture_refuses_a_denied_other_product_scope(): void {
		$profile = new RuleProofFamily( static fn ( OperationIdentity $identity, array $scope ): bool => 20 !== $scope['id'] ); $rows = $this->rows( $profile );
		$rows['logicals'][0]['scope_json'] = $profile->scope_schema()->encode( RuleProofEnvelope::scope( 'product', 20 ), 4096 ); $rows['logicals'][0]['scope_hash'] = RuleContent::scope_hash( $profile, RuleProofEnvelope::scope( 'product', 20 ) );
		[ $reader, $stats ] = $this->fixture( $rows, $profile );
		$error = $reader->capture( $this->actor(), $profile->family(), RuleProofEnvelope::scope( 'product', 10 ), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'not_authorized', $error->code ); self::assertSame( 1, $stats->opens ); self::assertStringNotContainsString( 'PRIVATE_FIXTURE_REASON', json_encode( $error->to_array(), JSON_THROW_ON_ERROR ) );
	}
	public function test_decision_authorizes_subject_before_loading(): void {
		$profile = new RuleProofFamily( static fn ( OperationIdentity $identity, array $scope ): bool => 20 !== $scope['id'] ); [ $reader, $stats ] = $this->fixture( $this->rows( $profile ), $profile );
		$error = $reader->decision( $this->actor(), $profile->family(), RuleProofEnvelope::scope( 'product', 10 ), RuleProofEnvelope::scope( 'product', 20 ), RuleTime::parse( '2026-10-06 12:00:00.000000' ), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'not_authorized', $error->code ); self::assertSame( 0, $stats->opens );
	}
	public function test_absent_family_remains_absent_and_has_no_fabricated_revision(): void {
		$profile = new RuleProofFamily(); [ $reader ] = $this->fixture( [ 'family' => null, 'logicals' => [], 'versions' => [] ], $profile );
		$decision = $reader->decision( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), RuleProofEnvelope::scope(), RuleTime::parse( '2026-10-06 12:00:00.000000' ), RequestContext::create() );
		self::assertNull( $decision->selected ); self::assertNull( $decision->family_revision ); self::assertSame( 'rule_unavailable', $decision->reason );
	}
	public function test_retired_only_history_is_not_an_incomplete_current_candidate_capture(): void {
		$profile = new RuleProofFamily(); $rows = $this->rows( $profile ); $rows['logicals'][0]['current_published_version_id'] = null;
		$rows['versions'][0]['state'] = 'retired'; $rows['versions'][0]['retired_at'] = '2026-10-06 12:00:00.000000';
		[ $reader ] = $this->fixture( $rows, $profile );
		$snapshot = $reader->capture( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), RequestContext::create() );
		self::assertInstanceOf( RuleSnapshot::class, $snapshot ); self::assertTrue( $snapshot->complete ); self::assertSame( [], $snapshot->versions );
	}
	public function test_failed_read_rollback_does_not_return_private_data(): void {
		$profile = new RuleProofFamily(); [ $reader, $stats ] = $this->fixture( $this->rows( $profile ), $profile ); $stats->rollback_ok = false;
		$error = $reader->capture( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'temporarily_unavailable', $error->code ); self::assertGreaterThanOrEqual( 1, $stats->retirements );
	}
	public function test_ambient_owner_is_refused_without_query_or_begin(): void {
		$profile = new RuleProofFamily(); [ $reader, $stats ] = $this->fixture( $this->rows( $profile ), $profile ); $stats->ambient = true;
		$error = $reader->capture( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( [], $stats->sql ); self::assertSame( 0, $stats->begins ); self::assertSame( 0, $stats->rollbacks );
	}
	public function test_empty_registry_refuses_without_any_family_adopter_lookup(): void {
		$profile = new RuleProofFamily(); [ , $stats, $factory, $readiness ] = $this->fixture( $this->rows( $profile ), $profile );
		$reader = new RuleLifecycleReadService( new RuleFamilyRegistry(), $factory, $readiness );
		$error = $reader->capture( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'unsupported_contract', $error->code ); self::assertSame( 0, $stats->opens );
	}
}
