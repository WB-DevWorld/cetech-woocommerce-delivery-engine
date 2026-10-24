<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\RefundPolicy;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\RefundPolicy\{RefundPolicyContent, RefundPolicyFamily};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;

/** Original synthetic Refund-only envelopes for the explicitly disposable SQL lane. */
final class RefundPolicyProofEnvelope {
	public const NOW = '2026-10-24 10:00:00.000000';

	public static function uuid( int $id ): string { return RuleProofEnvelope::uuid( $id ); }

	public static function content( array $changes = [] ): RefundPolicyContent {
		return RefundPolicyContent::from_array( array_replace( [
			'name' => 'Disposable Refund Policy',
			'customer_title' => 'Synthetic refunds',
			'summary' => "Fixture summary.\nUnicode supplied: café.",
			'full_text' => "Test-only text.\r\n\tSupplied <em>markup</em> is preserved.",
			'exceptions' => '',
		], $changes ) );
	}

	public static function family( ?callable $binding = null, ?callable $scope_grant = null ): RefundPolicyFamily {
		return new RefundPolicyFamily(
			$scope_grant ?? static fn( OperationIdentity $identity, array $scope ): bool =>
				1 === $identity->site_id && 'wordpress' === $identity->authority && in_array( $identity->principal, [ 'staff:9', 'staff:10' ], true ),
			static fn( int $site, int $author, array $scope ): bool => 1 === $site && in_array( $author, [ 9, 10 ], true ),
			$binding ?? static fn( OperationIdentity $identity, int $author, array $scope ): bool => 'staff:' . $author === $identity->principal
		);
	}

	public static function create( int $logical = 1, int $version = 101, array $content_changes = [] ): array {
		return [
			'family' => RefundPolicyContent::FAMILY,
			'logical_uuid' => self::uuid( $logical ), 'version_uuid' => self::uuid( $version ),
			'scope' => [ 'policy_uuid' => self::uuid( $logical ) ],
			'payload' => self::content( $content_changes )->to_rule_payload(),
			'start_mode' => 'immediate', 'effective_from' => null, 'effective_until' => null,
			'priority' => 0, 'author_user_id' => 9, 'change_reason' => 'Disposable Refund catalog proof',
			'predecessor_uuid' => null,
			'preconditions' => [
				'family_revision' => 0, 'logical_id' => 0, 'logical_revision' => 0,
				'version_id' => 0, 'version_revision' => 0, 'predecessor_id' => 0, 'predecessor_revision' => 0,
			],
		];
	}

	public static function identity( array $payload, string $operation, string $token = 'original', int $site = 1, string $principal = 'staff:9' ): OperationIdentity {
		$scope_hash = hash( 'sha256', 'cetech-rule-scope-v1:' . self::family()->scope_schema()->encode( $payload['scope'], 4096 ) );
		return new OperationIdentity( $site, 'wordpress', $principal, $operation, 1,
			RuleLifecycleCommand::make_target_key( $payload['family'], $scope_hash, $payload['logical_uuid'] ), $token );
	}

	public static function read_identity( string $principal = 'staff:9' ): OperationIdentity {
		return new OperationIdentity( 1, 'wordpress', $principal, 'refund.policy.catalog.read', 1, 'refund-policy-catalog', 'catalog-read' );
	}

	/** Uses genuinely opened rows; callers retain this original envelope for retries. */
	public static function opened( \mysqli $database, string $prefix, int $logical = 1, int $version = 101 ): array {
		return RuleProofEnvelope::opened( $database, $prefix, self::uuid( $logical ), self::uuid( $version ) );
	}

	public static function logical( \mysqli $database, string $prefix, int $logical = 1 ): array {
		return RuleProofEnvelope::logical( $database, $prefix, self::uuid( $logical ) );
	}

	public static function version( \mysqli $database, string $prefix, int $version = 101 ): array {
		return RuleProofEnvelope::version( $database, $prefix, self::uuid( $version ) );
	}
}
