<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Configuration;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;

/**
 * Private, bounded staff UI intent journal. These options confer no business authority.
 * Native lifecycle commands, receipts and their original CAS remain authoritative.
 */
final class PromiseStaffIntent {
	private const MAX_BYTES = 196608;
	private object $database;
	private string $table;
	private ?string $lock_name = null;
	private ?string $connection_id = null;

	public function __construct() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! is_string( $wpdb->options ?? null ) || 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $wpdb->options ) ) {
			throw new \RuntimeException( 'Staff intent storage is unavailable.' );
		}
		$this->database = $wpdb;
		$this->table = $wpdb->options;
	}

	/** Durable reads deliberately bypass the option/object cache, including negative entries. */
	public function read( string $name ): ?string {
		$sql = $this->database->prepare( "SELECT option_value FROM `{$this->table}` WHERE option_name = %s LIMIT 1", $name );
		$value = $this->database->get_var( $sql );
		if ( '' !== (string) ( $this->database->last_error ?? '' ) || ( null !== $value && ( ! is_string( $value ) || strlen( $value ) > self::MAX_BYTES ) ) ) {
			throw new \RuntimeException( 'Staff intent read was not acknowledged.' );
		}
		$this->assert_lock();
		return $value;
	}

	/** The native unique option_name fences two first submissions by the same owner/site. */
	public function insert( string $name, string $bytes ): bool {
		$this->bounded( $bytes );
		$result = $this->database->query( $this->database->prepare( "INSERT INTO `{$this->table}` (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $bytes ) );
		$this->invalidate( $name );
		return 1 === $result && $this->read( $name ) === $bytes;
	}

	/** Exact bytes, including case, and durable readback are required before proceeding. */
	public function replace( string $name, string $before, ?string $after ): bool {
		if ( null !== $after ) { $this->bounded( $after ); }
		$sql = null === $after
			? $this->database->prepare( "DELETE FROM `{$this->table}` WHERE option_name = %s AND BINARY option_name = %s AND BINARY option_value = %s", $name, $name, $before )
			: $this->database->prepare( "UPDATE `{$this->table}` SET option_value = %s WHERE option_name = %s AND BINARY option_name = %s AND BINARY option_value = %s", $after, $name, $name, $before );
		$this->assert_lock();
		$result = $this->database->query( $sql );
		$this->invalidate( $name );
		return 1 === $result && $this->read( $name ) === $after;
	}

	public static function encode( array $plan ): string {
		return json_encode( $plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public static function decode( string $bytes ): array {
		$plan = json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR );
		if ( ! is_array( $plan ) || 1 !== ( $plan['format_version'] ?? null ) || ! is_array( $plan['steps'] ?? null ) || count( $plan['steps'] ) < 1 || count( $plan['steps'] ) > 4
			|| ! is_int( $plan['cursor'] ?? null ) || $plan['cursor'] < 0 || $plan['cursor'] > count( $plan['steps'] )
			|| ! in_array( $plan['state'] ?? null, [ 'ready', 'inflight', 'uncertain', 'rejected', 'complete' ], true ) ) {
			throw new \RuntimeException( 'Invalid staff intent.' );
		}
		foreach ( $plan['steps'] as $step ) {
			if ( ! is_array( $step ) || ! in_array( $step['operation'] ?? null, [ 'promise.version.create', 'promise.version.retire' ], true ) || ! is_string( $step['token'] ?? null ) || ! RequestContext::is_valid_identifier( $step['token'] ) || ! is_array( $step['payload'] ?? null ) ) {
				throw new \RuntimeException( 'Invalid staff intent command.' );
			}
		}
		return $plan;
	}

	/**
	 * Claim before attempt. An abandoned claim or unknown acknowledgement can only reconcile
	 * the exact stored command; it can never become a newly minted mutation attempt.
	 * @param callable(array, bool): OperationAttemptResult $execute
	 */
	public function run( string $name, string $bytes, callable $execute, bool $retry_original = false ): string {
		if ( null !== $this->lock_name ) { return $this->run_locked( $name, $bytes, $execute, $retry_original ); }
		return $this->locked( $name, fn(): string => $this->run_locked( $name, $bytes, $execute, $retry_original ) );
	}

	/** Connection-scoped execution fence: no expiry, takeover clock or business authority. */
	public function locked( string $name, callable $work ): mixed {
		if ( null !== $this->lock_name ) { throw new \RuntimeException( 'Nested staff request.' ); }
		$database = $this->database->get_var( 'SELECT DATABASE()' );
		if ( ! is_string( $database ) || '' === $database ) { throw new \RuntimeException( 'Staff request database identity is unavailable.' ); }
		$lock = 'cetech_staff_' . substr( hash( 'sha256', $database . ':' . $this->table . ':' . $name ), 0, 48 );
		$id = $this->database->get_var( 'SELECT CONNECTION_ID()' );
		if ( ! is_scalar( $id ) || '1' !== (string) $this->database->get_var( $this->database->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { throw new \RuntimeException( 'A staff request is still running.' ); }
		$this->lock_name = $lock; $this->connection_id = (string) $id;
		try { $this->assert_lock(); return $work(); }
		finally {
			try { $this->database->get_var( $this->database->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); } catch ( \Throwable ) {}
			$this->lock_name = null; $this->connection_id = null;
		}
	}

	private function assert_lock(): void {
		if ( null === $this->lock_name ) { return; }
		$id = $this->database->get_var( 'SELECT CONNECTION_ID()' );
		$owner = $this->database->get_var( $this->database->prepare( 'SELECT IS_USED_LOCK(%s)', $this->lock_name ) );
		if ( (string) $id !== $this->connection_id || (string) $owner !== $this->connection_id ) { throw new \RuntimeException( 'Staff request connection fence was lost.' ); }
	}

	private function run_locked( string $name, string $bytes, callable $execute, bool $retry_original ): string {
		$plan = self::decode( $bytes );
		if ( $this->read( $name ) !== $bytes ) { return 'uncertain'; }
		while ( $plan['cursor'] < count( $plan['steps'] ) && 'rejected' !== $plan['state'] ) {
			$was_uncertain = 'ready' !== $plan['state'] || ! empty( $plan['had_uncertainty'] );
			$reconcile = 'ready' !== $plan['state'] && ! $retry_original;
			$claimed = $plan;
			$claimed['state'] = 'inflight';
			$claimed['had_uncertainty'] = $was_uncertain;
			$claimed['claim'] = RequestContext::create()->request_id;
			$next = self::encode( $claimed );
			if ( ! $this->replace( $name, $bytes, $next ) ) { return 'uncertain'; }
			$bytes = $next; $plan = $claimed;
			try {
				$this->assert_lock();
				$result = $execute( $plan['steps'][ $plan['cursor'] ], $reconcile );
				$state = $result->outcome->state;
				$this->assert_lock();
				// A once-uncertain dispatcher may still finish on its separate native SQL owner.
				// Rejected reconciliation/retry never authorizes discarding that original intent.
				if ( 'rejected' === $state && $was_uncertain ) { $state = 'unconfirmed'; }
			} catch ( \Throwable ) { $state = 'unconfirmed'; }
			$next_plan = $plan;
			if ( 'accepted' === $state ) {
				++$next_plan['cursor'];
				$next_plan['had_uncertainty'] = false;
				$next_plan['state'] = count( $next_plan['steps'] ) === $next_plan['cursor'] ? 'complete' : 'ready';
			} else {
				$next_plan['state'] = 'rejected' === $state ? 'rejected' : 'uncertain';
				$next_plan['had_uncertainty'] = 'rejected' !== $state;
			}
			$next = self::encode( $next_plan );
			if ( ! $this->replace( $name, $bytes, $next ) ) { return 'uncertain'; }
			$bytes = $next; $plan = $next_plan;
			if ( 'uncertain' === $plan['state'] ) { return 'uncertain'; }
		}
		return $plan['state'];
	}

	/** Merge a projection without losing another rule or overwriting a changed same-rule row. */
	public function merge_index( string $name, string $id, ?array $expected, array $row ): bool {
		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$before = $this->read( $name );
			$index = null === $before ? [] : unserialize( $before, [ 'allowed_classes' => false ] );
			if ( ! is_array( $index ) ) { return false; }
			$current = $index[ $id ] ?? null;
			if ( $current === $row ) { return true; }
			if ( $current !== $expected ) { return false; }
			$index[ $id ] = $row; $after = serialize( $index );
			if ( null === $before ? $this->insert( $name, $after ) : $this->replace( $name, $before, $after ) ) { return true; }
		}
		return false;
	}

	private function bounded( string $bytes ): void {
		if ( strlen( $bytes ) > self::MAX_BYTES ) { throw new \RuntimeException( 'Staff intent is too large.' ); }
	}
	private function invalidate( string $name ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			foreach ( [ $name, 'alloptions', 'notoptions' ] as $key ) { wp_cache_delete( $key, 'options' ); }
		}
	}
}
