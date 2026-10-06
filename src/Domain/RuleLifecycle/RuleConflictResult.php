<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** Incomplete analysis never grants publication, even when no conflict was found. */
final readonly class RuleConflictResult implements \JsonSerializable {
	public array $conflicting_version_ids;
	public function __construct( public bool $complete, public bool $conflict, public string $reason, array $conflicting_version_ids = [] ) {
		if ( ! in_array( $reason, [ 'rule_clear', 'rule_conflict', 'rule_incomplete' ], true ) || ( ! $complete && 'rule_incomplete' !== $reason ) || ( $complete && $conflict && 'rule_conflict' !== $reason ) || ( $complete && ! $conflict && 'rule_clear' !== $reason ) || ! array_is_list( $conflicting_version_ids ) || count( $conflicting_version_ids ) > 1000 ) {
			throw new \InvalidArgumentException( 'Invalid rule conflict facts.' );
		}
		$out = [];
		foreach ( $conflicting_version_ids as $id ) { $out[] = RuleRecordCodec::integer( $id ); }
		sort( $out, SORT_NUMERIC );
		$this->conflicting_version_ids = array_values( array_unique( $out ) );
	}
	public function permits(): bool { return $this->complete && ! $this->conflict; }
	public function jsonSerialize(): mixed { throw new \InvalidArgumentException( 'Rule conflict requires an explicit authorized projection.' ); }
}
