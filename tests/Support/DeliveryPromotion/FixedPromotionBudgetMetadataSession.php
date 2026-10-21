<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\FixedPromotionBudgetSchema as Schema;

/** Unit protocol only. Production SQL locking is proved by the separate owned SQL lane. */
class FixedPromotionBudgetMetadataSession implements OperationSession {
	public int $site = 1;
	public string $prefix = 'wp_';
	public string $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	public bool $transaction = true;
	public bool $retired = false;
	public bool $valid_tables = true;
	public int $error = 0;
	public int $controls = 0;
	public array $reads = [];
	public array $validated = [];
	public ?\Closure $on_query = null;
	public ?\Closure $metadata = null;
	public function site_id(): int { return $this->site; }
	public function table_prefix(): string { return $this->prefix; }
	public function charset_collate(): string { return $this->charset; }
	public function begin(): bool { ++$this->controls; throw new \LogicException( 'Caller controls the transaction.' ); }
	public function commit(): OperationCommitResult { ++$this->controls; throw new \LogicException( 'Caller controls the transaction.' ); }
	public function rollback(): bool { ++$this->controls; throw new \LogicException( 'Caller controls the transaction.' ); }
	public function retire(): bool { ++$this->controls; throw new \LogicException( 'Caller controls the transaction.' ); }
	public function is_retired(): bool { return $this->retired; }
	public function in_transaction(): bool { return $this->transaction; }
	public function validate_tables( array $table_names ): bool { $this->validated[] = $table_names; $this->touch( 'VALIDATE TABLES' ); return $this->valid_tables; }
	public function query( string $sql ): int|false { throw new \LogicException( 'Unexpected write in metadata-only unit fixture.' ); }
	public function get_row( string $sql ): array|null|false {
		$this->touch( $sql );
		if ( str_starts_with( $sql, 'SHOW CHARACTER SET LIKE ' ) ) { $row = [ 'Default collation' => 'utf8mb4_unicode_ci' ]; }
		elseif ( preg_match( "/\ASHOW TABLE STATUS WHERE Name = '([a-zA-Z0-9_]+)'\z/D", $sql, $m ) ) { $row = [ 'Name' => $m[1], 'Engine' => 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ]; }
		else { throw new \LogicException( 'Unexpected metadata row query.' ); }
		return null === $this->metadata ? $row : ( $this->metadata )( $sql, $row );
	}
	public function get_results( string $sql ): array|false {
		$this->touch( $sql ); $suffix = null;
		foreach ( Schema::SUFFIXES as $candidate ) { if ( str_contains( $sql, $candidate . '`' ) ) { $suffix = $candidate; } }
		if ( null === $suffix ) { throw new \LogicException( 'Unexpected metadata table.' ); }
		$rows = [];
		if ( str_starts_with( $sql, 'SHOW FULL COLUMNS FROM ' ) ) {
			foreach ( Schema::columns( $suffix ) as $name => [ $type, $nullable, $default, $extra, $collation ] ) { $rows[] = [ 'Field' => $name, 'Type' => $type, 'Null' => $nullable ? 'YES' : 'NO', 'Default' => $default, 'Extra' => $extra, 'Collation' => 'site' === $collation ? 'utf8mb4_unicode_ci' : $collation ]; }
		} elseif ( str_starts_with( $sql, 'SHOW INDEX FROM ' ) ) {
			foreach ( Schema::indexes( $suffix ) as $name => $index ) { foreach ( $index['columns'] as $position => $field ) { $rows[] = [ 'Key_name' => $name, 'Seq_in_index' => $position + 1, 'Non_unique' => $index['unique'] ? '0' : '1', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A', 'Column_name' => $field, 'Expression' => null, 'Visible' => 'YES', 'Ignored' => 'NO' ]; } }
		} else { throw new \LogicException( 'Unexpected metadata list query.' ); }
		return null === $this->metadata ? $rows : ( $this->metadata )( $sql, $rows );
	}
	public function prepare( string $sql, mixed ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$position = 0;
		return preg_replace_callback( '/%[sd]/', static function ( array $m ) use ( &$position, $args ): string { $value = $args[$position++]; return '%d' === $m[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'"; }, $sql );
	}
	public function errno(): int { return $this->error; }
	public function insert_id(): int { return 0; }
	protected function touch( string $sql ): void { $this->reads[] = $sql; if ( null !== $this->on_query ) { ( $this->on_query )( $sql, $this ); } }
}
