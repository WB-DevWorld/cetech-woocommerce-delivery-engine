<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Configuration;

use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePlacementActivation;
use CetechDeliveryEngine\Application\ServicePromise\Calculation\{DeterministicPromiseCalculator, PromiseCalendarArithmetic};
use CetechDeliveryEngine\Application\ServicePromise\Persistence\{PromiseAssignmentService, PromiseVersionLifecycleService};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationRefusal};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseInput, PromiseJson, PromiseLimits, PromiseShape, PublicPromiseView, ServicePromisePolicy};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromiseSiteBinding, PromiseVersionCommand};
use CetechDeliveryEngine\Integrations\ServicePromise\{NativePromiseRuntimeCapture, PromiseNativeServiceRegistry};
use CetechDeliveryEngine\Integrations\ServicePromise\Configuration\NativePromiseConfigurationAuthority;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;

/** Native composition for immutable P02 commands. Hypothetical preview never issues a quote. */
final class PromiseConfigurationService {
	private PromiseConfigurationReadService $reader;
	public function __construct( private OperationConnectionFactory $connections, private NativePromiseConfigurationAuthority $authority, private PromiseQuotePlacementActivation $activation ) {
		$this->reader = new PromiseConfigurationReadService( $connections, $authority );
	}
	public function can_access(): bool { return $this->authority->can_access(); }
	/** A prepared private editor projection is disclosed only under the caller's present native scope grant. */
	public function can_disclose( PromiseSiteBinding $binding, array $scope ): bool {
		try { $identity = $this->authority->identity( $binding, 'promise.configuration.read', 'promise-configuration-disclosure:' . $binding->site_key(), RequestContext::create()->request_id ); return $this->authority->authorize( $identity, $binding, $scope, 0 ); }
		catch ( \Throwable ) { return false; }
	}
	public function binding( string $site_key ): PromiseSiteBinding {
		if ( ! $this->can_access() ) { self::deny(); }
		return PromiseSiteBinding::bind( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1, $site_key );
	}
	public function inspect_version( PromiseSiteBinding $binding, string $kind, string $logical_id, int $version, array $scope ): array {
		$identity = $this->authority->identity( $binding, 'promise.configuration.read', PromiseVersionCommand::target_key( $binding, $kind, $logical_id ), RequestContext::create()->request_id );
		return $this->reader->version( $identity, $binding, $kind, $logical_id, $version, $scope );
	}
	public function inspect_assignment( PromiseSiteBinding $binding, array $key ): array {
		$identity = $this->authority->identity( $binding, 'promise.configuration.read', PromiseAssignmentCommand::target_key( $binding, $key ), RequestContext::create()->request_id );
		return $this->reader->assignment( $identity, $binding, $key );
	}
	/** Payload excludes all caller site/principal/author facts; retry keeps the original token and CAS. */
	public function version( PromiseSiteBinding $binding, string $operation, string $token, array $payload, bool $reconcile = false ): OperationAttemptResult {
		PromiseShape::fields( $payload, [ 'kind', 'logical_id', 'domain_version', 'version_uuid', 'body_digest', 'scope', 'body_json', 'declared_from', 'declared_until', 'reason', 'scheduled_author_user_id', 'preconditions' ] );
		if ( ! RequestContext::is_valid_identifier( $token ) ) { PromiseShape::invalid(); }
		$identity = $this->authority->identity( $binding, $operation, PromiseVersionCommand::target_key( $binding, $payload['kind'], $payload['logical_id'] ), $token );
		$command = PromiseVersionCommand::from_array( $identity, $binding, $payload + [ 'author_user_id' => get_current_user_id() ] );
		$service = new PromiseVersionLifecycleService( $binding, $this->connections, $this->authority );
		return $reconcile ? $service->reconcile( $identity, $command, RequestContext::create() ) : $service->attempt( $identity, $command, RequestContext::create() );
	}
	public function assignment( PromiseSiteBinding $binding, string $token, array $payload, bool $reconcile = false ): OperationAttemptResult {
		PromiseShape::fields( $payload, [ 'key', 'mode', 'policy_reference', 'expected_revision', 'expected_generation', 'reason' ] );
		if ( ! RequestContext::is_valid_identifier( $token ) ) { PromiseShape::invalid(); }
		$identity = $this->authority->identity( $binding, PromiseAssignmentCommand::OPERATION, PromiseAssignmentCommand::target_key( $binding, $payload['key'] ), $token );
		$command = PromiseAssignmentCommand::from_array( $identity, $binding, $payload + [ 'author_user_id' => get_current_user_id() ] );
		$service = new PromiseAssignmentService( $binding, $this->connections, $this->authority );
		return $reconcile ? $service->reconcile( $identity, $command, RequestContext::create() ) : $service->attempt( $identity, $command, RequestContext::create() );
	}
	public function adoption_status(): array {
		$this->require_manager(); $result = $this->activation->status(); $this->require_manager(); return $result;
	}
	/** Explicit configuration acknowledges OFF; enable is always another native action. */
	public function configure_adoption( PromiseSiteBinding $binding, array $registry, int $revision, string $token ): bool {
		$this->require_manager(); $identity = $this->adoption_identity( $binding, $token );
		$probe = $this->authority->identity( $binding, 'promise.version.publish', $identity->target_key, $token );
		if ( ! $this->authority->authorize( $probe, $binding, [ 'kind' => 'global', 'target_id' => 0 ], get_current_user_id() ) ) { self::deny(); }
		$result = $this->activation->configure( $binding, PromiseNativeServiceRegistry::from_array( $registry ), $revision, $identity );
		$this->require_manager(); return $result;
	}
	public function change_adoption( PromiseSiteBinding $binding, bool $enabled, int $revision, string $token ): bool {
		$this->require_manager();
		// Enable/configuration require current policy-manager authority; disable remains a recovery action.
		if ( $enabled ) { $probe = $this->authority->identity( $binding, 'promise.version.publish', 'promise-adoption:' . $binding->site_key(), $token ); if ( ! $this->authority->authorize( $probe, $binding, [ 'kind' => 'global', 'target_id' => 0 ], get_current_user_id() ) ) { self::deny(); } }
		$result = $this->activation->change( $enabled, $revision, $this->adoption_identity( $binding, $token ) );
		$this->require_manager(); return $result;
	}

	/** Entirely hypothetical bodies. Native clock/runtime and private current scope grant are independent. */
	public function preview( PromiseSiteBinding $binding, string $policy_json, array $calendar_bodies ): array {
		$policy = ServicePromisePolicy::from_json( $policy_json ); $scope = PromiseVersionCommand::policy_scope( $policy );
		$identity = $this->authority->identity( $binding, 'promise.preview', PromiseVersionCommand::target_key( $binding, 'policy', $policy->reference()->policy_id() ), RequestContext::create()->request_id );
		if ( $policy->site_id() !== $binding->site_key() || ! $this->authority->authorize( $identity, $binding, $scope, 0 ) ) { self::deny(); }
		PromiseShape::list( $calendar_bodies, 0, PromiseLimits::CALENDARS ); $calendars = [];
		foreach ( $calendar_bodies as $body ) { $calendar = BusinessCalendarVersion::from_array( PromiseShape::object( $body ) ); if ( $calendar->site_id() !== $binding->site_key() ) { PromiseShape::invalid(); } $calendars[] = $calendar; }
		$runtime = ( new NativePromiseRuntimeCapture() )->capture(); $at = RuleTime::parse( ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' ) );
		$input = self::hypothetical_input( $binding, $policy, $at, $runtime, $identity->principal );
		$result = ( new DeterministicPromiseCalculator( $runtime ) )->calculate( $input, $calendars ); $views = [];
		foreach ( $policy->endpoint_terminal_component_ids() as $terminal ) { $views[] = PublicPromiseView::from_result( $result, $terminal )->fields(); }
		if ( ( new NativePromiseRuntimeCapture() )->capture() !== $runtime ) { throw new \RuntimeException( 'Hypothetical preview runtime changed.' ); }
		if ( ! $this->authority->authorize( $identity, $binding, $scope, 0 ) ) { self::deny(); }
		return [ 'hypothetical' => true, 'admission' => false, 'destination_complete' => true, 'views' => $views ];
	}
	private static function hypothetical_input( PromiseSiteBinding $binding, ServicePromisePolicy $policy, RuleTime $at, array $runtime, string $principal ): PromiseInput {
		$facts = $policy->private_facts(); $expires = RuleTime::from_epoch_microseconds( $at->epoch_microseconds() + 300000000 ); $deadline = $expires;
		if ( null !== $policy->effective_until() && $policy->effective_until()->compare( $deadline ) < 0 ) { $deadline = $policy->effective_until(); }
		$day = null; $math = new PromiseCalendarArithmetic();
		if ( 'none' !== $facts['day_constraint'] || 'order_accepted' === $policy->anchor() ) {
			$local = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $at->sql(), new \DateTimeZone( 'UTC' ) )->setTimezone( new \DateTimeZone( $policy->promise_timezone() ) );
			$date = $local->format( 'Y-m-d' ); $next_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) )->modify( '+1 day' )->format( 'Y-m-d' );
			$start = $math->resolve_local( $date, '00:00', $policy->promise_timezone() ); $end = $math->resolve_local( $next_date, '00:00', $policy->promise_timezone() );
			if ( 'order_accepted' === $policy->anchor() && $end->compare( $deadline ) < 0 ) { $deadline = $end; }
			if ( 'none' !== $facts['day_constraint'] ) { $day = [ 'local_date' => $date, 'timezone' => $policy->promise_timezone(), 'start_at' => $start->sql(), 'end_at' => $end->sql() ]; }
		}
		$anchor = [ 'format_version' => 1, 'kind' => $policy->anchor(), 'evaluated_at' => $at->sql(), 'quote_expires_at' => $expires->sql() ] + match ( $policy->anchor() ) { 'order_accepted' => [ 'accept_until' => $deadline->sql() ], 'checkout_capture' => [ 'capture_at' => $at->sql() ], 'payment_confirmed' => [ 'awaited_event' => 'woocommerce_payment_confirmed' ] };
		$destination = [ 'endpoint' => $policy->endpoint(), 'endpoint_kind' => $policy->endpoint_kind(), 'identity_digest' => hash( 'sha256', 'hypothetical-destination:' . $policy->endpoint() ) ];
		$sources = []; foreach ( $facts['graph']['components'] as $component ) { $sources[$component['source']['source_id']] = $component['source']; }
		$capacity = [ 'format_version' => 1, 'mode' => 'none' ];
		if ( 'required' === $policy->capacity_mode() ) {
			$source = $policy->capacity_source(); $sources[$source['source_id']] = $source;
			// A configured adapter observation is never replaced by an optimistic preview.
			$capacity = [ 'format_version' => 1, 'mode' => 'required', 'site_id' => $binding->site_key(), 'service_digest' => $policy->service()->digest(), 'endpoint_digest' => PromiseInput::endpoint_digest( $destination ),
				'window' => [ 'from' => $at->sql(), 'until' => $expires->sql(), 'display_timezone' => $policy->promise_timezone() ], 'source' => $source, 'revision' => 1, 'state' => 'unknown', 'observed_at' => $at->sql(), 'valid_until' => $expires->sql() ];
		}
		$seed = hash( 'sha256', 'cetech-hypothetical-promise:' . $binding->digest() . ':' . $principal );
		return PromiseInput::from_array( [ 'format_version' => 1, 'site_id' => $binding->site_key(), 'evaluated_at' => $at->sql(), 'owner' => [ 'site_id' => $binding->site_key(), 'kind' => 'customer', 'principal_hash' => $seed, 'session_hash' => $seed, 'key_epoch' => 'hypothetical-only' ],
			'material' => [ 'group_id' => 'hypothetical-only', 'material_digest' => hash( 'sha256', $policy->to_private_json() ) ], 'origin' => [ 'endpoint' => 'hypothetical-origin', 'endpoint_kind' => 'origin', 'identity_digest' => $seed ],
			'destination' => $destination, 'policy' => $facts, 'calendar_refs' => $facts['calendars'], 'source_receipts' => array_values( $sources ), 'anchor' => $anchor, 'service_day' => $day, 'capacity' => $capacity, 'runtime' => $runtime ] );
	}
	private function adoption_identity( PromiseSiteBinding $binding, string $token ): OperationIdentity {
		if ( ! RequestContext::is_valid_identifier( $token ) || $binding->site_id() !== ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 ) ) { PromiseShape::invalid(); }
		return new OperationIdentity( $binding->site_id(), 'service_promise.settings', 'user:' . get_current_user_id(), 'service_promise.adoption', 1, 'promise-adoption:' . $binding->site_key(), $token );
	}
	private function require_manager(): void { if ( ! $this->can_access() || AdminPageAccess::current_user_is_restricted() || ! current_user_can( 'manage_delivery_settings' ) ) { self::deny(); } }
	private static function deny(): never { throw new OperationRefusal( 'not_authorized', 'contact_support' ); }
}
