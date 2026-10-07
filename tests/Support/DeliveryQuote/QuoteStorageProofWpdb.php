<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;

/** Native SQL adapter for the migration runner; not a native WordPress/dbDelta proof. */
final class QuoteStorageProofWpdb {
	public string $last_error = '';
	public int $insert_id = 0;
	public array $completed_units = [];
	public ?int $fault_unit = null;
	public string $fault_side = 'before';
	public readonly string $options;
	public function __construct( public readonly \mysqli $database, public string $prefix ) {
		$this->options = $prefix . 'options';
	}
	public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function prepare( string $sql, mixed ...$args ): string {
		$at = 0;
		$sql = (string) preg_replace_callback( '/%[sd]/', function ( array $match ) use ( &$at, $args ): string {
			if ( ! array_key_exists( $at, $args ) ) { throw new \RuntimeException( 'Disposable quote prepare mismatch.' ); }
			$value = $args[$at++];
			return '%d' === $match[0] ? (string) (int) $value : "'" . $this->database->real_escape_string( (string) $value ) . "'";
		}, $sql );
		if ( $at !== count( $args ) ) { throw new \RuntimeException( 'Disposable quote prepare mismatch.' ); }
		return $sql;
	}
	public function query( string $sql ): int|false {
		$unit = str_starts_with( ltrim( $sql ), 'ALTER TABLE' ) && str_contains( $sql, DeliveryQuoteSchema::RATE_INDEX ) ? 3 : null;
		if ( null !== $unit ) { $this->fault( $unit, 'before' ); }
		$result = $this->database->query( $sql );
		$this->last_error = false === $result ? 'Disposable quote SQL was refused.' : '';
		$this->insert_id = (int) $this->database->insert_id;
		if ( false === $result ) { return false; }
		if ( $result instanceof \mysqli_result ) { $result->free(); }
		if ( null !== $unit ) { $this->completed_units[] = $unit; $this->fault( $unit, 'after' ); }
		return (int) $this->database->affected_rows;
	}
	public function get_results( string $sql, mixed $output = null ): array|false {
		unset( $output ); $result = $this->database->query( $sql );
		$this->last_error = $result instanceof \mysqli_result ? '' : 'Disposable quote read was refused.';
		if ( ! $result instanceof \mysqli_result ) { return false; }
		$rows = $result->fetch_all( MYSQLI_ASSOC ); $result->free(); return $rows;
	}
	public function get_row( string $sql, mixed $output = null ): array|null|false {
		$rows = $this->get_results( $sql, $output ); return false === $rows ? false : ( $rows[0] ?? null );
	}
	/** The existing test dbDelta stand-in calls this; every new table executes real DDL. */
	public function register_table_definition( string $sql ): void {
		if ( ! preg_match( '/CREATE TABLE\s+`?([a-z0-9_]+)`?/i', $sql, $matches ) ) { throw new \RuntimeException( 'Disposable quote DDL identity refused.' ); }
		$unit = null;
		foreach ( DeliveryQuoteSchema::SUFFIXES as $index => $suffix ) { if ( $matches[1] === $this->prefix . 'delivery_engine_' . $suffix ) { $unit = $index; } }
		if ( null === $unit ) { throw new \RuntimeException( 'Disposable quote DDL identity refused.' ); }
		$this->fault( $unit, 'before' );
		$exists = $this->get_row( $this->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $matches[1] ) );
		if ( false === $exists || ( null === $exists && false === $this->query( $sql ) ) ) { throw new \RuntimeException( 'Disposable quote DDL refused.' ); }
		// Completed whole-table units are retained on retry; this does not emulate dbDelta ALTER.
		$this->completed_units[] = $unit; $this->fault( $unit, 'after' );
	}
	public function register_table( string $name ): void { unset( $name ); }
	private function fault( int $unit, string $side ): void {
		if ( $unit === $this->fault_unit && $side === $this->fault_side ) { throw new \RuntimeException( 'Injected quote DDL unit refusal.' ); }
	}
}
