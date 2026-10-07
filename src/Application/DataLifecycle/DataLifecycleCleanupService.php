<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DataLifecycle;

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleContinuation;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecyclePolicy;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleProgress;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleRegistry;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleResult;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheEnvelope;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\DataLifecycleOptionsStore;

/** One current-site cache batch. Every durable effect shares its owned checkpoint. */
final class DataLifecycleCleanupService {
	public const INSPECTION_LIMIT = 200;
	public const DELETE_LIMIT = 50;
	public const IDENTITY_WINDOW = 1000;
	private \Closure $authorize;
	private \Closure $clock;
	private DataLifecycleOptionsStore $store;

	public function __construct( private DataLifecycleRegistry $registry, private OperationConnectionFactory $connections, callable $authorizer, ?DataLifecycleOptionsStore $store = null, private bool $trusted_equivalent_route = false, ?callable $monotonic_clock = null ) {
		$this->authorize = \Closure::fromCallable( $authorizer );
		$this->clock = null === $monotonic_clock ? static fn (): float => hrtime( true ) / 1_000_000_000 : \Closure::fromCallable( $monotonic_clock );
		$this->store = $store ?? new DataLifecycleOptionsStore();
	}

	public function start( int $site, string $mode = 'expired', ?RequestContext $request = null, ?DataLifecycleProgress $expected_previous = null, bool $expect_absent = false ): DataLifecycleResult {
		$request ??= RequestContext::create(); $session = null; $attempt = null;
		try {
			$this->check( $site, $mode ); $session = $this->open( $site );
			$row = $this->coordinator( $session ); $previous = $this->progress( $row );
			if ( ( null !== $expected_previous && ( null === $previous || ! $previous->same_checkpoint( $expected_previous ) ) ) || ( $expect_absent && null !== $previous ) ) { return $this->read_refusal( $session, $request, 'checkpoint_conflict' ); }
			if ( null !== $previous ) { $this->validate_progress( $site, $previous ); }
			if ( null !== $previous && 'completed' !== $previous->status ) { return $this->read_refusal( $session, $request, 'checkpoint_conflict' ); }
			$next = DataLifecycleProgress::initial( $site, $this->registry->policy_digest(), $mode, $this->store->now( $session ), $this->store->max_id( $session ) );
			$attempt = new DataLifecycleContinuation( $previous, $next, 'start' );
			$this->write( $session, $row, $next ); $this->check( $site, $mode );
			return $this->finish( $session, $attempt, [ DataLifecycleManifest::COORDINATOR_OPTION ], $request );
		} catch ( \Throwable $error ) { return $this->failure( $session, $attempt, $request, $error ); }
		finally { $this->retire( $session ); }
	}

	public function read( int $site, ?RequestContext $request = null ): DataLifecycleResult {
		$request ??= RequestContext::create(); $session = null;
		try {
			$this->check( $site ); $session = $this->open( $site ); $progress = $this->progress( $this->coordinator( $session ) ); $this->check( $site );
			if ( ! $this->rollback_and_retire( $session ) ) { return $this->unknown( null, false, $request ); }
			if ( null === $progress ) { return new DataLifecycleResult( 'refused', error: self::error( $request, 'no_checkpoint' ), reason: 'no_checkpoint' ); }
			$this->validate_progress( $site, $progress );
			return new DataLifecycleResult( 'accepted', $progress, DataLifecycleContinuation::checkpoint( $progress ) );
		} catch ( \Throwable $error ) { return $this->failure( $session, null, $request, $error ); }
		finally { $this->retire( $session ); }
	}

	public function batch( int $site, DataLifecycleContinuation $continuation, ?RequestContext $request = null ): DataLifecycleResult {
		$request ??= RequestContext::create();
		try { $this->validate_progress( $site, $continuation->after ); $this->check( $site, $continuation->after->mode ); }
		catch ( \Throwable $error ) { return $this->failure( null, null, $request, $error ); }
		if ( $continuation->uncertain ) { return $this->unknown( $continuation, $continuation->retirement_confirmed, $request ); }
		if ( 'preview' === $continuation->kind || 'start' === $continuation->kind ) { return new DataLifecycleResult( 'refused', continuation: $continuation, error: self::error( $request, 'invalid_input' ), reason: 'invalid_input' ); }
		$session = null; $attempt = $continuation;
		try {
			$started = $this->now_monotonic();
			$before = $continuation->before; $this->validate_progress( $site, $before ); $this->check( $site, $before->mode ); $session = $this->open( $site );
			$row = $this->coordinator( $session ); $current = $this->progress( $row );
			if ( null === $current || ! $current->same_checkpoint( $before ) ) { return $this->read_refusal( $session, $request, 'checkpoint_conflict', $continuation ); }
			if ( 'completed' === $before->status ) { if ( ! $this->rollback_and_retire( $session ) ) { return $this->unknown( $continuation, false, $request ); } return new DataLifecycleResult( 'accepted', $before, DataLifecycleContinuation::checkpoint( $before ) ); }
			[ $cursor, $counts, $names ] = $this->walk( $session, $before, false, $started );
			$after = $before->checkpoint( $cursor, $counts ); $attempt = new DataLifecycleContinuation( $before, $after, 'batch' );
			$this->check( $site, $before->mode ); $this->write( $session, $row, $after );
			return $this->finish( $session, $attempt, [ DataLifecycleManifest::COORDINATOR_OPTION, ...$names ], $request );
		} catch ( \Throwable $error ) { return $this->failure( $session, $attempt, $request, $error ); }
		finally { $this->retire( $session ); }
	}

	public function resume( int $site, DataLifecycleContinuation $continuation, ?RequestContext $request = null ): DataLifecycleResult {
		if ( 'start' !== $continuation->kind ) { return $this->batch( $site, $continuation, $request ); }
		$request ??= RequestContext::create();
		try { $this->validate_progress( $site, $continuation->after ); $this->check( $site, $continuation->after->mode ); }
		catch ( \Throwable $error ) { return $this->failure( null, null, $request, $error ); }
		if ( $continuation->uncertain ) { return $this->unknown( $continuation, $continuation->retirement_confirmed, $request ); }
		$session = null;
		try {
			$this->validate_progress( $site, $continuation->after ); $this->check( $site, $continuation->after->mode ); $session = $this->open( $site ); $row = $this->coordinator( $session ); $current = $this->progress( $row );
			if ( ! self::same_nullable( $current, $continuation->before ) ) { return $this->read_refusal( $session, $request, 'checkpoint_conflict', $continuation ); }
			$this->write( $session, $row, $continuation->after ); $this->check( $site, $continuation->after->mode );
			return $this->finish( $session, $continuation, [ DataLifecycleManifest::COORDINATOR_OPTION ], $request );
		} catch ( \Throwable $error ) { return $this->failure( $session, $continuation, $request, $error ); }
		finally { $this->retire( $session ); }
	}

	/** Fresh current-lock read only: reconciliation never deletes. */
	public function reconcile( int $site, DataLifecycleContinuation $continuation, ?RequestContext $request = null ): DataLifecycleResult {
		$request ??= RequestContext::create(); $session = null;
		if ( 'preview' === $continuation->kind ) { return new DataLifecycleResult( 'refused', error: self::error( $request, 'invalid_input' ), reason: 'invalid_input' ); }
		try {
			$this->validate_progress( $site, $continuation->after ); $this->check( $site, $continuation->after->mode ); $session = $this->open( $site ); $current = $this->progress( $this->coordinator( $session ) ); $this->check( $site, $continuation->after->mode );
			if ( ! $this->rollback_and_retire( $session ) ) { return $this->unknown( $continuation, false, $request ); }
			if ( null !== $current && hash_equals( $current->run_manifest_hash, $continuation->after->run_manifest_hash ) && ( $current->same_checkpoint( $continuation->after ) || $current->revision > $continuation->after->revision && $current->last_batch_sequence >= $continuation->after->last_batch_sequence && $current->cursor_id >= $continuation->after->cursor_id ) ) {
				$this->validate_progress( $site, $current ); return new DataLifecycleResult( 'accepted', $current, DataLifecycleContinuation::checkpoint( $current ), publication_pending: true );
			}
			if ( $continuation->retirement_confirmed && self::same_nullable( $current, $continuation->before ) ) {
				$retry = new DataLifecycleContinuation( $continuation->before, $continuation->after, $continuation->kind );
				return new DataLifecycleResult( 'refused', $current, $retry, self::error( $request, 'storage_refused' ), reason: 'storage_refused' );
			}
			return $this->unknown( $continuation, $continuation->retirement_confirmed, $request );
		} catch ( \Throwable $error ) {
			if ( $error instanceof DataLifecycleMaintenanceRefusal ) { return $this->failure( $session, null, $request, $error ); }
			return $this->unknown( $continuation, $this->rollback_and_retire( $session ) && $continuation->retirement_confirmed, $request );
		}
		finally { $this->retire( $session ); }
	}

	public function preview( int $site, ?DataLifecycleContinuation $continuation = null, ?RequestContext $request = null ): DataLifecycleResult {
		$request ??= RequestContext::create(); $session = null;
		try {
			$this->check( $site, 'expired' );
			if ( null !== $continuation && ( 'preview' !== $continuation->kind || 'expired' !== $continuation->after->mode || $continuation->uncertain ) ) { throw new DataLifecycleMaintenanceRefusal( 'invalid_input' ); }
			$started = $this->now_monotonic(); $session = $this->open( $site );
			$before = $continuation?->after ?? DataLifecycleProgress::initial( $site, $this->registry->policy_digest(), 'expired', $this->store->now( $session ), $this->store->max_id( $session ) ); $this->validate_progress( $site, $before );
			[ $cursor, $counts, , $eligible ] = $this->walk( $session, $before, true, $started ); $after = $before->checkpoint( $cursor, $counts ); $this->check( $site, 'expired' );
			if ( ! $this->rollback_and_retire( $session ) ) { return $this->unknown( null, false, $request ); }
			return new DataLifecycleResult( 'preview', $after, new DataLifecycleContinuation( null, $after, 'preview' ), eligible_candidates: $eligible );
		} catch ( \Throwable $error ) { return $this->failure( $session, null, $request, $error ); }
		finally { $this->retire( $session ); }
	}

	/** Metadata enumeration is bounded; every effect obtains another current row lock. */
	private function walk( OperationSession $session, DataLifecycleProgress $before, bool $preview, float $started ): array {
		$cursor = $before->cursor_id; $counts = $before->counts(); $names = []; $eligible = 0; $inspected = 0;
		$upper = $cursor + min( self::IDENTITY_WINDOW, $before->ceiling_id - $cursor );
		$rows = $this->store->window( $session, $cursor, $upper, self::INSPECTION_LIMIT + 1 );
		if ( ! array_is_list( $rows ) || count( $rows ) > self::INSPECTION_LIMIT + 1 ) { throw new \RuntimeException(); }
		$exhausted = true;
		foreach ( $rows as $metadata ) {
			if ( $inspected >= self::INSPECTION_LIMIT || ( ! $preview && count( $names ) >= self::DELETE_LIMIT ) || $this->now_monotonic() - $started >= 2.0 ) { $exhausted = false; break; }
			$id = self::integer( $metadata['option_id'] ?? null );
			if ( $id <= $cursor || $id > $upper ) { throw new \RuntimeException(); }
			$current = $this->store->current_by_id( $session, $id, 67584 ); ++$inspected; self::increment( $counts, 'inspected' ); $cursor = $id;
			if ( null === $current ) { self::increment( $counts, 'disappeared' ); continue; }
			$name = $current['option_name'] ?? null;
			if ( $name !== ( $metadata['option_name'] ?? null ) || self::integer( $current['option_id'] ?? null ) !== $id || ! is_string( $name ) || 1 !== preg_match( '/\A' . preg_quote( DataLifecycleManifest::CACHE_PREFIX, '/' ) . '[0-9a-f]{64}\z/D', $name ) || ! is_string( $current['option_value'] ?? null ) || ! in_array( $current['autoload'] ?? null, [ 'off', 'no' ], true ) ) { self::increment( $counts, 'invalid' ); continue; }
			try { $envelope = ManagedGeographyCacheEnvelope::from_json( $current['option_value'] ); }
			catch ( \Throwable ) { self::increment( $counts, 'invalid' ); continue; }
			if ( $envelope->site_id() !== $before->site_id || $name !== DataLifecycleManifest::CACHE_PREFIX . $envelope->identity_digest() ) { self::increment( $counts, 'invalid' ); continue; }
			$this->check( $before->site_id, $before->mode );
			if ( 'expired' === $before->mode && $envelope->expires_at() >= $before->cutoff_utc ) { self::increment( $counts, 'renewed' ); continue; }
			++$eligible;
			if ( ! $preview ) {
				if ( ! $this->store->delete( $session, $id, $name, $current['option_value'] ) ) { throw new \RuntimeException(); }
				self::increment( $counts, 'deleted' ); $names[] = $name;
			}
		}
		if ( $exhausted && count( $rows ) <= self::INSPECTION_LIMIT ) { $cursor = $upper; }
		return [ $cursor, $counts, $names, $eligible ];
	}

	private function open( int $site ): OperationSession {
		$session = $this->connections->open();
		$owned = false;
		try {
			if ( $session->site_id() !== $site ) { throw new DataLifecycleMaintenanceRefusal( 'not_authorized' ); }
			if ( $session->is_retired() || $session->in_transaction() ) { throw new \RuntimeException(); }
			if ( ! $this->trusted_equivalent_route ) { $this->store->assert_standard_wordpress_route( $session ); }
			if ( ! $session->begin() ) { throw new \RuntimeException(); }
			$owned = true;
			$this->store->assert_ready( $session, $site ); return $session;
		} catch ( \Throwable $error ) { if ( $owned && ! $this->rollback_and_retire( $session ) ) { throw new DataLifecycleMaintenanceUnknown(); } if ( ! $this->verified_retire( $session ) ) { throw new DataLifecycleMaintenanceUnknown(); } throw $error; }
	}
	private function coordinator( OperationSession $session ): ?array { return $this->store->current_by_name( $session, DataLifecycleManifest::COORDINATOR_OPTION, 16384 ); }
	private function progress( ?array $row ): ?DataLifecycleProgress { return null === $row ? null : DataLifecycleProgress::from_json( is_string( $row['option_value'] ?? null ) && in_array( $row['autoload'] ?? null, [ 'off', 'no' ], true ) ? $row['option_value'] : throw new \RuntimeException() ); }
	private function write( OperationSession $session, ?array $row, DataLifecycleProgress $progress ): void {
		if ( null === $row ) { if ( $this->store->insert( $session, DataLifecycleManifest::COORDINATOR_OPTION, $progress->to_json() ) < 1 ) { throw new \RuntimeException(); } }
		elseif ( ! $this->store->replace( $session, self::integer( $row['option_id'] ), DataLifecycleManifest::COORDINATOR_OPTION, $row['option_value'], $progress->to_json() ) ) { throw new \RuntimeException(); }
	}
	private function finish( OperationSession $session, DataLifecycleContinuation $attempt, array $names, RequestContext $request ): DataLifecycleResult {
		try { $commit = $session->commit(); } catch ( \Throwable ) { return $this->unknown( $attempt, $this->verified_retire( $session ), $request ); }
		if ( OperationCommitResult::Acknowledged !== $commit ) {
			if ( OperationCommitResult::NotSent === $commit && $this->rollback_and_retire( $session ) ) { return new DataLifecycleResult( 'refused', $attempt->before, $attempt, self::error( $request, 'storage_refused' ), reason: 'storage_refused' ); }
			return $this->unknown( $attempt, $this->verified_retire( $session ), $request );
		}
		if ( ! $this->verified_retire( $session ) ) { return $this->unknown( $attempt, false, $request ); }
		$pending = false;
		try { $pending = ! $this->store->invalidate( $names ) || $pending; } catch ( \Throwable ) { $pending = true; }
		return new DataLifecycleResult( 'accepted', $attempt->after, DataLifecycleContinuation::checkpoint( $attempt->after ), publication_pending: $pending );
	}

	/** Current-site authorization is checked again at an administrative/log disclosure. */
	public function safe_result( int $site, DataLifecycleResult $result, ?RequestContext $request = null ): array {
		$request ??= RequestContext::create();
		try { $this->check( $site ); if ( null !== $result->progress ) { $this->validate_progress( $site, $result->progress ); } return $result->safe(); }
		catch ( \Throwable ) { return ( new DataLifecycleResult( 'refused', error: self::error( $request, 'not_authorized' ), reason: 'not_authorized' ) )->safe(); }
	}
	private function failure( ?OperationSession $session, ?DataLifecycleContinuation $attempt, RequestContext $request, ?\Throwable $error = null ): DataLifecycleResult {
		$reason = $error instanceof DataLifecycleMaintenanceRefusal ? $error->reason : 'storage_refused';
		if ( $error instanceof DataLifecycleMaintenanceUnknown ) { return $this->unknown( $attempt, false, $request ); }
		if ( null === $session ) { return new DataLifecycleResult( 'refused', continuation: $attempt, error: self::error( $request, $reason ), reason: $reason ); }
		if ( $this->rollback_and_retire( $session ) ) { return new DataLifecycleResult( 'refused', $attempt?->before, $attempt, self::error( $request, $reason ), reason: $reason ); }
		return $this->unknown( $attempt, $this->verified_retire( $session ), $request );
	}
	private function read_refusal( OperationSession $session, RequestContext $request, string $code, ?DataLifecycleContinuation $continuation = null ): DataLifecycleResult {
		return $this->rollback_and_retire( $session ) ? new DataLifecycleResult( 'refused', continuation: $continuation, error: self::error( $request, $code ), reason: $code ) : $this->unknown( $continuation, false, $request );
	}
	private function unknown( ?DataLifecycleContinuation $attempt, bool $retired, RequestContext $request ): DataLifecycleResult {
		$continuation = null === $attempt ? null : new DataLifecycleContinuation( $attempt->before, $attempt->after, $attempt->kind, true, $retired );
		return new DataLifecycleResult( 'outcome_unknown', continuation: $continuation, error: new ContractError( 'outcome_unknown', $request, 'reconcile_original_request' ) );
	}
	private function check( int $site, ?string $mode = null ): void {
		if ( $site < 1 || true !== ( $this->authorize )( $site ) ) { throw new DataLifecycleMaintenanceRefusal( 'not_authorized' ); }
		if ( null !== $mode && ! in_array( $mode, [ 'expired', 'uninstall_cache' ], true ) ) { throw new DataLifecycleMaintenanceRefusal( 'invalid_input' ); }
		$class = $this->registry->get( DataLifecycleManifest::CACHE_CLASS );
		if ( ! $class->cleanup_eligible() || ( 'uninstall_cache' === $mode && DataLifecyclePolicy::ManagedCacheRemoval !== $class->explicit_uninstall_policy ) ) { throw new DataLifecycleMaintenanceRefusal( 'policy_changed' ); }
	}
	private function validate_progress( int $site, DataLifecycleProgress $progress ): void { if ( $progress->site_id !== $site ) { throw new DataLifecycleMaintenanceRefusal( 'not_authorized' ); } if ( ! hash_equals( $progress->policy_digest, $this->registry->policy_digest() ) ) { throw new DataLifecycleMaintenanceRefusal( 'policy_changed' ); } }
	private function rollback_and_retire( ?OperationSession $session ): bool {
		if ( null === $session ) { return false; }
		try { $rolled_back = $session->in_transaction() && ! $session->is_retired() && $session->rollback(); $retired = $session->retire(); return $rolled_back && $retired && $session->is_retired(); }
		catch ( \Throwable ) { $this->retire( $session ); return false; }
	}
	private function retire( ?OperationSession $session ): void { try { $session?->retire(); } catch ( \Throwable ) {} }
	private function verified_retire( OperationSession $session ): bool { try { return $session->retire() && $session->is_retired(); } catch ( \Throwable ) { return false; } }
	private function now_monotonic(): float { $now = ( $this->clock )(); if ( ! is_float( $now ) && ! is_int( $now ) || ! is_finite( (float) $now ) ) { throw new \RuntimeException(); } return (float) $now; }
	private static function integer( mixed $value ): int { if ( is_int( $value ) && $value > 0 ) { return $value; } if ( is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $value ) && ( strlen( $value ) < strlen( (string) PHP_INT_MAX ) || strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) <= 0 ) ) { return (int) $value; } throw new \RuntimeException(); }
	private static function increment( array &$counts, string $field ): void { if ( PHP_INT_MAX === $counts[$field] ) { throw new \RuntimeException(); } ++$counts[$field]; }
	private static function same_nullable( ?DataLifecycleProgress $first, ?DataLifecycleProgress $second ): bool { return null === $first ? null === $second : null !== $second && $first->same_checkpoint( $second ); }
	private static function error( RequestContext $request, string $code ): ContractError {
		return match ( $code ) { 'invalid_input' => new ContractError( 'invalid_input', $request, 'reload_and_submit' ), 'not_authorized' => new ContractError( 'not_authorized', $request, 'contact_support' ), default => new ContractError( 'temporarily_unavailable', $request, 'retry_original_request' ) };
	}
}

/** Finite internal refusal; no database or submitted exception text is projected. */
final class DataLifecycleMaintenanceRefusal extends \RuntimeException { public function __construct( public readonly string $reason ) { parent::__construct( 'Lifecycle maintenance was refused.' ); } }
final class DataLifecycleMaintenanceUnknown extends \RuntimeException {}
