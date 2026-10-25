<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PolicyAssignment;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Application\ReturnPolicy\ReturnPolicyCatalogService;
use CetechDeliveryEngine\Application\RefundPolicy\RefundPolicyCatalogService;
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationIdentity, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationPhaseObserver};
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand, PolicyAssignmentMap, PolicyAssignmentState};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleCandidate, RuleLifecycleEvaluator, RuleSnapshot, RuleState, RuleTime};
use CetechDeliveryEngine\Domain\ReturnPolicy\{ReturnPolicyFamily, ReturnPolicyVersion};
use CetechDeliveryEngine\Domain\RefundPolicy\{RefundPolicyFamily, RefundPolicyVersion};
use CetechDeliveryEngine\Infrastructure\Persistence\{PolicyAssignmentStore, WpdbPolicyAssignmentAdminIntent};
use CetechDeliveryEngine\Integrations\PolicyAssignment\NativePolicyAssignmentAuthority;
use CetechDeliveryEngine\Integrations\ReturnPolicy\NativeReturnPolicyAuthority;
use CetechDeliveryEngine\Integrations\RefundPolicy\NativeRefundPolicyAuthority;

/** Native orchestration retains an original command; the journal never retains a grant. */
final class PolicyAssignmentAdminWorkflow {

	private const RECORD = [ 'format', 'site_id', 'owner_id', 'definition_hash', 'ticket', 'state', 'opened', 'identity', 'payload', 'had_uncertainty' ];
	private const IDENT = [ 'site_id', 'authority', 'principal', 'operation', 'operation_version', 'target_key', 'idempotency_key' ];
	private const FIELDS = [ 'return_mode', 'return_policy_uuid', 'refund_mode', 'refund_policy_uuid', 'reason' ];
	private const OPENED = [ 'coordinate', 'offer_id', 'row_id', 'bytes', 'generation', 'binding', 'choices', 'target_label', 'offer_label' ];
	private ?array $active = null;

	public function __construct(
		private readonly NativePolicyAssignmentAuthority $authority,
		private readonly WpdbPolicyAssignmentAdminIntent $journal,
		private readonly OperationConnectionFactory $connections,
		private readonly EffectiveConfigurationResolver $resolver,
		private readonly ?OperationReadiness $readiness = null,
		private readonly ?OperationPhaseObserver $observer = null
	) {}

	/** Also rechecks a context already captured in this request before delayed disclosure. */
	public function can_access(): bool {
		try {
			if ( ! $this->authority->can_access() ) { return false; }
			return null === $this->active || ( $this->authority->actor() === $this->active['actor'] && $this->authority->binding() === $this->active['binding']
				&& $this->authority->target( $this->active['coordinate']['scope_type'], $this->active['coordinate']['scope_id'] ) === $this->active['coordinate']
				&& $this->authority->offer( $this->active['offer'] ) === $this->active['offer'] && $this->authority->actor() === $this->active['actor'] );
		} catch ( \Throwable ) { return false; }
	}

	public function view(): array {
		$actor = $this->authority->actor(); $name = WpdbPolicyAssignmentAdminIntent::name( $actor['site_id'], $actor['user_id'] );
		$record = $this->journal->locked( $name, function () use ( $name, $actor ): ?array {
			$raw = $this->journal->read( $name ); return null === $raw ? null : $this->decode( $raw, $actor, false );
		} );
		// An undispatched review may be replaced after its item is deleted/reparented.
		// A retained original command still requires its original live target.
		if ( null !== $record && 'review' === $record['state'] ) {
			$o = $record['opened'];
			try { $live = $this->authority->target( $o['coordinate']['scope_type'], $o['coordinate']['scope_id'] ) === $o['coordinate'] && $this->authority->offer( $o['offer_id'] ) === $o['offer_id']; }
			catch ( \Throwable ) { $live = false; }
			if ( ! $live ) { $record = null; $this->active = null; }
		}
		if ( null !== $record ) { $this->remember( $actor, $record['opened'] ); }
		if ( ! $this->can_access() || $this->authority->actor() !== $actor ) { self::unavailable(); }
		return [ 'review' => null !== $record && 'review' === $record['state'] ? $this->review( $record ) : null,
			'blocked' => null !== $record && 'review' !== $record['state'], 'preview' => null,
			'offers' => $this->authority->offer_choices() ];
	}

	public function open( string $type, int $id, int $offer = 0 ): array {
		$actor = $this->authority->actor(); $coordinate = $this->authority->target( $type, $id ); $this->authority->offer( $offer );
		$binding = $this->authority->binding(); $name = WpdbPolicyAssignmentAdminIntent::name( $actor['site_id'], $actor['user_id'] );
		return $this->journal->locked( $name, function () use ( $actor, $coordinate, $offer, $binding, $name ): array {
			$previous = $this->journal->read( $name );
			if ( null !== $previous && 'review' !== $this->decode( $previous, $actor, false )['state'] ) { self::unavailable(); }
			$state = $this->read_state( $coordinate, $offer );
			$choices = $this->choices();
			$offer_label = 'Default policy' ;
			if ( $offer > 0 ) {
				$found = false; foreach ( $this->authority->offer_choices() as $choice ) { if ( $choice['id'] === $offer ) { $offer_label = $choice['label']; $found = true; break; } }
				if ( ! $found ) { self::unavailable(); }
			}
			$opened = [ 'coordinate' => $coordinate, 'offer_id' => $offer, 'row_id' => $state->row_id(), 'bytes' => $state->original_bytes(),
				'generation' => $state->generation() ?? RequestContext::create()->request_id, 'binding' => $binding, 'choices' => $choices,
				'target_label' => $this->authority->target_label( $coordinate ), 'offer_label' => $offer_label ];
			$record = [ 'format' => 1, 'site_id' => $actor['site_id'], 'owner_id' => $actor['user_id'], 'definition_hash' => self::definition(),
				'ticket' => RequestContext::create()->request_id, 'state' => 'review', 'opened' => $opened, 'identity' => null, 'payload' => null, 'had_uncertainty' => false ];
			$raw = $this->encode( $record ); $this->remember( $actor, $opened );
			if ( ! $this->can_access() || ! ( null === $previous ? $this->journal->insert( $name, $raw ) : $this->journal->replace( $name, $previous, $raw ) ) || ! $this->can_access() ) { self::unavailable(); }
			return $this->review( $record );
		} );
	}

	public function submit( string $ticket, array $fields, RequestContext $request ): OperationAttemptResult {
		try {
			$actor = $this->authority->actor(); $name = WpdbPolicyAssignmentAdminIntent::name( $actor['site_id'], $actor['user_id'] );
			return $this->journal->locked( $name, function () use ( $actor, $name, $ticket, $fields, $request ): OperationAttemptResult {
				$raw = $this->journal->read( $name ); if ( null === $raw ) { return self::refused( $request, 'stale_revision' ); }
				$record = $this->decode( $raw, $actor );
				if ( 'review' !== $record['state'] ) { return self::unknown( $request ); }
				if ( ! hash_equals( $record['ticket'], $ticket ) ) { return self::refused( $request, 'stale_revision' ); }
				try {
					$payload = $this->payload( $record['opened'], $fields, $actor['user_id'] );
					$tuple = $this->authority->identity_data( PolicyAssignmentCommand::OPERATION, $payload['coordinate'], $payload['offer_id'], RequestContext::create()->request_id );
					$command = PolicyAssignmentCommand::from_array( $this->authority->identity( $tuple ), $payload );
				} catch ( \InvalidArgumentException ) { return self::refused( $request, 'invalid_input' ); }
				$this->remember( $actor, $record['opened'] ); if ( ! $this->can_access() ) { return self::unknown( $request ); }
				$ready = $record; $ready['identity'] = $tuple; $ready['payload'] = $command->payload(); $ready['state'] = 'ready';
				$next = $this->encode( $ready ); if ( ! $this->journal->replace( $name, $raw, $next ) ) { return self::unknown( $request ); }
				return $this->dispatch( $name, $next, $ready, false, false, $request );
			} );
		} catch ( \Throwable ) { return self::unknown( $request ); }
	}

	public function recover( string $verb, RequestContext $request ): OperationAttemptResult {
		if ( ! in_array( $verb, [ 'retry', 'reconcile' ], true ) ) { return self::refused( $request, 'invalid_input' ); }
		try {
			$actor = $this->authority->actor(); $name = WpdbPolicyAssignmentAdminIntent::name( $actor['site_id'], $actor['user_id'] );
			return $this->journal->locked( $name, function () use ( $actor, $name, $verb, $request ): OperationAttemptResult {
				$raw = $this->journal->read( $name ); if ( null === $raw ) { return self::refused( $request, 'stale_revision' ); }
				$record = $this->decode( $raw, $actor ); if ( 'review' === $record['state'] ) { return self::refused( $request, 'stale_revision' ); }
				return $this->dispatch( $name, $raw, $record, 'reconcile' === $verb, true, $request );
			} );
		} catch ( \Throwable ) { return self::unknown( $request ); }
	}

	/** Fresh SAVED state only; an edit-slot zero never becomes an effective offer. */
	public function preview( string $ticket, RequestContext $request ): array {
		$actor = $this->authority->actor(); $name = WpdbPolicyAssignmentAdminIntent::name( $actor['site_id'], $actor['user_id'] );
		$record = $this->journal->locked( $name, function () use ( $name, $actor, $ticket ): array {
			$raw = $this->journal->read( $name ); if ( null === $raw ) { self::unavailable(); }
			$record = $this->decode( $raw, $actor );
			if ( 'review' !== $record['state'] || ! hash_equals( $record['ticket'], $ticket ) || $record['opened']['offer_id'] < 1 ) { self::unavailable(); }
			return $record;
		} );
		$opened = $record['opened']; $this->remember( $actor, $opened );
		$tuple = $this->authority->identity_data( 'policy.assignment.read', $opened['coordinate'], $opened['offer_id'], $request->request_id );
		$identity = $this->authority->identity( $tuple ); [ $store, $catalog, $grant ] = $this->parts( $identity, $opened['coordinate'], $opened['offer_id'] );
		$result = ( new PolicyAssignmentCurrentService( $this->connections, $store, $catalog, $this->resolver, $grant['authorize'], $this->readiness ) )->capture( $identity, $opened['coordinate'], $opened['offer_id'] );
		if ( ! $this->can_access() || ! $this->authority->authorize( $identity, $opened['coordinate'], $opened['offer_id'] ) ) { self::unavailable(); }
		return $result;
	}

	private function dispatch( string $name, string $raw, array $record, bool $reconcile, bool $recovering, RequestContext $request ): OperationAttemptResult {
		try {
			$actor = [ 'site_id' => $record['site_id'], 'user_id' => $record['owner_id'] ]; $this->remember( $actor, $record['opened'] );
			if ( ! $this->can_access() ) { return self::unknown( $request ); }
			$identity = $this->authority->identity( $record['identity'] ); $command = PolicyAssignmentCommand::from_array( $identity, $record['payload'] );
			$inflight = $record; $inflight['state'] = 'inflight'; $inflight['had_uncertainty'] = $record['had_uncertainty'] || $recovering;
			$inflight_raw = $this->encode( $inflight ); if ( ! $this->journal->replace( $name, $raw, $inflight_raw ) ) { return self::unknown( $request ); }
			// Journal cache hooks cannot make a pre-acknowledgement native grant authorize SQL.
			if ( ! $this->can_access() ) { return self::unknown( $request ); }
			[ $store, $catalog, $grant ] = $this->parts( $identity, $command->coordinate(), $command->offer_id() );
			$service = new PolicyAssignmentService( $this->connections, $store, $catalog, $grant['authorize'], $this->readiness, $this->observer );
			try { $result = $reconcile ? $service->reconcile( $identity, $command->payload(), $request ) : $service->attempt( $identity, $command->payload(), $request ); }
			catch ( \Throwable ) { $result = self::unknown( $request ); }
			if ( ! $this->can_access() || ! $this->authority->authorize( $identity, $command->coordinate(), $command->offer_id() ) ) { $result = self::unknown( $request ); }
			$publication_pending = true === $result->outcome->mutation_accepted && $result->outcome->publication_pending && 'accepted' === $result->completion?->state;
			$resolved = ! $result->outcome->publication_pending && in_array( $result->outcome->state, [ 'accepted', 'not_applicable' ], true ) && null !== $result->completion;
			$initial_rejection = ! $recovering && ! $inflight['had_uncertainty'] && 'rejected' === $result->outcome->state;
			if ( $resolved || $initial_rejection ) {
				// No later native callback relabels a deletion that already happened.
				if ( ! $this->journal->remove( $name, $inflight_raw ) ) { return self::unknown( $request ); }
				return $result;
			}
			$uncertain = $inflight; $uncertain['state'] = 'uncertain'; $uncertain['had_uncertainty'] = true;
			if ( ! $this->journal->replace( $name, $inflight_raw, $this->encode( $uncertain ) ) ) { return self::unknown( $request ); }
			return $publication_pending && $this->can_access() && $this->authority->authorize( $identity, $command->coordinate(), $command->offer_id() ) ? $result : self::unknown( $request );
		} catch ( \Throwable ) { return self::unknown( $request ); }
	}

	private function read_state( array $coordinate, int $offer ): PolicyAssignmentState {
		$tuple = $this->authority->identity_data( 'policy.assignment.read', $coordinate, $offer, RequestContext::create()->request_id );
		$identity = $this->authority->identity( $tuple ); $binding = $this->authority->binding(); $actor = $this->authority->actor();
		[ $store, $catalog, $grant ] = $this->parts( $identity, $coordinate, $offer );
		$state = ( new PolicyAssignmentCurrentService( $this->connections, $store, $catalog, $this->resolver, $grant['authorize'], $this->readiness ) )->read( $identity, $coordinate, $offer );
		if ( ! $this->authority->authorize( $identity, $coordinate, $offer ) || $this->authority->binding() !== $binding || $this->authority->actor() !== $actor ) { self::unavailable(); }
		return $state;
	}

	private function parts( OperationIdentity $identity, array $coordinate, int $offer ): array {
		$binding = $this->authority->binding(); $grant = $this->authority->capture( $identity, $coordinate, $offer );
		if ( $this->authority->binding() !== $binding ) { self::unavailable(); }
		return [ new PolicyAssignmentStore( $binding['site_id'], $binding['prefix'] ),
			new PolicyCurrentCatalog( $grant['return_family'], $grant['refund_family'], $identity ), $grant ];
	}

	/** Server-held reviewed publications, rather than client supplied versions or hashes. */
	private function choices(): array {
		$all = [];
		foreach ( PolicyAssignmentMap::FAMILIES as $family ) {
			$is_return = 'return_policy_v1' === $family;
			$authority = $is_return ? new NativeReturnPolicyAuthority() : new NativeRefundPolicyAuthority();
			$operation = $is_return ? 'return.catalog.read' : 'refund.catalog.read'; $target = $is_return ? 'return-policy:catalog' : 'refund-policy:catalog';
			$scope = [ 'policy_uuid' => RequestContext::create()->request_id ]; $parts = $authority->identity_data( $operation, $target, RequestContext::create()->request_id );
			$id = $authority->identity( $parts ); $grant = $authority->capture( $id, $scope, 0 );
			$service = $is_return ? new ReturnPolicyCatalogService( $grant['family'], $this->connections, $grant['catalog'], $this->readiness )
				: new RefundPolicyCatalogService( $grant['family'], $this->connections, $grant['catalog'], $this->readiness );
			$snapshot = $service->capture( $id, $scope, RequestContext::create() );
			if ( ! $snapshot instanceof RuleSnapshot || ! $snapshot->complete || ! $authority->authorize( $id, $scope, 0 ) ) { self::unavailable(); }
			$rows = []; $versions = []; foreach ( $snapshot->versions as $version ) { $versions[$version->id] = $version; }
			$now = RuleTime::parse( ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' ) );
			foreach ( $snapshot->logicals as $logical ) {
				$version = $versions[$logical->current_published_version_id ?? 0] ?? null;
				if ( null === $version || RuleState::Published !== $version->state || ! $version->eligible_at( $now ) ) { continue; }
				$decision = ( new RuleLifecycleEvaluator() )->evaluate_snapshot( $snapshot, [ 'policy_uuid' => $logical->logical_uuid ], $now );
				if ( ! $decision->complete || null === $decision->selected || $decision->selected->version_id !== $version->id || $version->hypothetical ) { self::unavailable(); }
				$typed = $is_return ? ReturnPolicyVersion::from_candidate( new RuleCandidate( $logical, $version ), $grant['family'] )
					: RefundPolicyVersion::from_candidate( new RuleCandidate( $logical, $version ), $grant['family'] );
				$rows[$logical->logical_uuid] = [ 'label' => self::label( $typed->content()->facts()['name'] ),
					'witness' => [ 'policy_uuid' => $logical->logical_uuid, 'version_uuid' => $version->version_uuid, 'logical_revision' => $logical->revision,
						'version_revision' => $version->row_revision, 'content_hash' => $version->content_hash, 'guard_revision' => $snapshot->guard?->revision ?? 0 ] ];
				if ( count( $rows ) > 64 ) { self::unavailable(); }
			}
			ksort( $rows, SORT_STRING ); $all[$family] = $rows;
		}
		return $all;
	}

	private function payload( array $opened, array $fields, int $actor ): array {
		self::keys( $fields, self::FIELDS ); $instructions = $reviewed = [];
		foreach ( [ 'return_policy_v1' => 'return', 'refund_policy_v1' => 'refund' ] as $family => $prefix ) {
			$mode = $fields[$prefix . '_mode']; $uuid = $fields[$prefix . '_policy_uuid'];
			if ( ! is_string( $mode ) || ! in_array( $mode, [ 'inherit', 'disabled', 'assigned' ], true ) || ! is_string( $uuid ) ) { throw new \InvalidArgumentException(); }
			if ( 'assigned' === $mode ) {
				if ( ! isset( $opened['choices'][$family][$uuid] ) ) { throw new \InvalidArgumentException(); }
				$instructions[$family] = [ 'mode' => 'assigned', 'policy_uuid' => $uuid ]; $reviewed[$family] = $opened['choices'][$family][$uuid]['witness'];
			} else {
				if ( '' !== $uuid ) { throw new \InvalidArgumentException(); }
				$instructions[$family] = [ 'mode' => $mode ]; $reviewed[$family] = null;
			}
		}
		return [ 'format' => 1, 'coordinate' => $opened['coordinate'], 'offer_id' => $opened['offer_id'], 'opened' => [ 'row_id' => $opened['row_id'], 'bytes' => $opened['bytes'] ],
			'instructions' => $instructions, 'reviewed' => $reviewed, 'actor_user_id' => $actor, 'reason' => $fields['reason'], 'generation' => $opened['generation'] ];
	}

	private function review( array $record ): array {
		$o = $record['opened']; $state = $this->opened_state( $o ); $fields = [ 'reason' => '' ]; $choices = [];
		foreach ( [ 'return_policy_v1' => 'return', 'refund_policy_v1' => 'refund' ] as $family => $prefix ) {
			$facts = $state->map( $family )->facts();
			$slot = 0 === $o['offer_id'] ? $facts['default'] : ( $facts['offers']->{(string) $o['offer_id']} ?? [ 'mode' => 'inherit' ] );
			$fields[$prefix . '_mode'] = $slot['mode']; $fields[$prefix . '_policy_uuid'] = $slot['policy_uuid'] ?? '';
			$choices[$family] = []; foreach ( $o['choices'][$family] as $uuid => $choice ) { $choices[$family][] = [ 'policy_uuid' => $uuid, 'label' => $choice['label'] ]; }
		}
		return [ 'ticket' => $record['ticket'], 'scope_type' => $o['coordinate']['scope_type'], 'scope_id' => $o['coordinate']['scope_id'],
			'offer_id' => $o['offer_id'], 'target_label' => $o['target_label'], 'offer_label' => $o['offer_label'], 'fields' => $fields, 'choices' => $choices ];
	}

	private function opened_state( array $o ): PolicyAssignmentState {
		$c = $o['coordinate']; return 0 === $o['row_id'] ? PolicyAssignmentState::absent( $c['site_id'], $c['scope_type'], $c['scope_id'], $c['parent_id'] )
			: PolicyAssignmentState::from_physical( $c['site_id'], $c['scope_type'], $c['scope_id'], $c['parent_id'], $o['row_id'], $o['bytes'] );
	}

	private function decode( string $raw, array $actor, bool $live = true ): array {
		if ( strlen( $raw ) > 131072 ) { self::unavailable(); }
		$r = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR ); if ( ! is_array( $r ) ) { self::unavailable(); } self::keys( $r, self::RECORD );
		if ( 1 !== $r['format'] || $r['site_id'] !== $actor['site_id'] || $r['owner_id'] !== $actor['user_id'] || $r['definition_hash'] !== self::definition()
			|| ! RequestContext::is_valid_identifier( $r['ticket'] ) || ! in_array( $r['state'], [ 'review', 'ready', 'inflight', 'uncertain' ], true )
			|| ! is_bool( $r['had_uncertainty'] ) || ! is_array( $r['opened'] ) ) { self::unavailable(); }
		$o = $r['opened']; self::keys( $o, self::OPENED );
		if ( ! is_array( $o['coordinate'] ) || ! is_int( $o['offer_id'] ) || $o['offer_id'] < 0 || ! is_int( $o['row_id'] ) || $o['row_id'] < 0
			|| ! is_string( $o['bytes'] ) || ( 0 === $o['row_id'] ) !== ( '' === $o['bytes'] ) || ! RequestContext::is_valid_identifier( $o['generation'] )
			|| ! is_array( $o['binding'] ) || $o['binding'] !== $this->authority->binding() || ! is_array( $o['choices'] ) ) { self::unavailable(); }
		if ( ( $live || 'review' !== $r['state'] ) && ( $this->authority->target( $o['coordinate']['scope_type'] ?? '', $o['coordinate']['scope_id'] ?? -1 ) !== $o['coordinate']
			|| $this->authority->offer( $o['offer_id'] ) !== $o['offer_id'] ) ) { self::unavailable(); }
		if ( ( $o['coordinate']['site_id'] ?? null ) !== $actor['site_id'] ) { self::unavailable(); }
		foreach ( [ 'target_label', 'offer_label' ] as $key ) { if ( ! is_string( $o[$key] ) || strlen( $o[$key] ) > 512 || 1 !== preg_match( '//u', $o[$key] ) ) { self::unavailable(); } }
		$state = $this->opened_state( $o ); if ( $state->coordinate() !== $o['coordinate'] || ( null !== $state->generation() && $state->generation() !== $o['generation'] ) ) { self::unavailable(); }
		self::keys( $o['choices'], PolicyAssignmentMap::FAMILIES );
		foreach ( PolicyAssignmentMap::FAMILIES as $family ) {
			if ( ! is_array( $o['choices'][$family] ) || count( $o['choices'][$family] ) > 64 ) { self::unavailable(); }
			foreach ( $o['choices'][$family] as $uuid => $choice ) {
				if ( ! RequestContext::is_valid_identifier( $uuid ) || ! is_array( $choice ) ) { self::unavailable(); } self::keys( $choice, [ 'label', 'witness' ] );
				if ( ! is_string( $choice['label'] ) || strlen( $choice['label'] ) > 200 || 1 !== preg_match( '//u', $choice['label'] ) || ! is_array( $choice['witness'] ) || ( $choice['witness']['policy_uuid'] ?? null ) !== $uuid ) { self::unavailable(); }
				// The closed command validates every retained witness, including unselected choices.
				$validation = [ 'reason' => 'validation', 'return_mode' => 'inherit', 'return_policy_uuid' => '', 'refund_mode' => 'inherit', 'refund_policy_uuid' => '' ];
				$prefix = 'return_policy_v1' === $family ? 'return' : 'refund'; $validation[$prefix . '_mode'] = 'assigned'; $validation[$prefix . '_policy_uuid'] = $uuid;
				$payload = $this->payload( $o, $validation, $actor['user_id'] );
				$id = new OperationIdentity( $actor['site_id'], NativePolicyAssignmentAuthority::AUTHORITY, 'validation', PolicyAssignmentCommand::OPERATION, 1,
					PolicyAssignmentCommand::make_target( $o['coordinate']['scope_type'], $o['coordinate']['scope_id'], $o['offer_id'] ), 'validation' );
				PolicyAssignmentCommand::from_array( $id, $payload );
			}
		}
		if ( 'review' === $r['state'] ) {
			if ( null !== $r['identity'] || null !== $r['payload'] || $r['had_uncertainty'] ) { self::unavailable(); }
		} else {
			if ( ! is_array( $r['identity'] ) || ! is_array( $r['payload'] ) ) { self::unavailable(); } self::keys( $r['identity'], self::IDENT );
			$id = $this->authority->identity( $r['identity'] ); $command = PolicyAssignmentCommand::from_array( $id, $r['payload'] );
			if ( $id->operation !== PolicyAssignmentCommand::OPERATION || $command->coordinate() !== $o['coordinate'] || $command->offer_id() !== $o['offer_id']
				|| $command->opened()->row_id() !== $state->row_id() || $command->opened()->original_bytes() !== $state->original_bytes()
				|| $command->generation() !== $o['generation'] || $command->actor_user_id() !== $actor['user_id'] ) { self::unavailable(); }
		}
		if ( $this->encode( $r ) !== $raw || $this->authority->actor() !== $actor ) { self::unavailable(); }
		return $r;
	}

	private function remember( array $actor, array $opened ): void { $this->active = [ 'actor' => $actor, 'binding' => $opened['binding'], 'coordinate' => $opened['coordinate'], 'offer' => $opened['offer_id'] ]; }
	private static function definition(): string { return hash( 'sha256', 'cetech-policy-assignment-admin-review-v1:' . ReturnPolicyFamily::definition_hash() . ':' . RefundPolicyFamily::definition_hash() . ':two-original-slots:64choices-per-family:200-byte-labels:saved-preview-only' ); }
	private function encode( array $record ): string { $raw = json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, 32 ); if ( strlen( $raw ) > 131072 ) { throw new \InvalidArgumentException( 'Policy assignment original is too large.' ); } return $raw; }
	private static function keys( array $array, array $keys ): void { if ( count( $array ) !== count( $keys ) || array_diff( $keys, array_keys( $array ) ) ) { throw new \InvalidArgumentException( 'Invalid policy assignment original.' ); } }
	private static function label( string $text ): string { $label = substr( $text, 0, 200 ); while ( 1 !== preg_match( '//u', $label ) ) { $label = substr( $label, 0, -1 ); } return $label; }
	private static function refused( RequestContext $request, string $code ): OperationAttemptResult { return new OperationAttemptResult( OperationOutcome::rejected( new ContractError( $code, $request, 'reload_and_submit' ) ) ); }
	private static function unknown( RequestContext $request ): OperationAttemptResult { return new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $request, 'reconcile_original_request' ) ) ); }
	private static function unavailable(): never { throw new \RuntimeException( 'Policy assignments are unavailable. Reload to check the original request.' ); }
	public function __debugInfo(): array { return [ 'private_policy_assignment_workflow' => 'redacted' ]; }
}
