<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Configuration;

/** Exact option SQL contract fixture. It does not claim a live MySQL lock or native commit. */
final class StaffIntentDatabase {
	public string $options = 'wp_options';
	public string $prefix = 'wp_';
	public string $last_error = '';
	public array $rows = [];
	public array $queries = [];
	public array $offers = [ [ 'internal_code' => 'local-van', 'public_label' => 'Local van', 'route' => 'local_delivery' ] ];
	public int $connection = 23;
	public array $locks = [];
	public mixed $before_write = null;
	public mixed $after_write = null;
	public bool $fail_next_read = false;
	public bool $fail_writes = false;

	public function prepare( string $sql, mixed ...$args ): string { return $sql . "\n/*fixture:" . base64_encode( serialize( $args ) ) . '*/'; }
	private function unpack( string $sql ): array {
		if ( ! preg_match( '~\n/\*fixture:([A-Za-z0-9+/=]+)\*/$~', $sql, $match ) ) { return [ $sql, [] ]; }
		return [ substr( $sql, 0, -strlen( $match[0] ) ), unserialize( base64_decode( $match[1] ), [ 'allowed_classes' => false ] ) ];
	}
	public function get_var( string $sql ): mixed {
		[ $statement, $args ] = $this->unpack( $sql ); $this->queries[] = $statement;
		$this->last_error = '';
		if ( 'SELECT DATABASE()' === $statement ) { return 'fixture_db'; }
		if ( 'SELECT CONNECTION_ID()' === $statement ) { return (string) $this->connection; }
		if ( str_contains( $statement, 'GET_LOCK(' ) ) {
			$owner = $this->locks[ $args[0] ] ?? null;
			if ( null !== $owner && $owner !== $this->connection ) { return '0'; }
			$this->locks[ $args[0] ] = $this->connection; return '1';
		}
		if ( str_contains( $statement, 'IS_USED_LOCK(' ) ) { return isset( $this->locks[ $args[0] ] ) ? (string) $this->locks[ $args[0] ] : null; }
		if ( str_contains( $statement, 'RELEASE_LOCK(' ) ) {
			if ( ( $this->locks[ $args[0] ] ?? null ) !== $this->connection ) { return '0'; }
			unset( $this->locks[ $args[0] ] ); return '1';
		}
		if ( $this->fail_next_read ) { $this->fail_next_read = false; $this->last_error = 'fixture lost acknowledgement'; return null; }
		return $this->rows[ $args[0] ] ?? null;
	}
	public function query( string $sql ): int|false {
		[ $statement, $args ] = $this->unpack( $sql ); $this->queries[] = $statement;
		if ( is_callable( $this->before_write ) ) { $hook = $this->before_write; $this->before_write = null; $hook( $statement, $args ); }
		if ( $this->fail_writes ) { return false; }
		if ( str_starts_with( $statement, 'INSERT ' ) ) {
			[ $name, $value ] = $args;
			if ( array_key_exists( $name, $this->rows ) ) { return false; }
			$this->rows[ $name ] = $value;
		} elseif ( str_starts_with( $statement, 'UPDATE ' ) ) {
			[ $value, $name, $exact_name, $before ] = $args;
			if ( $name !== $exact_name || ( $this->rows[ $name ] ?? null ) !== $before ) { return 0; }
			$this->rows[ $name ] = $value;
		} elseif ( str_starts_with( $statement, 'DELETE ' ) ) {
			[ $name, $exact_name, $before ] = $args;
			if ( $name !== $exact_name || ( $this->rows[ $name ] ?? null ) !== $before ) { return 0; }
			unset( $this->rows[ $name ] );
		} else { throw new \LogicException( 'Unreviewed fixture write.' ); }
		if ( is_callable( $this->after_write ) ) { $hook = $this->after_write; $this->after_write = null; $hook( $statement, $args ); }
		return 1;
	}
	public function get_results( string $sql, mixed $format = null ): array { return str_contains( $sql, 'delivery_offers' ) ? $this->offers : []; }
}
