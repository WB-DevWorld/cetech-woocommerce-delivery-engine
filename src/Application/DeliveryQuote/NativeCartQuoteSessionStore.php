<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStorageCodec;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** CAS on a separate, purpose-keyed native Woo session row. No native customer-row rewrite. */
final class NativeCartQuoteSessionStore implements CartQuoteSessionStore {
	private \Closure $authorize;
	private ?\Closure $readiness;
	private ?\Closure $expiry;
	public function __construct( private OperationConnectionFactory $factory, callable $authorize, ?callable $readiness = null, private ?string $trusted_secret = null, ?callable $native_expiry = null ) {
		$this->authorize = \Closure::fromCallable( $authorize ); $this->readiness = null === $readiness ? null : \Closure::fromCallable( $readiness ); $this->expiry = null === $native_expiry ? null : \Closure::fromCallable( $native_expiry );
		if ( null !== $trusted_secret && ( strlen( $trusted_secret ) < 16 || strlen( $trusted_secret ) > 4096 ) ) { self::unavailable(); }
	}
	public function key_for( QuoteOwner $owner ): string {
		$key = $this->trusted_secret;
		if ( null === $key ) { if ( ! function_exists( 'wp_salt' ) || ( function_exists( 'has_filter' ) && false !== has_filter( 'salt' ) ) ) { self::unavailable(); } $key = wp_salt( 'auth' ); }
		if ( ! is_string( $key ) || strlen( $key ) < 16 || strlen( $key ) > 4096 ) { self::unavailable(); }
		return substr( hash_hmac( 'sha256', 'cetech-quote-review-session-v1:' . $owner->site_id() . ':' . $owner->key_epoch() . ':' . $owner->digest(), $key ), 0, 32 );
	}
	public function expires_at( QuoteOwner $owner ): int {
		if ( ! $this->authorized( $owner ) ) { self::unavailable(); }
		if ( null !== $this->expiry ) { $value = ( $this->expiry )( $owner ); }
		else {
			$native = function_exists( 'WC' ) ? ( WC()->session ?? null ) : null;
			if ( ! $native instanceof \WC_Session_Handler || \WC_Session_Handler::class !== get_class( $native ) || ! $owner->equals( ( new QuoteNativeOwnerResolver() )->current() ) ) { self::unavailable(); }
			$r = new \ReflectionProperty( \WC_Session_Handler::class, '_session_expiration' ); if ( ! $r->isInitialized( $native ) ) { self::unavailable(); } $value = method_exists( $r, 'getRawValue' ) ? $r->getRawValue( $native ) : $r->getValue( $native );
		}
		if ( ! is_int( $value ) || $value <= 0 ) { self::unavailable(); } return $value;
	}
	public function load( QuoteOwner $owner ): ?CartQuoteSessionEnvelope {
		$session = null; $begun = false;
		try {
			if ( ! $this->authorized( $owner ) ) { self::unavailable(); } $key = $this->key_for( $owner ); $session = $this->open( $owner ); $begun = true;
			$row = $this->row( $session, $key, false ); $envelope = null === $row ? null : $this->decode( $owner, $row );
			if ( ! $session->rollback() ) { self::unavailable(); } $begun = false; if ( ! $session->retire() || ! $this->authorized( $owner ) ) { self::unavailable(); } return $envelope;
		} catch ( \Throwable ) { self::unavailable(); }
		finally { $this->release( $session, $begun ); }
	}
	public function compare_and_swap( QuoteOwner $owner, ?CartQuoteSessionEnvelope $expected, CartQuoteSessionEnvelope $replacement ): bool {
		$session = null; $begun = false;
		try {
			if ( ! $this->authorized( $owner ) || ! $replacement->owner()->equals( $owner ) || ! $replacement->follows( $expected ) || ( null !== $expected && ! $expected->owner()->equals( $owner ) ) ) { return false; }
			$key = $this->key_for( $owner ); $session = $this->open( $owner ); $begun = true; $row = $this->row( $session, $key, true );
			if ( ( null === $row ) !== ( null === $expected ) ) { return false; }
			if ( null !== $row && $this->decode( $owner, $row )->to_private_json() !== $expected->to_private_json() ) { return false; }
			$now = QuoteOperationProfile::time( $session ); if ( $replacement->expires_at() * 1000000 <= $now->epoch_microseconds() ) { return false; }
			$table = $session->table_prefix() . 'woocommerce_sessions'; $json = $replacement->to_private_json();
			if ( null === $row ) { $sql = $session->prepare( "INSERT INTO `{$table}` (session_key,session_value,session_expiry) VALUES (%s,%s,%d)", $key, $json, $replacement->expires_at() ); }
			else { $sql = $session->prepare( "UPDATE `{$table}` SET session_value=%s,session_expiry=%d WHERE session_id=%d AND session_key=%s AND session_value=%s AND session_expiry=%d", $json, $replacement->expires_at(), QuoteStorageCodec::integer( $row['session_id'] ), $key, $expected->to_private_json(), $expected->expires_at() ); }
			if ( 1 !== $session->query( $sql ) ) { return false; }
			$commit = $session->commit(); $begun = false;
			if ( OperationCommitResult::Acknowledged !== $commit || ! $session->retire() ) { return false; }
			return $this->authorized( $owner );
		} catch ( \Throwable ) { return false; }
		finally { $this->release( $session, $begun ); }
	}
	private function open( QuoteOwner $owner ): OperationSession {
		$session = $this->factory->open();
		try {
			if ( $session->site_id() !== $owner->site_id() || $session->is_retired() || $session->in_transaction() || ! $session->begin() ) { self::unavailable(); }
			if ( null !== $this->readiness ) { ( $this->readiness )( $session ); }
			$this->assert_table( $session ); return $session;
		} catch ( \Throwable ) { $this->release( $session, $session->in_transaction() ); self::unavailable(); }
	}
	private function assert_table( OperationSession $session ): void {
		$table = $session->table_prefix() . 'woocommerce_sessions'; if ( ! $session->validate_tables( [ $table ] ) ) { self::unavailable(); }
		$columns = $session->get_results( "SHOW FULL COLUMNS FROM `{$table}`" ); $indexes = $session->get_results( "SHOW INDEX FROM `{$table}`" );
		if ( ! is_array( $columns ) || ! is_array( $indexes ) || count( $columns ) !== 4 || count( $indexes ) > 12 ) { self::unavailable(); }
		$types = [ 'session_id' => '/\Abigint(?:\(20\))? unsigned\z/D', 'session_key' => '/\Achar\(32\)\z/D', 'session_value' => '/\Alongtext\z/D', 'session_expiry' => '/\Abigint(?:\(20\))? unsigned\z/D' ]; $seen = [];
		foreach ( $columns as $column ) { $field = $column['Field'] ?? null; if ( ! is_string( $field ) || ! isset( $types[$field] ) || isset( $seen[$field] ) || 'NO' !== ( $column['Null'] ?? null ) || ! is_string( $column['Type'] ?? null ) || 1 !== preg_match( $types[$field], strtolower( $column['Type'] ) ) ) { self::unavailable(); } $seen[$field] = true; }
		$groups = []; foreach ( $indexes as $index ) { $name = $index['Key_name'] ?? null; if ( ! is_string( $name ) ) { self::unavailable(); } $groups[$name][] = $index; }
		$unique = false; $primary = false;
		foreach ( $groups as $name => $rows ) { if ( count( $rows ) === 1 && in_array( $rows[0]['Non_unique'] ?? null, [ 0, '0' ], true ) && in_array( $rows[0]['Seq_in_index'] ?? null, [ 1, '1' ], true ) && null === ( $rows[0]['Sub_part'] ?? null ) ) { $unique = $unique || 'session_key' === ( $rows[0]['Column_name'] ?? null ); $primary = $primary || ( 'PRIMARY' === $name && 'session_id' === ( $rows[0]['Column_name'] ?? null ) ); } }
		if ( ! $unique || ! $primary ) { self::unavailable(); }
	}
	private function row( OperationSession $session, string $key, bool $lock ): ?array {
		$table = $session->table_prefix() . 'woocommerce_sessions'; $max = CartQuoteSessionEnvelope::MAX_BYTES;
		$sql = $session->prepare( "SELECT session_id,session_key,CASE WHEN OCTET_LENGTH(session_value)<={$max} THEN session_value ELSE NULL END AS session_value,session_expiry,CASE WHEN OCTET_LENGTH(session_value)>{$max} THEN 1 ELSE 0 END AS payload_oversized FROM `{$table}` WHERE session_key=%s LIMIT 2" . ( $lock ? ' FOR UPDATE' : '' ), $key ); $rows = $session->get_results( $sql );
		if ( ! is_array( $rows ) || count( $rows ) > 1 ) { self::unavailable(); } return $rows[0] ?? null;
	}
	private function decode( QuoteOwner $owner, array $row ): CartQuoteSessionEnvelope {
		if ( ! in_array( $row['payload_oversized'] ?? null, [ 0, '0' ], true ) || ! is_string( $row['session_value'] ?? null ) || ( $row['session_key'] ?? null ) !== $this->key_for( $owner ) ) { self::unavailable(); }
		QuoteStorageCodec::integer( $row['session_id'] ); $envelope = CartQuoteSessionEnvelope::from_private_json( $row['session_value'] );
		if ( ! $envelope->owner()->equals( $owner ) || $envelope->expires_at() !== QuoteStorageCodec::integer( $row['session_expiry'] ) ) { self::unavailable(); } return $envelope;
	}
	private function authorized( QuoteOwner $owner ): bool { try { return true === ( $this->authorize )( $owner, 'delivery_quote.session' ); } catch ( \Throwable ) { return false; } }
	private function release( ?OperationSession $session, bool $begun ): void { if ( null === $session ) { return; } if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } }
	private static function unavailable(): never { throw new \RuntimeException( 'Cart quote session unavailable.' ); }
}
