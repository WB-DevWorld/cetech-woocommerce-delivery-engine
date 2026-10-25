<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\PolicyAssignment;

use CetechDeliveryEngine\Application\PolicyAssignment\{PolicyAssignmentCurrentService, PolicyAssignmentService, PolicyCurrentCatalog};
use CetechDeliveryEngine\Application\Configuration\{EffectiveConfigurationResolver, EffectiveConfigurationValidator};
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationPhaseObserver};
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand, PolicyAssignmentState};
use CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyFamily;
use CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyFamily;
use CetechDeliveryEngine\Infrastructure\Persistence\{InMemoryScopedConfigurationRepository, PolicyAssignmentStore};
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;

/** Original finite assignment envelopes for the explicitly disposable physical SQL lane. */
final class PolicyAssignmentProofEnvelope {

	public const NOW = '2026-10-24 10:00:00.000000';
	public const RETURN = 'return_policy_v1';
	public const REFUND = 'refund_policy_v1';

	public static function uuid( int $id ): string { return RuleProofEnvelope::uuid( $id ); }

	public static function families(): array {
		$scope = static fn( OperationIdentity $identity, array $scope ): bool => 1 === $identity->site_id && 'staff:9' === $identity->principal && in_array( $identity->authority, [ 'wordpress', PolicyAssignmentCommand::AUTHORITY ], true );
		$author = static fn( int $site, int $author, array $scope ): bool => 1 === $site && 9 === $author;
		$bound = static fn( OperationIdentity $identity, int $author, array $scope ): bool => 1 === $identity->site_id && 9 === $author && 'staff:9' === $identity->principal;
		return [ new ReturnPolicyFamily( $scope, $author, $bound ), new RefundPolicyFamily( $scope, $author, $bound ) ];
	}

	public static function authorize( OperationIdentity $identity, ?int $actor = null ): bool {
		return 1 === $identity->site_id && PolicyAssignmentCommand::AUTHORITY === $identity->authority && 'staff:9' === $identity->principal
			&& in_array( $identity->operation, [ PolicyAssignmentCommand::OPERATION, 'policy.assignment.read' ], true ) && 1 === $identity->operation_version && ( null === $actor || 9 === $actor );
	}

	public static function identity( array $coordinate, int $offer = 0, string $token = 'original', string $operation = PolicyAssignmentCommand::OPERATION ): OperationIdentity {
		return new OperationIdentity( $coordinate['site_id'], PolicyAssignmentCommand::AUTHORITY, 'staff:9', $operation, 1,
			PolicyAssignmentCommand::make_target( $coordinate['scope_type'], $coordinate['scope_id'], $offer ), $token );
	}

	public static function catalog( OperationIdentity $identity ): PolicyCurrentCatalog {
		[ $return, $refund ] = self::families();
		return new PolicyCurrentCatalog( $return, $refund, $identity );
	}

	public static function store( string $prefix ): PolicyAssignmentStore {
		return new PolicyAssignmentStore( 1, $prefix, static fn(): bool => true );
	}

	public static function service( OperationConnectionFactory $factory, string $prefix, OperationIdentity $identity, ?OperationPhaseObserver $observer = null ): PolicyAssignmentService {
		return new PolicyAssignmentService( $factory, self::store( $prefix ), self::catalog( $identity ), self::authorize( ... ), observer: $observer );
	}

	public static function reader( OperationConnectionFactory $factory, string $prefix, OperationIdentity $identity ): PolicyAssignmentCurrentService {
		return new PolicyAssignmentCurrentService( $factory, self::store( $prefix ), self::catalog( $identity ),
			new EffectiveConfigurationResolver( new InMemoryScopedConfigurationRepository(), new EffectiveConfigurationValidator() ), self::authorize( ... ) );
	}

	public static function witness( \mysqli $database, string $prefix, string $family, string $uuid ): array {
		$statement = $database->prepare( "SELECT l.logical_uuid,l.revision AS logical_revision,v.version_uuid,v.row_revision AS version_revision,v.content_hash,g.revision AS guard_revision FROM {$prefix}delivery_engine_rule_family_guards g JOIN {$prefix}delivery_engine_logical_rules l ON l.site_id=g.site_id AND l.family_guard_id=g.id JOIN {$prefix}delivery_engine_rule_versions v ON v.site_id=l.site_id AND v.logical_rule_id=l.id AND v.id=l.current_published_version_id WHERE g.site_id=1 AND g.family_code=? AND l.logical_uuid=?" );
		if ( false === $statement || ! $statement->bind_param( 'ss', $family, $uuid ) || ! $statement->execute() ) { throw new \RuntimeException( 'Owned policy witness unavailable.' ); }
		$result = $statement->get_result();
		$row = false === $result ? null : $result->fetch_assoc();
		if ( false !== $result ) { $result->free(); } $statement->close();
		if ( ! is_array( $row ) ) { throw new \RuntimeException( 'Owned current published witness absent.' ); }
		return [ 'policy_uuid' => $row['logical_uuid'], 'version_uuid' => $row['version_uuid'], 'logical_revision' => (int) $row['logical_revision'],
			'version_revision' => (int) $row['version_revision'], 'content_hash' => $row['content_hash'], 'guard_revision' => (int) $row['guard_revision'] ];
	}

	public static function payload( PolicyAssignmentState $opened, int $offer, array $instructions, array $reviewed, string $reason = 'Owned assignment physical proof', ?string $generation = null ): array {
		return [ 'format' => 1, 'coordinate' => $opened->coordinate(), 'offer_id' => $offer,
			'opened' => [ 'row_id' => $opened->row_id(), 'bytes' => $opened->original_bytes() ], 'instructions' => $instructions, 'reviewed' => $reviewed,
			'actor_user_id' => 9, 'reason' => $reason, 'generation' => $opened->generation() ?? $generation ?? self::uuid( 900 ) ];
	}

	public static function slots( string $return_mode = 'inherit', string $refund_mode = 'inherit', int $return_uuid = 1, int $refund_uuid = 2 ): array {
		$slot = static fn( string $mode, int $uuid ): array => 'assigned' === $mode ? [ 'mode' => $mode, 'policy_uuid' => self::uuid( $uuid ) ] : [ 'mode' => $mode ];
		return [ self::RETURN => $slot( $return_mode, $return_uuid ), self::REFUND => $slot( $refund_mode, $refund_uuid ) ];
	}
}
