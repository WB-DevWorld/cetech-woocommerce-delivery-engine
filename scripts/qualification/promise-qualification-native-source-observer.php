<?php
declare(strict_types=1);

use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Infrastructure\WordPress\{OperationConnection, OperationConnectionMysqliTransport, OperationConnectionResult, OperationConnectionTransport};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromisePersistenceAuthorizer, PromiseSiteBinding};
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;

/** Observe actual owned MySQLi calls, never manufacture acknowledgements, retries or operation results. */
final class CetechPromiseQualificationSourceFactory implements OperationConnectionFactory {
	public array $sessions = []; public array $transports = [];
	public function __construct( private wpdb $db ) { if ( '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || 1 !== preg_match( '/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST ) || 1 !== preg_match( '/\Acetech_wp_opening_qualification(?:_[a-z0-9]+)?\z/D', DB_NAME ) ) { throw new RuntimeException( 'P06 source observation requires marked disposable database.' ); } }
	public function open(): OperationSession {
		$host = $this->db->parse_db_host( DB_HOST ); if ( ! is_array( $host ) ) { throw new RuntimeException( 'P06 actual SQL host unavailable.' ); }
		$native = OperationConnectionMysqliTransport::connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2], $this->db->charset ?: 'utf8mb4', defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0 ); $transport = new CetechPromiseQualificationSourceTransport( $native ); $this->transports[] = $transport;
		$session = new OperationConnection( get_current_blog_id(), $this->db->prefix, $transport, $this->db->charset ?: 'utf8mb4', $this->db->collate ?: '' ); $this->sessions[] = $session; return $session;
	}
	public function has_owner(): bool { foreach ( $this->sessions as $session ) { if ( ! $session->is_retired() && $session->in_transaction() ) { return true; } } return false; }
	public function all_retired(): bool { foreach ( $this->sessions as $session ) { if ( ! $session->is_retired() ) { return false; } } return true; }
	public function close_all(): bool { $ok = true; foreach ( $this->sessions as $session ) { if ( $session->in_transaction() ) { $ok = $session->rollback() && $ok; } $ok = $session->retire() && $ok; } return $ok; }
}
final class CetechPromiseQualificationSourceTransport implements OperationConnectionTransport {
	public array $sql = [];
	public function __construct( private OperationConnectionTransport $native ) {}
	public function execute( string $sql ): OperationConnectionResult { $this->sql[] = $sql; return $this->native->execute( $sql ); }
	public function connection_id(): int { return $this->native->connection_id(); }
	public function transaction_state(): ?array { return $this->native->transaction_state(); }
	public function escape( string $value ): string { return $this->native->escape( $value ); }
	public function close(): bool { return $this->native->close(); }
	/** Query trace only: same identity once per held source operation, including actual absent rows. */
	public function source_trace(): array {
		$counts = [ 'assignment_reads' => 0, 'policy_reads' => 0, 'calendar_reads' => 0 ]; $unique = []; $duplicate = false; $all_source = [];
		foreach ( $this->sql as $sql ) { if ( str_starts_with( $sql, 'SELECT ' ) && preg_match( '/\bFROM `[^`]*delivery_engine_(?:promise_(?:objects|versions|assignments)|operation_(?:records|changes))`/', $sql ) ) { $duplicate = $duplicate || isset( $all_source[$sql] ); $all_source[$sql] = true; } }
		foreach ( $this->sql as $sql ) { if ( ! str_ends_with( $sql, 'FOR UPDATE' ) ) { continue; } $kind = null; if ( str_contains( $sql, 'delivery_engine_promise_assignments`' ) ) { $kind = 'assignment_reads'; } elseif ( str_contains( $sql, 'delivery_engine_promise_objects`' ) || str_contains( $sql, 'delivery_engine_promise_versions`' ) ) { $kind = str_contains( $sql, "kind='policy'" ) ? 'policy_reads' : 'calendar_reads'; } if ( null === $kind ) { continue; } ++$counts[$kind]; $duplicate = $duplicate || isset( $unique[$sql] ); $unique[$sql] = true; }
		return [ 'counts' => $counts, 'unique' => ! $duplicate, 'source_selects' => count( $all_source ), 'source_lock_selects' => array_sum( $counts ), 'trace_sha256' => hash( 'sha256', json_encode( $this->sql, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ) ];
	}
}
final class CetechPromiseQualificationNativeAuthority implements PromisePersistenceAuthorizer {
	public int $calls = 0; public int $owned_calls = 0;
	public function __construct( private PromisePersistenceAuthorizer $original, private CetechPromiseQualificationSourceFactory $factory ) {}
	public function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool { ++$this->calls; if ( $this->factory->has_owner() ) { ++$this->owned_calls; } return $this->original->authorize( $identity, $binding, $scope, $author_user_id ); }
	public function authorize_author( PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool { if ( $this->factory->has_owner() ) { ++$this->owned_calls; } return $this->original->authorize_author( $binding, $author_user_id, $scope ); }
}
