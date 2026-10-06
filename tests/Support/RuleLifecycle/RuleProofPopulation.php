<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\RuleLifecycle;

use CetechDeliveryEngine\Domain\RuleLifecycle\LogicalRule;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyGuard;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;

/** Codec-validated physical read fixtures, not asserted accepted mutation history. */
final class RuleProofPopulation {
	public static function clone_pair( \mysqli $database, string $prefix, array $logical, array $version, int $identity, array $scope, ?int $priority = null ): array {
		OperationProofDatabase::validate_prefix( $prefix ); $family = new RuleProofFamily();
		$guard = RuleFamilyGuard::from_row( RuleProofDatabase::row( $database, "SELECT * FROM `{$prefix}delivery_engine_rule_family_guards` WHERE id=" . (int) $logical['family_guard_id'] ), $family );
		$logical['id'] = $identity; $logical['logical_uuid'] = RuleProofEnvelope::uuid( 100000 + $identity ); $logical['scope_json'] = $family->scope_schema()->encode( $scope, 4096 ); $logical['scope_hash'] = RuleContent::scope_hash( $family, $scope );
		foreach ( [ 'draft_version_id', 'scheduled_version_id', 'current_published_version_id' ] as $field ) { if ( null !== $logical[$field] ) { $logical[$field] = $identity; } }
		$version['id'] = $identity; $version['logical_rule_id'] = $identity; $version['version_uuid'] = RuleProofEnvelope::uuid( 200000 + $identity );
		if ( null !== $version['supersedes_version_id'] ) { throw new \InvalidArgumentException( 'Read population clone must have no predecessor.' ); }
		$typed_logical = LogicalRule::from_row( $logical, $guard, $family );
		if ( null !== $priority ) {
			$typed = RuleVersion::from_row( $version, $typed_logical, $family ); $version['priority'] = $priority;
			$version['content_hash'] = RuleContent::hash( $family, $typed->payload(), $typed->start_mode, $typed->effective_from, $typed->effective_until, $priority, null );
		}
		RuleVersion::from_row( $version, $typed_logical, $family );
		self::insert( $database, $prefix . 'delivery_engine_logical_rules', $logical ); self::insert( $database, $prefix . 'delivery_engine_rule_versions', $version );
		return [ $logical, $version ];
	}
	private static function insert( \mysqli $database, string $table, array $row ): void {
		$values = []; foreach ( $row as $value ) { $values[] = null === $value ? 'NULL' : ( is_int( $value ) ? (string) $value : "'" . $database->real_escape_string( (string) $value ) . "'" ); }
		RuleProofDatabase::execute( $database, "INSERT INTO `{$table}` (" . implode( ',', array_keys( $row ) ) . ') VALUES (' . implode( ',', $values ) . ')' );
	}
}
