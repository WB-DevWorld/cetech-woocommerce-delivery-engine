<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\RefundPolicy;

/** Exact option SQL/fence model only. This fixture proves neither live MySQL locks nor commit. */
class RefundPolicyAdminIntentDatabase {
	public string $options = 'wp_options';
	public string $prefix = 'wp_';
	public string $last_error = '';
	public string $database_name = 'refund_editor_fixture';
	public array $rows = [];
	public array $queries = [];
	public int $connection = 23;
	public int $autocommit = 1;
	public int $in_transaction = 0;
	public array $locks = [];
	public mixed $before_write = null;
	public mixed $after_write = null;
	public mixed $before_read = null;
	public mixed $after_read = null;
	public mixed $on_scalar = null;
	public mixed $before_fence = null;
	public mixed $after_index = null;
	public bool $fail_next_read = false;
	public bool $fail_writes = false;
	public bool $fail_release = false;
	public bool $error_next_scalar = false;
	public bool $ack_without_write = false;
	public bool $bad_length = false;
	public bool $wrong_read_name = false;
	public bool $bad_prepare = false;
	public ?array $index_parts = null;
	public bool $error_index = false;

	public function prepare( string $sql, mixed ...$args ): ?string {
		return $this->bad_prepare ? null : $sql . "\n/*refundfixture:" . base64_encode( serialize( $args ) ) . '*/';
	}
	private function unpack( string $sql ): array {
		if ( ! preg_match( '~\n/\*refundfixture:([A-Za-z0-9+/=]+)\*/$~', $sql, $match ) ) { return [ $sql, [] ]; }
		return [ substr( $sql, 0, -strlen( $match[0] ) ), unserialize( base64_decode( $match[1] ), [ 'allowed_classes' => false ] ) ];
	}
	private function invoke( string $name, mixed ...$args ): void {
		if ( is_callable( $this->$name ) ) { $hook = $this->$name; $this->$name = null; $hook( ...$args ); }
	}
	public function get_var( string $sql ): mixed {
		[ $statement, $args ] = $this->unpack( $sql ); $this->queries[] = $statement; $this->last_error = '';
		$this->invoke( 'on_scalar', $statement, $args );
		if ( $this->error_next_scalar ) { $this->error_next_scalar = false; $this->last_error = 'synthetic_scalar_failure'; return null; }
		if ( 'SELECT @@session.autocommit' === $statement ) { return (string) $this->autocommit; }
		if ( 'SELECT @@in_transaction' === $statement ) { return (string) $this->in_transaction; }
		if ( 'SELECT DATABASE()' === $statement ) { return $this->database_name; }
		if ( 'SELECT CONNECTION_ID()' === $statement ) { return (string) $this->connection; }
		if ( str_contains( $statement, 'GET_LOCK(' ) ) {
			$owner = $this->locks[ $args[0] ] ?? null;
			if ( null !== $owner && $owner !== $this->connection ) { return '0'; }
			$this->locks[ $args[0] ] = $this->connection; return '1';
		}
		if ( str_contains( $statement, 'IS_USED_LOCK(' ) ) { return isset( $this->locks[ $args[0] ] ) ? (string) $this->locks[ $args[0] ] : null; }
		if ( str_contains( $statement, 'RELEASE_LOCK(' ) ) {
			if ( $this->fail_release || ( $this->locks[ $args[0] ] ?? null ) !== $this->connection ) { return '0'; }
			unset( $this->locks[ $args[0] ] ); return '1';
		}
		throw new \LogicException( 'Unreviewed editor scalar query.' );
	}
	public function get_row( string $sql, string $format = 'OBJECT' ): ?array {
		[ $statement, $args ] = $this->unpack( $sql ); $this->queries[] = $statement; $this->last_error = '';
		if ( str_starts_with( $statement, 'SELECT CONNECTION_ID() AS connection_id,' ) ) {
			$this->invoke( 'before_fence', $statement, $args );
			return [ 'connection_id' => (string) $this->connection, 'database_name' => $this->database_name, 'autocommit' => (string) $this->autocommit, 'in_transaction' => (string) $this->in_transaction, 'lock_owner' => isset( $this->locks[$args[0]] ) ? (string) $this->locks[$args[0]] : null ];
		}
		$this->invoke( 'before_read', $statement, $args );
		if ( $this->fail_next_read ) { $this->fail_next_read = false; $this->last_error = 'synthetic_lost_readback'; return null; }
		if ( ! str_starts_with( $statement, 'SELECT option_name, CASE WHEN OCTET_LENGTH(' ) || 'ARRAY_A' !== $format ) { throw new \LogicException( 'Unreviewed editor row query.' ); }
		[ $max, $name ] = $args;
		$row = null;
		$stored_name = null;
		foreach ( array_keys( $this->rows ) as $candidate ) { if ( strtolower( $candidate ) === strtolower( $name ) ) { $stored_name = $candidate; break; } }
		if ( null !== $stored_name ) {
			$bytes = $this->rows[$stored_name];
			$row = [ 'option_name' => $this->wrong_read_name ? strtoupper( $stored_name ) : $stored_name,
				'option_value' => strlen( $bytes ) <= $max ? $bytes : null,
				'byte_length' => (string) ( strlen( $bytes ) + ( $this->bad_length ? 1 : 0 ) ) ];
		}
		$this->invoke( 'after_read', $statement, $args ); return $row;
	}
	public function get_results( string $sql, string $format = 'OBJECT' ): ?array {
		$this->queries[] = $sql; $this->last_error = '';
		if ( $this->error_index ) { $this->last_error = 'synthetic_index_error'; return null; }
		if ( ! str_starts_with( $sql, 'SHOW INDEX FROM `' ) || 'ARRAY_A' !== $format ) { throw new \LogicException( 'Unreviewed editor index query.' ); }
		$parts = $this->index_parts ?? [ [ 'Table' => $this->options, 'Key_name' => 'option_name', 'Column_name' => 'option_name', 'Non_unique' => '0', 'Seq_in_index' => '1', 'Sub_part' => null ] ];
		$this->invoke( 'after_index', $sql, [] ); return $parts;
	}
	public function query( string $sql ): int|false {
		[ $statement, $args ] = $this->unpack( $sql ); $this->queries[] = $statement; $this->last_error = '';
		$this->invoke( 'before_write', $statement, $args );
		if ( $this->fail_writes ) { $this->last_error = 'synthetic_write_failure'; return false; }
		if ( ! $this->ack_without_write ) {
			if ( str_starts_with( $statement, 'INSERT ' ) ) {
				[ $name, $value ] = $args;
				if ( array_key_exists( $name, $this->rows ) ) { $this->last_error = 'synthetic_duplicate'; return false; }
				$this->rows[$name] = $value;
			} elseif ( str_starts_with( $statement, 'UPDATE ' ) ) {
				[ $value, $name, $exact_name, $before ] = $args;
				if ( $name !== $exact_name || ( $this->rows[$name] ?? null ) !== $before ) { return 0; }
				$this->rows[$name] = $value;
			} elseif ( str_starts_with( $statement, 'DELETE ' ) ) {
				[ $name, $exact_name, $before ] = $args;
				if ( $name !== $exact_name || ( $this->rows[$name] ?? null ) !== $before ) { return 0; }
				unset( $this->rows[$name] );
			} else { throw new \LogicException( 'Unreviewed editor write.' ); }
		}
		$this->invoke( 'after_write', $statement, $args ); return 1;
	}
}
