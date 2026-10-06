<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationCompletion;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** New internal store only. Every read/write uses the explicitly owned session. */
final class WpdbOperationRecordRepository {

	private readonly string $table;

	public function __construct( private readonly OperationSession $session ) {
		$this->table = self::table_name( $session, OperationStoreSchema::RECORDS_SUFFIX );
	}

	/** False means the exact full unique reservation key already exists. */
	public function insert_pending( OperationIdentity $identity, CanonicalIntent $intent ): bool {
		$this->assert_session( $identity );
		$sql = $this->session->prepare(
			"INSERT INTO `{$this->table}` (site_id, namespace_hash, intent_hash, namespace_format, intent_format, record_format, operation, operation_version, target_hash, state, publication_state, completion_json, audit_id, row_version, created_at, updated_at, completed_at) VALUES (%d, %s, %s, 1, 1, 1, %s, %d, %s, 'pending', 'none', NULL, NULL, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), NULL)",
			$identity->site_id, $identity->namespace_digest(), $intent->fingerprint(),
			$identity->operation, $identity->operation_version, self::target_hash( $identity )
		);
		$affected = $this->session->query( $sql );
		if ( false === $affected && 1062 === $this->session->errno() ) {
			return false;
		}
		if ( 1 !== $affected || $this->session->insert_id() < 1 ) {
			throw new OperationStorageException();
		}
		return true;
	}

	/** A current locking read; false database results are never treated as absence. */
	public function lock( OperationIdentity $identity ): ?array {
		$this->assert_session( $identity );
		$row = $this->session->get_row( $this->session->prepare(
			"SELECT * FROM `{$this->table}` WHERE site_id = %d AND namespace_hash = %s LIMIT 1 FOR UPDATE",
			$identity->site_id, $identity->namespace_digest()
		) );
		if ( false === $row ) {
			throw new OperationStorageException();
		}
		return $row;
	}

	/** Accepted/no-change records cannot be replaced by retry or rejection work. */
	public function write_completion( array $locked_row, OperationIdentity $identity, CanonicalIntent $intent, OperationCompletion $completion, ?int $audit_id ): void {
		$this->assert_session( $identity );
		$id = self::positive_integer( $locked_row['id'] ?? null );
		$version = self::positive_integer( $locked_row['row_version'] ?? null );
		if ( PHP_INT_MAX === $version
			|| ! in_array( $locked_row['state'] ?? null, [ 'pending', 'rejected' ], true )
			|| ! in_array( $completion->state, [ 'accepted', 'rejected', 'not_applicable' ], true )
			|| ( 'accepted' === $completion->state ) !== ( null !== $audit_id )
			|| ( null !== $audit_id && $audit_id < 1 )
		) {
			throw new OperationStorageException();
		}
		$publication = null === $completion->publication ? 'none' : 'pending';
		$audit_clause = null === $audit_id ? 'NULL' : '%d';
		$arguments = [ $completion->state, $publication, $completion->to_json() ];
		if ( null !== $audit_id ) {
			$arguments[] = $audit_id;
		}
		array_push( $arguments, $id, $identity->site_id, $identity->namespace_digest(), $intent->fingerprint(), $version );
		$sql = $this->session->prepare(
			"UPDATE `{$this->table}` SET state = %s, publication_state = %s, completion_json = %s, audit_id = {$audit_clause}, row_version = row_version + 1, updated_at = UTC_TIMESTAMP(6), completed_at = UTC_TIMESTAMP(6) WHERE id = %d AND site_id = %d AND namespace_hash = %s AND intent_hash = %s AND row_version = %d AND state IN ('pending', 'rejected')",
			...$arguments
		);
		if ( 1 !== $this->session->query( $sql ) ) {
			throw new OperationStorageException();
		}
	}

	/** Only the publication marker advances; completion and event remain immutable. */
	public function mark_published( array $locked_row, OperationIdentity $identity, CanonicalIntent $intent ): void {
		$this->assert_session( $identity );
		$id = self::positive_integer( $locked_row['id'] ?? null );
		$version = self::positive_integer( $locked_row['row_version'] ?? null );
		if ( PHP_INT_MAX === $version || 'accepted' !== ( $locked_row['state'] ?? null ) || 'pending' !== ( $locked_row['publication_state'] ?? null ) ) {
			throw new OperationStorageException();
		}
		$sql = $this->session->prepare(
			"UPDATE `{$this->table}` SET publication_state = 'published', row_version = row_version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = %d AND site_id = %d AND namespace_hash = %s AND intent_hash = %s AND row_version = %d AND state = 'accepted' AND publication_state = 'pending'",
			$id, $identity->site_id, $identity->namespace_digest(), $intent->fingerprint(), $version
		);
		if ( 1 !== $this->session->query( $sql ) ) {
			throw new OperationStorageException();
		}
	}

	public static function target_hash( OperationIdentity $identity ): string {
		return hash( 'sha256', 'cetech-operation-target-v1:' . $identity->target_key );
	}

	public static function table_name( OperationSession $session, string $suffix ): string {
		$name = $session->table_prefix() . TableNames::PREFIX . $suffix;
		if ( strlen( $name ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $name ) ) {
			throw new OperationStorageException();
		}
		return $name;
	}

	public static function positive_integer( mixed $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $value )
			&& ( strlen( $value ) < strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) <= 0 ) )
		) {
			return (int) $value;
		}
		throw new OperationStorageException();
	}

	private function assert_session( OperationIdentity $identity ): void {
		if ( ! $this->session->in_transaction() || $this->session->is_retired() || $this->session->site_id() !== $identity->site_id ) {
			throw new OperationStorageException();
		}
	}
}
