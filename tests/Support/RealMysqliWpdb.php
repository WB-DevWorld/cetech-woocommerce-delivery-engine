<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

/**
 * Thin PDO wpdb adapter for isolated geo.11 real-database proofs.
 *
 * Not a production WordPress dependency. Used only by @group geo11-real-db.
 */
final class RealMysqliWpdb {

	public string $prefix = 'wp_';

	public string $options = 'wp_options';

	public int $insert_id = 0;

	public string $last_error = '';

	public int $rows_affected = 0;

	/** @var list<string> */
	public array $sql_log = [];

	private \PDO $pdo;

	public function __construct( \PDO $pdo, string $prefix = 'wp_' ) {
		$this->pdo     = $pdo;
		$this->prefix  = $prefix;
		$this->options = $prefix . 'options';
	}

	public static function try_connect(): ?self {
		if ( ! class_exists( \PDO::class ) || ! in_array( 'mysql', \PDO::getAvailableDrivers(), true ) ) {
			return null;
		}

		$host = (string) ( getenv( 'CETECH_DE_REAL_DB_HOST' ) ?: '127.0.0.1' );
		$port = (int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 33078 );
		$user = (string) ( getenv( 'CETECH_DE_REAL_DB_USER' ) ?: 'root' );
		$pass = (string) ( getenv( 'CETECH_DE_REAL_DB_PASSWORD' ) ?: 'geo11pass' );
		$name = (string) ( getenv( 'CETECH_DE_REAL_DB_NAME' ) ?: 'cetech_geo11' );
		$dsn  = sprintf( 'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name );

		try {
			$pdo = new \PDO(
				$dsn,
				$user,
				$pass,
				[
					\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_SILENT,
					\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
				]
			);
		} catch ( \PDOException ) {
			return null;
		}

		return new self( $pdo );
	}

	public function clear_sql_log(): void {
		$this->sql_log = [];
	}

	private function log_sql( string $sql ): void {
		$this->sql_log[] = $sql;
	}

	public function pdo(): \PDO {
		return $this->pdo;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, "_%\\" );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$i = 0;

		return (string) preg_replace_callback(
			'/%[sdfF]/',
			function ( array $matches ) use ( &$i, $args ): string {
				if ( ! array_key_exists( $i, $args ) ) {
					throw new \RuntimeException( 'wpdb prepare argument mismatch.' );
				}
				$value  = $args[ $i++ ];
				$format = $matches[0];
				if ( '%d' === $format ) {
					return (string) (int) $value;
				}
				if ( '%f' === $format || '%F' === $format ) {
					return (string) (float) $value;
				}

				return $this->pdo->quote( (string) $value );
			},
			$query
		);
	}

	public function query( mixed $sql ): int|bool {
		$sql = (string) $sql;
		$this->log_sql( $sql );
		$result = $this->pdo->exec( $sql );
		if ( false === $result ) {
			$this->last_error    = $this->error_message();
			$this->rows_affected = 0;

			return false;
		}
		$this->last_error    = '';
		$this->rows_affected = (int) $result;

		return $this->rows_affected;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string>|null    $format
	 * @return int|false
	 */
	public function insert( string $table, array $data, $format = null ) {
		unset( $format );
		$columns = [];
		$values  = [];
		foreach ( $data as $column => $value ) {
			$columns[] = '`' . str_replace( '`', '', (string) $column ) . '`';
			$values[]  = $this->sql_value( $value );
		}
		$sql    = 'INSERT INTO `' . str_replace( '`', '', $table ) . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ')';
		$result = $this->pdo->exec( $sql );
		if ( false === $result ) {
			$this->last_error    = $this->error_message();
			$this->insert_id     = 0;
			$this->rows_affected = 0;

			return false;
		}
		$this->last_error    = '';
		$this->insert_id     = (int) $this->pdo->lastInsertId();
		$this->rows_affected = (int) $result;

		return $this->rows_affected;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 * @param list<string>|null    $format
	 * @param list<string>|null    $where_format
	 * @return int|false
	 */
	public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
		unset( $format, $where_format );
		$sets = [];
		foreach ( $data as $column => $value ) {
			$sets[] = '`' . str_replace( '`', '', (string) $column ) . '` = ' . $this->sql_value( $value );
		}
		$sql    = 'UPDATE `' . str_replace( '`', '', $table ) . '` SET ' . implode( ', ', $sets ) . ' WHERE ' . $this->where_sql( $where );
		$result = $this->pdo->exec( $sql );
		if ( false === $result ) {
			$this->last_error    = $this->error_message();
			$this->rows_affected = 0;

			return false;
		}
		$this->last_error    = '';
		$this->rows_affected = (int) $result;

		return $this->rows_affected;
	}

	/**
	 * @param array<string, mixed> $where
	 * @param list<string>|null    $where_format
	 * @return int|false
	 */
	public function delete( string $table, array $where, $where_format = null ) {
		unset( $where_format );
		$sql    = 'DELETE FROM `' . str_replace( '`', '', $table ) . '` WHERE ' . $this->where_sql( $where );
		$result = $this->pdo->exec( $sql );
		if ( false === $result ) {
			$this->last_error    = $this->error_message();
			$this->rows_affected = 0;

			return false;
		}
		$this->last_error    = '';
		$this->rows_affected = (int) $result;

		return $this->rows_affected;
	}

	/**
	 * @return list<string>
	 */
	public function get_col( string $sql ): array {
		$this->log_sql( $sql );
		$statement = $this->pdo->query( $sql );
		if ( false === $statement ) {
			$this->last_error = $this->error_message();

			return [];
		}
		$this->last_error = '';
		$values           = $statement->fetchAll( \PDO::FETCH_COLUMN, 0 );

		return is_array( $values ) ? array_map( 'strval', $values ) : [];
	}

	public function get_var( string $sql ) {
		$this->log_sql( $sql );
		$statement = $this->pdo->query( $sql );
		if ( false === $statement ) {
			$this->last_error = $this->error_message();

			return null;
		}
		$this->last_error = '';
		$row              = $statement->fetch( \PDO::FETCH_NUM );

		return is_array( $row ) ? $row[0] : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $sql, $output = 'ARRAY_A' ): ?array {
		unset( $output );
		$this->log_sql( $sql );
		$statement = $this->pdo->query( $sql );
		if ( false === $statement ) {
			$this->last_error = $this->error_message();

			return null;
		}
		$this->last_error = '';
		$row              = $statement->fetch( \PDO::FETCH_ASSOC );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $sql, $output = 'ARRAY_A' ): array {
		unset( $output );
		$this->log_sql( $sql );
		$statement = $this->pdo->query( $sql );
		if ( false === $statement ) {
			$this->last_error = $this->error_message();

			return [];
		}
		$this->last_error = '';
		$rows             = $statement->fetchAll( \PDO::FETCH_ASSOC );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @param array<string, mixed> $where
	 */
	private function where_sql( array $where ): string {
		$parts = [];
		foreach ( $where as $column => $value ) {
			$parts[] = '`' . str_replace( '`', '', (string) $column ) . '` = ' . $this->sql_value( $value );
		}

		return implode( ' AND ', $parts );
	}

	private function sql_value( mixed $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		return (string) $this->pdo->quote( (string) $value );
	}

	private function error_message(): string {
		$info = $this->pdo->errorInfo();

		return (string) ( $info[2] ?? 'pdo_error' );
	}
}
