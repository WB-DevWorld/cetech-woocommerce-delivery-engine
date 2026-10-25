<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentMap, PolicyAssignmentState};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Authoritative private maps on the supplied C03 owner; never cached option API state. */
final class PolicyAssignmentStore {

	public const PREFIX = 'cetech_de_policy_assignment_v1_';
	public const GUARD = 'policy_assignment_v1';
	private readonly ?\Closure $cache_delete;
	/** @var \WeakMap<OperationSession,array<string,PolicyAssignmentState>> */
	private \WeakMap $owners;
	/** @var \WeakMap<OperationSession,array<string,?string>> */
	private \WeakMap $autoloads;

	public function __construct( private readonly int $site, private readonly string $prefix, ?callable $cache_delete = null ) {
		if ( $site < 1 || '' === $prefix || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) || strlen( $prefix . 'options' ) > 64 ) { self::fail(); }
		$this->cache_delete = null === $cache_delete ? null : \Closure::fromCallable( $cache_delete );
		$this->owners = new \WeakMap();
		$this->autoloads = new \WeakMap();
	}

	public static function guard_hash(): string { return hash( 'sha256', 'cetech-policy-assignment-serialization-guard-v1:canonical-native-options:atomic-return-refund-maps:follow-current-published-v1' ); }

	public function name( array $coordinate ): string {
		$c = $this->coordinate( $coordinate );
		return self::PREFIX . hash( 'sha256', 'cetech-policy-assignment-key-v1:' . $c['site_id'] . ':' . $c['scope_type'] . ':' . $c['scope_id'] );
	}

	public function options_table( OperationSession $session ): string { $this->session( $session ); return $this->prefix . 'options'; }

	/** SQL-only structural verification; the native route was captured before ownership. */
	public function assert_ready( OperationSession $session ): void {
		try {
			$this->session( $session );
			( new DataLifecycleOptionsStore() )->assert_ready( $session, $this->site );
			$this->owners[$session] = [];
			$this->autoloads[$session] = [];
		} catch ( \Throwable ) { self::fail(); }
	}

	public function guard( OperationSession $session, bool $create ): ?array {
		$this->owned( $session );
		$row = ( new WpdbRuleLifecycleRepository( $session ) )->lock_family( self::GUARD, self::guard_hash(), $create );
		if ( null === $row ) { return null; }
		try {
			if ( $this->site !== self::integer( $row['site_id'] ?? null ) || self::GUARD !== ( $row['family_code'] ?? null ) || 1 !== self::integer( $row['family_format'] ?? null ) || self::guard_hash() !== ( $row['policy_hash'] ?? null ) || self::integer( $row['id'] ?? null ) < 1 || self::integer( $row['revision'] ?? null ) < 1 || RuleTime::parse( $row['updated_at'] )->compare( RuleTime::parse( $row['created_at'] ) ) < 0 ) { self::fail(); }
		} catch ( \Throwable ) { self::fail(); }
		return $row;
	}

	public function current( OperationSession $session, array $coordinate ): PolicyAssignmentState {
		$this->owned( $session ); $c = $this->coordinate( $coordinate ); $name = $this->name( $c ); $table = $this->options_table( $session );
		$row = $session->get_row( $session->prepare( "SELECT option_id, option_name, CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS option_value, OCTET_LENGTH(option_value) AS byte_length, autoload FROM `{$table}` FORCE INDEX (`option_name`) WHERE option_name = %s LIMIT 1 FOR UPDATE", 16384, $name ) );
		if ( false === $row ) { self::fail(); }
		try {
			if ( null === $row ) { $state = PolicyAssignmentState::absent( $this->site, $c['scope_type'], $c['scope_id'], $c['parent_id'] ); }
			else {
				if ( $name !== ( $row['option_name'] ?? null ) || ! is_string( $row['option_value'] ?? null ) || strlen( $row['option_value'] ) !== self::integer( $row['byte_length'] ?? null ) || ! is_string( $row['autoload'] ?? null ) || ! in_array( $row['autoload'], [ 'yes','no','on','off','auto','auto-on','auto-off' ], true ) ) { self::fail(); }
				$state = PolicyAssignmentState::from_physical( $this->site, $c['scope_type'], $c['scope_id'], $c['parent_id'], self::integer( $row['option_id'] ?? null ), $row['option_value'] );
			}
		} catch ( \Throwable ) { self::fail(); }
		$locked = $this->owners[$session]; $locked[$name] = $state; $this->owners[$session] = $locked;
		$autoloads = $this->autoloads[$session]; $autoloads[$name] = $row['autoload'] ?? null; $this->autoloads[$session] = $autoloads;
		return $state;
	}

	public function write( OperationSession $session, PolicyAssignmentState $before, string $bytes ): PolicyAssignmentState {
		$this->owned( $session ); $c = $this->coordinate( $before->coordinate() ); $name = $this->name( $c ); $locked = $this->owners[$session][$name] ?? null;
		if ( ! $locked instanceof PolicyAssignmentState || $locked->row_id() !== $before->row_id() || $locked->original_bytes() !== $before->original_bytes() || $locked->revision() !== $before->revision() || PHP_INT_MAX === $before->revision() ) { self::fail(); }
		$expected_autoload = 0 === $before->row_id() ? 'off' : ( $this->autoloads[$session][$name] ?? null );
		if ( ! is_string( $expected_autoload ) ) { self::fail(); }
		$this->validate_proposal( $before, $bytes ); $table = $this->options_table( $session );
		if ( 0 === $before->row_id() ) {
			$affected = $session->query( $session->prepare( "INSERT INTO `{$table}` (option_name,option_value,autoload) VALUES (%s,%s,%s)", $name, $bytes, 'off' ) );
			$id = $session->insert_id();
		} else {
			$affected = $session->query( $session->prepare( "UPDATE `{$table}` SET option_value=%s WHERE option_id=%d AND BINARY option_name=%s AND BINARY option_value=%s", $bytes, $before->row_id(), $name, $before->original_bytes() ) );
			$id = $before->row_id();
		}
		if ( 1 !== $affected || $id < 1 ) { self::fail(); }
		$after = $this->current( $session, $c );
		if ( $after->row_id() !== $id || $after->original_bytes() !== $bytes || $after->revision() !== $before->revision() + 1 || ( $this->autoloads[$session][$name] ?? null ) !== $expected_autoload ) { self::fail(); }
		return $after;
	}

	public function invalidate( array $coordinate ): bool {
		try {
			$names = [ $this->name( $coordinate ), 'alloptions', 'notoptions' ];
			if ( null === $this->cache_delete && ! function_exists( 'wp_cache_delete' ) ) { return false; }
			foreach ( $names as $name ) { if ( null === $this->cache_delete ) { wp_cache_delete( $name, 'options' ); } else { ( $this->cache_delete )( $name, 'options' ); } }
			return true;
		} catch ( \Throwable ) { return false; }
	}

	private function validate_proposal( PolicyAssignmentState $before, string $bytes ): void {
		try {
			if ( '' === $bytes || strlen( $bytes ) > 16384 ) { self::fail(); }
			$d = json_decode( $bytes, true, 12, JSON_THROW_ON_ERROR ); $c = $before->coordinate();
			if ( ! is_array( $d ) || ( $d['revision'] ?? null ) !== $before->revision() + 1 || ( null !== $before->generation() && ( $d['generation'] ?? null ) !== $before->generation() ) ) { self::fail(); }
			$rebuilt = PolicyAssignmentState::record_json( $c, $d['generation'], $d['revision'], PolicyAssignmentMap::from_json( $this->site, 'return_policy_v1', $d['return_map'] ), PolicyAssignmentMap::from_json( $this->site, 'refund_policy_v1', $d['refund_map'] ), $d['actor_user_id'], $d['reason'], $d['changed_at'] );
			if ( $rebuilt !== $bytes ) { self::fail(); }
			if ( '' !== $before->original_bytes() ) { $prior = json_decode( $before->original_bytes(), true, 12, JSON_THROW_ON_ERROR ); if ( RuleTime::parse( $d['changed_at'] )->compare( RuleTime::parse( $prior['changed_at'] ) ) < 0 ) { self::fail(); } }
		} catch ( \Throwable ) { self::fail(); }
	}

	private function coordinate( array $coordinate ): array {
		try { $c = PolicyAssignmentState::make_coordinate( $coordinate['site_id'], $coordinate['scope_type'], $coordinate['scope_id'], $coordinate['parent_id'] ); if ( $c !== $coordinate || $this->site !== $c['site_id'] ) { self::fail(); } return $c; }
		catch ( \Throwable ) { self::fail(); }
	}
	private function session( OperationSession $session ): void { if ( $session->site_id() !== $this->site || $session->table_prefix() !== $this->prefix || $session->is_retired() || ! $session->in_transaction() ) { self::fail(); } }
	private function owned( OperationSession $session ): void { $this->session( $session ); if ( ! isset( $this->owners[$session] ) ) { self::fail(); } }
	private static function integer( mixed $value ): int { if ( is_int( $value ) && $value >= 0 ) { return $value; } if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) { self::fail(); } return (int) $value; }
	private static function fail(): never { throw new OperationStorageException(); }
}
