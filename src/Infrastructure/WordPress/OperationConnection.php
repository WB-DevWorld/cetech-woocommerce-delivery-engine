<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\WordPress;

use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Explicit owner of one pinned, dedicated, non-reconnecting native session. */
final class OperationConnection implements OperationSession {

	private bool $retired = false;
	private bool $retirement_confirmed = false;
	private bool $owner = false;
	private int $last_errno = 0;
	private int $last_insert_id = 0;
	private int $connection_id;
	/** @var array<string, true> */
	private array $tables = [];

	public function __construct(
		private readonly int $site,
		private readonly string $prefix,
		private readonly OperationConnectionTransport $transport,
		private readonly string $charset = 'utf8mb4',
		private readonly string $collation = ''
	) {
		if ( $site < 1 || 1 !== preg_match( '/^[a-zA-Z0-9_]{1,60}$/D', $prefix ) || 1 !== preg_match( '/^[a-zA-Z0-9_]+$/D', $charset ) || ( '' !== $collation && 1 !== preg_match( '/^[a-zA-Z0-9_]+$/D', $collation ) ) ) {
			throw new \InvalidArgumentException( 'Invalid operation connection configuration.' );
		}
		try {
			$this->connection_id = $transport->connection_id();
		} catch ( \Throwable ) {
			$this->connection_id = 0;
		}
		if ( $this->connection_id < 1 ) {
			$this->retire();
			throw new \RuntimeException( 'Operation connection is unavailable.' );
		}
	}

	public function site_id(): int {
		return $this->site;
	}

	public function table_prefix(): string {
		return $this->prefix;
	}

	public function charset_collate(): string {
		return 'DEFAULT CHARACTER SET ' . $this->charset . ( '' === $this->collation ? '' : ' COLLATE ' . $this->collation );
	}

	public function begin(): bool {
		$this->last_errno = 0;
		if ( $this->retired || $this->owner ) {
			return false;
		}
		$state = $this->state();
		if ( null === $state || $state['in_transaction'] || ! $state['autocommit'] ) {
			$this->retire();
			return false;
		}
		$result = $this->execute( 'START TRANSACTION' );
		if ( ! $result->acknowledged ) {
			$this->retire();
			return false;
		}
		$this->owner = true;
		$this->tables = [];
		return $this->guard_owner();
	}

	public function commit(): OperationCommitResult {
		if ( ! $this->guard_owner() ) {
			// Positive pre-dispatch proof only; the caller still needs an
			// acknowledged whole rollback to call this a known rejection.
			return OperationCommitResult::NotSent;
		}
		$result = $this->execute( 'COMMIT' );
		if ( ! $result->sent ) {
			return OperationCommitResult::NotSent;
		}
		if ( ! $result->acknowledged ) {
			$this->retire();
			return OperationCommitResult::Unconfirmed;
		}
		$this->owner = false;
		$this->tables = [];
		return OperationCommitResult::Acknowledged;
	}

	public function rollback(): bool {
		if ( $this->retired || ! $this->owner || null === $this->state() ) {
			return false;
		}
		$result = $this->execute( 'ROLLBACK' );
		if ( ! $result->acknowledged ) {
			$this->retire();
			return false;
		}
		$this->owner = false;
		$this->tables = [];
		return true;
	}

	public function retire(): bool {
		if ( $this->retired ) {
			return $this->retirement_confirmed;
		}
		$this->retired = true;
		$this->owner = false;
		$this->tables = [];
		try {
			$this->retirement_confirmed = $this->transport->close();
			return $this->retirement_confirmed;
		} catch ( \Throwable ) {
			return false;
		}
	}

	public function is_retired(): bool {
		return $this->retired;
	}

	public function in_transaction(): bool {
		return $this->owner && ! $this->retired;
	}

	/** @param list<string> $table_names */
	public function validate_tables( array $table_names ): bool {
		if ( ! $this->guard_owner() || ! array_is_list( $table_names ) || [] === $table_names || count( $table_names ) > 16 ) {
			return false;
		}
		foreach ( $table_names as $table ) {
			if ( ! is_string( $table ) || ! str_starts_with( $table, $this->prefix ) || 1 !== preg_match( '/^[a-zA-Z0-9_]{1,64}$/D', $table ) ) {
				return false;
			}
		}
		foreach ( array_unique( $table_names ) as $table ) {
			// Acquire and hold the actual participant's metadata lock before
			// checking engine. A later DDL conversion cannot invalidate this unit.
			$probe = $this->execute( 'SELECT 1 FROM `' . $table . '` LIMIT 0' );
			if ( ! $probe->acknowledged ) {
				return false;
			}
			$engine = $this->execute( $this->prepare( 'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( ! $engine->acknowledged || 1 !== count( $engine->rows ) || 'INNODB' !== strtoupper( (string) ( $engine->rows[0]['engine'] ?? '' ) ) ) {
				return false;
			}
			$this->tables[$table] = true;
		}
		return true;
	}

	public function query( string $sql ): int|false {
		$result = $this->statement( $sql );
		return null === $result || ! $result->acknowledged ? false : $result->affected_rows;
	}

	public function get_row( string $sql ): array|null|false {
		$rows = $this->get_results( $sql );
		return false === $rows ? false : ( $rows[0] ?? null );
	}

	public function get_results( string $sql ): array|false {
		if ( 1 !== preg_match( '/^\s*(?:SELECT|SHOW)\b/i', $sql ) ) {
			return false;
		}
		$result = $this->statement( $sql );
		return null === $result || ! $result->acknowledged ? false : $result->rows;
	}

	public function prepare( string $sql, mixed ...$args ): string {
		if ( $this->retired ) {
			throw new \RuntimeException( 'Operation connection is unavailable.' );
		}
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}
		$index = 0;
		$result = preg_replace_callback( '/%([sdf%])/', function ( array $match ) use ( &$index, $args ): string {
			if ( '%' === $match[1] ) {
				return '%';
			}
			if ( ! array_key_exists( $index, $args ) ) {
				throw new \InvalidArgumentException( 'Invalid operation query parameters.' );
			}
			$value = $args[$index++];
			if ( 'd' === $match[1] && is_int( $value ) ) {
				return (string) $value;
			}
			if ( 's' === $match[1] && is_string( $value ) ) {
				try {
					return "'" . $this->transport->escape( $value ) . "'";
				} catch ( \Throwable ) {
					$this->retire();
					throw new \RuntimeException( 'Operation connection is unavailable.' );
				}
			}
			if ( 'f' === $match[1] && ( is_float( $value ) || is_int( $value ) ) && is_finite( (float) $value ) ) {
				return json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
			}
			throw new \InvalidArgumentException( 'Invalid operation query parameters.' );
		}, $sql );
		if ( ! is_string( $result ) || $index !== count( $args ) || 1 === preg_match( '/%(?![%sdf])/', $sql ) ) {
			throw new \InvalidArgumentException( 'Invalid operation query parameters.' );
		}
		return $result;
	}

	public function errno(): int {
		return $this->last_errno;
	}

	public function insert_id(): int {
		return $this->last_insert_id;
	}

	private function statement( string $sql ): ?OperationConnectionResult {
		$this->last_errno = 0;
		if ( ! $this->guard_owner() || ! $this->allowed_statement( $sql ) ) {
			return null;
		}
		return $this->execute( $sql );
	}

	private function allowed_statement( string $sql ): bool {
		// Ignore escaped literal contents while rejecting comments/multiple
		// statements/implicit commit and external-file constructs in actual SQL.
		$structural = preg_replace( "/'(?:''|\\\\.|[^'\\\\])*'|\"(?:\"\"|\\\\.|[^\"\\\\])*\"/s", "''", $sql );
		if ( ! is_string( $structural ) || 1 === preg_match( '/;|--|#|\/\*|\b(?:OUTFILE|DUMPFILE|PROCEDURE)\b|\bINTO\s+@/i', $structural ) ) {
			return false;
		}
		if ( 1 === preg_match( '/^\s*SELECT\b/i', $structural ) ) {
			return $this->safe_calls( $structural );
		}
		if ( 1 === preg_match( '/^\s*SHOW\b/i', $structural ) ) {
			return true;
		}
		if ( 1 !== preg_match( '/^\s*(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(`?)([a-zA-Z0-9_]+)\2(?:\s+|(?=\())(.*)$/is', $structural, $matches ) || ! isset( $this->tables[$matches[3]] ) ) {
			return false;
		}
		$verb = strtoupper( preg_replace( '/\s+/', ' ', $matches[1] ) );
		$tail = $matches[4];
		if ( ( 'UPDATE' === $verb && 1 !== preg_match( '/^SET\b/i', $tail ) ) || ( 'DELETE FROM' === $verb && 1 !== preg_match( '/^WHERE\b/i', $tail ) ) || ( 'INSERT INTO' === $verb && 1 !== preg_match( '/^(?:\(|VALUES\b)/i', $tail ) ) ) {
			return false;
		}
		// One declared write target. Joins, multi-table updates and INSERT SELECT
		// need a separately reviewed participant contract rather than a loophole.
		return 1 !== preg_match( '/\b(?:JOIN|SELECT|CALL|RETURNING)\b|\bON\s+DUPLICATE\s+KEY\b/i', $structural ) && $this->safe_calls( $tail );
	}

	private function safe_calls( string $sql ): bool {
		if ( 1 === preg_match( '/`?[a-zA-Z0-9_]+`?\s*\.\s*`?[a-zA-Z_][a-zA-Z0-9_]*`?\s*\(/', $sql ) ) {
			return false;
		}
		// The C07 current read pins the verified native options unique key.
		// This exact SQL clause is syntax, not a callable named INDEX. Other
		// index expressions and every unknown function remain refused.
		$sql = str_replace( ' FORCE INDEX (`option_name`)', '', $sql );
		preg_match_all( '/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $sql, $calls );
		foreach ( $calls[1] as $call ) {
			if ( ! in_array( strtoupper( $call ), [ 'COUNT', 'MIN', 'MAX', 'SUM', 'AVG', 'COALESCE', 'IFNULL', 'CAST', 'CONVERT', 'DATABASE', 'CONNECTION_ID', 'NOW', 'UTC_TIMESTAMP', 'OCTET_LENGTH', 'LENGTH', 'CHAR_LENGTH', 'IN', 'VALUES', 'WHERE', 'AND', 'OR', 'NOT' ], true ) ) {
				return false;
			}
		}
		return true;
	}

	private function guard_owner(): bool {
		if ( $this->retired || ! $this->owner ) {
			return false;
		}
		$state = $this->state();
		if ( null === $state || ! $state['in_transaction'] || ! $state['autocommit'] ) {
			$this->retire();
			return false;
		}
		return true;
	}

	private function state(): ?array {
		if ( $this->retired || ! $this->same_connection() ) {
			$this->retire();
			return null;
		}
		try {
			$state = $this->transport->transaction_state();
		} catch ( \Throwable ) {
			$state = null;
		}
		if ( ! is_array( $state ) || $this->connection_id !== ( $state['connection_id'] ?? null ) || ! is_bool( $state['in_transaction'] ?? null ) || ! is_bool( $state['autocommit'] ?? null ) ) {
			$this->retire();
			return null;
		}
		return $state;
	}

	private function execute( string $sql ): OperationConnectionResult {
		try {
			$result = $this->transport->execute( $sql );
		} catch ( \Throwable ) {
			$result = new OperationConnectionResult( false, true );
		}
		$this->last_errno = $result->errno;
		$this->last_insert_id = $result->insert_id;
		if ( ! $this->same_connection() || in_array( $result->errno, [ 2006, 2013, 2055 ], true ) ) {
			$this->retire();
			return new OperationConnectionResult( false, $result->sent, errno: $result->errno );
		}
		return $result;
	}

	private function same_connection(): bool {
		try {
			return $this->connection_id === $this->transport->connection_id();
		} catch ( \Throwable ) {
			return false;
		}
	}
}
