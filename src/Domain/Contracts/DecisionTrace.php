<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

/** Bounded internal facts. Unknown versions remain unknown, never inferred. */
final readonly class DecisionTrace {

	private array $captured;
	private bool $truncated;
	private int $observed;
	private bool $declared_source_complete;

	/**
	 * @param iterable<array{stage:string,reason_code:string,reference_kind:?string,reference_id:?int,reference_version:?int}> $entries
	 */
	public function __construct( iterable $entries = [], bool $source_complete = false, int $limit = 64 ) {
		if ( $limit < 1 || $limit > 64 ) {
			throw new \InvalidArgumentException( 'Decision trace is invalid.' );
		}
		$captured = [];
		$observed = 0;
		$truncated = false;
		try {
			foreach ( $entries as $entry ) {
				$validated = self::validated_entry( $entry );
				++$observed;
				if ( $observed > $limit ) {
					$truncated = true;
					break;
				}
				$captured[] = $validated;
			}
		} catch ( \Throwable ) {
			// Iterator failures must not expose their original exception or payload.
			throw new \InvalidArgumentException( 'Decision trace is invalid.' );
		}
		$this->captured = $captured;
		$this->observed = $observed;
		$this->truncated = $truncated;
		$this->declared_source_complete = $source_complete;
	}

	/** @return list<array{stage:string,reason_code:string,reference_kind:?string,reference_id:?int,reference_version:?int}> */
	public function entries(): array {
		return $this->captured;
	}

	public function is_complete(): bool {
		return $this->declared_source_complete && ! $this->truncated;
	}

	public function was_truncated(): bool {
		return $this->truncated;
	}

	public function captured_count(): int {
		return count( $this->captured );
	}

	/** Bounded observation count, not an estimate of the full source length. */
	public function observed_count(): int {
		return $this->observed;
	}

	public function source_complete(): bool {
		return $this->declared_source_complete;
	}

	private static function validated_entry( mixed $entry ): array {
		$keys = [ 'stage', 'reason_code', 'reference_kind', 'reference_id', 'reference_version' ];
		if ( ! is_array( $entry ) || count( $entry ) !== count( $keys ) || array_diff( $keys, array_keys( $entry ) ) ) {
			throw new \InvalidArgumentException( 'Decision trace is invalid.' );
		}
		$stage = $entry['stage'];
		$reason = $entry['reason_code'];
		$kind = $entry['reference_kind'];
		$id = $entry['reference_id'];
		$version = $entry['reference_version'];
		if ( ! is_string( $stage ) || ! in_array( $stage, [ 'configuration', 'coverage', 'quote', 'mutation' ], true )
			|| ! is_string( $reason ) || ! DecisionReasonCode::is_known( $reason )
			|| ( null !== $kind && ( ! is_string( $kind ) || ! in_array( $kind, [ 'configuration_scope', 'coverage_zone', 'coverage_group', 'rate_card', 'rule_version' ], true ) ) )
			|| ( null !== $id && ( ! is_int( $id ) || $id < 1 ) )
			|| ( null !== $version && ( ! is_int( $version ) || $version < 0 ) )
			|| ( ( null === $kind ) !== ( null === $id ) )
			|| ( null === $kind && null !== $version )
		) {
			throw new \InvalidArgumentException( 'Decision trace is invalid.' );
		}
		// Rebuild each field to sever any references held by caller-owned arrays.
		return [
			'stage'             => $stage,
			'reason_code'       => $reason,
			'reference_kind'    => $kind,
			'reference_id'      => $id,
			'reference_version' => $version,
		];
	}
}
