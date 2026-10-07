<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** One fixed native option; no global wpdb writes, option API writes or DDL. */
class EmergencyControlStore {
	public const OPTION_NAME = 'cetech_de_checkout_control_v1';
	private ?\Closure $cache_delete;
	public function __construct( ?callable $cache_delete = null ) { $this->cache_delete = null === $cache_delete ? null : \Closure::fromCallable( $cache_delete ); }
	public function options_table( OperationSession $session ): string { $table = $session->table_prefix() . 'options'; if ( strlen( $table ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { self::refuse(); } return $table; }
	public function assert_standard_wordpress_route( OperationSession $session ): void { ( new DataLifecycleOptionsStore() )->assert_standard_wordpress_route( $session ); }
	public function assert_ready( OperationSession $session, int $site ): void {
		// Reuse only the native options structural verifier, never its write allowlist.
		( new DataLifecycleOptionsStore() )->assert_ready( $session, $site );
		$row = $session->get_row( 'SELECT @@SESSION.tx_isolation AS isolation_level, @@SESSION.innodb_lock_wait_timeout AS lock_wait_seconds' );
		if ( ! is_array( $row ) || 'REPEATABLE-READ' !== strtoupper( (string) ( $row['isolation_level'] ?? '' ) ) || '2' !== (string) ( $row['lock_wait_seconds'] ?? '' ) ) { self::refuse(); }
	}
	public function current( OperationSession $session ): EmergencyControlState {
		if ( ! $session->in_transaction() || $session->is_retired() ) { self::refuse(); }
		$table = $this->options_table( $session );
		$sql = "SELECT option_id, option_name, CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS option_value, OCTET_LENGTH(option_value) AS byte_length, autoload FROM `{$table}` FORCE INDEX (`option_name`) WHERE option_name = %s LIMIT 1 FOR UPDATE";
		$row = $session->get_row( $session->prepare( $sql, EmergencyControlState::MAX_BYTES, self::OPTION_NAME ) );
		if ( false === $row ) { self::refuse(); }
		if ( null === $row ) { return EmergencyControlState::absent( $session->site_id() ); }
		if ( self::OPTION_NAME !== ( $row['option_name'] ?? null ) || ! is_string( $row['option_value'] ?? null ) || ! is_string( $row['autoload'] ?? null ) || ! in_array( $row['autoload'], [ 'yes', 'no', 'on', 'off', 'auto', 'auto-on', 'auto-off' ], true ) || strlen( $row['option_value'] ) !== self::integer( $row['byte_length'] ?? null ) ) { self::refuse(); }
		try { return EmergencyControlState::from_physical( $session->site_id(), self::integer( $row['option_id'] ?? null ), $row['option_value'] ); } catch ( \Throwable ) { self::refuse(); }
	}
	public function accepted_time( OperationSession $session ): int {
		$row = $session->get_row( 'SELECT UTC_TIMESTAMP(6) AS utc' ); $value = is_array( $row ) ? ( $row['utc'] ?? null ) : null;
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\z/D', $value ) ) { self::refuse(); }
		$time = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $time || $time->format( 'Y-m-d H:i:s.u' ) !== $value || $time->getTimestamp() < 1 ) { self::refuse(); }
		return $time->getTimestamp();
	}
	public function write( OperationSession $session, EmergencyControlState $before, string $bytes ): EmergencyControlState {
		if ( ! $session->in_transaction() || $session->is_retired() || $session->site_id() !== $before->site_id ) { self::refuse(); }
		$parsed = EmergencyControlState::from_physical( $before->site_id, max( 1, $before->row_id ), $bytes );
		if ( PHP_INT_MAX === $before->revision || $parsed->revision !== $before->revision + 1 || $parsed->state === $before->state || $parsed->changed_at_epoch < ( $before->changed_at_epoch ?? 0 ) ) { self::refuse(); }
		$table = $this->options_table( $session );
		if ( 0 === $before->row_id ) {
			$result = $session->query( $session->prepare( "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::OPTION_NAME, $bytes, 'off' ) ); $id = $session->insert_id();
		} else {
			// Existing autoload is deliberately not touched.
			$result = $session->query( $session->prepare( "UPDATE `{$table}` SET option_value = %s WHERE option_id = %d AND BINARY option_name = %s AND BINARY option_value = %s", $bytes, $before->row_id, self::OPTION_NAME, $before->original_bytes() ) ); $id = $before->row_id;
		}
		if ( 1 !== $result || $id < 1 ) { self::refuse(); }
		return EmergencyControlState::from_physical( $before->site_id, $id, $bytes );
	}
	public function invalidate(): bool {
		if ( null === $this->cache_delete && ! function_exists( 'wp_cache_delete' ) ) { return false; }
		try { foreach ( [ self::OPTION_NAME, 'alloptions', 'notoptions' ] as $name ) { if ( null === $this->cache_delete ) { wp_cache_delete( $name, 'options' ); } else { ( $this->cache_delete )( $name, 'options' ); } } return true; } catch ( \Throwable ) { return false; }
	}
	private static function integer( mixed $value ): int { if ( is_int( $value ) && $value >= 0 ) { return $value; } if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) { self::refuse(); } return (int) $value; }
	private static function refuse(): never { throw new OperationStorageException(); }
}
