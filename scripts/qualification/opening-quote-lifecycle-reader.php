<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdmissionGate;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableCommand;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableService;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProviderRegistry;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;

/** Fresh wp-load process: original read/reconcile only, no reconstructed capture capability. */
$path = $argv[1] ?? '';
if ( ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) ) { exit( 2 ); }
$phase = 'configuration'; $factory = null; $error_class = null;
$progress = [ 'process_id' => getmypid(), 'wp_load' => false, 'default_object_cache' => false, 'installed_candidate_autoload' => false ]; $checks = [];
try {
	$config = json_decode( (string) file_get_contents( $path ), true, 32, JSON_THROW_ON_ERROR );
	$keys = is_array( $config ) ? array_keys( $config ) : []; sort( $keys );
	$expected = [ 'wp_load', 'plugin_root', 'prefix', 'site', 'fixture_time', 'owner', 'context', 'reference', 'header', 'original_issue_token', 'completion_hash', 'row_hash', 'history_hash' ]; sort( $expected );
	if ( $keys !== $expected || ! is_string( $config['wp_load'] ) || ! str_ends_with( $config['wp_load'], '/wp-load.php' ) || ! is_file( $config['wp_load'] ) || ! is_string( $config['plugin_root'] ) || ! is_string( $config['prefix'] ) || 1 !== preg_match( '/\Agc6_[a-f0-9]{12}_\z/D', $config['prefix'] ) || 99176 !== $config['site'] || ! is_array( $config['owner'] ) || ! is_array( $config['context'] ) || ! is_array( $config['reference'] ) || ! is_string( $config['header'] ) || ! is_string( $config['fixture_time'] ) || ! is_string( $config['original_issue_token'] ) ) { throw new RuntimeException(); }
	foreach ( [ 'completion_hash', 'row_hash', 'history_hash' ] as $key ) { if ( ! is_string( $config[$key] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $config[$key] ) ) { throw new RuntimeException(); } }
	define( 'SHORTINIT', true ); define( 'WP_ADMIN', true );
	$phase = 'wp_load'; require $config['wp_load']; $progress['wp_load'] = true;
	$phase = 'fixture_authority';
	if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || ! defined( 'DB_HOST' ) || 1 !== preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST ) || ! defined( 'DB_NAME' ) || 1 !== preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME ) || ! $GLOBALS['wpdb'] instanceof wpdb || wpdb::class !== get_class( $GLOBALS['wpdb'] ) || ! isset( $GLOBALS['wp_object_cache'] ) || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] ) || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || is_file( WP_CONTENT_DIR . '/object-cache.php' ) || is_file( WP_CONTENT_DIR . '/db.php' ) ) { throw new RuntimeException(); }
	$progress['default_object_cache'] = true;
	$phase = 'installed_autoload';
	$installed = realpath( WP_CONTENT_DIR . '/plugins/cetech-woocommerce-delivery-engine' );
	if ( false === $installed || $installed !== realpath( $config['plugin_root'] ) || ! is_file( $installed . '/vendor/autoload.php' ) || ! is_file( $installed . '/cetech-woocommerce-delivery-engine.php' ) ) { throw new RuntimeException(); }
	require $installed . '/vendor/autoload.php';
	$progress['installed_candidate_autoload'] = true;
	require_once dirname( __DIR__, 2 ) . '/tests/Support/DeliveryQuote/QuoteFixtures.php';
	require_once __DIR__ . '/opening-quote-lifecycle-support.php';
	foreach ( get_included_files() as $included ) { if ( str_ends_with( str_replace( '\\', '/', $included ), '/tests/bootstrap.php' ) ) { throw new RuntimeException(); } }
	$GLOBALS['wpdb']->set_prefix( $config['prefix'] ); $GLOBALS['blog_id'] = $config['site']; $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	$checks['schema9'] = '9' === (string) get_option( 'cetech_de_db_version' );
	$phase = 'reconstruct_original';
	$owner = QuoteOwner::from_array( $config['owner'] ); $context = QuoteContext::from_array( $config['context'] ); $reference = QuoteReference::from_array( $config['reference'] ); $header = QuoteHeader::from_json( $config['header'] );
	if ( $owner->site_id() !== $config['site'] || ! $header->owner()->equals( $owner ) || ! $header->matches_reference( $reference ) || ! hash_equals( $header->material_digest(), $context->digest() ) ) { throw new RuntimeException(); }
	$original = QuoteDurableCommand::accept( $owner, $reference, $header, $context );
	$original_issue = QuoteIssueCommand::create( $owner, $context, 'fixture_v1', 1, 'fixture_v1', 1, $config['original_issue_token'] );
	if ( $original_issue->namespace_hashes( $header->id() ) !== $header->namespace_hashes() ) { throw new RuntimeException(); }
	$factory = new CetechNativeQuoteLifecycleFactory( $GLOBALS['wpdb'], $config['site'], $config['prefix'] ); $factory->fixture_time( QuoteTime::parse( $config['fixture_time'] ) );
	$provider = new CetechNativeQuoteLifecycleProvider( $context, $factory );
	$authorize = static fn ( QuoteOwner $candidate, string $operation ): bool => $owner->equals( $candidate ) && str_starts_with( $operation, 'delivery_quote.' );
	$service = new QuoteDurableService( $factory, new QuoteProviderRegistry( [ $provider ] ), $authorize, evidence: new CetechNativeQuoteLifecycleEvidence() );
	$history = static function () use ( $config ): array { $out = []; foreach ( [ 'operation_records', 'operation_changes', 'delivery_quotes', 'delivery_quote_bindings', 'delivery_quote_budget_windows' ] as $suffix ) { $rows = $GLOBALS['wpdb']->get_results( "SELECT * FROM `{$config['prefix']}delivery_engine_{$suffix}` ORDER BY id", ARRAY_A ); if ( ! is_array( $rows ) ) { throw new RuntimeException(); } $out[$suffix] = $rows; } return $out; };
	$before = $history();
	$phase = 'reconcile'; $result = $service->reconcile( $original, RequestContext::create() );
	$checks['exact_completion'] = 'accepted' === $result->attempt->outcome->state && null !== $result->attempt->completion && hash_equals( $config['completion_hash'], hash( 'sha256', $result->attempt->completion->to_json() ) );
	$checks['replayed'] = $result->attempt->replayed;
	$gate = ( new QuoteAdmissionGate( $factory, $authorize ) )->inspect( $original_issue );
	$checks['no_capture_capability'] = 'completed' === $gate->status && null === $gate->lease && $header->id()->equals( $gate->quote_id );
	$phase = 'current_read'; $read = $service->current( $owner, $reference, $context, RequestContext::create() );
	$phase = 'comparison';
	$checks['exact_row'] = 'ready' === $read->status && null === $read->reason && null !== $read->quote && hash_equals( $config['row_hash'], hash( 'sha256', json_encode( $read->quote->row(), JSON_THROW_ON_ERROR ) ) ) && $read->quote->row() === $result->quote?->row();
	$checks['unchanged_history'] = $before === $history() && hash_equals( $config['history_hash'], hash( 'sha256', json_encode( $before, JSON_THROW_ON_ERROR ) ) );
	$checks['capture_not_called'] = 0 === $provider->captures;
	$phase = 'retirement'; $checks['all_owners_retired'] = ! $factory->has_active_owner() && $factory->close_all();
	foreach ( $checks as $verified ) { if ( true !== $verified ) { throw new RuntimeException(); } }
	echo json_encode( [ 'status' => 'PASS', 'phase' => 'complete' ] + $progress + $checks, JSON_THROW_ON_ERROR );
} catch ( Throwable $error ) {
	if ( null !== $factory ) { try { $checks['all_owners_retired'] = $factory->close_all(); } catch ( Throwable ) { $checks['all_owners_retired'] = false; } }
	$error_class = match ( get_class( $error ) ) { RuntimeException::class => 'RuntimeException', Error::class => 'Error', TypeError::class => 'TypeError', JsonException::class => 'JsonException', InvalidArgumentException::class => 'InvalidArgumentException', LogicException::class => 'LogicException', 'CetechDeliveryEngine\\Application\\Operation\\OperationStorageException' => 'OperationStorageException', default => 'other' };
	echo json_encode( [ 'status' => 'FAIL', 'phase' => $phase, 'error_class' => $error_class ] + $progress + $checks, JSON_THROW_ON_ERROR ); exit( 1 );
}
