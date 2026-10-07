<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Bootstrap;

use CetechDeliveryEngine\Application\DataLifecycle\DataLifecycleCleanupService;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\Persistence\DataLifecycleOptionsStore;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory as NativeFactory;

/** Both entry points use one bounded cache-only/exact-permission executor. */
final class DataLifecycleUninstallExecutor {
	public static function run( ?DataLifecycleCleanupService $cleanup = null, ?OperationConnectionFactory $connections = null ): array {
		$site = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
		$status = [ 'format' => 1, 'site_id' => $site, 'status' => 'refused', 'code' => 'storage_refused', 'counts' => [], 'permissions_removed' => false, 'intent_cleared' => false ];
		if ( ! DataLifecycleManifest::supports_uninstall_intent( get_option( DataLifecycleManifest::UNINSTALL_INTENT, 0 ) ) ) { return [ ...$status, 'code' => 'not_requested' ]; }
		$connections ??= new NativeFactory();
		try {
			$intent = self::physical( $connections, $site, DataLifecycleManifest::UNINSTALL_INTENT );
			if ( null === $intent || '1' !== $intent['option_value'] ) { return [ ...$status, 'code' => 'not_requested' ]; }
			$cleanup ??= new DataLifecycleCleanupService( DataLifecycleRegistry::standard(), $connections, static fn ( int $requested ): bool => $requested === $site );
			$result = $cleanup->read( $site );
			if ( 'outcome_unknown' === $result->status ) { return self::record( [ ...$status, 'status' => 'outcome_unknown', 'code' => 'outcome_unknown' ], $connections ); }
			if ( null !== $result->progress && 'completed' !== $result->progress->status && 'uninstall_cache' !== $result->progress->mode ) { return self::record( [ ...$status, 'status' => 'incomplete', 'code' => 'active_cleanup', 'counts' => $result->progress->counts() ], $connections ); }
			if ( null === $result->progress ) {
				if ( 'no_checkpoint' !== $result->reason ) { return self::record( $status, $connections ); }
				$result = $cleanup->start( $site, 'uninstall_cache' );
			} elseif ( 'completed' === $result->progress->status ) { $result = $cleanup->start( $site, 'uninstall_cache' ); }
			if ( 'accepted' === $result->status && 'completed' !== $result->progress->status ) { $result = $cleanup->batch( $site, $result->continuation ); }
			if ( 'accepted' !== $result->status ) { return self::record( [ ...$status, 'status' => 'outcome_unknown' === $result->status ? 'outcome_unknown' : 'refused', 'code' => 'outcome_unknown' === $result->status ? 'outcome_unknown' : 'storage_refused' ], $connections ); }
			$status['counts'] = $result->progress->counts();
			if ( 'completed' !== $result->progress->status ) { return self::record( [ ...$status, 'status' => 'incomplete', 'code' => 'batch_incomplete' ], $connections ); }
			if ( $result->progress->invalid > 0 || $result->progress->protected > 0 ) { return self::record( [ ...$status, 'status' => 'incomplete', 'code' => 'retained_unknown_cache' ], $connections ); }
			if ( $result->publication_pending ) { return self::record( [ ...$status, 'status' => 'incomplete', 'code' => 'cache_publication_pending' ], $connections ); }
			if ( ! self::permissions( $connections, $site ) ) { return self::record( [ ...$status, 'status' => 'outcome_unknown', 'code' => 'outcome_unknown' ], $connections ); }
			$status['permissions_removed'] = true;
			delete_transient( DataLifecycleManifest::ACTIVATION_NOTICE );
			$notice_names = [ '_transient_' . DataLifecycleManifest::ACTIVATION_NOTICE, '_transient_timeout_' . DataLifecycleManifest::ACTIVATION_NOTICE ];
			self::invalidate( $notice_names );
			if ( false !== get_transient( DataLifecycleManifest::ACTIVATION_NOTICE ) ) { throw new \RuntimeException(); }
			foreach ( $notice_names as $name ) { if ( null !== self::physical( $connections, $site, $name ) ) { throw new \RuntimeException(); } }
			delete_option( DataLifecycleManifest::CAPABILITIES_MARKER ); self::invalidate( [ DataLifecycleManifest::CAPABILITIES_MARKER ] );
			if ( null !== self::physical( $connections, $site, DataLifecycleManifest::CAPABILITIES_MARKER ) ) { throw new \RuntimeException(); }
			// Confirm the supported steps before consuming their saved intent.
			$pending = self::record( [ ...$status, 'status' => 'incomplete', 'code' => 'intent_publication_pending' ], $connections );
			if ( 'outcome_unknown' === $pending['status'] ) { return $pending; }
			delete_option( DataLifecycleManifest::UNINSTALL_INTENT ); self::invalidate( [ DataLifecycleManifest::UNINSTALL_INTENT ] );
			if ( null !== self::physical( $connections, $site, DataLifecycleManifest::UNINSTALL_INTENT ) ) { return self::record( [ ...$status, 'code' => 'intent_removal_refused' ], $connections ); }
			return self::record( [ ...$status, 'status' => 'completed', 'code' => 'completed', 'intent_cleared' => true ], $connections );
		} catch ( \Throwable ) { return self::record( $status, $connections ); }
	}
	private static function permissions( OperationConnectionFactory $connections, int $site ): bool {
		if ( ! function_exists( 'wp_roles' ) || ! function_exists( 'get_role' ) ) { throw new \RuntimeException(); }
		$roles = wp_roles();
		if ( ! is_object( $roles ) || ! is_array( $roles->roles ?? null ) || ! is_string( $roles->role_key ?? null ) || ! method_exists( $roles, 'for_site' ) ) { throw new \RuntimeException(); }
		$row = self::physical( $connections, $site, $roles->role_key );
		if ( null === $row || strlen( $row['option_value'] ) > 1048576 ) { throw new \RuntimeException(); }
		$before = @unserialize( $row['option_value'], [ 'allowed_classes' => false ] );
		if ( ! is_array( $before ) || $before !== $roles->roles ) { throw new \RuntimeException(); }
		$expected = $before;
		foreach ( $before as $slug => $definition ) {
			if ( ! is_string( $slug ) || ! is_array( $definition ) || ! is_array( $definition['capabilities'] ?? null ) || ! is_object( get_role( $slug ) ) ) { throw new \RuntimeException(); }
			foreach ( DataLifecycleManifest::CAPABILITIES as $capability ) { unset( $expected[$slug]['capabilities'][$capability] ); }
		}
		// Native publication filters may decline, but cannot broaden this exact disposition.
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'pre_update_option_' . $roles->role_key, $expected, $before, $roles->role_key );
			$filtered = apply_filters( 'pre_update_option', $filtered, $roles->role_key, $before );
			if ( $filtered !== $expected ) { throw new \RuntimeException(); }
		}
		$value = serialize( $expected ); if ( strlen( $value ) > 1048576 ) { throw new \RuntimeException(); }
		$session = $connections->open(); $store = new DataLifecycleOptionsStore();
		try {
			$store->assert_standard_wordpress_route( $session ); if ( ! $session->begin() ) { throw new \RuntimeException(); }
			$store->assert_ready( $session, $site ); $table = $store->options_table( $session );
			if ( $roles->role_key !== $session->table_prefix() . 'user_roles' ) { throw new \RuntimeException(); }
			$current = $session->get_row( $session->prepare( "SELECT option_id,CASE WHEN OCTET_LENGTH(option_value)<=1048576 THEN option_value ELSE NULL END AS option_value,autoload FROM `{$table}` WHERE option_name=%s LIMIT 1 FOR UPDATE", $roles->role_key ) );
			if ( ! is_array( $current ) || (string) $current['option_id'] !== (string) $row['option_id'] || $current['option_value'] !== $row['option_value'] || $current['autoload'] !== $row['autoload'] ) { throw new \RuntimeException(); }
			$id = filter_var( (string) $current['option_id'], FILTER_VALIDATE_INT ); if ( false === $id || $id < 1 ) { throw new \RuntimeException(); }
			if ( $value !== $current['option_value'] && 1 !== $session->query( $session->prepare( "UPDATE `{$table}` SET option_value=%s,autoload=%s WHERE option_id=%d AND BINARY option_name=BINARY %s AND BINARY option_value=BINARY %s", $value, $current['autoload'], $id, $roles->role_key, $current['option_value'] ) ) ) { throw new \RuntimeException(); }
			$commit = $session->commit();
			if ( OperationCommitResult::NotSent === $commit ) { if ( ! $session->rollback() || ! $session->retire() ) { return false; } throw new \RuntimeException(); }
			if ( OperationCommitResult::Acknowledged !== $commit || ! $session->retire() ) { return false; }
		} catch ( \Throwable $error ) {
			try { if ( $session->in_transaction() && ! $session->rollback() ) { return false; } } catch ( \Throwable ) { return false; }
			throw $error;
		} finally { $session->retire(); }
		self::invalidate( [ $roles->role_key ] ); $roles->for_site( $site );
		$after = self::physical( $connections, $site, $roles->role_key );
		if ( null === $after || $expected !== @unserialize( $after['option_value'], [ 'allowed_classes' => false ] ) || $roles->roles !== $expected ) { throw new \RuntimeException(); }
		return true;
	}
	/** Only fixed internal names, never a caller-provided option selector. */
	private static function physical( OperationConnectionFactory $connections, int $site, string $name ): ?array {
		$session = $connections->open(); $store = new DataLifecycleOptionsStore();
		try {
			$store->assert_standard_wordpress_route( $session ); if ( ! $session->begin() ) { throw new \RuntimeException(); }
			$store->assert_ready( $session, $site ); $table = $store->options_table( $session );
			if ( ! in_array( $name, [ DataLifecycleManifest::UNINSTALL_INTENT, DataLifecycleManifest::CAPABILITIES_MARKER, '_transient_' . DataLifecycleManifest::ACTIVATION_NOTICE, '_transient_timeout_' . DataLifecycleManifest::ACTIVATION_NOTICE, $session->table_prefix() . 'user_roles' ], true ) ) { throw new \RuntimeException(); }
			$row = $session->get_row( $session->prepare( "SELECT option_id,CASE WHEN OCTET_LENGTH(option_value)<=1048576 THEN option_value ELSE NULL END AS option_value,autoload FROM `{$table}` WHERE option_name=%s LIMIT 1 FOR UPDATE", $name ) );
			if ( false === $row || ( null !== $row && ! is_string( $row['option_value'] ?? null ) ) || ! $session->rollback() || ! $session->retire() ) { throw new \RuntimeException(); }
			return $row;
		} finally { $session->retire(); }
	}
	private static function record( array $status, OperationConnectionFactory $connections ): array {
		$session = null; $store = new DataLifecycleOptionsStore();
		try {
			$value = json_encode( $status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ); if ( strlen( $value ) > 16384 ) { throw new \RuntimeException(); }
			$session = $connections->open(); $store->assert_standard_wordpress_route( $session ); if ( ! $session->begin() ) { throw new \RuntimeException(); }
			$store->assert_ready( $session, $status['site_id'] ); $row = $store->current_by_name( $session, DataLifecycleManifest::UNINSTALL_STATUS, 16384 );
			if ( null === $row ) { $store->insert( $session, DataLifecycleManifest::UNINSTALL_STATUS, $value ); }
			elseif ( $value !== $row['option_value'] && ! $store->replace( $session, $row['option_id'], DataLifecycleManifest::UNINSTALL_STATUS, $row['option_value'], $value ) ) { throw new \RuntimeException(); }
			if ( OperationCommitResult::Acknowledged !== $session->commit() ) { throw new \RuntimeException(); }
			$store->invalidate( [ DataLifecycleManifest::UNINSTALL_STATUS ] ); return $status;
		} catch ( \Throwable ) {
			try { if ( $session?->in_transaction() ) { $session->rollback(); } } catch ( \Throwable ) {}
			return [ ...$status, 'status' => 'outcome_unknown', 'code' => 'status_unconfirmed' ];
		} finally { $session?->retire(); }
	}
	private static function invalidate( array $names ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) { throw new \RuntimeException(); }
		foreach ( $names as $name ) { wp_cache_delete( $name, 'options' ); }
		wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
	}
}
