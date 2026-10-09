<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\WordPress;

/**
 * Native MariaDB/mysqli owner transport. No wpdb, retries, reconnect or query log.
 * Unsupported transaction-state introspection refuses this new feature only.
 */
final class OperationConnectionMysqliTransport implements OperationConnectionTransport {

	private bool $closed = false;

	private function __construct( private \mysqli $handle ) {
	}

	public static function connect(
		string $host,
		string $user,
		string $password,
		string $database,
		int $port = 3306,
		?string $socket = null,
		string $charset = 'utf8mb4',
		int $flags = 0
	): self {
		if ( ! class_exists( \mysqli::class ) || str_starts_with( $host, 'p:' ) || $port < 1 || $port > 65535 || 1 !== preg_match( '/^[a-zA-Z0-9_]+$/D', $charset ) ) {
			throw new \RuntimeException( 'Operation connection is unavailable.' );
		}
		$handle = null;
		try {
			$handle = mysqli_init();
			if ( false === $handle || ! $handle->options( MYSQLI_OPT_CONNECT_TIMEOUT, 5 ) || ! $handle->options( MYSQLI_OPT_READ_TIMEOUT, 5 ) || ! @$handle->real_connect( $host, $user, $password, $database, $port, $socket, $flags ) || ! @$handle->set_charset( $charset ) ) {
				throw new \RuntimeException( 'Operation connection is unavailable.' );
			}
			$transport = new self( $handle );
			// Connection-local budgets; no global driver/reporting/session policy.
			$result = $transport->execute( 'SET SESSION innodb_lock_wait_timeout=2, lock_wait_timeout=2' );
			if ( ! $result->acknowledged || null === $transport->transaction_state() ) {
				$transport->close();
				throw new \RuntimeException( 'Operation connection is unavailable.' );
			}
			return $transport;
		} catch ( \Throwable ) {
			if ( $handle instanceof \mysqli ) {
				try {
					@$handle->close();
				} catch ( \Throwable ) {
					// A failed connection is never exposed or reused.
				}
			}
			throw new \RuntimeException( 'Operation connection is unavailable.' );
		}
	}

	public function execute( string $sql ): OperationConnectionResult {
		if ( $this->closed ) {
			return new OperationConnectionResult( false, false );
		}
		try {
			// A failure after invoking query is possibly sent. Error text cannot
			// establish otherwise, and this method never invokes a retry path.
			$result = @$this->handle->query( $sql );
			if ( false === $result ) {
				return new OperationConnectionResult( false, true, errno: $this->handle->errno );
			}
			$rows = [];
			if ( $result instanceof \mysqli_result ) {
				$rows = $result->fetch_all( MYSQLI_ASSOC );
				$result->free();
			}
			return new OperationConnectionResult( true, true, $rows, (int) $this->handle->affected_rows, (int) $this->handle->insert_id );
		} catch ( \Throwable $error ) {
			$errno = (int) $error->getCode();
			return new OperationConnectionResult( false, true, errno: max( 0, $errno ) );
		}
	}

	public function connection_id(): int {
		if ( $this->closed ) {
			return 0;
		}
		try {
			return (int) $this->handle->thread_id;
		} catch ( \Throwable ) {
			return 0;
		}
	}

	public function transaction_state(): ?array {
		$result = $this->execute( 'SELECT CONNECTION_ID() AS connection_id, @@in_transaction AS in_transaction, @@autocommit AS autocommit, @@sql_mode AS sql_mode' );
		$row = $result->rows[0] ?? null;
		if ( ! $result->acknowledged || ! is_array( $row ) || ! isset( $row['connection_id'], $row['in_transaction'], $row['autocommit'], $row['sql_mode'] ) || (int) $row['connection_id'] < 1 ) {
			return null;
		}
		// The owned literal lexer requires backslash escapes and double-quoted
		// strings. Identifier-quote modes would hide callable names from it.
		if ( [] !== array_intersect( [ 'NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES' ], explode( ',', strtoupper( (string) $row['sql_mode'] ) ) ) ) { return null; }
		return [
			'connection_id'  => (int) $row['connection_id'],
			'in_transaction' => 1 === (int) $row['in_transaction'],
			'autocommit'     => 1 === (int) $row['autocommit'],
		];
	}

	public function escape( string $value ): string {
		if ( $this->closed ) {
			throw new \RuntimeException( 'Operation connection is unavailable.' );
		}
		try {
			return $this->handle->real_escape_string( $value );
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'Operation connection is unavailable.' );
		}
	}

	public function close(): bool {
		if ( $this->closed ) {
			return true;
		}
		$this->closed = true;
		try {
			return @$this->handle->close();
		} catch ( \Throwable ) {
			return false;
		}
	}
}
