<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Bulk;

use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;

/**
 * Durable logical bulk job. Physical work is always processed in bounded batches.
 */
final class BulkJob {

	public const FORMAT_VERSION = 1;

	public const DEFAULT_BATCH_SIZE = 25;

	/**
	 * @param array<string, mixed> $target_definition
	 * @param array<string, mixed> $action_manifest
	 * @param array<string, mixed> $summary
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $job_uuid,
		public readonly string $job_code,
		public readonly BulkOperationType $operation_type,
		public readonly int $actor_user_id,
		public readonly BulkJobStatus $status,
		public readonly bool $dry_run,
		public readonly bool $cancel_requested,
		public readonly string $target_hash,
		public readonly string $action_hash,
		public readonly array $target_definition,
		public readonly array $action_manifest,
		public readonly array $summary,
		public readonly int $total_count,
		public readonly int $enumerated_count,
		public readonly int $processed_count,
		public readonly int $changed_count,
		public readonly int $skipped_count,
		public readonly int $failed_count,
		public readonly int $warning_count,
		public readonly bool $enumeration_complete,
		public readonly string $checkpoint_cursor,
		public readonly int $batch_size,
		public readonly ?string $claim_token,
		public readonly ?string $claimed_at,
		public readonly int $retry_count,
		public readonly ?string $error_code,
		public readonly ?string $error_summary,
		public readonly ?int $parent_job_id,
		public readonly int $format_version,
		public readonly ?string $created_at,
		public readonly ?string $started_at,
		public readonly ?string $completed_at,
		public readonly ?string $updated_at
	) {
	}

	public static function create(
		BulkOperationType $operation_type,
		int $actor_user_id,
		array $target_definition,
		array $action_manifest,
		bool $dry_run = true,
		int $batch_size = self::DEFAULT_BATCH_SIZE,
		?int $parent_job_id = null
	): self {
		$uuid = self::new_uuid();
		$now  = gmdate( 'Y-m-d H:i:s' );

		return new self(
			null,
			$uuid,
			'PENDING-' . substr( $uuid, 0, 8 ),
			$operation_type,
			max( 0, $actor_user_id ),
			BulkJobStatus::Draft,
			$dry_run,
			false,
			self::hash_payload( $target_definition ),
			self::hash_payload( $action_manifest ),
			$target_definition,
			$action_manifest,
			[],
			0,
			0,
			0,
			0,
			0,
			0,
			0,
			false,
			'0',
			max( 1, min( 100, $batch_size ) ),
			null,
			null,
			0,
			null,
			null,
			$parent_job_id,
			self::FORMAT_VERSION,
			$now,
			null,
			null,
			$now
		);
	}

	public function with_id( int $id, string $job_code ): self {
		return $this->with(
			[
				'id'       => $id,
				'job_code' => $job_code,
			]
		);
	}

	public function with_status( BulkJobStatus $status ): self {
		$started   = $this->started_at;
		$completed = $this->completed_at;
		$now       = gmdate( 'Y-m-d H:i:s' );

		if ( null === $started && $status->is_active_worker_state() ) {
			$started = $now;
		}

		if ( $status->is_terminal() && null === $completed ) {
			$completed = $now;
		}

		return $this->with(
			[
				'status'       => $status,
				'started_at'   => $started,
				'completed_at' => $completed,
				'updated_at'   => $now,
			]
		);
	}

	/**
	 * @param array<string, mixed> $summary
	 */
	public function with_progress(
		int $total_count,
		int $enumerated_count,
		int $processed_count,
		int $changed_count,
		int $skipped_count,
		int $failed_count,
		int $warning_count,
		bool $enumeration_complete,
		string $checkpoint_cursor,
		array $summary
	): self {
		return $this->with(
			[
				'total_count'           => max( 0, $total_count ),
				'enumerated_count'      => max( 0, $enumerated_count ),
				'processed_count'       => max( 0, $processed_count ),
				'changed_count'         => max( 0, $changed_count ),
				'skipped_count'         => max( 0, $skipped_count ),
				'failed_count'          => max( 0, $failed_count ),
				'warning_count'         => max( 0, $warning_count ),
				'enumeration_complete'  => $enumeration_complete,
				'checkpoint_cursor'     => $checkpoint_cursor,
				'summary'               => $summary,
				'updated_at'            => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}

	public function with_error( string $error_code, string $error_summary ): self {
		return $this->with(
			[
				'error_code'    => $error_code,
				'error_summary' => $error_summary,
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}

	public function with_claim( ?string $token, ?string $claimed_at ): self {
		return $this->with(
			[
				'claim_token' => $token,
				'claimed_at'  => $claimed_at,
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}

	public function request_cancel(): self {
		return $this->with(
			[
				'cancel_requested' => true,
				'status'           => BulkJobStatus::CancelRequested,
				'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	public function with( array $overrides ): self {
		return new self(
			array_key_exists( 'id', $overrides ) ? $overrides['id'] : $this->id,
			$overrides['job_uuid'] ?? $this->job_uuid,
			$overrides['job_code'] ?? $this->job_code,
			$overrides['operation_type'] ?? $this->operation_type,
			$overrides['actor_user_id'] ?? $this->actor_user_id,
			$overrides['status'] ?? $this->status,
			$overrides['dry_run'] ?? $this->dry_run,
			$overrides['cancel_requested'] ?? $this->cancel_requested,
			$overrides['target_hash'] ?? $this->target_hash,
			$overrides['action_hash'] ?? $this->action_hash,
			$overrides['target_definition'] ?? $this->target_definition,
			$overrides['action_manifest'] ?? $this->action_manifest,
			$overrides['summary'] ?? $this->summary,
			$overrides['total_count'] ?? $this->total_count,
			$overrides['enumerated_count'] ?? $this->enumerated_count,
			$overrides['processed_count'] ?? $this->processed_count,
			$overrides['changed_count'] ?? $this->changed_count,
			$overrides['skipped_count'] ?? $this->skipped_count,
			$overrides['failed_count'] ?? $this->failed_count,
			$overrides['warning_count'] ?? $this->warning_count,
			$overrides['enumeration_complete'] ?? $this->enumeration_complete,
			$overrides['checkpoint_cursor'] ?? $this->checkpoint_cursor,
			$overrides['batch_size'] ?? $this->batch_size,
			array_key_exists( 'claim_token', $overrides ) ? $overrides['claim_token'] : $this->claim_token,
			array_key_exists( 'claimed_at', $overrides ) ? $overrides['claimed_at'] : $this->claimed_at,
			$overrides['retry_count'] ?? $this->retry_count,
			array_key_exists( 'error_code', $overrides ) ? $overrides['error_code'] : $this->error_code,
			array_key_exists( 'error_summary', $overrides ) ? $overrides['error_summary'] : $this->error_summary,
			array_key_exists( 'parent_job_id', $overrides ) ? $overrides['parent_job_id'] : $this->parent_job_id,
			$overrides['format_version'] ?? $this->format_version,
			$overrides['created_at'] ?? $this->created_at,
			array_key_exists( 'started_at', $overrides ) ? $overrides['started_at'] : $this->started_at,
			array_key_exists( 'completed_at', $overrides ) ? $overrides['completed_at'] : $this->completed_at,
			$overrides['updated_at'] ?? $this->updated_at
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function hash_payload( array $payload ): string {
		$encoded = json_encode( $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );

		return hash( 'sha256', $encoded );
	}

	public static function new_uuid(): string {
		$data = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	public static function display_code_for_id( int $id ): string {
		return sprintf( 'BULK-%06d', $id );
	}
}
