<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** Versioned canonical hashes; no retry metadata or execution time is inferred. */
final class RuleContent {
	public static function scope_hash( RuleFamilyProfile $profile, array $scope ): string {
		return hash( 'sha256', 'cetech-rule-scope-v1:' . $profile->scope_schema()->encode( $scope, 4096 ) );
	}

	public static function hash( RuleFamilyProfile $profile, array $payload, RuleStartMode $start_mode, ?RuleTime $from, ?RuleTime $until, int $priority, ?int $predecessor ): string {
		RuleRecordCodec::priority( $priority );
		if ( null !== $predecessor ) { RuleRecordCodec::integer( $predecessor ); }
		RuleRecordCodec::digest( $profile->policy_hash() );
		$payload_json = $profile->payload_schema()->encode( $payload, 16384 );
		$encoded = json_encode( [ 'payload_format' => $profile->version(), 'payload' => json_decode( $payload_json, false, 8, JSON_THROW_ON_ERROR ), 'start_mode' => $start_mode->value, 'effective_from' => $from?->sql(), 'effective_until' => $until?->sql(), 'priority' => $priority, 'supersedes_version_id' => $predecessor, 'policy_hash' => $profile->policy_hash() ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', 'cetech-rule-content-v1:' . $encoded );
	}
}
