<?php

declare(strict_types=1);

/**
 * Native WordPress option, cache, and resolver proof for COR-007.
 *
 * This file loads wp-load.php and does not load tests/bootstrap.php.
 * The disposable site must already exist. CETECH_DE_WP_LOAD points at wp-load.php.
 */

$root = dirname( __DIR__, 2 );
$load = getenv( 'CETECH_DE_WP_LOAD' );
if ( ! is_string( $load ) || ! is_file( $load ) ) {
	fwrite( STDERR, "CETECH_DE_WP_LOAD must point at a disposable wp-load.php\n" );
	exit( 2 );
}

if ( isset( $argv[1] ) && in_array( $argv[1], [ 'page', 'render' ], true ) && ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

require $load;
require $root . '/vendor/autoload.php';

use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Application\Configuration\Admin\LegacyCategoryConfigurationInspector;
use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationSubmissionParser;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationWriteCommand;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Infrastructure\Persistence\AbstractWpdbRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbAuditLogRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbScopedConfigurationRepository;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\ConfigurationAuditLogger;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;
use CetechDeliveryEngine\Presentation\Admin\ScopedConfigurationPage;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Support\Logger;

/**
 * @param array<string, mixed> $payload
 */
function cor007_emit( array $payload, int $exit = 0 ): never {
	echo wp_json_encode( $payload ) . "\n";
	exit( $exit );
}

function cor007_fail( string $message ): never {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

function cor007_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		cor007_fail( $message );
	}
}

function cor007_service(): ScopedConfigurationAdminService {
	$repository = new WpdbScopedConfigurationRepository();
	$resolver   = new EffectiveConfigurationResolver( $repository, new EffectiveConfigurationValidator(), new PassthroughFulfilmentConstraintService() );

	return new ScopedConfigurationAdminService(
		$repository,
		$resolver,
		new ScopedConfigurationSubmissionParser(),
		new ProductVariationScopeGuard(),
		new EntityLabelResolver(),
		new LegacyCategoryConfigurationInspector(),
		new ConfigurationAuditLogger( new WpdbAuditLogRepository(), new Logger() )
	);
}

function cor007_priority( EffectiveConfigurationResolver $resolver ): string {
	$effective = $resolver->resolve( new EffectiveConfigurationRequest( 1 ) );
	$field     = $effective->scalar( ConfigurationFieldKey::PRIORITY );

	return null === $field || null === $field->value ? '' : (string) $field->value;
}

/**
 * @return array<string, array<string, mixed>>
 */
function cor007_fields( string $priority ): array {
	return [
		ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'override', 'value' => FulfilmentAvailability::InStore->value ],
		ConfigurationFieldKey::FULFILMENT_CHOICE       => [ 'mode' => 'override', 'value' => FulfilmentChoice::Delivery->value ],
		ConfigurationFieldKey::LOGISTICS_PROFILE_ID    => [ 'mode' => 'override', 'value' => '10' ],
		ConfigurationFieldKey::SUPPLIER_ID             => [ 'mode' => 'override', 'value' => '20' ],
		ConfigurationFieldKey::ORIGIN_ID               => [ 'mode' => 'override', 'value' => '30' ],
		ConfigurationFieldKey::PRIORITY                => [ 'mode' => 'override', 'value' => $priority ],
		ConfigurationFieldKey::DELIVERY_OFFER_IDS      => [ 'mode' => 'replace', 'members' => [ '1' ] ],
	];
}

function cor007_command( string $priority, string $token, ?int $expected = null ): ScopedConfigurationWriteCommand {
	return new ScopedConfigurationWriteCommand(
		ConfigurationScopeType::Global,
		0,
		'',
		null,
		cor007_fields( $priority ),
		false,
		$expected,
		$token
	);
}

function cor007_durable_option(): ?string {
	global $wpdb;

	$name = ScopedConfigurationSchema::GLOBAL_VERSION_OPTION;
	$sql  = $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $name );
	$wpdb->flush();
	$value = $wpdb->get_var( $sql );
	if ( '' !== trim( (string) $wpdb->last_error ) ) {
		cor007_fail( 'Durable option read failed: ' . $wpdb->last_error );
	}

	return null === $value ? null : (string) $value;
}

function cor007_durable_priority(): ?string {
	global $wpdb;

	$scopes = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );
	$fields = TableNames::for( ScopedConfigurationSchema::FIELDS_SUFFIX );
	$sql    = "SELECT f.value_text FROM `{$fields}` f INNER JOIN `{$scopes}` s ON s.id = f.scope_row_id WHERE s.scope_type = 'global' AND s.scope_id = 0 AND s.slice_key = '' AND f.field_key = 'priority'";
	$value  = $wpdb->get_var( $sql );
	if ( '' !== trim( (string) $wpdb->last_error ) ) {
		cor007_fail( 'Durable priority read failed: ' . $wpdb->last_error );
	}

	return null === $value ? null : (string) $value;
}

function cor007_audit_count(): int {
	global $wpdb;

	$table = TableNames::for( 'audit_log' );

	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
}

/**
 * @return array<string, mixed>
 */
function cor007_observe(): array {
	global $wpdb;

	$key        = ScopedConfigurationSchema::GLOBAL_VERSION_OPTION;
	$notoptions = wp_cache_get( 'notoptions', 'options' );
	$alloptions = wp_cache_get( 'alloptions', 'options' );
	$cached     = wp_cache_get( $key, 'options' );
	$repository = new WpdbScopedConfigurationRepository();
	$resolver   = new EffectiveConfigurationResolver( $repository, new EffectiveConfigurationValidator(), new PassthroughFulfilmentConstraintService() );

	return [
		'wordpress'          => get_bloginfo( 'version' ),
		'object_cache_dropin'=> is_file( WP_CONTENT_DIR . '/object-cache.php' ),
		'option_autoload'    => (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM `{$wpdb->options}` WHERE option_name = %s", $key ) ),
		'get_option'         => get_option( $key, null ),
		'cache_value'        => false === $cached ? null : $cached,
		'in_notoptions'      => is_array( $notoptions ) && array_key_exists( $key, $notoptions ),
		'in_alloptions'      => is_array( $alloptions ) && array_key_exists( $key, $alloptions ),
		'resolver_priority'  => cor007_priority( $resolver ),
		'durable_option'     => cor007_durable_option(),
		'durable_priority'   => cor007_durable_priority(),
	];
}

function cor007_install(): void {
	global $wpdb;

	if ( ! str_starts_with( (string) $wpdb->dbname, 'cetech_cor004_' ) ) {
		cor007_fail( 'Refusing to mutate a database outside the COR-004 disposable prefix.' );
	}

	$prefix = $wpdb->prefix . TableNames::PREFIX;
	foreach ( [ 'configuration_collections', 'configuration_fields', 'configuration_scopes', 'audit_log' ] as $suffix ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $prefix . $suffix . '`' );
	}
	foreach ( ScopedConfigurationSchema::create_table_statements( $wpdb->get_charset_collate(), $prefix ) as $sql ) {
		$created = $wpdb->query( $sql );
		if ( false === $created ) {
			cor007_fail( 'Could not create a configuration table: ' . $wpdb->last_error );
		}
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name = %s", ScopedConfigurationSchema::GLOBAL_VERSION_OPTION ) );
	$audit = $prefix . 'audit_log';
	$created_audit = $wpdb->query( "CREATE TABLE `{$audit}` (id bigint unsigned NOT NULL AUTO_INCREMENT, actor_user_id bigint unsigned DEFAULT NULL, action varchar(64) NOT NULL, entity_type varchar(64) NOT NULL, entity_id bigint unsigned DEFAULT NULL, previous_value longtext, new_value longtext, site_context varchar(255) DEFAULT NULL, created_at datetime NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB" );
	if ( false === $created_audit ) {
		cor007_fail( 'Could not create the audit table: ' . $wpdb->last_error );
	}
	$wpdb->query( 'DROP TRIGGER IF EXISTS cor007_reject_field' );
	$wpdb->query( 'DROP TRIGGER IF EXISTS cor007_reject_option' );
	$fields = $prefix . 'configuration_fields';
	$wpdb->query( "CREATE TRIGGER cor007_reject_field BEFORE INSERT ON `{$fields}` FOR EACH ROW BEGIN IF NEW.value_text = '77' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rejected field insert'; END IF; END" );
	$wpdb->query( "CREATE TRIGGER cor007_reject_option BEFORE UPDATE ON `{$wpdb->options}` FOR EACH ROW BEGIN IF @cor007_reject_option = 1 AND NEW.option_name = '" . ScopedConfigurationSchema::GLOBAL_VERSION_OPTION . "' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rejected option update'; END IF; END" );
}

/**
 * @return array<string, mixed>
 */
function cor007_child(): array {
	$command = escapeshellarg( PHP_BINARY ) . ' -d extension=mysqli ' . escapeshellarg( __FILE__ ) . ' observe';
	$output  = tempnam( sys_get_temp_dir(), 'cor007wp' );
	$process = proc_open(
		$command,
		[ 1 => [ 'file', (string) $output, 'w' ], 2 => [ 'file', (string) $output, 'a' ] ],
		$pipes,
		dirname( __DIR__, 2 ),
		[
			'CETECH_DE_WP_LOAD' => (string) getenv( 'CETECH_DE_WP_LOAD' ),
		]
	);
	if ( ! is_resource( $process ) ) {
		cor007_fail( 'Could not start an independent WordPress request.' );
	}
	$exit = proc_close( $process );
	$raw  = (string) file_get_contents( (string) $output );
	@unlink( (string) $output );
	$decoded = json_decode( trim( $raw ), true );
	if ( 0 !== $exit || ! is_array( $decoded ) ) {
		cor007_fail( 'Independent WordPress request failed: ' . $raw );
	}

	return $decoded;
}

/**
 * @return array<string, mixed>
 */
function cor007_spawn( string $mode, array $env, string $output ): array {
	$command = escapeshellarg( PHP_BINARY ) . ' -d extension=mysqli ' . escapeshellarg( __FILE__ ) . ' ' . $mode;
	$process = proc_open(
		$command,
		[ 1 => [ 'file', $output, 'w' ], 2 => [ 'file', $output, 'a' ] ],
		$pipes,
		dirname( __DIR__, 2 ),
		$env
	);
	if ( ! is_resource( $process ) ) {
		cor007_fail( 'Could not start the ' . $mode . ' WordPress process.' );
	}
	$exit = proc_close( $process );
	$raw  = (string) file_get_contents( $output );

	return [ 'exit' => $exit, 'raw' => $raw ];
}

function cor007_replay_child(): never {
	$service = cor007_service();
	$result  = $service->save( cor007_command( (string) getenv( 'CETECH_DE_COR007_PRIORITY' ), (string) getenv( 'CETECH_DE_COR007_TOKEN' ), (int) getenv( 'CETECH_DE_COR007_EXPECTED' ) ) );
	cor007_emit(
		[
			'success'  => $result->success,
			'replayed' => $result->replayed,
			'errors'   => $result->errors,
			'audits'   => cor007_audit_count(),
			'priority' => cor007_durable_priority(),
		]
	);
}

function cor007_page_child(): never {
	cor007_act_as_admin();
	$out = (string) getenv( 'CETECH_DE_COR007_PAGE_OUT' );
	if ( '' === $out ) {
		cor007_fail( 'The page process needs an output path.' );
	}
	global $wpdb;
	$retired = new Cor007LostAckWpdb( $wpdb );
	$wpdb    = $retired;
	$retired->lose_next_commit = true;
	register_shutdown_function(
		static function () use ( $retired, $out ): void {
			global $wpdb;
			file_put_contents(
				$out,
				wp_json_encode(
					[
						'retired_ready'    => $retired->ready,
						'retired_query'    => $retired->query( 'SELECT 1' ),
						'replaced'         => $wpdb !== $retired && '' !== (string) ( $wpdb->options ?? '' ),
						'user_id'          => get_current_user_id(),
					]
				)
			);
		}
	);
	$scopes  = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );
	$version = (int) $wpdb->get_var( "SELECT config_version FROM `{$scopes}` WHERE scope_type = 'global' AND scope_id = 0 AND slice_key = ''" );
	$row_id  = (int) $wpdb->get_var( "SELECT id FROM `{$scopes}` WHERE scope_type = 'global' AND scope_id = 0 AND slice_key = ''" );
	$_POST   = [
		'cetech_de_action'       => ScopedConfigurationPage::ACTION_SAVE,
		'cetech_de_nonce'        => wp_create_nonce( ScopedConfigurationPage::ACTION_SAVE ),
		'scope_type'             => 'global',
		'scope_id'               => '0',
		'slice_key'              => '',
		'expected_revision'      => (string) $version,
		'expected_scope_row_id'  => (string) $row_id,
		'request_token'          => 'page-lost-ack',
		'fields'                 => cor007_fields( '4' ),
	];
	cor007_page()->handle_actions();
	cor007_fail( 'The page handler returned without redirecting.' );
}

function cor007_render_child(): never {
	cor007_act_as_admin();
	require_once ABSPATH . 'wp-admin/includes/template.php';
	$_GET = [ 'scope_type' => 'global' ];
	$out   = (string) getenv( 'CETECH_DE_COR007_RENDER_OUT' );
	$draft = get_transient( 'cetech_de_scoped_draft_' . get_current_user_id() . '_global_0__none' );
	file_put_contents(
		$out . '.draft',
		(string) wp_json_encode(
			[
				'user'      => get_current_user_id(),
				'authority' => is_array( $draft ) ? ( $draft['authority'] ?? null ) : null,
				'token'     => is_array( $draft ) ? ( $draft['save_token'] ?? null ) : null,
			]
		)
	);
	$level = ob_get_level();
	ob_start();
	cor007_page()->render();
	$html = '';
	while ( ob_get_level() > $level ) {
		$html = (string) ob_get_clean() . $html;
	}
	file_put_contents( $out, $html );
	cor007_emit( [ 'rendered' => true, 'bytes' => strlen( $html ) ] );
}

function cor007_act_as_admin(): void {
	$users = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
	if ( [] === $users ) {
		cor007_fail( 'The disposable site has no administrator.' );
	}
	$users[0]->add_cap( Capabilities::SITE_WIDE );
	wp_set_current_user( (int) $users[0]->ID );
}

function cor007_page(): ScopedConfigurationPage {
	return new ScopedConfigurationPage(
		cor007_service(),
		new ProductTargetResolver( new Requirements() ),
		new AdminActionHandler( new AdminNoticeService() ),
		new ScopedConfigurationAuthorization()
	);
}

$mode = $argv[1] ?? 'prove';
if ( 'observe' === $mode ) {
	cor007_emit( cor007_observe() );
}
if ( 'replay' === $mode ) {
	cor007_replay_child();
}
if ( 'page' === $mode ) {
	cor007_page_child();
}
if ( 'render' === $mode ) {
	cor007_render_child();
}

cor007_install();
$key = ScopedConfigurationSchema::GLOBAL_VERSION_OPTION;
wp_cache_delete( 'notoptions', 'options' );
wp_cache_delete( 'alloptions', 'options' );
wp_cache_delete( $key, 'options' );
$missing = get_option( $key, null );
$notoptions = wp_cache_get( 'notoptions', 'options' );
$alloptions = wp_cache_get( 'alloptions', 'options' );
cor007_check( null === $missing, 'The revision option is absent before the first accepted save.' );
cor007_check( is_array( $notoptions ) && array_key_exists( $key, $notoptions ), 'A missing revision option is recorded in notoptions.' );
cor007_check( is_array( $alloptions ) && ! array_key_exists( $key, $alloptions ), 'The non-autoload revision option is absent from alloptions.' );

$service = cor007_service();
$resolver = ( new ReflectionClass( $service ) )->getProperty( 'resolver' );
$resolver->setAccessible( true );
/** @var EffectiveConfigurationResolver $prewarmed */
$prewarmed = $resolver->getValue( $service );

$first = $service->save( cor007_command( '5', 'wp-base', 0 ) );
cor007_check( true === $first->success, 'First save failed: ' . implode( ' ', $first->errors ) );
$published = (string) get_option( $key, '' );
cor007_check( $published === (string) $first->version_after, 'Same-request get_option does not match the accepted revision.' );
cor007_check( (string) wp_cache_get( $key, 'options' ) === $published, 'Object cache does not match the accepted revision.' );
$notoptions = wp_cache_get( 'notoptions', 'options' );
$alloptions = wp_cache_get( 'alloptions', 'options' );
cor007_check( ! is_array( $notoptions ) || ! array_key_exists( $key, $notoptions ), 'notoptions still treats the published revision as missing.' );
cor007_check( is_array( $alloptions ) && ! array_key_exists( $key, $alloptions ), 'A non-autoload revision was added to alloptions.' );
cor007_check( cor007_durable_option() === $published, 'Durable option row does not match get_option.' );
cor007_check( '5' === cor007_priority( $prewarmed ), 'Prewarmed resolver does not see the accepted priority.' );

$child = cor007_child();
cor007_check( (string) $child['get_option'] === $published, 'Independent request get_option disagrees with this request.' );
cor007_check( '5' === (string) $child['resolver_priority'], 'Fresh resolver disagrees with the prewarmed resolver.' );
cor007_check( false === $child['in_alloptions'], 'Independent alloptions contains the non-autoload revision.' );

$wpdb->query( 'SET @cor007_reject_option := 1' );
$before_option = cor007_durable_option();
$before_audits = cor007_audit_count();
$rejected_option = $service->save( cor007_command( '9', 'wp-option-fail', $first->version_after ) );
cor007_check( false === $rejected_option->success, 'A rejected option update was reported as saved.' );
cor007_check( str_contains( implode( ' ', $rejected_option->errors ), 'Save outcome could not be confirmed.' ), 'Rejected option update did not stay unconfirmed: ' . implode( ' ', $rejected_option->errors ) );
cor007_check( '9' === cor007_durable_priority(), 'Committed settings were rolled back after the option publication failed.' );
cor007_check( $before_option === cor007_durable_option(), 'The durable revision option changed after a rejected update.' );
cor007_check( cor007_audit_count() === $before_audits + 1, 'The accepted audit was not retained when option publication failed.' );
$same_request_option = get_option( $key, null );
cor007_check( (string) $same_request_option === (string) $before_option, 'Same-request option cache diverged from the durable revision after a rejected update. Cache=' . var_export( $same_request_option, true ) . ' durable=' . var_export( $before_option, true ) );
$child = cor007_child();
cor007_check( (string) $child['get_option'] === (string) $before_option, 'Independent request published a revision the option update rejected.' );
cor007_check( '9' === (string) $child['resolver_priority'], 'Fresh resolver hid the committed settings after option publication failed.' );
cor007_check( '9' === cor007_priority( $prewarmed ), 'Prewarmed resolver hid the committed settings after its memoization was cleared.' );

$wpdb->query( 'SET @cor007_reject_option := 0' );
$replay = $service->save( cor007_command( '9', 'wp-option-fail', $first->version_after ) );
cor007_check( true === $replay->success && true === $replay->replayed, 'Retry did not republish the recorded completion: ' . implode( ' ', $replay->errors ) );
cor007_check( cor007_audit_count() === $before_audits + 1, 'Retry appended another audit for the same accepted intent.' );
cor007_check( (string) get_option( $key, '' ) === (string) $replay->version_after, 'Retry did not publish the recorded revision.' );
cor007_check( cor007_durable_option() === (string) get_option( $key, '' ), 'Durable option still disagrees with get_option after retry.' );

cor007_priority( $prewarmed );
$before_fail = cor007_durable_priority();
$failed = $service->save( cor007_command( '77', 'wp-field-fail', (int) $replay->version_after ) );
cor007_check( false === $failed->success, 'Rejected field insert was reported as saved.' );
cor007_check( str_contains( implode( ' ', $failed->errors ), 'Settings were not saved.' ), 'Rejected field insert used the wrong failure: ' . implode( ' ', $failed->errors ) );
cor007_check( $before_fail === cor007_durable_priority(), 'Rejected field insert changed durable settings.' );
cor007_check( $before_fail === cor007_priority( $prewarmed ), 'Prewarmed resolver showed a rejected change.' );
$child = cor007_child();
cor007_check( $before_fail === (string) $child['resolver_priority'], 'Fresh resolver showed a rejected change.' );
cor007_check( (string) $child['get_option'] === (string) get_option( $key, '' ), 'Option cache disagreed between requests after a known failure.' );

$buried_audits = cor007_audit_count();
for ( $i = 0; $i < 101; $i++ ) {
	$wpdb->query( $wpdb->prepare( 'INSERT INTO `' . TableNames::for( 'audit_log' ) . '` (actor_user_id, action, entity_type, entity_id, previous_value, new_value, site_context, created_at) VALUES (1, %s, %s, 1, NULL, %s, NULL, UTC_TIMESTAMP())', 'unrelated', 'note', '{"request_token":"wp-other-' . $i . '"}' ) );
}
$replay_out = (string) tempnam( sys_get_temp_dir(), 'cor007replay' );
$replay     = cor007_spawn(
	'replay',
	[
		'CETECH_DE_WP_LOAD'            => (string) getenv( 'CETECH_DE_WP_LOAD' ),
		'CETECH_DE_COR007_PRIORITY'    => '9',
		'CETECH_DE_COR007_TOKEN'       => 'wp-option-fail',
		'CETECH_DE_COR007_EXPECTED'    => (string) $first->version_after,
	],
	$replay_out
);
$replay_json = json_decode( trim( $replay['raw'] ), true );
@unlink( $replay_out );
cor007_check( is_array( $replay_json ) && true === $replay_json['replayed'], 'A fresh process did not replay the buried token: ' . $replay['raw'] );
cor007_check( cor007_audit_count() === $buried_audits + 101, 'The fresh-process replay appended another audit.' );

$page_out   = (string) tempnam( sys_get_temp_dir(), 'cor007page' );
$render_out = (string) tempnam( sys_get_temp_dir(), 'cor007html' );
cor007_spawn(
	'page',
	[
		'CETECH_DE_WP_LOAD'       => (string) getenv( 'CETECH_DE_WP_LOAD' ),
		'CETECH_DE_COR007_PAGE_OUT'=> $page_out,
	],
	$page_out . '.log'
);
$page_json = json_decode( (string) file_get_contents( $page_out ), true );
cor007_check( is_array( $page_json ) && false === $page_json['retired_ready'] && false === $page_json['retired_query'] && true === $page_json['replaced'], 'The page handler did not replace the closed connection: ' . (string) file_get_contents( $page_out . '.log' ) );
$render = cor007_spawn(
	'render',
	[
		'CETECH_DE_WP_LOAD'         => (string) getenv( 'CETECH_DE_WP_LOAD' ),
		'CETECH_DE_COR007_RENDER_OUT'=> $render_out,
	],
	$render_out . '.log'
);
$html = (string) file_get_contents( $render_out );
preg_match_all( '/name="request_token" value="([^"]*)"/', $html, $rendered_tokens );
cor007_check( str_contains( $html, 'value="page-lost-ack"' ), 'The rerendered page did not keep the unconfirmed token. Draft ' . (string) @file_get_contents( $render_out . '.draft' ) . ' rendered ' . implode( ',', $rendered_tokens[1] ?? [] ) . ' bytes ' . (string) strlen( $html ) );
cor007_check( str_contains( $html, 'value="4"' ), 'The rerendered page did not keep the unsaved priority.' );
cor007_check( str_contains( $html, 'could not be confirmed' ), 'The rerendered page did not show the lost-acknowledgement error.' );
@unlink( $page_out );
@unlink( $page_out . '.log' );
@unlink( $render_out );
@unlink( $render_out . '.log' );

$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM `{$wpdb->options}` WHERE option_name = %s", $key ) );
$retired  = $wpdb;
$retired->close();
cor007_check( false === $retired->query( 'SELECT 1' ), 'A closed connection still accepted a query.' );
cor007_check( true === AbstractWpdbRepository::replace_closed_connection(), 'The closed connection was not replaced before recovery.' );
global $wpdb;
cor007_check( $wpdb !== $retired && false === $retired->ready, 'Recovery reused the closed connection.' );
set_transient( 'cetech_de_cor007_recovery', [ 'error' => 'Save outcome could not be confirmed.', 'draft' => 'priority-9' ], 60 );
$recovered = get_transient( 'cetech_de_cor007_recovery' );
cor007_check( is_array( $recovered ) && 'Save outcome could not be confirmed.' === ( $recovered['error'] ?? '' ) && 'priority-9' === ( $recovered['draft'] ?? '' ), 'The useful error and editable draft did not survive the closed connection.' );

cor007_emit(
	[
		'result'             => 'PASS',
		'wordpress'          => get_bloginfo( 'version' ),
		'php'                => PHP_VERSION,
		'database'           => (string) $wpdb->dbname,
		'prefix'             => (string) $wpdb->prefix,
		'object_cache_dropin'=> is_file( WP_CONTENT_DIR . '/object-cache.php' ),
		'option'             => $key,
		'autoload'           => $autoload,
		'recovery'           => 'helper-cache',
		'page_handler'       => 'replaced-closed-connection',
	]
);

/**
 * Sends COMMIT, then reports failure and stays closed. The native wpdb object is the inner connection.
 */
final class Cor007LostAckWpdb {

	public bool $ready = true;

	public bool $lose_next_commit = false;

	private bool $commit_sent = false;

	public function __construct( private \wpdb $inner ) {
	}

	public function query( string $query ) {
		if ( ! $this->ready ) {
			return false;
		}
		if ( $this->lose_next_commit && str_starts_with( ltrim( $query ), 'COMMIT' ) ) {
			$this->lose_next_commit = false;
			$this->inner->query( $query );
			$this->commit_sent = true;

			return false;
		}

		return $this->inner->query( $query );
	}

	public function commit_was_sent(): bool {
		return $this->commit_sent;
	}

	public function close(): bool {
		$this->ready = false;
		$this->inner->close();

		return true;
	}

	public function __get( string $name ): mixed {
		return $this->inner->$name;
	}

	public function __set( string $name, mixed $value ): void {
		$this->inner->$name = $value;
	}

	public function __isset( string $name ): bool {
		return isset( $this->inner->$name );
	}

	/**
	 * @param list<mixed> $arguments
	 */
	public function __call( string $name, array $arguments ): mixed {
		return $this->inner->$name( ...$arguments );
	}
}
