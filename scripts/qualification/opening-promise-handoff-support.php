<?php
declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteService, NativeCartQuoteEnvironment, NativeCartQuotePreparation, NativeCartQuoteSessionStore, PromiseQuotePlacementActivation, QuotePreparationGate};
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, OrderDeliverySnapshot, OrderDeliverySnapshotReader, QuoteNativeOrderHistory};
use CetechDeliveryEngine\Application\ServicePromise\Handoff\PromiseNativeCaptureService;
use CetechDeliveryEngine\Application\ServicePromise\Persistence\{PromiseAssignmentService, PromiseVersionLifecycleService};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteId, QuoteStoredRow};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, ServicePromisePolicy};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromisePersistenceAuthorizer, PromiseSiteBinding, PromiseVersionCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Integrations\ServicePromise\PromiseNativeServiceRegistry;

require_once __DIR__ . '/opening-quote-placement-support.php';
require_once __DIR__ . '/opening-promise-storage-support.php';

/** Observe the original admitted call, without retrying or manufacturing authority. */
final class CetechPromiseHandoffEnvironmentObservation implements CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteProfileEnvironment {
	public ?Throwable $preparation_error = null;
	public ?CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuotePreparedCapture $prepared = null;
	public function __construct( private NativeCartQuoteEnvironment $native ) {}
	public function draft(): ?CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartDraft { return $this->native->draft(); }
	public function authorize( CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner $owner, string $operation ): bool { return $this->native->authorize( $owner, $operation ); }
	public function profile(): string { return $this->native->profile(); }
	public function prepare( CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartDraft $draft ): CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuotePreparedCapture { return $this->prepare_at( $draft, CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::now() ); }
	public function prepare_at( CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartDraft $draft, CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime $at ): CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuotePreparedCapture { try { return $this->prepared = $this->native->prepare_at( $draft, $at ); } catch ( Throwable $error ) { $this->preparation_error = $error; throw $error; } }
	public function evidence( CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand $original, CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader $header, CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartDraft $draft ): ?CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartCurrentEvidence { return $this->native->evidence( $original, $header, $draft ); }
}

/** Native grants are captured before SQL; the callback never performs WordPress IO under a lock. */
final class CetechPromiseHandoffNativeAuthorizer implements PromisePersistenceAuthorizer {
	public bool $allowed = true;
	private bool $administration_allowed;
	public function __construct( private PromiseSiteBinding $binding, private string $native_principal ) {
		$user = get_user_by( 'id', 1 ); $this->administration_allowed = $user instanceof WP_User && user_can( $user, 'manage_options' );
	}
	public function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool {
		return $this->allowed && $this->administration_allowed && $binding->digest() === $this->binding->digest() && $identity->site_id === $binding->site_id() && ( ( 'native-p04-internal' === $identity->authority && 'user:1' === $identity->principal && 1 === $author_user_id ) || ( 'service_promise.native_capture.v1' === $identity->authority && $this->native_principal === $identity->principal && 'promise.assignment.read' === $identity->operation && 0 === $author_user_id ) );
	}
	public function authorize_author( PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool { return $this->allowed && $this->administration_allowed && $binding->digest() === $this->binding->digest() && 1 === $author_user_id; }
}

/** All effects use actual installed services, native Woo fixtures and pre-registered exact object identities. */
final class CetechPromiseHandoffNativeFixture {
	public CetechQuotePlacementFixture $native;
	public CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementEvidence $legacy;
	public PromiseSiteBinding $binding;
	public CetechPromiseHandoffNativeAuthorizer $authority;
	public BusinessCalendarVersion $calendar;
	public ServicePromisePolicy $policy;
	public PromiseNativeServiceRegistry $registry;
	public PromiseNativeCaptureService $capture;
	public PromiseQuotePlacementActivation $activation;
	public CetechPromiseHandoffEnvironmentObservation $observation;
	public array $legacy_bytes = [];
	private string $token;
	private array $namespaces = [];
	private array $registered = [ 'promise_objects' => [], 'promise_versions' => [], 'promise_assignments' => [] ];
	private array $version_uuids = [];
	private array $object_keys = [];
	private array $assignment_keys = [];
	private array $configuration_entities = [];
	private array $promise_before;
	private ?array $adoption_before;
	private ?array $configuration_before;
	public function __construct( private wpdb $db ) {
		$this->token = 'p04-' . bin2hex( random_bytes( 6 ) ); $this->native = new CetechQuotePlacementFixture( $db );
		$this->promise_before = $this->promise_rows(); $this->adoption_before = $this->option_row( PromiseQuotePlacementActivation::OPTION );
		$this->configuration_before = $this->option_row( 'cetech_de_quote_placement_adoption' );
	}
	public function prepare( array $policy_changes = [] ): void {
		$this->native->prepare(); add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX ); $this->legacy = $this->native->confirmed_evidence();
		$this->legacy_bytes = $this->native->cart->row( $this->legacy->header()->id()->value() );
		$this->binding = PromiseSiteBinding::bind( get_current_blog_id(), $this->token );
		$this->authority = new CetechPromiseHandoffNativeAuthorizer( $this->binding, $this->legacy->owner()->digest() );
		$this->enable_native_configuration();
		$this->calendar = BusinessCalendarVersion::from_array( array_replace( CetechNativePromiseBodies::calendar( $this->binding->site_key() )->private_facts(), [ 'calendar_id' => $this->token . '-calendar' ] ) );
		$now = RuleTime::parse( CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::now()->sql() ); $from = RuleTime::from_epoch_microseconds( $now->epoch_microseconds() - 3600000000 )->sql(); $until = RuleTime::from_epoch_microseconds( $now->epoch_microseconds() + 86400000000 )->sql();
		$facts = CetechNativePromiseBodies::policy( $this->binding->site_key(), $from, $until, $this->calendar->reference() )->private_facts();
		$facts['policy_id'] = $this->token . '-policy'; $facts['endpoint'] = 'doorstep';
		$facts['graph']['components'][0]['duration'] = [ 'format_version' => 1, 'min' => 60, 'max' => 120, 'unit' => 'elapsed_minutes', 'calendar' => null ];
		$facts['graph']['components'][0]['operating_calendar'] = null; $facts['graph']['components'][0]['completion_window_rule'] = 'none'; $facts['graph']['components'][0]['endpoint'] = 'doorstep';
		$facts = array_replace( $facts, $policy_changes ); if ( null !== $facts['capacity_source'] && 'replace-after-binding' === $facts['capacity_source']['site_id'] ) { $facts['capacity_source']['site_id'] = $this->binding->site_key(); } $this->policy = ServicePromisePolicy::from_array( $facts );
		foreach ( [ $this->calendar, $this->policy ] as $body ) { foreach ( [ 'create', 'seal', 'publish' ] as $action ) { $this->version( $action, $body ); } }
		$this->assignment( 'assigned', $this->policy );
		$entries = []; foreach ( $this->legacy->context()->private_facts()['groups'] as $group ) { $entries[$group['service_id']] = [ 'native_service_id' => $group['service_id'], 'service_kind' => 'built_in', 'service_code' => 'standard', 'origin_endpoint' => 'native-origin', 'origin_kind' => 'origin', 'destination_endpoint' => 'doorstep', 'destination_kind' => 'doorstep' ]; }
		$this->registry = new PromiseNativeServiceRegistry( array_values( $entries ) );
		$this->capture = new PromiseNativeCaptureService( $this->binding, $this->native->factory, $this->authority );
		$this->activation = new PromiseQuotePlacementActivation( $this->native->factory, static fn(): bool => true, authorize: fn( OperationIdentity $actor, PromiseSiteBinding $binding ): bool => $this->authority->allowed && $actor->site_id === $this->binding->site_id() && 'native-p04-internal' === $actor->authority && 'user:1' === $actor->principal && $binding->digest() === $this->binding->digest() );
		$actor = new OperationIdentity( get_current_blog_id(), 'native-p04-internal', 'user:1', 'service_promise.adoption', 1, 'promise-adoption:' . $this->binding->site_key(), $this->token . '-adoption' );
		if ( ! $this->activation->configure( $this->binding, $this->registry, 0, $actor ) ) { throw new RuntimeException( 'P04 tracked native adoption configuration did not acknowledge.' ); }
		if ( ! $this->activation->change( true, 1, $actor ) ) { throw new RuntimeException( 'P04 tracked native adoption enablement did not acknowledge.' ); }
		$preparation = new NativeCartQuotePreparation( $this->native->factory, promise_capture: $this->capture, promise_services: $this->registry );
		$environment = new NativeCartQuoteEnvironment( $this->native->factory, preparation: $preparation, profile_selector: static fn(): string => 'service_promise_v1' );
		$environment->set_rate_projection( $this->native->rate_projection );
		$this->native->cart->environment = $environment; $this->native->cart->observed_environment = new CetechQuoteCartEnvironmentObservation( $environment );
		$this->native->cart->sessions = new NativeCartQuoteSessionStore( $this->native->factory, [ $environment, 'authorize' ] );
		$this->observation = new CetechPromiseHandoffEnvironmentObservation( $environment );
		$this->native->cart->service = new CartQuoteService( $this->observation, new QuotePreparationGate( $this->native->factory, [ $environment, 'authorize' ] ), $this->native->cart->sessions, $this->native->factory );
	}
	private function enable_native_configuration(): void {
		$native = $this->native->cart->native;
		$insert = function ( string $suffix, array $row ): int {
			if ( false === $this->db->insert( TableNames::for( $suffix ), $row ) || $this->db->insert_id < 1 ) { throw new RuntimeException( 'P04 tracked configuration allocation failed.' ); }
			$id = (int) $this->db->insert_id; $this->configuration_entities[] = [ $suffix, $id ]; return $id;
		};
		foreach ( $native->products as $product_id ) {
			if ( ! wc_get_product( $product_id ) instanceof WC_Product || 0 !== (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM `' . TableNames::for( 'configuration_scopes' ) . '` WHERE scope_type=%s AND scope_id=%d', 'product', $product_id ) ) || '' !== $this->db->last_error ) { throw new RuntimeException( 'P04 refuses a missing or previously configured fixture product.' ); }
			$scope = $insert( 'configuration_scopes', [ 'scope_type' => 'product', 'scope_id' => $product_id, 'slice_key' => 'in_warehouse', 'status' => 'active', 'config_version' => 1, 'source' => 'native' ] );
			foreach ( [ 'fulfilment_availability' => [ 'string', 'in_warehouse' ], 'fulfilment_choice' => [ 'string', 'delivery' ], 'priority' => [ 'int', '1' ], 'origin_id' => [ 'int', (string) $native->alternate_origin ], 'supplier_id' => [ 'int', (string) $native->alternate_supplier ], 'logistics_profile_id' => [ 'int', (string) $native->alternate_profile ] ] as $field => [ $type, $value ] ) { $insert( 'configuration_fields', [ 'scope_row_id' => $scope, 'field_key' => $field, 'mode' => 'override', 'value_type' => $type, 'value_text' => $value ] ); }
			$insert( 'configuration_collections', [ 'scope_row_id' => $scope, 'field_key' => 'delivery_offer_ids', 'mode' => 'replace', 'members_json' => json_encode( [ $native->offer ], JSON_THROW_ON_ERROR ) ] );
		}
		foreach ( [ ...CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation::CHAIN, 'enable_blocks_adapter' ] as $flag ) { $native->set_option( 'cetech_de_' . $flag, '1' ); }
		$native->cart();
		foreach ( WC()->cart->cart_contents as &$item ) { $item['variation'] ??= []; } unset( $item );
	}
	public function make_free(): void {
		$native = $this->native->cart->native;
		foreach ( $native->products as $id ) { $product = wc_get_product( $id ); if ( ! $product instanceof WC_Product ) { throw new RuntimeException( 'P04 tracked native free product is unavailable.' ); } $product->set_regular_price( '0.00' ); $product->set_sale_price( '' ); $product->set_date_on_sale_from( null ); $product->set_date_on_sale_to( null ); $product->set_price( '0.00' ); $product->save(); }
		$native->physical_update( 'rate_cards', $native->rate, [ 'base_amount' => '0.0000' ] ); $native->cart();
		foreach ( WC()->cart->cart_contents as &$item ) { $item['variation'] ??= []; } unset( $item );
	}
	public function replay_original_seal( CetechQuotePlacementNativeFlow $flow ): CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableResult {
		$control = CetechDeliveryEngine\Bootstrap\Plugin::instance()->container()->get( CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService::class )->read( get_current_blog_id() );
		$local = CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding::capture( $flow->order );
		if ( null === $local || null === $control->state || null === $flow->physical_binding || null === $flow->saved || 2 !== $flow->evidence->terms()->format_version() ) { throw new RuntimeException( 'P04 original final proof is unavailable.' ); }
		$proof = CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementProof::capture( $flow->physical_binding, $control->state->revision, $local, $flow->saved, $flow->evidence->terms(), CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::now() );
		$result = $flow->service->seal( $flow->physical_binding, $proof, RequestContext::create() ); $this->native->track_result( $result ); return $result;
	}
	private function version( string $action, BusinessCalendarVersion|ServicePromisePolicy $body ): void {
		$kind = $body instanceof ServicePromisePolicy ? 'policy' : 'calendar'; $facts = $body->private_facts(); $logical = $facts[$kind . '_id'];
		$this->object_keys[$kind . ':' . $logical] = true;
		$objects = $this->rows( $this->db->prepare( 'SELECT * FROM `' . TableNames::for( 'promise_objects' ) . '` WHERE site_id=%d AND site_key=%s AND kind=%s AND logical_id=%s LIMIT 2', get_current_blog_id(), $this->binding->site_key(), $kind, $logical ) );
		$versions = $this->rows( $this->db->prepare( 'SELECT * FROM `' . TableNames::for( 'promise_versions' ) . '` WHERE site_id=%d AND site_key=%s AND kind=%s AND logical_id=%s AND domain_version=%d LIMIT 2', get_current_blog_id(), $this->binding->site_key(), $kind, $logical, $facts['version'] ) );
		if ( count( $objects ) > 1 || count( $versions ) > 1 ) { throw new RuntimeException( 'P04 exact version guard is ambiguous.' ); } $object = $objects[0] ?? null; $version = $versions[0] ?? null;
		$id = new OperationIdentity( get_current_blog_id(), 'native-p04-internal', 'user:1', 'promise.version.' . $action, 1, PromiseVersionCommand::target_key( $this->binding, $kind, $logical ), $this->token . '-' . $kind . '-' . $action . '-' . $facts['version'] );
		$this->namespaces[$id->namespace_digest()] = $id->operation;
		$uuid = $version['version_uuid'] ?? QuoteId::generate()->value(); if ( null !== $version && ! isset( $this->version_uuids[$uuid] ) ) { throw new RuntimeException( 'P04 exact existing version lacks prior UUID authority.' ); } $this->version_uuids[$uuid] = true;
		$payload = [ 'kind' => $kind, 'logical_id' => $logical, 'domain_version' => $facts['version'], 'version_uuid' => $uuid, 'body_digest' => $body->digest(), 'scope' => [ 'kind' => 'global', 'target_id' => 0 ], 'body_json' => 'create' === $action ? $body->to_private_json() : null, 'declared_from' => 'policy' === $kind ? $facts['effective_from'] : '1970-01-01 00:00:00.000000', 'declared_until' => 'policy' === $kind ? $facts['effective_until'] : null, 'author_user_id' => 1, 'reason' => 'Tracked native P04 handoff fixture', 'scheduled_author_user_id' => null, 'preconditions' => [ 'object_revision' => (int) ( $object['revision'] ?? 0 ), 'version_revision' => (int) ( $version['row_revision'] ?? 0 ), 'published_version_id' => (int) ( $object['published_version_id'] ?? 0 ) ] ];
		$command = PromiseVersionCommand::from_array( $id, $this->binding, $payload ); $result = ( new PromiseVersionLifecycleService( $this->binding, $this->native->factory, $this->authority ) )->attempt( $id, $command, RequestContext::create() );
		$this->register_sources(); if ( 'accepted' !== $result->outcome->state || null === $result->completion ) { throw new RuntimeException( 'P04 native immutable ' . $kind . ' ' . $action . ' did not acknowledge.' ); }
	}
	public function assignment( string $mode, ?ServicePromisePolicy $policy ): void {
		$key = [ 'scope_kind' => 'global', 'scope_id' => 0, 'service_kind' => 'built_in', 'service_code' => 'standard', 'endpoint' => 'doorstep', 'endpoint_kind' => 'doorstep' ];
		$this->assignment_keys[] = $key;
		$old = isset( $this->binding ) ? $this->rows( $this->db->prepare( 'SELECT * FROM `' . TableNames::for( 'promise_assignments' ) . '` WHERE site_id=%d AND site_key=%s AND scope_kind=%s AND scope_id=%d AND service_kind=%s AND service_code=%s AND endpoint=%s AND endpoint_kind=%s LIMIT 2', get_current_blog_id(), $this->binding->site_key(), $key['scope_kind'], $key['scope_id'], $key['service_kind'], $key['service_code'], $key['endpoint'], $key['endpoint_kind'] ) ) : [];
		if ( count( $old ) > 1 ) { throw new RuntimeException( 'P04 exact assignment guard is ambiguous.' ); } $old = $old[0] ?? null;
		$id = new OperationIdentity( get_current_blog_id(), 'native-p04-internal', 'user:1', PromiseAssignmentCommand::OPERATION, 1, PromiseAssignmentCommand::target_key( $this->binding, $key ), $this->token . '-assignment-' . $mode . '-' . ( $old['revision'] ?? 0 ) ); $this->namespaces[$id->namespace_digest()] = $id->operation;
		$command = PromiseAssignmentCommand::from_array( $id, $this->binding, [ 'key' => $key, 'mode' => $mode, 'policy_reference' => $policy?->reference()->private_facts(), 'expected_revision' => (int) ( $old['revision'] ?? 0 ), 'expected_generation' => (int) ( $old['generation'] ?? 0 ), 'author_user_id' => 1, 'reason' => 'Tracked native P04 assignment fixture' ] );
		$result = ( new PromiseAssignmentService( $this->binding, $this->native->factory, $this->authority ) )->attempt( $id, $command, RequestContext::create() ); $this->register_sources();
		if ( 'accepted' !== $result->outcome->state || null === $result->completion ) { throw new RuntimeException( 'P04 native revisioned assignment did not acknowledge.' ); }
	}
	private function register_sources(): void {
		foreach ( $this->registered as $suffix => $ids ) { foreach ( $this->rows( $this->db->prepare( 'SELECT * FROM `' . TableNames::for( $suffix ) . '` WHERE site_id=%d AND site_key=%s ORDER BY id', get_current_blog_id(), $this->binding->site_key() ) ) as $row ) {
			if ( $row['site_key'] !== $this->token || isset( $row['logical_id'] ) && ! isset( $this->object_keys[$row['kind'] . ':' . $row['logical_id']] ) || 'promise_versions' === $suffix && ! isset( $this->version_uuids[$row['version_uuid']] ) ) { throw new RuntimeException( 'P04 cleanup refuses a source without prior exact authority.' ); }
			if ( 'promise_assignments' === $suffix ) { $key_known = false; foreach ( $this->assignment_keys as $key ) { $same = true; foreach ( $key as $field => $value ) { $same = $same && (string) $row[$field] === (string) $value; } $key_known = $key_known || $same; } if ( ! $key_known ) { throw new RuntimeException( 'P04 cleanup refuses an assignment without prior exact key authority.' ); } }
			$this->registered[$suffix][(int) $row['id']] = $row;
		} }
	}
	public function rows( string $sql ): array { $rows = $this->db->get_results( $sql, ARRAY_A ); if ( ! is_array( $rows ) || count( $rows ) > 20000 || '' !== $this->db->last_error ) { throw new RuntimeException( 'P04 native physical observation is unavailable.' ); } return $rows; }
	public function promise_rows(): array { $out = []; foreach ( [ 'promise_objects', 'promise_versions', 'promise_assignments' ] as $suffix ) { $out[$suffix] = $this->rows( 'SELECT * FROM `' . TableNames::for( $suffix ) . '` ORDER BY id' ); } return $out; }
	private function option_row( string $name ): ?array { $rows = $this->rows( $this->db->prepare( "SELECT option_id,option_name,option_value,autoload FROM `{$this->db->options}` WHERE option_name=%s LIMIT 2", $name ) ); if ( count( $rows ) > 1 ) { throw new RuntimeException( 'P04 native option observation is ambiguous.' ); } return $rows[0] ?? null; }
	public function packet( WC_Order $order ): DeliveryQuoteSnapshotEnvelope { $read = ( new OrderDeliverySnapshotReader() )->read_package( $order ); if ( 'recorded' !== $read->delivery_quote?->status || null === $read->delivery_quote?->envelope ) { throw new RuntimeException( 'P04 saved mandatory quote packet is unavailable.' ); } return $read->delivery_quote->envelope; }
	public function source_rows(): array { return $this->rows( $this->db->prepare( 'SELECT * FROM `' . TableNames::for( 'promise_versions' ) . '` WHERE site_id=%d AND site_key=%s ORDER BY id', get_current_blog_id(), $this->binding->site_key() ) ); }
	/** A separate native process must recover only the actual saved historical packet. */
	public static function fresh_reader( WC_Order $order, DeliveryQuoteSnapshotEnvelope $envelope, bool $lifecycle = false ): array {
		$packet = $envelope->promise_packet(); if ( null === $packet ) { throw new RuntimeException( 'P04 fresh history packet is absent.' ); }
		$config = [ 'wp_load' => rtrim( ABSPATH, '/' ) . '/wp-load.php', 'order_id' => $order->get_id(), 'envelope_digest' => hash( 'sha256', $envelope->to_private_json() ), 'promise_packet_digest' => hash( 'sha256', $packet->to_private_json() ), 'public_groups_digest' => hash( 'sha256', json_encode( $packet->public_groups(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), 'run_lifecycle' => $lifecycle ];
		$path = tempnam( sys_get_temp_dir(), 'p04-native-reader-' ); $process = null; $pipes = [];
		if ( false === $path ) { throw new RuntimeException( 'P04 native reader configuration is unavailable.' ); }
		try {
			if ( ! chmod( $path, 0600 ) || false === file_put_contents( $path, json_encode( $config, JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'P04 native reader setup failed.' ); }
			$process = proc_open( [ PHP_BINARY, __DIR__ . '/opening-promise-handoff-reader.php', $path ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'P04 native reader did not start.' ); }
			fclose( $pipes[0] ); unset( $pipes[0] ); foreach ( $pipes as $pipe ) { stream_set_blocking( $pipe, false ); }
			$output = ''; $errors = ''; $deadline = microtime( true ) + 10; $exit = -1;
			do { $output .= stream_get_contents( $pipes[1] ); $errors .= stream_get_contents( $pipes[2] ); $status = proc_get_status( $process ); if ( ! $status['running'] ) { $exit = $status['exitcode']; break; } if ( strlen( $output ) > 4096 || strlen( $errors ) > 16384 ) { break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
			if ( $status['running'] ) { proc_terminate( $process, 9 ); }
			$output .= stream_get_contents( $pipes[1] ); $errors .= stream_get_contents( $pipes[2] ); foreach ( $pipes as $pipe ) { fclose( $pipe ); } $pipes = []; $closed = proc_close( $process ); $process = null;
			$decoded = json_decode( $output, true );
			if ( $status['running'] || strlen( $output ) > 4096 || '' !== $errors || 0 !== ( $exit >= 0 ? $exit : $closed ) || ! is_array( $decoded ) ) { return [ 'status' => 'FAIL', 'transport_complete' => false ]; }
			return $decoded + [ 'transport_complete' => true ];
		} finally { foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } } if ( is_resource( $process ) ) { proc_terminate( $process, 9 ); proc_close( $process ); } unlink( $path ); }
	}
	/** Directly delete only the original tracked rows, to establish historical independence from live configuration. */
	public function remove_sources(): bool {
		$ok = true;
		foreach ( [ 'promise_assignments', 'promise_versions', 'promise_objects' ] as $suffix ) {
			foreach ( $this->rows( $this->db->prepare( 'SELECT * FROM `' . TableNames::for( $suffix ) . '` WHERE site_id=%d AND site_key=%s ORDER BY id', get_current_blog_id(), $this->binding->site_key() ) ) as $current ) { if ( ! isset( $this->registered[$suffix][(int) $current['id']] ) || $current !== $this->registered[$suffix][(int) $current['id']] ) { throw new RuntimeException( 'P04 refuses unregistered or changed tracked source cleanup.' ); } }
			foreach ( $this->registered[$suffix] as $id => $row ) {
				$current = $this->rows( $this->db->prepare( 'SELECT * FROM `' . TableNames::for( $suffix ) . '` WHERE id=%d AND site_id=%d AND site_key=%s LIMIT 2', $id, get_current_blog_id(), $this->binding->site_key() ) );
				if ( count( $current ) > 1 || [] !== $current && $current[0] !== $row ) { throw new RuntimeException( 'P04 refuses a changed original source row.' ); }
				$ok = false !== $this->db->delete( TableNames::for( $suffix ), [ 'id' => $id, 'site_id' => get_current_blog_id(), 'site_key' => $this->binding->site_key() ] ) && $ok;
			}
		}
		return $ok;
	}
	public function cleanup(): array {
		$ok = $this->native->factory->close_all(); if ( isset( $this->binding ) ) { $ok = $this->remove_sources() && $ok; }
		foreach ( array_reverse( $this->configuration_entities ) as [ $suffix, $id ] ) { $ok = false !== $this->db->delete( TableNames::for( $suffix ), [ 'id' => $id ] ) && $ok; }
		foreach ( $this->namespaces as $namespace => $operation ) { $records = $this->rows( $this->db->prepare( 'SELECT id FROM `' . TableNames::for( 'operation_records' ) . '` WHERE site_id=%d AND namespace_hash=%s AND operation=%s LIMIT 2', get_current_blog_id(), $namespace, $operation ) ); if ( count( $records ) > 1 ) { throw new RuntimeException( 'P04 cleanup refuses an ambiguous original command.' ); } foreach ( $records as $record ) { $ok = false !== $this->db->delete( TableNames::for( 'operation_changes' ), [ 'site_id' => get_current_blog_id(), 'operation_id' => (int) $record['id'] ] ) && $ok; $ok = false !== $this->db->delete( TableNames::for( 'operation_records' ), [ 'site_id' => get_current_blog_id(), 'id' => (int) $record['id'] ] ) && $ok; } }
		foreach ( [ PromiseQuotePlacementActivation::OPTION => $this->adoption_before, 'cetech_de_quote_placement_adoption' => $this->configuration_before ] as $name => $row ) { $ok = false !== ( null === $row ? $this->db->delete( $this->db->options, [ 'option_name' => $name ] ) : $this->db->replace( $this->db->options, $row ) ) && $ok; wp_cache_delete( $name, 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( 'alloptions', 'options' ); }
		$native = $this->native->cleanup();
		return [ 'cleanup_restored' => $ok && $native['cleanup_restored'] && $this->promise_before === $this->promise_rows(), 'owned_orders_removed' => $native['owned_orders_removed'], 'original_quote_history_restored' => $native['quote_operation_history_restored'], 'original_promise_history_restored' => $this->promise_before === $this->promise_rows(), 'all_owned_connections_retired' => $this->native->factory->all_retired() && $native['owned_connections_retired'], 'default_adoption_restored' => $this->adoption_before === $this->option_row( PromiseQuotePlacementActivation::OPTION ) ];
	}
}
