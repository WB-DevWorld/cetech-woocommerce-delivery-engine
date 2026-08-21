<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

/**
 * Minimal wpdb double for shipment repository tests.
 *
 * Supports insert/update/delete plus a small SELECT subset used by WpdbShipmentRepository.
 */
final class FakeWpdb {

	public string $prefix = 'wp_';

	public int $insert_id = 0;

	public string $last_error = '';

	public int $query_count = 0;

	/** @var list<string> */
	public array $sql_log = [];

	/** @var array<string, list<array<string, mixed>>> */
	private array $tables = [];

	/** @var array<string, list<list<string>>> */
	private array $unique_indexes = [];

	/** @var array<string, int> */
	private array $auto_increment = [];

	public ?string $fail_next_insert_table = null;

	/** @var array<string, list<array<string, mixed>>>|null */
	private ?array $transaction_tables = null;

	/** @var array<string, int>|null */
	private ?array $transaction_auto_increment = null;

	private int $transaction_insert_id = 0;

	/**
	 * @param list<list<string>> $unique_indexes
	 */
	public function create_table( string $table, array $unique_indexes = [] ): void {
		$this->tables[ $table ]         = [];
		$this->unique_indexes[ $table ] = $unique_indexes;
		$this->auto_increment[ $table ] = 1;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function table_rows( string $table ): array {
		return $this->tables[ $table ] ?? [];
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, "_%\\" );
	}

	public function register_table( string $table, array $unique_indexes = [] ): void {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->create_table( $table, $unique_indexes );
		}
	}

	public function has_table( string $table ): bool {
		return isset( $this->tables[ $table ] );
	}

	/**
	 * @return list<string>
	 */
	public function table_names(): array {
		return array_keys( $this->tables );
	}

	public function query( mixed $sql ): bool {
		$this->record_sql( (string) $sql );
		$normalized = strtoupper( trim( (string) $sql ) );

		if ( preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`\s*$/i', trim( (string) $sql ), $drop ) ) {
			unset( $this->tables[ $drop[1] ], $this->unique_indexes[ $drop[1] ], $this->auto_increment[ $drop[1] ] );

			return true;
		}

		if ( 'START TRANSACTION' === $normalized ) {
			$this->transaction_tables         = unserialize( serialize( $this->tables ) );
			$this->transaction_auto_increment = $this->auto_increment;
			$this->transaction_insert_id      = $this->insert_id;

			return true;
		}

		if ( 'COMMIT' === $normalized ) {
			$this->transaction_tables         = null;
			$this->transaction_auto_increment = null;

			return true;
		}

		if ( 'ROLLBACK' === $normalized ) {
			if ( null !== $this->transaction_tables ) {
				$this->tables         = $this->transaction_tables;
				$this->auto_increment = $this->transaction_auto_increment ?? $this->auto_increment;
				$this->insert_id      = $this->transaction_insert_id;
			}

			$this->transaction_tables         = null;
			$this->transaction_auto_increment = null;

			return true;
		}

		throw new \RuntimeException( 'Unsupported SQL: ' . (string) $sql );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$i = 0;

		return (string) preg_replace_callback(
			'/%[sdfF]/',
			static function ( array $matches ) use ( &$i, $args ): string {
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

				return "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], (string) $value ) . "'";
			},
			$query
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string>|null    $format
	 * @return int|false
	 */
	public function insert( string $table, array $data, $format = null ) {
		unset( $format );
		$this->record_sql( 'INSERT ' . $table );
		$this->last_error = '';

		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->last_error = 'Table not found';

			return false;
		}

		if ( $this->fail_next_insert_table === $table ) {
			$this->fail_next_insert_table = null;
			$this->last_error             = 'Simulated insert failure';

			return false;
		}

		if ( ! isset( $data['id'] ) || 0 === (int) $data['id'] ) {
			$data['id'] = $this->auto_increment[ $table ];
			++$this->auto_increment[ $table ];
		}

		if ( $this->violates_unique( $table, $data, null ) ) {
			$this->last_error = "Duplicate entry for key on {$table}";

			return false;
		}

		$this->tables[ $table ][] = $data;
		$this->insert_id          = (int) $data['id'];

		return 1;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 * @return int|false
	 */
	public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
		unset( $format, $where_format );
		$this->last_error = '';
		$updated          = 0;

		foreach ( $this->tables[ $table ] ?? [] as $index => $row ) {
			if ( ! $this->row_matches( $row, $where ) ) {
				continue;
			}

			$merged = array_merge( $row, $data );

			if ( $this->violates_unique( $table, $merged, $index ) ) {
				$this->last_error = "Duplicate entry for key on {$table}";

				return false;
			}

			$this->tables[ $table ][ $index ] = $merged;
			++$updated;
		}

		return $updated;
	}

	/**
	 * @param array<string, mixed> $where
	 * @return int|false
	 */
	public function delete( string $table, array $where, $where_format = null ) {
		unset( $where_format );
		$this->last_error = '';
		$deleted          = 0;
		$kept             = [];

		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			if ( $this->row_matches( $row, $where ) ) {
				++$deleted;
				continue;
			}

			$kept[] = $row;
		}

		$this->tables[ $table ] = $kept;

		return $deleted;
	}

	public function get_var( string $sql ) {
		$this->record_sql( $sql );
		$trimmed = trim( $sql );

		if ( preg_match( '/^SHOW TABLES LIKE \'((?:\\\\\'|[^\'])*)\'\s*$/i', $trimmed, $like ) ) {
			$name = stripcslashes( $like[1] );

			return isset( $this->tables[ $name ] ) ? $name : null;
		}

		if ( preg_match( '/SELECT\s+COALESCE\(\s*MAX\(\s*`id`\s*\)\s*,\s*0\s*\)\s+FROM\s+`([^`]+)`\s*$/is', $trimmed, $matches ) ) {
			$max = 0;

			foreach ( $this->tables[ $matches[1] ] ?? [] as $row ) {
				$max = max( $max, (int) ( $row['id'] ?? 0 ) );
			}

			return (string) $max;
		}

		if ( preg_match( '/SELECT\s+COUNT\(\s*DISTINCT\s+`shipment_id`\s*\)\s+FROM\s+`([^`]+)`\s+WHERE\s+`id`\s*>\s*(\d+)\s*$/is', $trimmed, $matches ) ) {
			$after = (int) $matches[2];
			$ids   = [];

			foreach ( $this->tables[ $matches[1] ] ?? [] as $row ) {
				if ( (int) ( $row['id'] ?? 0 ) <= $after ) {
					continue;
				}

				$shipment_id = (int) ( $row['shipment_id'] ?? 0 );

				if ( $shipment_id > 0 ) {
					$ids[ $shipment_id ] = true;
				}
			}

			return (string) count( $ids );
		}

		$parsed = $this->parse_select( $sql );
		$rows   = $this->filter_rows( $parsed );

		if ( $parsed['count'] ) {
			return (string) count( $rows );
		}

		if ( [] === $rows ) {
			return null;
		}

		$first = $rows[0];
		$keys  = array_keys( $first );

		return $first[ $keys[0] ] ?? null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $sql, $output = ARRAY_A ) {
		unset( $output );
		$this->record_sql( $sql );
		$trimmed = trim( $sql );

		if ( preg_match( '/^SHOW INDEX FROM `([^`]+)` WHERE Key_name = \'((?:\\\\\'|[^\'])*)\'\s*$/i', $trimmed, $index ) ) {
			$table = $index[1];
			$name  = stripcslashes( $index[2] );

			if ( isset( $this->tables[ $table ] ) ) {
				return [
					'Table'    => $table,
					'Key_name' => $name,
				];
			}

			return null;
		}

		$parsed = $this->parse_select( $sql );
		$rows   = $this->filter_rows( $parsed );

		return $rows[0] ?? null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $sql, $output = ARRAY_A ) {
		unset( $output );
		$this->record_sql( $sql );

		$grouped = $this->grouped_counts( $sql );

		if ( null !== $grouped ) {
			return $grouped;
		}

		$parsed = $this->parse_select( $sql );

		return $this->filter_rows( $parsed );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $where
	 */
	private function row_matches( array $row, array $where ): bool {
		foreach ( $where as $column => $value ) {
			if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param list<array{column: string, op: string, value: string}> $clauses
	 */
	private function row_matches_any( array $row, array $clauses ): bool {
		foreach ( $clauses as $clause ) {
			$actual = (string) ( $row[ $clause['column'] ] ?? '' );

			if ( 'like' === $clause['op'] && $this->like_matches( $actual, $clause['value'] ) ) {
				return true;
			}

			if ( '=' === $clause['op'] && $actual === (string) $clause['value'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $candidate
	 */
	private function violates_unique( string $table, array $candidate, ?int $skip_index ): bool {
		foreach ( $this->unique_indexes[ $table ] ?? [] as $columns ) {
			foreach ( $this->tables[ $table ] as $index => $row ) {
				if ( $skip_index === $index ) {
					continue;
				}

				$same = true;

				foreach ( $columns as $column ) {
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) ( $candidate[ $column ] ?? '' ) ) {
						$same = false;
						break;
					}
				}

				if ( $same ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @return array{table: string, count: bool, where: array<string, mixed>, or_any: list<array{column: string, op: string, value: string}>, order: list<array{column: string, direction: string}>, limit: ?int, offset: int}
	 */
	private function parse_select( string $sql ): array {
		if ( ! preg_match(
			'/SELECT\s+(COUNT\(\*\)|\*)\s+FROM\s+`([^`]+)`\s+WHERE\s+(.+?)(?:\s+ORDER BY\s+(.+?))?(?:\s+LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?)?\s*$/is',
			trim( $sql ),
			$matches
		) ) {
			throw new \RuntimeException( 'Unsupported SQL: ' . $sql );
		}

		$where_sql = (string) $matches[3];
		$or_any    = [];

		if ( preg_match( '/AND\s+\((.*)\)\s*$/is', $where_sql, $or_match ) ) {
			$or_sql    = (string) $or_match[1];
			$where_sql = substr( $where_sql, 0, -strlen( $or_match[0] ) );
			$or_any    = $this->parse_or_clauses( $or_sql );
		}

		$where = [];

		if ( preg_match_all(
			'/(?:`([a-z0-9_]+)`|([a-z0-9_]+))\s*=\s*(?:\'((?:\\\\\'|[^\'])*)\'|(\d+)|NULL)/i',
			$where_sql,
			$condition_matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $condition_matches as $condition ) {
				$column = ( $condition[1] ?? '' ) !== '' ? $condition[1] : ( $condition[2] ?? '' );

				if ( '1' === $column || '' === $column ) {
					continue;
				}

				if ( ( $condition[3] ?? '' ) !== '' ) {
					$where[ $column ] = stripcslashes( $condition[3] );
					continue;
				}

				if ( ( $condition[4] ?? '' ) !== '' ) {
					$where[ $column ] = $condition[4];
				}
			}
		}

		$order = [];

		if ( isset( $matches[4] ) && '' !== trim( (string) $matches[4] ) ) {
			foreach ( explode( ',', (string) $matches[4] ) as $part ) {
				if ( preg_match( '/`?([a-z0-9_]+)`?\s*(ASC|DESC)?/i', trim( $part ), $order_match ) ) {
					$order[] = [
						'column'    => $order_match[1],
						'direction' => strtoupper( $order_match[2] ?? 'ASC' ),
					];
				}
			}
		}

		return [
			'table'   => $matches[2],
			'count'   => 0 === strcasecmp( $matches[1], 'COUNT(*)' ),
			'where'   => $where,
			'or_any'  => $or_any,
			'order'   => $order,
			'limit'   => isset( $matches[5] ) && '' !== $matches[5] ? (int) $matches[5] : null,
			'offset'  => isset( $matches[6] ) && '' !== $matches[6] ? (int) $matches[6] : 0,
		];
	}

	/**
	 * @return list<array{column: string, op: string, value: string}>
	 */
	private function parse_or_clauses( string $sql ): array {
		$clauses = [];

		if ( preg_match_all(
			'/`([a-z0-9_]+)`\s+LIKE\s+\'((?:\\\\\'|[^\'])*)\'/i',
			$sql,
			$likes,
			PREG_SET_ORDER
		) ) {
			foreach ( $likes as $like ) {
				$clauses[] = [
					'column' => $like[1],
					'op'     => 'like',
					'value'  => stripcslashes( $like[2] ),
				];
			}
		}

		if ( preg_match_all(
			'/`([a-z0-9_]+)`\s*=\s*(?:\'((?:\\\\\'|[^\'])*)\'|(\d+))/i',
			$sql,
			$equals,
			PREG_SET_ORDER
		) ) {
			foreach ( $equals as $equal ) {
				$clauses[] = [
					'column' => $equal[1],
					'op'     => '=',
					'value'  => ( $equal[2] ?? '' ) !== '' ? stripcslashes( $equal[2] ) : (string) ( $equal[3] ?? '' ),
				];
			}
		}

		return $clauses;
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	private function grouped_counts( string $sql ): ?array {
		if ( ! preg_match(
			'/SELECT\s+`shipment_id`,\s+COUNT\(\*\)\s+AS\s+`item_count`\s+FROM\s+`([^`]+)`\s+WHERE\s+`shipment_id`\s+IN\s+\(([\d,\s]+)\)\s+GROUP BY\s+`shipment_id`\s*$/is',
			trim( $sql ),
			$matches
		) ) {
			return null;
		}

		$ids = [];

		foreach ( explode( ',', $matches[2] ) as $id ) {
			$ids[] = (int) $id;
		}

		$counts = [];

		foreach ( $this->tables[ $matches[1] ] ?? [] as $row ) {
			$shipment_id = (int) ( $row['shipment_id'] ?? 0 );

			if ( in_array( $shipment_id, $ids, true ) ) {
				$counts[ $shipment_id ] = ( $counts[ $shipment_id ] ?? 0 ) + 1;
			}
		}

		$result = [];

		foreach ( $counts as $shipment_id => $count ) {
			$result[] = [
				'shipment_id' => $shipment_id,
				'item_count'  => $count,
			];
		}

		return $result;
	}

	private function record_sql( string $sql ): void {
		++$this->query_count;
		$this->sql_log[] = $sql;
	}

	private function like_matches( string $value, string $pattern ): bool {
		$regex = '/^' . str_replace( '%', '.*', preg_quote( $pattern, '/' ) ) . '$/i';

		return 1 === preg_match( $regex, $value );
	}

	/**
	 * @param array{table: string, count: bool, where: array<string, mixed>, or_any: list<array{column: string, op: string, value: string}>, order: list<array{column: string, direction: string}>, limit: ?int, offset: int} $parsed
	 * @return list<array<string, mixed>>
	 */
	private function filter_rows( array $parsed ): array {
		$rows = $this->tables[ $parsed['table'] ] ?? [];
		$filtered = [];

		foreach ( $rows as $row ) {
			if ( ! $this->row_matches( $row, $parsed['where'] ) ) {
				continue;
			}

			if ( [] !== $parsed['or_any'] && ! $this->row_matches_any( $row, $parsed['or_any'] ) ) {
				continue;
			}

			$filtered[] = $row;
		}

		if ( [] !== $parsed['order'] ) {
			usort(
				$filtered,
				static function ( array $a, array $b ) use ( $parsed ): int {
					foreach ( $parsed['order'] as $order ) {
						$left  = (string) ( $a[ $order['column'] ] ?? '' );
						$right = (string) ( $b[ $order['column'] ] ?? '' );
						$cmp   = $left <=> $right;

						if ( 0 !== $cmp ) {
							return 'DESC' === $order['direction'] ? -$cmp : $cmp;
						}
					}

					return 0;
				}
			);
		}

		if ( null !== $parsed['limit'] ) {
			$filtered = array_slice( $filtered, $parsed['offset'], $parsed['limit'] );
		}

		return array_values( $filtered );
	}
}
