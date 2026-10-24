<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/**
 * Bounded private editor journal transport, never a permission or a business receipt.
 * The workflow owns its closed record and immutable original command. No TTL or cache
 * can discard an unresolved command. Every write requires the same connection fence.
 */
final class WpdbRefundPolicyAdminIntent {
	public const PREFIX = 'cetech_de_refund_intent_v1_';
	public const MAX_BYTES = 131072;
	private readonly object $database;
	private readonly string $table;
	private readonly string $prefix;
	private readonly bool $native_database;
	private readonly ?int $blog_id;
	private bool $bound = false;
	private ?string $lock_name = null;
	private ?string $locked_option = null;
	private ?string $connection_id = null;
	private ?string $database_name = null;

	public function __construct( ?object $database = null ) {
		$this->native_database = null === $database;
		// Composition must remain bootable on unsupported hosts. Native routing is
		// bound once, only when the editor first requests its guarded SQL unit.
		if ( null !== $database ) { $this->bind( $database ); }
	}

	private function bind( ?object $database = null ): void {
		if ( $this->bound ) { return; }
		$database ??= $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $database ) || ! is_string( $database->prefix ?? null ) || '' === $database->prefix
			|| ! is_string( $database->options ?? null ) || $database->options !== $database->prefix . 'options'
			|| strlen( $database->options ) > 64 || 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $database->options ) ) { self::refuse(); }
		if ( $this->native_database && ( \wpdb::class !== get_class( $database ) || ( defined( 'WP_CONTENT_DIR' ) && is_file( WP_CONTENT_DIR . '/db.php' ) ) ) ) { self::refuse(); }
		$blog = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : null;
		if ( null !== $blog && $blog < 1 ) { self::refuse(); }
		$this->database = $database;
		$this->table = $database->options;
		$this->prefix = $database->prefix;
		$this->blog_id = $blog;
		$this->bound = true;
	}

	/** Owner and site are server values; this opaque storage key grants neither. */
	public static function name( int $site, int $owner ): string {
		if ( $site < 1 || $owner < 1 ) { self::refuse(); }
		return self::PREFIX . hash( 'sha256', 'site:' . $site . ':owner:' . $owner );
	}

	/** Exact, bounded SQL read; no native option or object-cache lookup is involved. */
	public function read( string $name ): ?string {
		$this->assert_fence( $name );
		$sql = $this->prepared( "SELECT option_name, CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS option_value, OCTET_LENGTH(option_value) AS byte_length FROM `{$this->table}` WHERE option_name = %s LIMIT 1", self::MAX_BYTES, $name );
		$row = $this->database->get_row( $sql, 'ARRAY_A' );
		$this->acknowledged();
		if ( null !== $row ) {
			if ( ! is_array( $row ) || ( $row['option_name'] ?? null ) !== $name || ! is_string( $row['option_value'] ?? null )
				|| ! self::is_length( $row['byte_length'] ?? null ) || strlen( $row['option_value'] ) !== (int) $row['byte_length'] ) { self::refuse(); }
			self::bounded( $row['option_value'] );
		}
		$this->assert_fence( $name );
		return null === $row ? null : $row['option_value'];
	}

	/** Native unique option_name prevents a second tab replacing the first intent. */
	public function insert( string $name, string $bytes ): bool {
		self::bounded( $bytes ); $this->assert_fence( $name );
		$result = $this->database->query( $this->prepared( "INSERT INTO `{$this->table}` (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $bytes ) );
		// Even an unsuccessful acknowledgement cannot authorize execution. The caller
		// retains the original request and performs a fresh locked read next time.
		$this->acknowledged(); $this->assert_fence( $name ); $this->invalidate( $name );
		return 1 === $result && $this->read( $name ) === $bytes;
	}

	public function replace( string $name, string $expected, string $new ): bool {
		self::bounded( $expected ); self::bounded( $new ); $this->assert_fence( $name );
		$result = $this->database->query( $this->prepared( "UPDATE `{$this->table}` SET option_value = %s, autoload = 'off' WHERE option_name = %s AND BINARY option_name = %s AND BINARY option_value = %s", $new, $name, $name, $expected ) );
		$this->acknowledged(); $this->assert_fence( $name ); $this->invalidate( $name );
		return 1 === $result && $this->read( $name ) === $new;
	}

	public function remove( string $name, string $expected ): bool {
		self::bounded( $expected ); $this->assert_fence( $name );
		$result = $this->database->query( $this->prepared( "DELETE FROM `{$this->table}` WHERE option_name = %s AND BINARY option_name = %s AND BINARY option_value = %s", $name, $name, $expected ) );
		$this->acknowledged(); $this->assert_fence( $name ); $this->invalidate( $name );
		return 1 === $result && null === $this->read( $name );
	}

	/** Nonwaiting connection-owned fence, without expiry, takeover or business authority. */
	public function locked( string $name, callable $work ): mixed {
		self::checked_name( $name ); $this->bind(); $this->assert_host();
		if ( null !== $this->lock_name ) { self::refuse(); }
		$this->assert_autocommit();
		$database = $this->scalar( 'SELECT DATABASE()' );
		$id = $this->scalar( 'SELECT CONNECTION_ID()' );
		if ( ! is_string( $database ) || '' === $database || strlen( $database ) > 64 || ! self::is_connection( $id ) ) { self::refuse(); }
		$lock = 'cetech_refund_' . substr( hash( 'sha256', $database . ':' . $this->table . ':' . $name ), 0, 48 );
		// MariaDB named locks are recursive. An already-held lock, even on this
		// connection, is not a fresh acquisition after an uncertain release.
		if ( null !== $this->scalar( $this->prepared( 'SELECT IS_USED_LOCK(%s)', $lock ) ) ) { self::refuse(); }
		if ( '1' !== (string) $this->scalar( $this->prepared( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { self::refuse(); }
		$this->database_name = $database; $this->connection_id = (string) $id;
		$this->lock_name = $lock; $this->locked_option = $name;
		try {
			$this->assert_fence( $name );
			$result = $work();
			$this->assert_fence( $name );
		} finally {
			try {
				// Reconnecting or losing the lock never produces an acknowledged result.
				$id = $this->scalar( 'SELECT CONNECTION_ID()' );
				$owner = $this->scalar( $this->prepared( 'SELECT IS_USED_LOCK(%s)', $lock ) );
				if ( ! self::is_connection( $id ) || (string) $id !== $this->connection_id || ! self::is_connection( $owner )
					|| (string) $owner !== $this->connection_id || '1' !== (string) $this->scalar( $this->prepared( 'SELECT RELEASE_LOCK(%s)', $lock ) ) ) { self::refuse(); }
			} finally {
				$this->database_name = $this->connection_id = $this->lock_name = $this->locked_option = null;
			}
		}
		return $result;
	}

	private function assert_fence( string $name ): void {
		self::checked_name( $name ); $this->assert_host();
		if ( null === $this->lock_name || $this->locked_option !== $name || null === $this->connection_id || null === $this->database_name ) { self::refuse(); }
		$this->assert_unique_name();
		// Capture all physical ownership/transaction facts in the final single probe,
		// after index inspection, rather than combining potentially different units.
		$row = $this->database->get_row( $this->prepared( 'SELECT CONNECTION_ID() AS connection_id, DATABASE() AS database_name, @@session.autocommit AS autocommit, @@in_transaction AS in_transaction, IS_USED_LOCK(%s) AS lock_owner', $this->lock_name ), 'ARRAY_A' );
		$this->acknowledged();
		if ( ! is_array( $row ) || ! self::is_connection( $row['connection_id'] ?? null ) || (string) $row['connection_id'] !== $this->connection_id
			|| ( $row['database_name'] ?? null ) !== $this->database_name || ! self::is_connection( $row['lock_owner'] ?? null ) || (string) $row['lock_owner'] !== $this->connection_id
			|| ! self::is_flag( $row['autocommit'] ?? null, '1' ) || ! self::is_flag( $row['in_transaction'] ?? null, '0' ) ) { self::refuse(); }
		$this->assert_host();
	}

	private function assert_unique_name(): void {
		// LIMIT 1 is meaningful only with WordPress's actual complete unique key.
		$parts = $this->database->get_results( "SHOW INDEX FROM `{$this->table}` WHERE Key_name = 'option_name'", 'ARRAY_A' );
		$this->acknowledged();
		if ( ! is_array( $parts ) || 1 !== count( $parts ) || ! is_array( $parts[0] ) ) { self::refuse(); }
		$part = $parts[0];
		if ( ( $part['Table'] ?? null ) !== $this->table || ( $part['Key_name'] ?? null ) !== 'option_name' || ( $part['Column_name'] ?? null ) !== 'option_name'
			|| ! ( is_int( $part['Non_unique'] ?? null ) || is_string( $part['Non_unique'] ?? null ) ) || '0' !== (string) $part['Non_unique']
			|| ! ( is_int( $part['Seq_in_index'] ?? null ) || is_string( $part['Seq_in_index'] ?? null ) ) || '1' !== (string) $part['Seq_in_index']
			|| ! array_key_exists( 'Sub_part', $part ) || null !== $part['Sub_part'] || null !== ( $part['Expression'] ?? null ) ) { self::refuse(); }
		$this->assert_host();
	}

	private function assert_autocommit(): void {
		// A same-connection readback is not durable while another plugin owns a
		// transaction. Never commit or roll back a unit this journal did not own.
		if ( '1' !== (string) $this->scalar( 'SELECT @@session.autocommit' ) || '0' !== (string) $this->scalar( 'SELECT @@in_transaction' ) ) { self::refuse(); }
	}

	private function assert_host(): void {
		if ( ! $this->bound ) { self::refuse(); }
		if ( ( $this->database->options ?? null ) !== $this->table || ( $this->database->prefix ?? null ) !== $this->prefix
			|| ( $this->native_database && ( ( $GLOBALS['wpdb'] ?? null ) !== $this->database || \wpdb::class !== get_class( $this->database ) || ( defined( 'WP_CONTENT_DIR' ) && is_file( WP_CONTENT_DIR . '/db.php' ) ) ) )
			|| ( null !== $this->blog_id && ( ! function_exists( 'get_current_blog_id' ) || get_current_blog_id() !== $this->blog_id ) ) ) { self::refuse(); }
	}
	private function scalar( string $sql ): mixed { $value = $this->database->get_var( $sql ); $this->acknowledged(); return $value; }
	private function prepared( string $sql, mixed ...$args ): string {
		$value = $this->database->prepare( $sql, ...$args );
		if ( ! is_string( $value ) || '' === $value ) { self::refuse(); }
		return $value;
	}
	private function acknowledged(): void { if ( '' !== (string) ( $this->database->last_error ?? '' ) ) { self::refuse(); } }
	private static function checked_name( string $name ): void { if ( 1 !== preg_match( '/\A' . self::PREFIX . '[a-f0-9]{64}\z/D', $name ) ) { self::refuse(); } }
	private static function bounded( string $bytes ): void { if ( '' === $bytes || strlen( $bytes ) > self::MAX_BYTES || 1 !== preg_match( '//u', $bytes ) ) { self::refuse(); } }
	private static function is_flag( mixed $value, string $expected ): bool { return ( is_int( $value ) || is_string( $value ) ) && (string) $value === $expected; }
	private static function is_connection( mixed $value ): bool { return ( is_int( $value ) || is_string( $value ) ) && 1 === preg_match( '/\A[1-9][0-9]{0,19}\z/D', (string) $value ); }
	private static function is_length( mixed $value ): bool { return ( is_int( $value ) || is_string( $value ) ) && 1 === preg_match( '/\A(?:0|[1-9][0-9]{0,5})\z/D', (string) $value ) && (int) $value <= self::MAX_BYTES; }
	private function invalidate( string $name ): void {
		if ( function_exists( 'wp_cache_delete' ) ) { foreach ( [ $name, 'alloptions', 'notoptions' ] as $key ) { wp_cache_delete( $key, 'options' ); } }
	}
	public function __debugInfo(): array { return [ 'refund_policy_editor_journal' => 'redacted' ]; }
	private static function refuse(): never { throw new \RuntimeException( 'Refund policy editor storage could not be confirmed.' ); }
}
