<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ReturnPolicy;

use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationIdentity, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationPhaseObserver};
use CetechDeliveryEngine\Domain\ReturnPolicy\{ReturnPolicyContent, ReturnPolicyFamily};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleFamilyRegistry, RuleLifecycleCommand, RuleSnapshot, RuleState, RuleVersion};
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbReturnPolicyAdminIntent;
use CetechDeliveryEngine\Integrations\ReturnPolicy\NativeReturnPolicyAuthority;

/** Native editor orchestration. The durable journal retains original requests, never permissions. */
final class ReturnPolicyAdminWorkflow {
	private const FIELDS = [ 'name', 'customer_title', 'summary', 'full_text', 'exceptions' ];
	private const RECORD = [ 'format', 'site_id', 'owner_id', 'definition_hash', 'ticket', 'state', 'opened', 'identity', 'payload', 'had_uncertainty' ];
	private const IDENT = [ 'site_id', 'authority', 'principal', 'operation', 'operation_version', 'target_key', 'idempotency_key' ];

	public function __construct(
		private readonly NativeReturnPolicyAuthority $authority,
		private readonly WpdbReturnPolicyAdminIntent $journal,
		private readonly OperationConnectionFactory $connections,
		private readonly ?OperationReadiness $readiness = null,
		private readonly ?OperationPhaseObserver $observer = null
	) {}

	public function can_access(): bool { return $this->authority->can_access(); }

	/** Current heads and immediate predecessors are not a retired-policy archive. */
	public function view(): array {
		$actor = $this->authority->actor();
		$name = WpdbReturnPolicyAdminIntent::name( $actor['site_id'], $actor['user_id'] );
		$record = $this->journal->locked( $name, function () use ( $name, $actor ): ?array {
			$raw = $this->journal->read( $name );
			return null === $raw ? null : $this->decode( $raw, $actor );
		} );
		$snapshot = $this->snapshot( RequestContext::create() );
		$rows = [];
		$logicals = [];
		foreach ( $snapshot->logicals as $logical ) { $logicals[$logical->id] = $logical; }
		foreach ( $snapshot->versions as $version ) {
			if ( RuleState::Retired === $version->state ) { continue; }
			$rows[] = [ 'policy_uuid' => $logicals[$version->logical_rule_id]->logical_uuid, 'version_uuid' => $version->version_uuid,
				'state' => $version->state->value, 'sequence' => $version->version_sequence,
				'fields' => ReturnPolicyContent::from_rule_payload( $version->payload )->facts(), 'reason' => $version->change_reason ];
		}
		if ( $this->authority->actor() !== $actor ) { throw new \RuntimeException( 'Return policy access is unavailable.' ); }
		return [ 'rows' => $rows, 'review' => null !== $record && 'review' === $record['state'] ? $this->review( $record ) : null, 'blocked' => null !== $record && 'review' !== $record['state'] ];
	}

	/** Opens server-owned CAS and content. Opaque posted selectors grant no access. */
	public function open( ?string $policy_uuid = null, ?string $version_uuid = null ): array {
		$actor = $this->authority->actor();
		$name = WpdbReturnPolicyAdminIntent::name( $actor['site_id'], $actor['user_id'] );
		return $this->journal->locked( $name, function () use ( $name, $actor, $policy_uuid, $version_uuid ): array {
			$previous = $this->journal->read( $name );
			if ( null !== $previous && 'review' !== $this->decode( $previous, $actor )['state'] ) { throw new \RuntimeException( 'Resolve the original return policy request first.' ); }
			if ( null !== $policy_uuid && ! RequestContext::is_valid_identifier( $policy_uuid ) || null !== $version_uuid && ! RequestContext::is_valid_identifier( $version_uuid ) || null === $policy_uuid && null !== $version_uuid ) { throw new \InvalidArgumentException( 'Invalid return policy selection.' ); }
			$snapshot = $this->snapshot( RequestContext::create() );
			$opened = $this->opened( $snapshot, $policy_uuid, $version_uuid, $actor['user_id'] );
			$record = [ 'format' => 1, 'site_id' => $actor['site_id'], 'owner_id' => $actor['user_id'], 'definition_hash' => ReturnPolicyFamily::definition_hash(),
				'ticket' => RequestContext::create()->request_id, 'state' => 'review', 'opened' => $opened, 'identity' => null, 'payload' => null, 'had_uncertainty' => false ];
			$raw = $this->encode( $record );
			if ( $this->authority->actor() !== $actor || ! ( null === $previous ? $this->journal->insert( $name, $raw ) : $this->journal->replace( $name, $previous, $raw ) ) ) { throw new \RuntimeException( 'Return policy review was not acknowledged.' ); }
			if ( $this->authority->actor() !== $actor ) { throw new \RuntimeException( 'Return policy access is unavailable.' ); }
			return $this->review( $record );
		} );
	}

	public function submit( string $ticket, string $verb, array $fields, RequestContext $request ): OperationAttemptResult {
		try {
			$actor = $this->authority->actor(); $name = WpdbReturnPolicyAdminIntent::name( $actor['site_id'], $actor['user_id'] );
			return $this->journal->locked( $name, function () use ( $name, $actor, $ticket, $verb, $fields, $request ): OperationAttemptResult {
				$raw = $this->journal->read( $name );
				if ( null === $raw ) { return self::refused( $request, 'stale_revision' ); }
				$record = $this->decode( $raw, $actor );
				if ( 'review' !== $record['state'] ) { return self::unknown( $request ); }
				if ( ! hash_equals( $record['ticket'], $ticket ) || ! in_array( $verb, $this->verbs( $record['opened']['mode'] ), true ) ) { return self::refused( $request, 'stale_revision' ); }
				try {
					$payload = $this->payload( $record['opened'], $verb, $fields, $actor['user_id'] );
					$target = RuleLifecycleCommand::make_target_key( ReturnPolicyContent::FAMILY, RuleContent::scope_hash( self::validation_family(), $payload['scope'] ), $payload['logical_uuid'] );
					if ( $this->authority->actor() !== $actor ) { return self::unknown( $request ); }
					$identity = $this->authority->identity_data( self::operation( $verb ), $target, RequestContext::create()->request_id );
					if ( $this->authority->actor() !== $actor ) { return self::unknown( $request ); }
					// Validate the complete C01 envelope before retaining anything dispatchable.
					RuleLifecycleCommand::from_payload( $this->authority->identity( $identity ), $payload, new RuleFamilyRegistry( [ self::validation_family() ] ) );
				} catch ( \InvalidArgumentException ) { return self::refused( $request, 'invalid_input' ); }
				$ready = $record; $ready['identity'] = $identity; $ready['payload'] = $payload; $ready['state'] = 'ready';
				$next = $this->encode( $ready );
				if ( ! $this->journal->replace( $name, $raw, $next ) ) { return self::unknown( $request ); }
				return $this->dispatch( $name, $next, $ready, false, false, $request );
			} );
		} catch ( \Throwable ) { return self::unknown( $request ); }
	}

	/** Neither recovery action reconstructs an original command from advanced current rows. */
	public function recover( string $verb, RequestContext $request ): OperationAttemptResult {
		if ( ! in_array( $verb, [ 'retry', 'reconcile' ], true ) ) { return self::refused( $request, 'invalid_input' ); }
		try {
			$actor = $this->authority->actor(); $name = WpdbReturnPolicyAdminIntent::name( $actor['site_id'], $actor['user_id'] );
			return $this->journal->locked( $name, function () use ( $name, $actor, $verb, $request ): OperationAttemptResult {
				$raw = $this->journal->read( $name );
				if ( null === $raw ) { return self::refused( $request, 'stale_revision' ); }
				$record = $this->decode( $raw, $actor );
				if ( 'review' === $record['state'] ) { return self::refused( $request, 'stale_revision' ); }
				return $this->dispatch( $name, $raw, $record, 'reconcile' === $verb, true, $request );
			} );
		} catch ( \Throwable ) { return self::unknown( $request ); }
	}

	private function dispatch( string $name, string $raw, array $record, bool $reconcile, bool $recovering, RequestContext $request ): OperationAttemptResult {
		// Native permission capture occurs before C03; captured family callbacks are pure.
		try {
			if ( $this->authority->actor() !== [ 'site_id' => $record['site_id'], 'user_id' => $record['owner_id'] ] ) { return self::unknown( $request ); }
			$identity = $this->authority->identity( $record['identity'] );
			$inflight = $record; $inflight['state'] = 'inflight'; $inflight['had_uncertainty'] = $record['had_uncertainty'] || $recovering;
			$inflight_raw = $this->encode( $inflight );
			if ( ! $this->journal->replace( $name, $raw, $inflight_raw ) ) { return self::unknown( $request ); }
			// Refresh after the journal acknowledgment: cache hooks there cannot stale this grant.
			if ( $this->authority->actor() !== [ 'site_id' => $record['site_id'], 'user_id' => $record['owner_id'] ] ) { return self::unknown( $request ); }
			$grant = $this->authority->capture( $identity, $record['payload']['scope'], $record['payload']['author_user_id'] );
			$service = new ReturnPolicyCatalogService( $grant['family'], $this->connections, $grant['catalog'], $this->readiness, $this->observer );
			try {
				$result = $reconcile ? $service->reconcile( $identity, $record['payload'], $request ) : $service->attempt( $identity, $record['payload'], $request );
			} catch ( \Throwable ) { $result = self::unknown( $request ); }
			// C03 has retired its connection before this native grant is refreshed.
			if ( ! $this->authority->authorize( $identity, $record['payload']['scope'], $record['payload']['author_user_id'] ) ) { $result = self::unknown( $request ); }
			$complete = in_array( $result->outcome->state, [ 'accepted', 'not_applicable' ], true ) && null !== $result->completion;
			$proven_initial_rejection = ! $recovering && ! $inflight['had_uncertainty'] && 'rejected' === $result->outcome->state;
			if ( $complete || $proven_initial_rejection ) {
				// Only the live authenticated result can clear; no persisted completion shortcut.
				if ( ! $this->journal->remove( $name, $inflight_raw ) ) { return self::unknown( $request ); }
				return $result;
			}
			$uncertain = $inflight; $uncertain['state'] = 'uncertain'; $uncertain['had_uncertainty'] = true;
			$this->journal->replace( $name, $inflight_raw, $this->encode( $uncertain ) );
			return self::unknown( $request );
		} catch ( \Throwable ) { return self::unknown( $request ); }
	}

	private function snapshot( RequestContext $request ): RuleSnapshot {
		$parts = $this->authority->identity_data( 'return.catalog.read', 'return-policy:catalog', RequestContext::create()->request_id );
		$identity = $this->authority->identity( $parts ); $scope = [ 'policy_uuid' => RequestContext::create()->request_id ];
		$grant = $this->authority->capture( $identity, $scope, 0 );
		$service = new ReturnPolicyCatalogService( $grant['family'], $this->connections, $grant['catalog'], $this->readiness );
		$snapshot = $service->capture( $identity, $scope, $request );
		if ( ! $snapshot instanceof RuleSnapshot || ! $this->authority->authorize( $identity, $scope, 0 ) ) { throw new \RuntimeException( 'Return policy catalog is unavailable.' ); }
		return $snapshot;
	}

	private function opened( RuleSnapshot $snapshot, ?string $policy, ?string $version_uuid, int $author ): array {
		if ( null === $policy ) {
			$policy = RequestContext::create()->request_id; $version = RequestContext::create()->request_id;
			return [ 'mode' => 'new', 'base_payload' => [ 'family' => ReturnPolicyContent::FAMILY, 'logical_uuid' => $policy, 'version_uuid' => $version,
				'scope' => [ 'policy_uuid' => $policy ], 'payload' => [], 'start_mode' => 'immediate', 'effective_from' => null, 'effective_until' => null,
				'priority' => 0, 'author_user_id' => $author, 'change_reason' => '', 'predecessor_uuid' => null,
				'preconditions' => [ 'family_revision' => $snapshot->guard?->revision ?? 0, 'logical_id' => 0, 'logical_revision' => 0, 'version_id' => 0, 'version_revision' => 0, 'predecessor_id' => 0, 'predecessor_revision' => 0 ] ], 'create_version_uuid' => $version ];
		}
		$logical = null; $versions = [];
		foreach ( $snapshot->logicals as $candidate ) { if ( $policy === $candidate->logical_uuid ) { $logical = $candidate; break; } }
		if ( null === $logical ) { throw new \InvalidArgumentException( 'The selected return policy is unavailable.' ); }
		foreach ( $snapshot->versions as $candidate ) { if ( $candidate->logical_rule_id === $logical->id ) { $versions[$candidate->id] = $candidate; } }
		$version = null;
		if ( null === $version_uuid ) { $version = $versions[$logical->draft_version_id ?? $logical->scheduled_version_id ?? $logical->current_published_version_id ?? 0] ?? null; }
		else { foreach ( $versions as $candidate ) { if ( $candidate->version_uuid === $version_uuid ) { $version = $candidate; break; } } }
		if ( ! $version instanceof RuleVersion || RuleState::Retired === $version->state ) { throw new \InvalidArgumentException( 'The selected return policy version is unavailable.' ); }
		$prior = null === $version->supersedes_version_id ? null : ( $versions[$version->supersedes_version_id] ?? null );
		if ( null !== $version->supersedes_version_id && null === $prior ) { throw new \RuntimeException( 'The original return policy predecessor is unavailable.' ); }
		return [ 'mode' => $version->state->value, 'base_payload' => [ 'family' => ReturnPolicyContent::FAMILY, 'logical_uuid' => $policy, 'version_uuid' => $version->version_uuid,
			'scope' => $logical->scope, 'payload' => $version->payload, 'start_mode' => $version->start_mode->value, 'effective_from' => $version->effective_from?->sql(), 'effective_until' => $version->effective_until?->sql(),
			'priority' => $version->priority, 'author_user_id' => $version->author_user_id, 'change_reason' => $version->change_reason, 'predecessor_uuid' => $prior?->version_uuid,
			'preconditions' => [ 'family_revision' => $snapshot->guard?->revision ?? 0, 'logical_id' => $logical->id, 'logical_revision' => $logical->revision, 'version_id' => $version->id, 'version_revision' => $version->row_revision, 'predecessor_id' => $prior?->id ?? 0, 'predecessor_revision' => $prior?->row_revision ?? 0 ] ],
			'create_version_uuid' => RequestContext::create()->request_id ];
	}

	private function payload( array $opened, string $verb, array $fields, int $author ): array {
		$payload = $opened['base_payload'];
		if ( in_array( $verb, [ 'create', 'edit' ], true ) ) {
			self::keys( $fields, [ ...self::FIELDS, 'reason' ] ); $content = $fields; unset( $content['reason'] );
			$payload['payload'] = ReturnPolicyContent::from_array( $content )->to_rule_payload();
			if ( ! is_string( $fields['reason'] ) ) { throw new \InvalidArgumentException( 'Invalid return policy change reason.' ); }
			$payload['author_user_id'] = $author; $payload['change_reason'] = $fields['reason'];
			if ( 'create' === $verb && 'published' === $opened['mode'] ) {
				$payload['predecessor_uuid'] = $payload['version_uuid'];
				$payload['preconditions']['predecessor_id'] = $payload['preconditions']['version_id'];
				$payload['preconditions']['predecessor_revision'] = $payload['preconditions']['version_revision'];
				$payload['version_uuid'] = $opened['create_version_uuid']; $payload['preconditions']['version_id'] = 0; $payload['preconditions']['version_revision'] = 0;
				$payload['start_mode'] = 'immediate'; $payload['effective_from'] = null; $payload['effective_until'] = null;
			}
		}
		return $payload;
	}

	private function review( array $record ): array {
		$opened = $record['opened']; $base = $opened['base_payload'];
		return [ 'ticket' => $record['ticket'], 'mode' => $opened['mode'], 'fields' => 'new' === $opened['mode'] ? array_fill_keys( self::FIELDS, '' ) : ReturnPolicyContent::from_rule_payload( $base['payload'] )->facts(), 'reason' => $base['change_reason'], 'verbs' => $this->verbs( $opened['mode'] ) ];
	}

	private function verbs( string $mode ): array { return match ( $mode ) { 'new' => [ 'create' ], 'draft' => [ 'edit', 'publish', 'retire' ], 'published' => [ 'create', 'retire' ], 'scheduled' => [ 'retire' ], default => throw new \InvalidArgumentException( 'Invalid return policy editor state.' ) }; }
	private static function operation( string $verb ): string { return match ( $verb ) { 'create' => 'rule.draft.create', 'edit' => 'rule.draft.edit', 'publish' => 'rule.publish', 'retire' => 'rule.retire', default => throw new \InvalidArgumentException( 'Invalid return policy editor action.' ) }; }

	private function decode( string $raw, array $actor ): array {
		if ( strlen( $raw ) > 131072 ) { throw new \RuntimeException( 'Return policy recovery is unavailable.' ); }
		$record = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
		if ( ! is_array( $record ) ) { throw new \RuntimeException( 'Return policy recovery is unavailable.' ); }
		self::keys( $record, self::RECORD );
		if ( 1 !== $record['format'] || $record['site_id'] !== $actor['site_id'] || $record['owner_id'] !== $actor['user_id'] || ! is_string( $record['definition_hash'] ) || ! hash_equals( ReturnPolicyFamily::definition_hash(), $record['definition_hash'] ) || ! RequestContext::is_valid_identifier( $record['ticket'] ) || ! in_array( $record['state'], [ 'review', 'ready', 'inflight', 'uncertain' ], true ) || ! is_bool( $record['had_uncertainty'] ) || ! is_array( $record['opened'] ) ) { throw new \RuntimeException( 'Return policy recovery is unavailable.' ); }
		self::keys( $record['opened'], [ 'mode', 'base_payload', 'create_version_uuid' ] );
		$opened = $record['opened']; $this->verbs( $opened['mode'] );
		if ( ! RequestContext::is_valid_identifier( $opened['create_version_uuid'] ) || ! is_array( $opened['base_payload'] ) ) { throw new \RuntimeException( 'Return policy recovery is unavailable.' ); }
		$base = $opened['base_payload'];
		if ( 'new' === $opened['mode'] ) {
			if ( [] !== ( $base['payload'] ?? null ) || '' !== ( $base['change_reason'] ?? null ) ) { throw new \RuntimeException( 'Return policy recovery is unavailable.' ); }
			$base['payload'] = ReturnPolicyContent::from_array( [ 'name' => 'validation', 'customer_title' => 'validation', 'summary' => 'validation', 'full_text' => 'validation', 'exceptions' => '' ] )->to_rule_payload(); $base['change_reason'] = 'validation';
		}
		$this->validate_payload( $base, 'new' === $opened['mode'] ? 'rule.draft.create' : 'rule.retire', $actor['site_id'] );
		if ( 'review' === $record['state'] ) {
			if ( null !== $record['identity'] || null !== $record['payload'] || $record['had_uncertainty'] ) { throw new \RuntimeException( 'Return policy recovery is unavailable.' ); }
		} else {
			if ( ! is_array( $record['identity'] ) || ! is_array( $record['payload'] ) ) { throw new \RuntimeException( 'Return policy recovery is unavailable.' ); }
			self::keys( $record['identity'], self::IDENT ); $identity = $this->authority->identity( $record['identity'] );
			if ( ! in_array( $identity->operation, array_map( self::operation( ... ), $this->verbs( $opened['mode'] ) ), true ) || $record['payload']['logical_uuid'] !== $base['logical_uuid'] || $record['payload']['scope'] !== $base['scope'] ) { throw new \RuntimeException( 'Return policy recovery is unavailable.' ); }
			$this->validate_payload( $record['payload'], $identity->operation, $actor['site_id'], $identity );
		}
		if ( $this->encode( $record ) !== $raw ) { throw new \RuntimeException( 'Return policy recovery is unavailable.' ); }
		return $record;
	}

	private function validate_payload( array $payload, string $operation, int $site, ?OperationIdentity $identity = null ): void {
		$family = self::validation_family();
		$identity ??= new OperationIdentity( $site, 'validation', 'validation', $operation, 1, RuleLifecycleCommand::make_target_key( ReturnPolicyContent::FAMILY, RuleContent::scope_hash( $family, $payload['scope'] ), $payload['logical_uuid'] ), 'validation' );
		$command = RuleLifecycleCommand::from_payload( $identity, $payload, new RuleFamilyRegistry( [ $family ] ) );
		if ( ReturnPolicyContent::FAMILY !== $payload['family'] || 0 !== $command->priority || $command->scope['policy_uuid'] !== $command->logical_uuid ) { throw new \InvalidArgumentException( 'Invalid return policy recovery command.' ); }
		ReturnPolicyContent::from_rule_payload( $command->payload );
	}

	private function encode( array $record ): string {
		$raw = json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, 32 );
		if ( strlen( $raw ) > 131072 ) { throw new \InvalidArgumentException( 'Return policy recovery is too large.' ); }
		return $raw;
	}
	private static function keys( array $data, array $keys ): void { if ( count( $data ) !== count( $keys ) || array_diff( $keys, array_keys( $data ) ) ) { throw new \InvalidArgumentException( 'Invalid return policy editor input.' ); } }
	private static function validation_family(): ReturnPolicyFamily { return new ReturnPolicyFamily( static fn (): bool => false, static fn (): bool => false, static fn (): bool => false ); }
	private static function refused( RequestContext $request, string $code ): OperationAttemptResult { return new OperationAttemptResult( OperationOutcome::rejected( new ContractError( $code, $request, 'reload_and_submit' ) ) ); }
	private static function unknown( RequestContext $request ): OperationAttemptResult { return new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $request, 'reconcile_original_request' ) ) ); }
	public function __debugInfo(): array { return [ 'return_policy_admin_workflow' => 'redacted' ]; }
}
