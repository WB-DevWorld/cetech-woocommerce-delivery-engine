<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DataLifecycle;

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecyclePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataLifecyclePolicyTest extends TestCase {

	#[DataProvider( 'intent_provider' )]
	public function test_explicit_uninstall_intent_does_not_use_loose_truthiness( mixed $value, bool $supported ): void {
		self::assertSame( $supported, DataLifecycleManifest::supports_uninstall_intent( $value ) );
	}

	public static function intent_provider(): array {
		return [ 'saved integer' => [ 1, true ], 'saved WordPress string' => [ '1', true ], 'boolean' => [ true, false ],
			'float' => [ 1.0, false ], 'false' => [ false, false ], 'absent' => [ null, false ], 'zero' => [ 0, false ],
			'array' => [ [ 1 ], false ], 'object' => [ new \stdClass(), false ], 'leading space' => [ ' 1', false ],
			'trailing space' => [ '1 ', false ], 'suffix' => [ '1delete', false ], 'leading zero' => [ '01', false ],
			'other integer' => [ 2, false ], 'empty' => [ '', false ], 'numeric exponent' => [ '1e0', false ] ];
	}

	public function test_only_managed_cache_policy_names_can_describe_sql_row_removal(): void {
		$cleanup = $sql = [];
		foreach ( DataLifecyclePolicy::cases() as $policy ) {
			if ( $policy->cleanup_eligible() ) { $cleanup[] = $policy; }
			if ( $policy->sql_row_removal_eligible() ) { $sql[] = $policy; }
		}
		self::assertSame( [ DataLifecyclePolicy::ManagedCacheExpiry ], $cleanup );
		self::assertSame( [ DataLifecyclePolicy::ManagedCacheExpiry, DataLifecyclePolicy::ManagedCacheRemoval ], $sql );
		self::assertFalse( DataLifecyclePolicy::OwnerExpiryOnly->cleanup_eligible() );
	}

	public function test_protocol_work_and_payload_limits_are_fixed_not_caller_supplied(): void {
		self::assertSame( [ 120, 300, 200, 50, 1000, 2, 2000 ], [ DataLifecycleManifest::CACHE_TTL_SECONDS,
			DataLifecycleManifest::SCHEDULE_INTERVAL_SECONDS, DataLifecycleManifest::MAX_INSPECTIONS,
			DataLifecycleManifest::MAX_DELETIONS, DataLifecycleManifest::MAX_ID_WINDOW,
			DataLifecycleManifest::LOCK_WAIT_SECONDS, DataLifecycleManifest::SOFT_WALL_MILLISECONDS ] );
		self::assertSame( [ 65536, 67584, 16384 ], [ DataLifecycleManifest::CACHE_MAX_PAYLOAD_BYTES,
			DataLifecycleManifest::CACHE_MAX_ENVELOPE_BYTES, DataLifecycleManifest::COORDINATOR_MAX_BYTES ] );
		self::assertSame( [ 'incomplete', 'refused', 'outcome_unknown', 'completed' ], DataLifecycleManifest::UNINSTALL_STATUSES );
	}
}
