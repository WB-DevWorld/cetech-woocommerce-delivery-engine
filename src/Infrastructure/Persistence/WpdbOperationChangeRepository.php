<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Operation\OperationMaterialEvent;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** One immutable same-site aggregate event for one accepted changed operation. */
final class WpdbOperationChangeRepository {

	private readonly string $table;

	public function __construct( private readonly OperationSession $session ) {
		$this->table = WpdbOperationRecordRepository::table_name( $session, OperationStoreSchema::CHANGES_SUFFIX );
	}

	public function append( int $operation_id, int $site_id, OperationMaterialEvent $event ): int {
		$this->assert_session( $site_id );
		if ( $operation_id < 1 ) {
			throw new OperationStorageException();
		}
		$sql = $this->session->prepare(
			"INSERT INTO `{$this->table}` (site_id, operation_id, event_format, event_json, created_at) VALUES (%d, %d, 1, %s, UTC_TIMESTAMP(6))",
			$site_id, $operation_id, $event->to_json()
		);
		if ( 1 !== $this->session->query( $sql ) ) {
			throw new OperationStorageException();
		}
		$id = $this->session->insert_id();
		if ( $id < 1 ) {
			throw new OperationStorageException();
		}
		return $id;
	}

	public function find( int $audit_id, int $site_id, int $operation_id ): ?array {
		$this->assert_session( $site_id );
		if ( $audit_id < 1 || $operation_id < 1 ) {
			throw new OperationStorageException();
		}
		$row = $this->session->get_row( $this->session->prepare(
			"SELECT * FROM `{$this->table}` WHERE id = %d AND site_id = %d AND operation_id = %d LIMIT 1",
			$audit_id, $site_id, $operation_id
		) );
		if ( false === $row ) {
			throw new OperationStorageException();
		}
		return $row;
	}

	/**
	 * The owned parent serializes every valid event writer. Never gap-lock a missing
	 * event: a collector could otherwise block an unrelated producer's append while
	 * waiting for that producer's parent. Accepted events still require current reads.
	 */
	public function find_for_operation( int $site_id, int $operation_id ): ?array {
		$this->assert_session( $site_id );
		if ( $operation_id < 1 ) {
			throw new OperationStorageException();
		}
		$records = WpdbOperationRecordRepository::table_name( $this->session, OperationStoreSchema::RECORDS_SUFFIX );
		$parent = $this->session->get_row( $this->session->prepare(
			"SELECT state, audit_id FROM `{$records}` WHERE id = %d AND site_id = %d LIMIT 1 FOR UPDATE", $operation_id, $site_id
		) );
		if ( ! is_array( $parent ) || ! in_array( $parent['state'] ?? null, [ 'pending', 'rejected', 'not_applicable', 'accepted' ], true ) || ! array_key_exists( 'audit_id', $parent ) ) { throw new OperationStorageException(); }
		if ( 'accepted' === $parent['state'] ) {
			$audit_id = WpdbOperationRecordRepository::positive_integer( $parent['audit_id'] );
			$row = $this->session->get_row( $this->session->prepare(
				"SELECT * FROM `{$this->table}` WHERE id = %d AND site_id = %d AND operation_id = %d LIMIT 1 FOR UPDATE", $audit_id, $site_id, $operation_id
			) );
		} else {
			if ( null !== $parent['audit_id'] ) { throw new OperationStorageException(); }
			// No valid concurrent append is possible while the exact parent is owned.
			$row = $this->session->get_row( $this->session->prepare(
				"SELECT * FROM `{$this->table}` WHERE site_id = %d AND operation_id = %d LIMIT 1", $site_id, $operation_id
			) );
		}
		if ( false === $row ) {
			throw new OperationStorageException();
		}
		return $row;
	}

	private function assert_session( int $site_id ): void {
		if ( $site_id < 1 || $site_id !== $this->session->site_id() || ! $this->session->in_transaction() || $this->session->is_retired() ) {
			throw new OperationStorageException();
		}
	}
}
