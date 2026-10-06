<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** Real captured immutable identity for a later specifically approved snapshot writer. */
final readonly class RuleVersionReference implements \JsonSerializable {
	public int $site_id;
	public string $family;
	public int $logical_id;
	public string $logical_uuid;
	public int $logical_revision;
	public int $version_id;
	public string $version_uuid;
	public int $version_sequence;
	public int $version_revision;
	public string $content_hash;
	public string $scope_hash;
	public bool $hypothetical;

	public function __construct( RuleCandidate $candidate ) {
		$this->site_id = $candidate->logical->site_id;
		$this->family = $candidate->logical->family_code;
		$this->logical_id = $candidate->logical->id;
		$this->logical_uuid = $candidate->logical->logical_uuid;
		$this->logical_revision = $candidate->logical->revision;
		$this->version_id = $candidate->version->id;
		$this->version_uuid = $candidate->version->version_uuid;
		$this->version_sequence = $candidate->version->version_sequence;
		$this->version_revision = $candidate->version->row_revision;
		$this->content_hash = $candidate->version->content_hash;
		$this->scope_hash = $candidate->logical->scope_hash;
		$this->hypothetical = $candidate->version->hypothetical;
	}

	/** Internal facts only. The caller's projection owns current authorization. */
	public function facts(): array {
		return [ 'site_id' => $this->site_id, 'family' => $this->family, 'logical_id' => $this->logical_id, 'logical_uuid' => $this->logical_uuid, 'logical_revision' => $this->logical_revision, 'version_id' => $this->version_id, 'version_uuid' => $this->version_uuid, 'version_sequence' => $this->version_sequence, 'version_revision' => $this->version_revision, 'content_hash' => $this->content_hash, 'scope_hash' => $this->scope_hash, 'hypothetical' => $this->hypothetical ];
	}

	public function jsonSerialize(): mixed { throw new \InvalidArgumentException( 'Rule reference requires an explicit authorized projection.' ); }
}
