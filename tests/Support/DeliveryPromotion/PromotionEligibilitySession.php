<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;

/** SQL/metadata model for guard boundary tests; physical isolation is tested separately. */
final class PromotionEligibilitySession implements OperationSession {
	public bool $active = true;
	public bool $retired = false;
	public bool $failure = false;
	public string $prefix = 'proof_';
	public int $site = 1;
	public array $queries = [];
	public ?\Closure $after_read = null;
	public array $rows;
	public function __construct() { $this->rows = PromotionEligibilityFixture::rows(); }
	public function site_id(): int { return $this->site; }
	public function table_prefix(): string { return $this->prefix; }
	public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function begin(): bool { throw new \LogicException( 'Guard must not begin.' ); }
	public function commit(): OperationCommitResult { throw new \LogicException( 'Guard must not commit.' ); }
	public function rollback(): bool { throw new \LogicException( 'Guard must not roll back.' ); }
	public function retire(): bool { throw new \LogicException( 'Guard must not retire.' ); }
	public function is_retired(): bool { return $this->retired; }
	public function in_transaction(): bool { return $this->active; }
	public function validate_tables( array $tables ): bool { return $tables === RuleLifecycleSchema::tables( $this->prefix ); }
	public function query( string $sql ): int|false { throw new \LogicException( 'Read guard wrote SQL.' ); }
	public function get_row( string $sql ): array|null|false {
		$this->queries[] = $sql; if ( $this->failure ) { return false; }
		if ( str_starts_with( $sql, 'SHOW TABLE STATUS' ) ) { return [ 'Engine' => 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ]; }
		if ( str_contains( $sql, 'rule_family_guards' ) && str_contains( $sql, 'FOR UPDATE' ) ) { $row = $this->site === (int) ( $this->rows[0]['site_id'] ?? 0 ) ? $this->rows[0] : null; $this->after( $sql ); return $row; }
		throw new \LogicException( 'Unexpected row query.' );
	}
	public function get_results( string $sql ): array|false {
		$this->queries[] = $sql; if ( $this->failure ) { return false; }
		foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) {
			if ( str_contains( $sql, 'SHOW FULL COLUMNS' ) && str_contains( $sql, $suffix ) ) {
				$out = []; foreach ( RuleLifecycleSchema::columns( $suffix ) as $name => [ $type, $nullable, $default, $extra, $collation ] ) { $out[] = [ 'Field' => $name, 'Type' => $type, 'Null' => $nullable ? 'YES' : 'NO', 'Default' => $default, 'Extra' => $extra, 'Collation' => 'site' === $collation ? 'utf8mb4_unicode_ci' : $collation ]; } return $out;
			}
			if ( str_starts_with( $sql, 'SHOW INDEX' ) && str_contains( $sql, $suffix ) ) {
				$out = []; foreach ( RuleLifecycleSchema::indexes( $suffix ) as $name => $definition ) { foreach ( $definition['columns'] as $i => $column ) { $out[] = [ 'Key_name' => $name, 'Seq_in_index' => $i + 1, 'Non_unique' => $definition['unique'] ? 0 : 1, 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A', 'Column_name' => $column ]; } } return $out;
			}
		}
		if ( str_contains( $sql, 'FOR UPDATE' ) ) {
			$versions = str_contains( $sql, 'INNER JOIN' ); $out = $this->rows[$versions ? 2 : 1];
			foreach ( $out as &$row ) { foreach ( $versions ? [ 'payload_json', 'change_reason' ] : [ 'scope_json' ] as $field ) { $row[$field . '__bytes'] = strlen( $row[$field] ); } } unset( $row );
			$this->after( $sql ); return $out;
		}
		throw new \LogicException( 'Unexpected rows query.' );
	}
	public function prepare( string $sql, mixed ...$args ): string { foreach ( $args as $arg ) { $sql = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $sql, 1 ); } return $sql; }
	public function errno(): int { return $this->failure ? 1 : 0; }
	public function insert_id(): int { return 0; }
	private function after( string $sql ): void { if ( null !== $this->after_read ) { ( $this->after_read )( $this, $sql ); } }
}
