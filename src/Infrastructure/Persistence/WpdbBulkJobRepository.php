<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Bulk\BulkJobRepositoryInterface;
use CetechDeliveryEngine\Domain\Bulk\BulkRecipe;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;

final class WpdbBulkJobRepository extends AbstractWpdbRepository implements BulkJobRepositoryInterface {

	protected function table_suffix(): string {
		return BulkJobSchema::JOBS_SUFFIX;
	}

	public function save_job( BulkJob $job ): BulkJob {
		global $wpdb;
		$row = $this->job_to_row( $job );
		if ( null === $job->id ) {
			$id = $this->insert_row( $row['data'], $row['formats'] );
			if ( $id <= 0 ) {
				throw new \RuntimeException( 'Failed to create bulk job.' );
			}
			$code = BulkJob::display_code_for_id( $id );
			$wpdb->update( $this->table_name(), [ 'job_code' => $code ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
			$saved = $this->find_job( $id );
			if ( ! $saved instanceof BulkJob ) {
				throw new \RuntimeException( 'Bulk job insert succeeded but the row could not be reloaded.' );
			}

			return $saved;
		}

		$this->update_row( $job->id, $row['data'], $row['formats'] );
		$saved = $this->find_job( $job->id );

		return $saved instanceof BulkJob ? $saved : $job;
	}

	public function find_job( int $id ): ?BulkJob {
		$row = $this->fetch_row_by_id( $id );

		return is_array( $row ) ? $this->hydrate_job( $row ) : null;
	}

	public function find_job_by_code( string $job_code ): ?BulkJob {
		global $wpdb;
		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` WHERE job_code = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $job_code ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate_job( $row ) : null;
	}

	public function find_job_by_uuid( string $job_uuid ): ?BulkJob {
		global $wpdb;
		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` WHERE job_uuid = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $job_uuid ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate_job( $row ) : null;
	}

	public function list_jobs( int $limit = 50, int $after_id = 0, ?BulkJobStatus $status = null ): array {
		global $wpdb;
		$table = $this->table_name();
		$limit = max( 1, min( 200, $limit ) );
		if ( $status instanceof BulkJobStatus ) {
			$sql = "SELECT * FROM `{$table}` WHERE id > %d AND status = %s ORDER BY id DESC LIMIT %d";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $after_id, $status->value, $limit ), ARRAY_A );
		} else {
			$sql = "SELECT * FROM `{$table}` WHERE id > %d ORDER BY id DESC LIMIT %d";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $after_id, $limit ), ARRAY_A );
		}

		$jobs = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$jobs[] = $this->hydrate_job( $row );
		}

		return $jobs;
	}

	public function insert_items( array $items ): array {
		$saved = [];
		foreach ( $items as $item ) {
			if ( ! $item instanceof BulkJobItem ) {
				continue;
			}
			$existing = $this->find_item( $item->job_id, $item->target_type, $item->target_id, $item->external_key );
			if ( null !== $existing ) {
				$saved[] = $existing;
				continue;
			}
			$saved[] = $this->save_item( $item );
		}

		return $saved;
	}

	public function claim_items( int $job_id, int $limit, string $claim_token, int $claim_ttl_seconds = 300 ): array {
		global $wpdb;
		$table   = TableNames::for( BulkJobSchema::ITEMS_SUFFIX );
		$limit   = max( 1, min( 100, $limit ) );
		$expired = gmdate( 'Y-m-d H:i:s', time() - $claim_ttl_seconds );
		$sql     = "SELECT * FROM `{$table}` WHERE job_id = %d AND (status = %s OR (status = %s AND claimed_at IS NOT NULL AND claimed_at < %s)) ORDER BY id ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $job_id, BulkJobItemStatus::Pending->value, BulkJobItemStatus::Claimed->value, $expired, $limit ),
			ARRAY_A
		);
		$claimed = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$item = $this->hydrate_item( $row );
			$ok   = $wpdb->update(
				$table,
				[
					'status'        => BulkJobItemStatus::Claimed->value,
					'claim_token'   => $claim_token,
					'claimed_at'    => gmdate( 'Y-m-d H:i:s' ),
					'attempt_count' => $item->attempt_count + 1,
					'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
				],
				[
					'id'     => $item->id,
					'status' => $item->status->value,
				],
				[ '%s', '%s', '%s', '%d', '%s' ],
				[ '%d', '%s' ]
			);
			if ( false === $ok || 0 === $ok ) {
				continue;
			}
			$claimed[] = $item->with(
				[
					'status'        => BulkJobItemStatus::Claimed,
					'claim_token'   => $claim_token,
					'claimed_at'    => gmdate( 'Y-m-d H:i:s' ),
					'attempt_count' => $item->attempt_count + 1,
				]
			);
		}

		return $claimed;
	}

	public function save_item( BulkJobItem $item ): BulkJobItem {
		global $wpdb;
		$table = TableNames::for( BulkJobSchema::ITEMS_SUFFIX );
		$data  = $this->item_to_row( $item );
		if ( null === $item->id ) {
			$wpdb->insert( $table, $data['data'], $data['formats'] );
			$id = (int) $wpdb->insert_id;
			$saved = $this->hydrate_item( array_merge( $data['data'], [ 'id' => $id ] ) );

			return $saved;
		}
		$wpdb->update( $table, $data['data'], [ 'id' => $item->id ], $data['formats'], [ '%d' ] );

		return $item;
	}

	public function list_items( int $job_id, int $limit = 50, int $after_id = 0, ?BulkJobItemStatus $status = null ): array {
		global $wpdb;
		$table = TableNames::for( BulkJobSchema::ITEMS_SUFFIX );
		$limit = max( 1, min( 200, $limit ) );
		if ( $status instanceof BulkJobItemStatus ) {
			$sql = "SELECT * FROM `{$table}` WHERE job_id = %d AND id > %d AND status = %s ORDER BY id ASC LIMIT %d";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $job_id, $after_id, $status->value, $limit ), ARRAY_A );
		} else {
			$sql = "SELECT * FROM `{$table}` WHERE job_id = %d AND id > %d ORDER BY id ASC LIMIT %d";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $job_id, $after_id, $limit ), ARRAY_A );
		}
		$items = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$items[] = $this->hydrate_item( $row );
		}

		return $items;
	}

	public function count_items( int $job_id, ?BulkJobItemStatus $status = null ): int {
		global $wpdb;
		$table = TableNames::for( BulkJobSchema::ITEMS_SUFFIX );
		if ( $status instanceof BulkJobItemStatus ) {
			$sql = "SELECT COUNT(*) FROM `{$table}` WHERE job_id = %d AND status = %s";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $job_id, $status->value ) );
		}
		$sql = "SELECT COUNT(*) FROM `{$table}` WHERE job_id = %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $job_id ) );
	}

	public function find_item( int $job_id, string $target_type, int $target_id, string $external_key = '' ): ?BulkJobItem {
		global $wpdb;
		$table = TableNames::for( BulkJobSchema::ITEMS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE job_id = %d AND target_type = %s AND target_id = %d AND external_key = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $job_id, $target_type, $target_id, $external_key ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate_item( $row ) : null;
	}

	public function reset_item_statuses( int $job_id, array $from_statuses, BulkJobItemStatus $to ): int {
		global $wpdb;
		$table = TableNames::for( BulkJobSchema::ITEMS_SUFFIX );
		$codes = [];
		foreach ( $from_statuses as $status ) {
			if ( $status instanceof BulkJobItemStatus ) {
				$codes[] = $status->value;
			}
		}
		if ( [] === $codes ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		$sql          = "UPDATE `{$table}` SET status = %s, claim_token = NULL, claimed_at = NULL, updated_at = %s WHERE job_id = %d AND status IN ({$placeholders})";
		$args         = array_merge( [ $to->value, gmdate( 'Y-m-d H:i:s' ), $job_id ], $codes );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query( $wpdb->prepare( $sql, ...$args ) );

		return is_int( $updated ) ? $updated : 0;
	}

	public function claim_job( int $job_id, string $claim_token, int $claim_ttl_seconds = 300 ): ?BulkJob {
		global $wpdb;
		$job = $this->find_job( $job_id );
		if ( ! $job instanceof BulkJob ) {
			return null;
		}
		$expired = null === $job->claimed_at || strtotime( (string) $job->claimed_at ) < ( time() - $claim_ttl_seconds );
		if ( null !== $job->claim_token && $job->claim_token !== $claim_token && ! $expired ) {
			return null;
		}
		$table = $this->table_name();
		$wpdb->update(
			$table,
			[
				'claim_token' => $claim_token,
				'claimed_at'  => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $job_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		return $this->find_job( $job_id );
	}

	public function release_job_claim( int $job_id, string $claim_token ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . $this->table_name() . '` SET claim_token = NULL, claimed_at = NULL WHERE id = %d AND claim_token = %s',
				$job_id,
				$claim_token
			)
		);
	}

	public function save_recipe( BulkRecipe $recipe ): BulkRecipe {
		global $wpdb;
		$table = TableNames::for( BulkJobSchema::RECIPES_SUFFIX );
		$data  = [
			'recipe_code'             => $recipe->recipe_code,
			'name'                    => $recipe->name,
			'owner_user_id'           => $recipe->owner_user_id,
			'target_definition_json'  => wp_json_encode( $recipe->target_definition ),
			'action_manifest_json'    => wp_json_encode( $recipe->action_manifest ),
			'updated_at'              => gmdate( 'Y-m-d H:i:s' ),
		];
		if ( null === $recipe->id ) {
			$data['created_at'] = gmdate( 'Y-m-d H:i:s' );
			$wpdb->insert( $table, $data );
			return $recipe->with_id( (int) $wpdb->insert_id );
		}
		$wpdb->update( $table, $data, [ 'id' => $recipe->id ], null, [ '%d' ] );

		return $recipe;
	}

	public function find_recipe( int $id ): ?BulkRecipe {
		global $wpdb;
		$table = TableNames::for( BulkJobSchema::RECIPES_SUFFIX );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate_recipe( $row ) : null;
	}

	public function list_recipes( int $limit = 50 ): array {
		global $wpdb;
		$table = TableNames::for( BulkJobSchema::RECIPES_SUFFIX );
		$limit = max( 1, min( 100, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		$out   = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = $this->hydrate_recipe( $row );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate_job( array $row ): BulkJob {
		return new BulkJob(
			(int) $row['id'],
			(string) $row['job_uuid'],
			(string) $row['job_code'],
			BulkOperationType::from( (string) $row['operation_type'] ),
			(int) $row['actor_user_id'],
			BulkJobStatus::from( (string) $row['status'] ),
			(bool) $row['dry_run'],
			(bool) $row['cancel_requested'],
			(string) $row['target_hash'],
			(string) $row['action_hash'],
			$this->decode_json( $row['target_definition_json'] ?? '{}' ),
			$this->decode_json( $row['action_manifest_json'] ?? '{}' ),
			$this->decode_json( $row['summary_json'] ?? '{}' ),
			(int) $row['total_count'],
			(int) $row['enumerated_count'],
			(int) $row['processed_count'],
			(int) $row['changed_count'],
			(int) $row['skipped_count'],
			(int) $row['failed_count'],
			(int) $row['warning_count'],
			(bool) $row['enumeration_complete'],
			(string) $row['checkpoint_cursor'],
			(int) $row['batch_size'],
			isset( $row['claim_token'] ) ? (string) $row['claim_token'] : null,
			isset( $row['claimed_at'] ) ? (string) $row['claimed_at'] : null,
			(int) $row['retry_count'],
			isset( $row['error_code'] ) ? (string) $row['error_code'] : null,
			isset( $row['error_summary'] ) ? (string) $row['error_summary'] : null,
			isset( $row['parent_job_id'] ) ? (int) $row['parent_job_id'] : null,
			(int) $row['format_version'],
			isset( $row['created_at'] ) ? (string) $row['created_at'] : null,
			isset( $row['started_at'] ) ? (string) $row['started_at'] : null,
			isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null,
			isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null
		);
	}

	/**
	 * @return array{data: array<string, mixed>, formats: list<string>}
	 */
	private function job_to_row( BulkJob $job ): array {
		$data = [
			'job_uuid'                 => $job->job_uuid,
			'job_code'                 => $job->job_code,
			'operation_type'           => $job->operation_type->value,
			'actor_user_id'            => $job->actor_user_id,
			'status'                   => $job->status->value,
			'dry_run'                  => $job->dry_run ? 1 : 0,
			'cancel_requested'         => $job->cancel_requested ? 1 : 0,
			'target_hash'              => $job->target_hash,
			'action_hash'              => $job->action_hash,
			'target_definition_json'   => wp_json_encode( $job->target_definition ),
			'action_manifest_json'     => wp_json_encode( $job->action_manifest ),
			'summary_json'             => wp_json_encode( $job->summary ),
			'total_count'              => $job->total_count,
			'enumerated_count'         => $job->enumerated_count,
			'processed_count'          => $job->processed_count,
			'changed_count'            => $job->changed_count,
			'skipped_count'            => $job->skipped_count,
			'failed_count'             => $job->failed_count,
			'warning_count'            => $job->warning_count,
			'enumeration_complete'     => $job->enumeration_complete ? 1 : 0,
			'checkpoint_cursor'        => $job->checkpoint_cursor,
			'batch_size'               => $job->batch_size,
			'claim_token'              => $job->claim_token,
			'claimed_at'               => $job->claimed_at,
			'retry_count'              => $job->retry_count,
			'error_code'               => $job->error_code,
			'error_summary'            => $job->error_summary,
			'parent_job_id'            => $job->parent_job_id,
			'format_version'           => $job->format_version,
			'started_at'               => $job->started_at,
			'completed_at'             => $job->completed_at,
			'updated_at'               => gmdate( 'Y-m-d H:i:s' ),
		];
		$formats = array_fill( 0, count( $data ), '%s' );
		$formats[3] = '%d';

		return [ 'data' => $data, 'formats' => $formats ];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate_item( array $row ): BulkJobItem {
		return new BulkJobItem(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(int) $row['job_id'],
			(string) $row['target_type'],
			(int) $row['target_id'],
			(string) ( $row['external_key'] ?? '' ),
			isset( $row['parent_target_id'] ) ? (int) $row['parent_target_id'] : null,
			BulkJobItemStatus::from( (string) $row['status'] ),
			(int) ( $row['attempt_count'] ?? 0 ),
			(string) ( $row['precondition_fingerprint'] ?? '' ),
			(string) ( $row['after_fingerprint'] ?? '' ),
			$this->decode_json( $row['before_snapshot_json'] ?? '{}' ),
			$this->decode_json( $row['result_json'] ?? '{}' ),
			isset( $row['error_code'] ) ? (string) $row['error_code'] : null,
			isset( $row['error_summary'] ) ? (string) $row['error_summary'] : null,
			isset( $row['claim_token'] ) ? (string) $row['claim_token'] : null,
			isset( $row['claimed_at'] ) ? (string) $row['claimed_at'] : null,
			isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null,
			isset( $row['created_at'] ) ? (string) $row['created_at'] : null,
			isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null
		);
	}

	/**
	 * @return array{data: array<string, mixed>, formats: list<string>}
	 */
	private function item_to_row( BulkJobItem $item ): array {
		return [
			'data'    => [
				'job_id'                    => $item->job_id,
				'target_type'               => $item->target_type,
				'target_id'                 => $item->target_id,
				'external_key'              => $item->external_key,
				'parent_target_id'          => $item->parent_target_id,
				'status'                    => $item->status->value,
				'attempt_count'             => $item->attempt_count,
				'precondition_fingerprint'  => $item->precondition_fingerprint,
				'after_fingerprint'         => $item->after_fingerprint,
				'before_snapshot_json'      => wp_json_encode( $item->before_snapshot ),
				'result_json'               => wp_json_encode( $item->result ),
				'error_code'                => $item->error_code,
				'error_summary'             => $item->error_summary,
				'claim_token'               => $item->claim_token,
				'claimed_at'                => $item->claimed_at,
				'completed_at'              => $item->completed_at,
				'updated_at'                => gmdate( 'Y-m-d H:i:s' ),
			],
			'formats' => [ '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate_recipe( array $row ): BulkRecipe {
		return new BulkRecipe(
			(int) $row['id'],
			(string) $row['recipe_code'],
			(string) $row['name'],
			(int) $row['owner_user_id'],
			$this->decode_json( $row['target_definition_json'] ?? '{}' ),
			$this->decode_json( $row['action_manifest_json'] ?? '{}' ),
			isset( $row['created_at'] ) ? (string) $row['created_at'] : null,
			isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decode_json( mixed $json ): array {
		if ( is_array( $json ) ) {
			return $json;
		}
		$decoded = json_decode( is_string( $json ) ? $json : '{}', true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
