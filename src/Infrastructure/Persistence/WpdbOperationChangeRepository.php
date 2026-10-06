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

	/** Current reverse lookup also exposes orphan events on nonaccepted records. */
	public function find_for_operation( int $site_id, int $operation_id ): ?array {
		$this->assert_session( $site_id );
		if ( $operation_id < 1 ) {
			throw new OperationStorageException();
		}
		$row = $this->session->get_row( $this->session->prepare(
			"SELECT * FROM `{$this->table}` WHERE site_id = %d AND operation_id = %d LIMIT 1 FOR UPDATE",
			$site_id, $operation_id
		) );
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
