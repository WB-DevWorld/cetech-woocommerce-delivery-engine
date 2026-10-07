<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DataLifecycle;

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;

/** Complete finite source inventory. Registration never authorizes business deletion. */
final readonly class DataLifecycleRegistry implements \JsonSerializable {

	/** @var array<string, DataLifecycleClass> */
	private array $entries;
	private string $digest;

	/** @param list<DataLifecycleClass> $classes */
	public function __construct( array $classes ) {
		if ( ! array_is_list( $classes ) || count( $classes ) > 256 ) { self::invalid(); }
		$entries = [];
		foreach ( $classes as $class ) {
			if ( ! $class instanceof DataLifecycleClass || isset( $entries[$class->class_id] ) ) { self::invalid(); }
			$entries[$class->class_id] = $class;
		}
		$expected = DataLifecycleManifest::definitions();
		if ( count( $entries ) !== count( $expected ) ) { self::invalid(); }
		foreach ( $expected as $definition ) {
			$id = $definition['class_id'];
			if ( ! isset( $entries[$id] ) || $entries[$id]->definition() !== $definition ) { self::invalid(); }
		}
		ksort( $entries, SORT_STRING );
		$this->entries = $entries;
		$policy = [
			'format' => DataLifecycleManifest::FORMAT,
			'classes' => array_map( static fn( DataLifecycleClass $class ): array => $class->definition(), array_values( $entries ) ),
			'capabilities' => DataLifecycleManifest::CAPABILITIES,
			'budgets' => [ DataLifecycleManifest::CACHE_TTL_SECONDS, DataLifecycleManifest::MAX_INSPECTIONS,
				DataLifecycleManifest::MAX_DELETIONS, DataLifecycleManifest::MAX_ID_WINDOW, DataLifecycleManifest::LOCK_WAIT_SECONDS,
				DataLifecycleManifest::SOFT_WALL_MILLISECONDS, DataLifecycleManifest::SCHEDULE_INTERVAL_SECONDS ],
		];
		$this->digest = hash( 'sha256', 'cetech-data-lifecycle-policy-v1:' . json_encode( $policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}

	public static function standard(): self {
		return new self( array_map( static fn( array $entry ): DataLifecycleClass => DataLifecycleClass::from_definition( $entry ), DataLifecycleManifest::definitions() ) );
	}

	public function get( string $class_id ): DataLifecycleClass {
		if ( ! isset( $this->entries[$class_id] ) ) { throw new \InvalidArgumentException( 'Data lifecycle class is unavailable.' ); }
		return $this->entries[$class_id];
	}

	/** @return list<DataLifecycleClass> */
	public function classes(): array {
		return array_values( $this->entries );
	}

	public function policy_digest(): string {
		return $this->digest;
	}

	public function diagnostics(): array {
		return [
			'format' => DataLifecycleManifest::FORMAT, 'complete' => true, 'registered_classes' => count( $this->entries ),
			'preserved_domain_tables' => count( DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES ),
			'normal_cleanup_classes' => [ DataLifecycleManifest::CACHE_CLASS ],
		];
	}

	public function jsonSerialize(): never {
		throw new \LogicException( 'Data lifecycle registry requires an explicit projection.' );
	}

	private static function invalid(): never {
		throw new \InvalidArgumentException( 'Incomplete or invalid data lifecycle registry.' );
	}
}
