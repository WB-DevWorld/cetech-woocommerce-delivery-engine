<?php
/** Fresh-process native history read; private configuration is supplied only by the owned fixture. */
declare(strict_types=1);
$path = $argv[1] ?? null;
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) || filesize( $path ) > 16384 ) { throw new RuntimeException( 'P04 history reader needs its owned private configuration.' ); }
$config = json_decode( (string) file_get_contents( $path ), true, 16, JSON_THROW_ON_ERROR );
if ( ! is_array( $config ) || [ 'wp_load', 'order_id', 'envelope_digest', 'promise_packet_digest', 'public_groups_digest', 'run_lifecycle' ] !== array_keys( $config ) || ! is_string( $config['wp_load'] ) || ! is_file( $config['wp_load'] ) || ! is_int( $config['order_id'] ) || $config['order_id'] < 1 || ! is_bool( $config['run_lifecycle'] ) ) { throw new RuntimeException( 'P04 history reader configuration is closed.' ); }
foreach ( [ 'envelope_digest', 'promise_packet_digest', 'public_groups_digest' ] as $field ) { if ( ! is_string( $config[$field] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $config[$field] ) ) { throw new RuntimeException( 'P04 history reader digest is invalid.' ); } }
define( 'WP_ADMIN', true ); require $config['wp_load'];
if ( ! defined( 'DB_HOST' ) || 1 !== preg_match( '/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST ) || ! defined( 'DB_NAME' ) || 1 !== preg_match( '/\Acetech_wp_opening_qualification(?:_[a-z0-9]+)?\z/D', DB_NAME ) || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! class_exists( WC_Order::class ) || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] ) || is_multisite() ) { throw new RuntimeException( 'P04 history reader requires the marked native fixture.' ); }
foreach ( get_included_files() as $file ) { if ( str_ends_with( str_replace( '\\', '/', $file ), '/tests/bootstrap.php' ) ) { throw new RuntimeException( 'P04 history cannot use a unit bootstrap.' ); } }
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );
$lifecycle = [ 'native_expired_checkpoint_completed' => false, 'native_deactivation_preserved' => false, 'native_explicit_uninstall_completed' => false, 'native_uninstall_business_rows_preserved' => false, 'native_fixture_options_restored' => false ];
if ( $config['run_lifecycle'] ) {
	global $wpdb;
	$rows = static function ( string $sql ) use ( $wpdb ): array { $value = $wpdb->get_results( $sql, ARRAY_A ); if ( ! is_array( $value ) || '' !== $wpdb->last_error || count( $value ) > 20000 ) { throw new RuntimeException( 'P04 lifecycle native observation is unavailable.' ); } return $value; };
	$domain = static function () use ( $rows ): array { $value = []; foreach ( CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES as $suffix ) { $value[$suffix] = $rows( 'SELECT * FROM `' . CetechDeliveryEngine\Infrastructure\Persistence\TableNames::for( $suffix ) . '` ORDER BY id' ); } return $value; };
	$names = [ $wpdb->prefix . 'user_roles', 'cron', 'rewrite_rules', CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::COORDINATOR_OPTION, CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::UNINSTALL_STATUS, CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::UNINSTALL_INTENT, CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::CAPABILITIES_MARKER, '_transient_' . CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::ACTIVATION_NOTICE, '_transient_timeout_' . CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::ACTIVATION_NOTICE ];
	$original = []; foreach ( $names as $name ) { $observed = $rows( $wpdb->prepare( "SELECT option_id,option_name,option_value,autoload FROM `{$wpdb->options}` WHERE option_name=%s LIMIT 2", $name ) ); if ( count( $observed ) > 1 ) { throw new RuntimeException( 'P04 lifecycle fixed option is ambiguous.' ); } $original[$name] = $observed[0] ?? null; }
	$before = $domain();
	try {
		add_filter( 'flush_rewrite_rules_hard', '__return_false', PHP_INT_MAX ); CetechDeliveryEngine\Bootstrap\Deactivator::deactivate();
		$lifecycle['native_deactivation_preserved'] = 38 === count( $before ) && $before === $domain();
		// Native boot may already own an expired-cache checkpoint. Complete its
		// exact acknowledged continuation before requesting a different lifecycle mode.
		$native_site = get_current_blog_id();
		$maintenance = new CetechDeliveryEngine\Application\DataLifecycle\DataLifecycleCleanupService( CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleRegistry::standard(), new CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory(), static fn( int $requested ): bool => $requested === $native_site );
		$pending = $maintenance->read( $native_site );
		if ( null !== $pending->progress && 'completed' !== $pending->progress->status ) {
			if ( 'accepted' !== $pending->status || 'expired' !== $pending->progress->mode ) { throw new RuntimeException( 'P04 existing native maintenance cannot be resumed.' ); }
			for ( $batch = 0; $batch < 64 && 'completed' !== $pending->progress->status; ++$batch ) { $pending = $maintenance->batch( $native_site, $pending->continuation ); if ( 'accepted' !== $pending->status || null === $pending->progress || 'expired' !== $pending->progress->mode ) { throw new RuntimeException( 'P04 original native maintenance did not acknowledge.' ); } }
			if ( 'completed' !== $pending->progress->status ) { throw new RuntimeException( 'P04 original native maintenance exceeded its finite fixture bound.' ); }
		} elseif ( 'accepted' !== $pending->status && 'no_checkpoint' !== $pending->reason ) { throw new RuntimeException( 'P04 original native maintenance is unavailable.' ); }
		$lifecycle['native_expired_checkpoint_completed'] = 'accepted' === $pending->status && null !== $pending->progress && 'expired' === $pending->progress->mode && 'completed' === $pending->progress->status;
		$before = $domain(); // Explicit uninstall's census begins after acknowledged fixture preparation.
		update_option( CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::CAPABILITIES_MARKER, 4, false ); update_option( CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::UNINSTALL_INTENT, 1, false );
		CetechDeliveryEngine\Bootstrap\Uninstaller::uninstall(); $status = json_decode( (string) get_option( CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::UNINSTALL_STATUS, '' ), true );
		$lifecycle['native_explicit_uninstall_completed'] = 'completed' === ( $status['status'] ?? null ) && false === get_option( CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::UNINSTALL_INTENT, false );
		$lifecycle['native_uninstall_business_rows_preserved'] = $before === $domain();
	} finally {
		$restored = true; foreach ( $original as $name => $row ) { $restored = false !== ( null === $row ? $wpdb->delete( $wpdb->options, [ 'option_name' => $name ] ) : $wpdb->replace( $wpdb->options, $row ) ) && $restored; wp_cache_delete( $name, 'options' ); }
		wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_roles()->for_site( get_current_blog_id() );
		foreach ( $original as $name => $row ) { $observed = $rows( $wpdb->prepare( "SELECT option_id,option_name,option_value,autoload FROM `{$wpdb->options}` WHERE option_name=%s LIMIT 2", $name ) ); $restored = ( $observed[0] ?? null ) === $row && $restored; }
		$lifecycle['native_fixture_options_restored'] = $restored;
	}
}
add_filter( 'locale', static fn(): string => 'fr_FR', PHP_INT_MAX ); date_default_timezone_set( 'Pacific/Auckland' );
$order = new WC_Order( $config['order_id'] );
$read = ( new CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader() )->read_package( $order ); $envelope = $read->delivery_quote?->envelope; $packet = $envelope?->promise_packet();
$root = dirname( (string) ( new ReflectionClass( CetechDeliveryEngine\Bootstrap\Plugin::class ) )->getFileName(), 3 );
$autoload = realpath( $root . '/vendor/autoload.php' ); $included = array_map( static fn( string $file ): string|false => realpath( $file ), get_included_files() );
$ok = null !== $envelope && null !== $packet && CetechDeliveryEngine\Application\Order\QuoteNativeOrderHistory::verify( $order ) && $config['envelope_digest'] === hash( 'sha256', $envelope->to_private_json() ) && $config['promise_packet_digest'] === hash( 'sha256', $packet->to_private_json() ) && $config['public_groups_digest'] === hash( 'sha256', json_encode( $packet->public_groups(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
echo json_encode( [ 'status' => $ok && ( ! $config['run_lifecycle'] || ! in_array( false, $lifecycle, true ) ) ? 'PASS' : 'FAIL', 'process_id' => getmypid(), 'wp_load' => defined( 'ABSPATH' ), 'installed_production_autoload' => false !== $autoload && in_array( $autoload, $included, true ), 'native_locale_changed' => 'fr_FR' === get_locale(), 'native_timezone_changed' => 'Pacific/Auckland' === date_default_timezone_get(), 'exact_original_envelope' => null !== $envelope && $config['envelope_digest'] === hash( 'sha256', $envelope->to_private_json() ), 'exact_original_promise_packet' => null !== $packet && $config['promise_packet_digest'] === hash( 'sha256', $packet->to_private_json() ), 'exact_original_public_projection' => $ok, ...$lifecycle ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
