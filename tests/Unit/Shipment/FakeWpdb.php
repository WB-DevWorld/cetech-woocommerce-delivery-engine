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

	public int $id_only_updates = 0;

	public int $rows_affected = 0;

	public int $max_select_row_count = 0;

	/** @var list<string> */
	public array $sql_log = [];

	/** @var array<string, list<array<string, mixed>>> */
	private array $tables = [];

	/** @var array<string, list<list<string>>> */
	private array $unique_indexes = [];

	/** @var array<string, int> */
	private array $auto_increment = [];

	public ?string $fail_next_insert_table = null;

	public ?string $fail_next_delete_table = null;

	public ?string $fail_next_update_table = null;

	public ?string $fail_next_update_column = null;

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

	public function query( mixed $sql ): int|bool {
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

		$mutated = $this->execute_mutation( trim( (string) $sql ) );
		if ( null !== $mutated ) {
			$this->rows_affected = false === $mutated ? 0 : (int) $mutated;

			return $mutated;
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
		if ( $this->fail_next_update_table === $table ) {
			$this->fail_next_update_table = null;
			$this->last_error             = 'Simulated update failure';

			return false;
		}
		if ( null !== $this->fail_next_update_column && array_key_exists( $this->fail_next_update_column, $data ) && count( $data ) <= 3 ) {
			$this->fail_next_update_column = null;
			$this->last_error              = 'Simulated column update failure';

			return false;
		}
		$updated          = 0;

		if ( 1 === count( $where ) && array_key_exists( 'id', $where ) ) {
			++$this->id_only_updates;
		}

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
		if ( $this->fail_next_delete_table === $table ) {
			$this->fail_next_delete_table = null;
			$this->last_error             = 'Simulated delete failure';

			return false;
		}
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

		if ( preg_match( '/^SHOW COLUMNS FROM `([^`]+)` LIKE \'((?:\\\\\'|[^\'])*)\'\s*$/i', $trimmed, $column ) ) {
			$table = $column[1];
			$name  = stripcslashes( $column[2] );

			if ( isset( $this->tables[ $table ] ) ) {
				return [
					'Field' => $name,
					'Type'  => 'varchar(191)',
				];
			}

			return null;
		}

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
		$rows   = $this->filter_rows( $parsed );
		$count  = count( $rows );
		if ( $count > $this->max_select_row_count ) {
			$this->max_select_row_count = $count;
		}

		return $rows;
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

			if ( '!=' === $clause['op'] && $actual !== (string) $clause['value'] ) {
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
			'/SELECT\s+(COUNT\(\*\)|\*|id|`id`)\s+FROM\s+`([^`]+)`\s+WHERE\s+(.+?)(?:\s+ORDER BY\s+(.+?))?(?:\s+LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?)?\s*$/is',
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
		$in    = [];

		if ( preg_match_all(
			'/(?:`([a-z0-9_]+)`|([a-z0-9_]+))\s+IN\s+\(([\d,\s]+)\)/i',
			$where_sql,
			$in_matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $in_matches as $in_match ) {
				$column = ( $in_match[1] ?? '' ) !== '' ? $in_match[1] : (string) ( $in_match[2] ?? '' );
				if ( '' === $column ) {
					continue;
				}
				$values = [];
				foreach ( explode( ',', $in_match[3] ) as $value ) {
					$values[] = trim( $value );
				}
				$in[ $column ] = $values;
			}
		}

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

		$gt = [];
		if ( preg_match_all(
			'/(?:`([a-z0-9_]+)`|([a-z0-9_]+))\s*>\s*(\d+)/i',
			$where_sql,
			$gt_matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $gt_matches as $gt_match ) {
				$column = ( $gt_match[1] ?? '' ) !== '' ? $gt_match[1] : (string) ( $gt_match[2] ?? '' );
				if ( '' !== $column ) {
					$gt[ $column ] = (int) $gt_match[3];
				}
			}
		}

		$lt = [];
		if ( preg_match_all(
			'/(?:`([a-z0-9_]+)`|([a-z0-9_]+))\s*<\s*(\d+)/i',
			$where_sql,
			$lt_matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $lt_matches as $lt_match ) {
				$column = ( $lt_match[1] ?? '' ) !== '' ? $lt_match[1] : (string) ( $lt_match[2] ?? '' );
				if ( '' !== $column ) {
					$lt[ $column ] = (int) $lt_match[3];
				}
			}
		}

		$neq = [];
		if ( preg_match_all(
			'/(?:`([a-z0-9_]+)`|([a-z0-9_]+))\s*(?:!=|<>)\s*(?:\'((?:\\\\\'|[^\'])*)\'|(\d+))/i',
			$where_sql,
			$neq_matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $neq_matches as $neq_match ) {
				$column = ( $neq_match[1] ?? '' ) !== '' ? $neq_match[1] : (string) ( $neq_match[2] ?? '' );
				if ( '' === $column ) {
					continue;
				}
				$neq[ $column ] = ( $neq_match[3] ?? '' ) !== '' ? stripcslashes( $neq_match[3] ) : (string) ( $neq_match[4] ?? '' );
			}
		}

		$like = [];
		if ( preg_match_all(
			'/(?:`([a-z0-9_]+)`|([a-z0-9_]+))\s+LIKE\s+\'((?:\\\\\'|[^\'])*)\'/i',
			$where_sql,
			$like_matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $like_matches as $like_match ) {
				$column = ( $like_match[1] ?? '' ) !== '' ? $like_match[1] : (string) ( $like_match[2] ?? '' );
				if ( '' !== $column ) {
					$like[ $column ] = stripcslashes( $like_match[3] );
				}
			}
		}

		$eq_column = [];
		if ( preg_match_all(
			'/(?:`([a-z0-9_]+)`|([a-z][a-z0-9_]*))\s*=\s*(?:`([a-z0-9_]+)`|([a-z][a-z0-9_]*))/i',
			$where_sql,
			$eq_col_matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $eq_col_matches as $eq_col ) {
				$left  = ( $eq_col[1] ?? '' ) !== '' ? $eq_col[1] : (string) ( $eq_col[2] ?? '' );
				$right = ( $eq_col[3] ?? '' ) !== '' ? $eq_col[3] : (string) ( $eq_col[4] ?? '' );
				if ( '' === $left || '' === $right ) {
					continue;
				}
				$eq_column[ $left ] = $right;
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
			'in'      => $in,
			'or_any'  => $or_any,
			'gt'      => $gt,
			'lt'      => $lt,
			'neq'     => $neq,
			'like'      => $like,
			'eq_column' => $eq_column,
			'order'     => $order,
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
			'/(?:`([a-z0-9_]+)`|([a-z0-9_]+))\s+LIKE\s+\'((?:\\\\\'|[^\'])*)\'/i',
			$sql,
			$likes,
			PREG_SET_ORDER
		) ) {
			foreach ( $likes as $like ) {
				$column = ( $like[1] ?? '' ) !== '' ? $like[1] : (string) ( $like[2] ?? '' );
				if ( '' === $column ) {
					continue;
				}
				$clauses[] = [
					'column' => $column,
					'op'     => 'like',
					'value'  => stripcslashes( $like[3] ),
				];
			}
		}

		if ( preg_match_all(
			'/(?:`([a-z0-9_]+)`|([a-z0-9_]+))\s*=\s*(?:\'((?:\\\\\'|[^\'])*)\'|(\d+))/i',
			$sql,
			$equals,
			PREG_SET_ORDER
		) ) {
			foreach ( $equals as $equal ) {
				$column = ( $equal[1] ?? '' ) !== '' ? $equal[1] : (string) ( $equal[2] ?? '' );
				if ( '' === $column ) {
					continue;
				}
				$clauses[] = [
					'column' => $column,
					'op'     => '=',
					'value'  => ( $equal[3] ?? '' ) !== '' ? stripcslashes( $equal[3] ) : (string) ( $equal[4] ?? '' ),
				];
			}
		}

		if ( preg_match_all(
			'/(?:`([a-z0-9_]+)`|([a-z0-9_]+))\s*(?:!=|<>)\s*(?:\'((?:\\\\\'|[^\'])*)\'|(\d+))/i',
			$sql,
			$neqs,
			PREG_SET_ORDER
		) ) {
			foreach ( $neqs as $neq ) {
				$column = ( $neq[1] ?? '' ) !== '' ? $neq[1] : (string) ( $neq[2] ?? '' );
				if ( '' === $column ) {
					continue;
				}
				$clauses[] = [
					'column' => $column,
					'op'     => '!=',
					'value'  => ( $neq[3] ?? '' ) !== '' ? stripcslashes( $neq[3] ) : (string) ( $neq[4] ?? '' ),
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

	private function execute_mutation( string $sql ): int|false|null {
		$trimmed = trim( $sql );
		if ( preg_match( '/^INSERT INTO `/i', $trimmed ) ) {
			return $this->execute_insert_select( $trimmed );
		}
		if ( preg_match( '/^UPDATE `/i', $trimmed ) ) {
			return $this->execute_update_sql( $trimmed );
		}
		if ( preg_match( '/^DELETE FROM `/i', $trimmed ) ) {
			return $this->execute_delete_sql( $trimmed );
		}

		return null;
	}

	private function execute_delete_sql( string $sql ): int|false {
		if ( ! preg_match( '/^DELETE FROM `([^`]+)`\s+WHERE (.+)$/is', $sql, $matches ) ) {
			throw new \RuntimeException( 'Unsupported SQL: ' . $sql );
		}
		$table = $matches[1];
		if ( $this->fail_next_delete_table === $table ) {
			$this->fail_next_delete_table = null;
			$this->last_error             = 'Simulated delete failure';

			return false;
		}
		$where = (string) $matches[2];
		$kept  = [];
		$deleted = 0;
		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			if ( $this->sql_where_matches( $row, $where ) ) {
				++$deleted;
				continue;
			}
			$kept[] = $row;
		}
		$this->tables[ $table ] = $kept;

		return $deleted;
	}

	private function execute_update_sql( string $sql ): int|false {
		if ( preg_match( '/^UPDATE `([^`]+)`/i', $sql, $table_match ) && $this->fail_next_update_table === $table_match[1] ) {
			$this->fail_next_update_table = null;
			$this->last_error             = 'Simulated update failure';

			return false;
		}
		if ( preg_match( '/JSON_EXTRACT/i', $sql ) ) {
			return $this->execute_json_draft_update( $sql );
		}
		if ( preg_match( '/INNER JOIN/i', $sql ) ) {
			return $this->execute_join_update( $sql );
		}
		if ( preg_match( '/CONCAT\s*\(/i', $sql ) && preg_match( '/SUBSTRING\s*\(/i', $sql ) ) {
			return $this->execute_concat_substring_update( $sql );
		}
		if ( preg_match( '/ancestry_path\s*=\s*`?prepared_ancestry_path`?/i', $sql ) ) {
			return $this->execute_apply_prepared_ancestry_update( $sql );
		}
		if ( ! preg_match( '/^UPDATE `([^`]+)` SET (.+) WHERE (.+)$/is', $sql, $matches ) ) {
			throw new \RuntimeException( 'Unsupported SQL: ' . $sql );
		}
		if ( null !== $this->fail_next_update_column && str_contains( (string) $matches[2], $this->fail_next_update_column ) ) {
			$this->fail_next_update_column = null;
			$this->last_error              = 'Simulated column update failure';

			return false;
		}
		$table   = $matches[1];
		$set     = $this->parse_set_clause( (string) $matches[2] );
		$where   = (string) $matches[3];
		$limit   = null;
		if ( preg_match( '/^(.*)\s+LIMIT\s+(\d+)\s*$/is', $where, $limit_match ) ) {
			$where = (string) $limit_match[1];
			$limit = (int) $limit_match[2];
		}
		$updated = 0;
		foreach ( $this->tables[ $table ] ?? [] as $index => $row ) {
			if ( ! $this->sql_where_matches( $row, $where ) ) {
				continue;
			}
			$this->tables[ $table ][ $index ] = array_merge( $row, $set );
			++$updated;
			if ( null !== $limit && $updated >= $limit ) {
				break;
			}
		}

		return $updated;
	}

	private function execute_json_draft_update( string $sql ): int {
		if ( ! preg_match( '/^UPDATE `([^`]+)`/i', $sql, $matches ) ) {
			throw new \RuntimeException( 'Unsupported SQL: ' . $sql );
		}
		if ( ! preg_match( '/\bWHERE\b(.+)$/is', $sql, $where_match ) ) {
			throw new \RuntimeException( 'Unsupported SQL: ' . $sql );
		}
		$table   = $matches[1];
		$where   = trim( (string) $where_match[1] );
		$updated = 0;
		foreach ( $this->tables[ $table ] ?? [] as $index => $row ) {
			if ( ! $this->sql_where_matches( $row, $where ) ) {
				continue;
			}
			$draft = [];
			if ( isset( $row['draft_json'] ) && is_string( $row['draft_json'] ) && '' !== $row['draft_json'] ) {
				$decoded = json_decode( $row['draft_json'], true );
				$draft   = is_array( $decoded ) ? $decoded : [];
			}
			if ( isset( $draft['canonical_name'] ) && '' !== trim( (string) $draft['canonical_name'] ) ) {
				$row['canonical_name'] = (string) $draft['canonical_name'];
			}
			if ( isset( $draft['normalized_name'] ) && '' !== trim( (string) $draft['normalized_name'] ) ) {
				$row['normalized_name'] = (string) $draft['normalized_name'];
			} elseif ( isset( $draft['canonical_name'] ) ) {
				$row['normalized_name'] = strtolower( trim( (string) $draft['canonical_name'] ) );
			}
			if ( isset( $draft['ascii_name'] ) ) {
				$row['ascii_name'] = (string) $draft['ascii_name'];
			}
			if ( isset( $draft['latitude'] ) ) {
				$row['latitude'] = $draft['latitude'];
			}
			if ( isset( $draft['longitude'] ) ) {
				$row['longitude'] = $draft['longitude'];
			}
			if ( isset( $draft['parent_location_id'] ) ) {
				$row['parent_location_id'] = $draft['parent_location_id'];
			}
			$row['draft_json']             = null;
			$row['draft_generation_token'] = '';
			$this->tables[ $table ][ $index ] = $row;
			++$updated;
		}

		return $updated;
	}

	private function execute_join_update( string $sql ): int {
		if ( ! preg_match( '/^UPDATE `([^`]+)` live INNER JOIN `([^`]+)` staged\s+ON (.+?)\s+SET (.+)$/is', $sql, $matches ) ) {
			throw new \RuntimeException( 'Unsupported SQL: ' . $sql );
		}
		$table   = $matches[1];
		$on_sql  = (string) $matches[3];
		$updated = 0;
		$join_eq = [];
		if ( preg_match_all( '/live\.`([a-z0-9_]+)`\s*=\s*staged\.`([a-z0-9_]+)`/i', $on_sql, $eq, PREG_SET_ORDER ) ) {
			foreach ( $eq as $pair ) {
				$join_eq[ $pair[1] ] = $pair[2];
			}
		}
		$live_token  = '';
		$staged_token = '';
		if ( preg_match( '/live\.`generation_token`\s*=\s*\'((?:\\\\\'|[^\'])*)\'/i', $on_sql, $live_tok ) ) {
			$live_token = stripcslashes( $live_tok[1] );
		}
		if ( preg_match( '/staged\.`generation_token`\s*=\s*\'((?:\\\\\'|[^\'])*)\'/i', $on_sql, $staged_tok ) ) {
			$staged_token = stripcslashes( $staged_tok[1] );
		}
		$rows = $this->tables[ $table ] ?? [];
		foreach ( $rows as $live_index => $live ) {
			if ( (string) ( $live['generation_token'] ?? '' ) !== $live_token ) {
				continue;
			}
			foreach ( $rows as $staged ) {
				if ( (string) ( $staged['generation_token'] ?? '' ) !== $staged_token ) {
					continue;
				}
				$match = true;
				foreach ( $join_eq as $live_col => $staged_col ) {
					if ( (string) ( $live[ $live_col ] ?? '' ) !== (string) ( $staged[ $staged_col ] ?? '' ) ) {
						$match = false;
						break;
					}
				}
				if ( ! $match ) {
					continue;
				}
				$merged = $live;
				foreach ( [ 'location_id', 'pack_id', 'dataset_version', 'provider_parent_reference', 'feature_class', 'feature_code', 'provider_metadata_json', 'alias', 'alias_type', 'status' ] as $copy ) {
					if ( array_key_exists( $copy, $staged ) ) {
						$merged[ $copy ] = $staged[ $copy ];
					}
				}
				if ( preg_match( '/live\.`updated_at`\s*=\s*\'((?:\\\\\'|[^\'])*)\'/i', $sql, $updated_at ) ) {
					$merged['updated_at'] = stripcslashes( $updated_at[1] );
				}
				if ( preg_match( '/live\.`status`\s*=\s*\'((?:\\\\\'|[^\'])*)\'/i', $sql, $status ) ) {
					$merged['status'] = stripcslashes( $status[1] );
				}
				$this->tables[ $table ][ $live_index ] = $merged;
				++$updated;
			}
		}

		return $updated;
	}

	private function execute_insert_select( string $sql ): int {
		if ( ! preg_match(
			'/^INSERT INTO `([^`]+)` \(([^)]+)\)\s+SELECT (.+)\s+FROM `([^`]+)` staged\s+WHERE (.+)$/is',
			$sql,
			$matches
		) ) {
			throw new \RuntimeException( 'Unsupported SQL: ' . $sql );
		}
		$table      = $matches[1];
		$columns    = array_map(
			static function ( string $column ): string {
				return trim( $column, " `\t\n\r" );
			},
			explode( ',', $matches[2] )
		);
		$source_sql = (string) $matches[3];
		$where      = (string) $matches[5];
		$not_exists_live = str_contains( strtoupper( $where ), 'NOT EXISTS' );
		$where_main      = $where;
		if ( preg_match( '/^(.*)\s+AND NOT EXISTS/is', $where, $split ) ) {
			$where_main = trim( $split[1] );
		}
		$inserted = 0;
		$source_tokens = array_map( 'trim', explode( ',', $source_sql ) );
		foreach ( $this->tables[ $table ] ?? [] as $staged ) {
			if ( ! $this->sql_where_matches( $staged, $where_main ) ) {
				continue;
			}
			if ( $not_exists_live ) {
				$exists = false;
				foreach ( $this->tables[ $table ] as $live ) {
					if ( (string) ( $live['generation_token'] ?? '' ) !== '' ) {
						continue;
					}
					if ( isset( $staged['provider'], $staged['external_id'] )
						&& (string) ( $live['provider'] ?? '' ) === (string) $staged['provider']
						&& (string) ( $live['external_id'] ?? '' ) === (string) $staged['external_id'] ) {
						$exists = true;
						break;
					}
					if ( isset( $staged['location_id'], $staged['normalized_alias'] )
						&& (int) ( $live['location_id'] ?? 0 ) === (int) $staged['location_id']
						&& (string) ( $live['normalized_alias'] ?? '' ) === (string) $staged['normalized_alias'] ) {
						$exists = true;
						break;
					}
				}
				if ( $exists ) {
					continue;
				}
			}
			$row = [];
			foreach ( $columns as $index => $column ) {
				$expr = $source_tokens[ $index ] ?? 'NULL';
				$row[ $column ] = $this->eval_select_expr( $expr, $staged );
			}
			if ( ! isset( $row['id'] ) || 0 === (int) $row['id'] ) {
				$row['id'] = $this->auto_increment[ $table ] ?? 1;
				$this->auto_increment[ $table ] = (int) $row['id'] + 1;
			}
			$this->tables[ $table ][] = $row;
			++$inserted;
		}

		return $inserted;
	}

	private function execute_concat_substring_update( string $sql ): int|false {
		if ( ! preg_match(
			"/^UPDATE `([^`]+)` SET prepared_ancestry_path = CONCAT\\('((?:\\\\'|[^'])*)', SUBSTRING\\(ancestry_path, (\\d+)\\)\\), prepared_generation_token = '((?:\\\\'|[^'])*)'(?:, prepared_hierarchy_root_id = (\\d+))?, updated_at = '((?:\\\\'|[^'])*)' WHERE (.+?)(?:\\s+ORDER BY\\s+id\\s+ASC)?(?: LIMIT (\\d+))?\\s*$/is",
			$sql,
			$matches
		) ) {
			throw new \RuntimeException( 'Unsupported SQL: ' . $sql );
		}
		if ( null !== $this->fail_next_update_column && str_contains( $sql, $this->fail_next_update_column ) ) {
			$this->fail_next_update_column = null;
			$this->last_error              = 'Simulated column update failure';

			return false;
		}
		$table      = $matches[1];
		$prefix     = stripcslashes( $matches[2] );
		$start      = max( 1, (int) $matches[3] );
		$token      = stripcslashes( $matches[4] );
		$root_id    = isset( $matches[5] ) && '' !== (string) $matches[5] ? (int) $matches[5] : null;
		$updated_at = stripcslashes( $matches[6] );
		$where      = (string) $matches[7];
		$limit      = isset( $matches[8] ) && '' !== $matches[8] ? (int) $matches[8] : null;
		$order_by_id = (bool) preg_match( '/ORDER BY\s+id\s+ASC/i', $sql );
		$candidates = [];
		foreach ( $this->tables[ $table ] ?? [] as $index => $row ) {
			if ( ! $this->sql_where_matches( $row, $where ) ) {
				continue;
			}
			$candidates[] = $index;
		}
		if ( $order_by_id ) {
			usort(
				$candidates,
				function ( int $left, int $right ) use ( $table ): int {
					return (int) ( $this->tables[ $table ][ $left ]['id'] ?? 0 ) <=> (int) ( $this->tables[ $table ][ $right ]['id'] ?? 0 );
				}
			);
		}
		$updated = 0;
		foreach ( $candidates as $index ) {
			$row    = $this->tables[ $table ][ $index ];
			$path   = (string) ( $row['ancestry_path'] ?? '' );
			$suffix = substr( $path, $start - 1 );
			$this->tables[ $table ][ $index ]['prepared_ancestry_path']     = $prefix . $suffix;
			$this->tables[ $table ][ $index ]['prepared_generation_token']  = $token;
			if ( null !== $root_id ) {
				$this->tables[ $table ][ $index ]['prepared_hierarchy_root_id'] = $root_id;
			}
			$this->tables[ $table ][ $index ]['updated_at']                 = $updated_at;
			++$updated;
			if ( null !== $limit && $updated >= $limit ) {
				break;
			}
		}

		return $updated;
	}

	private function execute_apply_prepared_ancestry_update( string $sql ): int|false {
		if ( null !== $this->fail_next_update_column && str_contains( $sql, $this->fail_next_update_column ) ) {
			$this->fail_next_update_column = null;
			$this->last_error              = 'Simulated column update failure';

			return false;
		}
		if ( ! preg_match( '/^UPDATE `([^`]+)` SET .+ WHERE (.+)$/is', $sql, $matches ) ) {
			throw new \RuntimeException( 'Unsupported SQL: ' . $sql );
		}
		$table   = $matches[1];
		$where   = (string) $matches[2];
		$updated = 0;
		foreach ( $this->tables[ $table ] ?? [] as $index => $row ) {
			if ( ! $this->sql_where_matches( $row, $where ) ) {
				continue;
			}
			$path = (string) ( $row['prepared_ancestry_path'] ?? '' );
			$this->tables[ $table ][ $index ]['ancestry_path']              = $path;
			$this->tables[ $table ][ $index ]['prepared_ancestry_path']     = '';
			$this->tables[ $table ][ $index ]['prepared_generation_token']  = '';
			$this->tables[ $table ][ $index ]['prepared_hierarchy_root_id'] = 0;
			if ( preg_match( "/updated_at = '((?:\\\\'|[^'])*)'/i", $sql, $at ) ) {
				$this->tables[ $table ][ $index ]['updated_at'] = stripcslashes( $at[1] );
			}
			++$updated;
		}

		return $updated;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function eval_select_expr( string $expr, array $row ): mixed {
		$expr = trim( $expr );
		if ( "''" === $expr || "'' " === $expr ) {
			return '';
		}
		if ( preg_match( '/^\'((?:\\\\\'|[^\'])*)\'$/', $expr, $str ) ) {
			return stripcslashes( $str[1] );
		}
		if ( preg_match( '/^staged\.`([a-z0-9_]+)`$/i', $expr, $col ) ) {
			return $row[ $col[1] ] ?? null;
		}
		if ( preg_match( '/^`([a-z0-9_]+)`$/', $expr, $col ) ) {
			return $row[ $col[1] ] ?? null;
		}

		return $expr;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parse_set_clause( string $sql ): array {
		$set = [];
		if ( preg_match_all(
			'/`?([a-z0-9_]+)`?\s*=\s*(?:\'((?:\\\\\'|[^\'])*)\'|(\d+)|NULL)/i',
			$sql,
			$matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $matches as $match ) {
				if ( ( $match[2] ?? '' ) !== '' ) {
					$set[ $match[1] ] = stripcslashes( $match[2] );
					continue;
				}
				if ( array_key_exists( 3, $match ) && '' !== (string) $match[3] ) {
					$set[ $match[1] ] = (int) $match[3];
					continue;
				}
				$set[ $match[1] ] = null;
			}
		}

		return $set;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function sql_where_matches( array $row, string $where ): bool {
		$where = trim( $where );
		$parts = $this->split_top_level( $where, 'AND' );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( str_starts_with( $part, '(' ) && str_ends_with( $part, ')' ) ) {
				$ors = $this->split_top_level( substr( $part, 1, -1 ), 'OR' );
				$ok  = false;
				foreach ( $ors as $or ) {
					if ( $this->sql_clause_matches( $row, trim( $or ) ) ) {
						$ok = true;
						break;
					}
				}
				if ( ! $ok ) {
					return false;
				}
				continue;
			}
			if ( ! $this->sql_clause_matches( $row, $part ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return list<string>
	 */
	private function split_top_level( string $sql, string $keyword ): array {
		$parts    = [];
		$buffer   = '';
		$depth    = 0;
		$length   = strlen( $sql );
		$needle   = ' ' . strtoupper( $keyword ) . ' ';
		$i        = 0;
		while ( $i < $length ) {
			$char = $sql[ $i ];
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
			}
			if ( 0 === $depth && $i + strlen( $needle ) <= $length && strtoupper( substr( $sql, $i, strlen( $needle ) ) ) === $needle ) {
				$parts[] = $buffer;
				$buffer  = '';
				$i      += strlen( $needle );
				continue;
			}
			$buffer .= $char;
			++$i;
		}
		$parts[] = $buffer;

		return $parts;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function sql_clause_matches( array $row, string $clause ): bool {
		$clause = trim( $clause );
		$clause = preg_replace( '/^(?:live|staged)\./i', '', $clause ) ?? $clause;
		if ( preg_match( '/^`?([a-z0-9_]+)`?\s*=\s*\'((?:\\\\\'|[^\'])*)\'$/i', $clause, $matches ) ) {
			return (string) ( $row[ $matches[1] ] ?? '' ) === stripcslashes( $matches[2] );
		}
		if ( preg_match( '/^`?([a-z0-9_]+)`?\s*=\s*(\d+)$/i', $clause, $matches ) ) {
			return (string) ( $row[ $matches[1] ] ?? '' ) === (string) $matches[2];
		}
		if ( preg_match( '/^`?([a-z0-9_]+)`?\s*<\s*(\d+)$/i', $clause, $matches ) ) {
			return (int) ( $row[ $matches[1] ] ?? 0 ) < (int) $matches[2];
		}
		if ( preg_match( '/^`?([a-z0-9_]+)`?\s*>\s*(\d+)$/i', $clause, $matches ) ) {
			return (int) ( $row[ $matches[1] ] ?? 0 ) > (int) $matches[2];
		}
		if ( preg_match( '/^`?([a-z0-9_]+)`?\s*(?:!=|<>)\s*\'((?:\\\\\'|[^\'])*)\'$/i', $clause, $matches ) ) {
			return (string) ( $row[ $matches[1] ] ?? '' ) !== stripcslashes( $matches[2] );
		}
		if ( preg_match( '/^`?([a-z0-9_]+)`?\s*(?:!=|<>)\s*(\d+)$/i', $clause, $matches ) ) {
			return (int) ( $row[ $matches[1] ] ?? 0 ) !== (int) $matches[2];
		}
		if ( preg_match( '/^`?([a-z0-9_]+)`?\s+LIKE\s+\'((?:\\\\\'|[^\'])*)\'$/i', $clause, $matches ) ) {
			return $this->like_matches( (string) ( $row[ $matches[1] ] ?? '' ), stripcslashes( $matches[2] ) );
		}

		return false;
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

			foreach ( $parsed['in'] ?? [] as $column => $values ) {
				if ( ! in_array( (string) ( $row[ $column ] ?? '' ), $values, true ) ) {
					continue 2;
				}
			}

			foreach ( $parsed['gt'] ?? [] as $column => $min ) {
				if ( (int) ( $row[ $column ] ?? 0 ) <= (int) $min ) {
					continue 2;
				}
			}

			foreach ( $parsed['lt'] ?? [] as $column => $max ) {
				if ( (int) ( $row[ $column ] ?? 0 ) >= (int) $max ) {
					continue 2;
				}
			}

			foreach ( $parsed['neq'] ?? [] as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) === (string) $value ) {
					continue 2;
				}
			}

			foreach ( $parsed['like'] ?? [] as $column => $pattern ) {
				if ( ! $this->like_matches( (string) ( $row[ $column ] ?? '' ), (string) $pattern ) ) {
					continue 2;
				}
			}

			foreach ( $parsed['eq_column'] ?? [] as $left => $right ) {
				if ( (string) ( $row[ $left ] ?? '' ) !== (string) ( $row[ $right ] ?? '' ) ) {
					continue 2;
				}
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
						$cmp   = is_numeric( $left ) && is_numeric( $right )
							? ( (int) $left <=> (int) $right )
							: ( $left <=> $right );

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
