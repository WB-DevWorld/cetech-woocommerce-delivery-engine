<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** One joined, validated logical scope and its version, never a naked row. */
final readonly class RuleCandidate implements \JsonSerializable {
	public function __construct( public LogicalRule $logical, public RuleVersion $version ) {
		if ( $logical->id !== $version->logical_rule_id || $logical->site_id !== $version->site_id ) {
			throw new \InvalidArgumentException( 'Rule candidate identity mismatch.' );
		}
	}
	public function jsonSerialize(): mixed { throw new \InvalidArgumentException( 'Rule candidate requires an explicit authorized projection.' ); }
}
