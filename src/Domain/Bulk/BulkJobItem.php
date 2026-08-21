<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Bulk;

use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;

final class BulkJobItem {

	/**
	 * @param array<string, mixed> $before_snapshot
	 * @param array<string, mixed> $result
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $job_id,
		public readonly string $target_type,
		public readonly int $target_id,
		public readonly string $external_key,
		public readonly ?int $parent_target_id,
		public readonly BulkJobItemStatus $status,
		public readonly int $attempt_count,
		public readonly string $precondition_fingerprint,
		public readonly string $after_fingerprint,
		public readonly array $before_snapshot,
		public readonly array $result,
		public readonly ?string $error_code,
		public readonly ?string $error_summary,
		public readonly ?string $claim_token,
		public readonly ?string $claimed_at,
		public readonly ?string $completed_at,
		public readonly ?string $created_at,
		public readonly ?string $updated_at
	) {
	}

	public static function pending(
		int $job_id,
		string $target_type,
		int $target_id,
		string $external_key = '',
		?int $parent_target_id = null
	): self {
		$now = gmdate( 'Y-m-d H:i:s' );

		return new self(
			null,
			$job_id,
			$target_type,
			max( 0, $target_id ),
			$external_key,
			$parent_target_id,
			BulkJobItemStatus::Pending,
			0,
			'',
			'',
			[],
			[],
			null,
			null,
			null,
			null,
			null,
			$now,
			$now
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	public function with( array $overrides ): self {
		return new self(
			array_key_exists( 'id', $overrides ) ? $overrides['id'] : $this->id,
			$overrides['job_id'] ?? $this->job_id,
			$overrides['target_type'] ?? $this->target_type,
			$overrides['target_id'] ?? $this->target_id,
			$overrides['external_key'] ?? $this->external_key,
			array_key_exists( 'parent_target_id', $overrides ) ? $overrides['parent_target_id'] : $this->parent_target_id,
			$overrides['status'] ?? $this->status,
			$overrides['attempt_count'] ?? $this->attempt_count,
			$overrides['precondition_fingerprint'] ?? $this->precondition_fingerprint,
			$overrides['after_fingerprint'] ?? $this->after_fingerprint,
			$overrides['before_snapshot'] ?? $this->before_snapshot,
			$overrides['result'] ?? $this->result,
			array_key_exists( 'error_code', $overrides ) ? $overrides['error_code'] : $this->error_code,
			array_key_exists( 'error_summary', $overrides ) ? $overrides['error_summary'] : $this->error_summary,
			array_key_exists( 'claim_token', $overrides ) ? $overrides['claim_token'] : $this->claim_token,
			array_key_exists( 'claimed_at', $overrides ) ? $overrides['claimed_at'] : $this->claimed_at,
			array_key_exists( 'completed_at', $overrides ) ? $overrides['completed_at'] : $this->completed_at,
			$overrides['created_at'] ?? $this->created_at,
			$overrides['updated_at'] ?? gmdate( 'Y-m-d H:i:s' )
		);
	}
}
