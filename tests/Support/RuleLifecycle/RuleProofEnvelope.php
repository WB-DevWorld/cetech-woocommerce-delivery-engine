<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\RuleLifecycle;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand;

/** Builds synthetic original envelopes; never refreshes scheduled preconditions. */
final class RuleProofEnvelope {
	public static function uuid( int $identity ): string { return sprintf( '00000000-0000-4000-8000-%012x', $identity ); }
	public static function scope( string $type = 'global', int $id = 0, int $parent = 0 ): array { return [ 'type' => $type, 'id' => $id, 'parent_id' => $parent ]; }
	public static function create( string $logical_uuid, string $version_uuid, ?array $scope = null ): array {
		return [ 'family' => 'fixture_availability_v1', 'logical_uuid' => $logical_uuid, 'version_uuid' => $version_uuid,
			'scope' => $scope ?? self::scope(), 'payload' => [ 'availability' => 'allow' ], 'start_mode' => 'immediate',
			'effective_from' => null, 'effective_until' => null, 'priority' => 0, 'author_user_id' => 9,
			'change_reason' => 'PRIVATE_SYNTHETIC_CHANGE_REASON', 'predecessor_uuid' => null,
			'preconditions' => [ 'family_revision' => 0, 'logical_id' => 0, 'logical_revision' => 0, 'version_id' => 0, 'version_revision' => 0, 'predecessor_id' => 0, 'predecessor_revision' => 0 ] ];
	}
	public static function identity( array $payload, string $operation, string $token = 'original', int $site = 1, string $principal = 'staff:9', string $authority = 'wordpress' ): OperationIdentity {
		$scope_hash = hash( 'sha256', 'cetech-rule-scope-v1:' . ( new RuleProofFamily() )->scope_schema()->encode( $payload['scope'], 4096 ) );
		return new OperationIdentity( $site, $authority, $principal, $operation, 1, RuleLifecycleCommand::make_target_key( $payload['family'], $scope_hash, $payload['logical_uuid'] ), $token );
	}
	public static function opened( \mysqli $database, string $prefix, string $logical_uuid, string $version_uuid, bool $activation = false ): array {
		$logical = self::logical( $database, $prefix, $logical_uuid ); $version = self::version( $database, $prefix, $version_uuid );
		$guard = RuleProofDatabase::row( $database, "SELECT * FROM `{$prefix}delivery_engine_rule_family_guards` WHERE id=" . (int) $logical['family_guard_id'] );
		$predecessor = null === $version['supersedes_version_id'] ? null : RuleProofDatabase::row( $database, "SELECT * FROM `{$prefix}delivery_engine_rule_versions` WHERE id=" . (int) $version['supersedes_version_id'] );
		return [ 'family' => $guard['family_code'], 'logical_uuid' => $logical['logical_uuid'], 'version_uuid' => $version['version_uuid'],
			'scope' => json_decode( $logical['scope_json'], true, 8, JSON_THROW_ON_ERROR ), 'payload' => json_decode( $version['payload_json'], true, 8, JSON_THROW_ON_ERROR ),
			'start_mode' => $version['start_mode'], 'effective_from' => $version['effective_from'], 'effective_until' => $version['effective_until'],
			'priority' => (int) $version['priority'], 'author_user_id' => (int) $version['author_user_id'], 'change_reason' => $version['change_reason'],
			'predecessor_uuid' => $predecessor['version_uuid'] ?? null,
			'preconditions' => [ 'family_revision' => $activation ? 0 : (int) $guard['revision'], 'logical_id' => (int) $logical['id'],
				'logical_revision' => (int) ( $activation ? $version['scheduled_logical_revision'] : $logical['revision'] ), 'version_id' => (int) $version['id'],
				'version_revision' => (int) ( $activation ? $version['scheduled_revision'] : $version['row_revision'] ),
				'predecessor_id' => (int) ( $predecessor['id'] ?? 0 ),
				'predecessor_revision' => $activation ? (int) ( $version['scheduled_predecessor_row_revision'] ?? 0 ) : (int) ( $predecessor['row_revision'] ?? 0 ) ] ];
	}
	public static function logical( \mysqli $database, string $prefix, string $uuid ): array {
		if ( 1 !== preg_match( '/\A[0-9a-f-]{36}\z/D', $uuid ) ) { throw new \InvalidArgumentException( 'Invalid disposable logical UUID.' ); }
		return RuleProofDatabase::row( $database, "SELECT * FROM `{$prefix}delivery_engine_logical_rules` WHERE logical_uuid='{$uuid}'" ) ?? throw new \RuntimeException( 'Disposable logical rule is missing.' );
	}
	public static function version( \mysqli $database, string $prefix, string $uuid ): array {
		if ( 1 !== preg_match( '/\A[0-9a-f-]{36}\z/D', $uuid ) ) { throw new \InvalidArgumentException( 'Invalid disposable version UUID.' ); }
		return RuleProofDatabase::row( $database, "SELECT * FROM `{$prefix}delivery_engine_rule_versions` WHERE version_uuid='{$uuid}'" ) ?? throw new \RuntimeException( 'Disposable rule version is missing.' );
	}
}
