<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Operation;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationCompletion;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Domain\Operation\OperationProfile;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationRecord;
use CetechDeliveryEngine\Domain\Operation\OperationRefusal;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbOperationChangeRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbOperationRecordRepository;

/** Internal primitive. Its production profile registry is deliberately empty. */
final class OperationCoordinator {

	private readonly OperationReadiness $readiness;

	public function __construct(
		private readonly OperationProfileRegistry $registry,
		private readonly OperationConnectionFactory $factory,
		?OperationReadiness $readiness = null,
		private readonly ?OperationPhaseObserver $observer = null
	) {
		$this->readiness = $readiness ?? new DatabaseOperationReadiness();
	}

	public function attempt( OperationIdentity $identity, mixed $payload, RequestContext $context ): OperationAttemptResult {
		$prepared = $this->prepare( $identity, $payload, $context );
		if ( $prepared instanceof OperationAttemptResult ) {
			return $prepared;
		}
		[ $profile, $command ] = $prepared;
		$intent = $command->intent();
		$session = null;
		$effect_started = false;
		$accepted_committed = false;
		try {
			$session = $this->open( $identity );
			$this->readiness->assert_ready( $session );
			$this->begin_unit( $session, $profile );
			$records = new WpdbOperationRecordRepository( $session );
			$created = $records->insert_pending( $identity, $intent );
			if ( ! $created ) {
				$this->authorize( $profile, $identity );
				[ $raw, $record ] = $this->locked_record( $session, $profile, $identity, $intent );
				if ( in_array( $record->state, [ 'accepted', 'not_applicable' ], true ) ) {
					$accepted_committed = 'accepted' === $record->state;
					$this->authorize( $profile, $identity );
					$this->rollback( $session );
					$result = $this->record_result( $record, $context, true );
					return $this->publish_result( $profile, $identity, $intent, $context, $result );
				}
			}
			$this->commit( $session );
			if ( $created ) {
				$this->observe( 'reservation_committed', $identity );
			}

			$this->begin_unit( $session, $profile );
			$this->authorize( $profile, $identity );
			[ $raw, $record ] = $this->locked_record( $session, $profile, $identity, $intent );
			if ( in_array( $record->state, [ 'accepted', 'not_applicable' ], true ) ) {
				$accepted_committed = 'accepted' === $record->state;
				$this->authorize( $profile, $identity );
				$this->rollback( $session );
				return $this->publish_result( $profile, $identity, $intent, $context, $this->record_result( $record, $context, true ) );
			}
			$effect_started = true;
			$target = $profile->lock_target( $session, $identity, $command );
			$this->authorize( $profile, $identity );
			$mutation = $profile->mutate( $session, $identity, $command, $target, $context );
			$this->observe( 'effect_mutated', $identity );
			if ( 'rejected' === $mutation->completion->state ) {
				$error = $mutation->completion->error( $context );
				throw new OperationRefusal( $error->code, $error->recovery_action, $error->parameters, $error->field_violations );
			}
			$audit_id = null;
			if ( $mutation->changed ) {
				if ( ! $profile->validate_accepted_facts( $mutation->completion, $mutation->event ) ) {
					throw new OperationStorageException();
				}
				$audit_id = ( new WpdbOperationChangeRepository( $session ) )->append( $record->id, $identity->site_id, $mutation->event );
				$this->observe( 'audit_staged', $identity );
			}
			$records->write_completion( $raw, $identity, $intent, $mutation->completion, $audit_id );
			$this->observe( 'completion_staged', $identity );
			$this->observe( 'before_effect_commit', $identity );
			$this->commit( $session );
			$accepted_committed = 'accepted' === $mutation->completion->state;
			$this->authorize( $profile, $identity );
			$publication = null === $mutation->completion->publication ? 'none' : 'pending';
			$result = new OperationAttemptResult( $mutation->completion->outcome( $context, $publication ), $mutation->completion );
			return $this->publish_result( $profile, $identity, $intent, $context, $result );
		} catch ( OperationUnconfirmedException ) {
			return $this->unknown( $context );
		} catch ( OperationRefusal $refusal ) {
			if ( $accepted_committed ) {
				return $this->unknown( $context );
			}
			if ( null !== $session && $session->in_transaction() && ! $this->try_rollback( $session ) ) {
				return $this->unknown( $context );
			}
			if ( $effect_started && ! in_array( $refusal->error( $context )->code, [ 'not_authorized', 'intent_conflict' ], true ) ) {
				return $this->record_rejection( $profile, $identity, $intent, $context, $refusal->error( $context ) );
			}
			return new OperationAttemptResult( OperationOutcome::rejected( $refusal->error( $context ) ) );
		} catch ( \Throwable ) {
			if ( $accepted_committed ) {
				return $this->unknown( $context );
			}
			if ( null !== $session && $session->in_transaction() && ! $this->try_rollback( $session ) ) {
				return $this->unknown( $context );
			}
			if ( null !== $session && $session->is_retired() ) {
				return $this->unknown( $context );
			}
			$error = new ContractError( 'temporarily_unavailable', $context, 'retry_original_request' );
			return $effect_started ? $this->record_rejection( $profile, $identity, $intent, $context, $error ) : new OperationAttemptResult( OperationOutcome::rejected( $error ) );
		} finally {
			$this->release( $session );
		}
	}

	/** Resolves only recorded facts/publication; this path never calls mutate(). */
	public function reconcile( OperationIdentity $identity, mixed $payload, RequestContext $context ): OperationAttemptResult {
		$prepared = $this->prepare( $identity, $payload, $context );
		if ( $prepared instanceof OperationAttemptResult ) {
			return $prepared;
		}
		[ $profile, $command ] = $prepared;
		$intent = $command->intent();
		$session = null;
		$accepted_known = false;
		try {
			$session = $this->open( $identity );
			$this->readiness->assert_ready( $session );
			$this->begin_unit( $session, $profile );
			$this->authorize( $profile, $identity );
			[ $raw, $record ] = $this->locked_record( $session, $profile, $identity, $intent );
			$accepted_known = 'accepted' === $record->state;
			if ( 'pending' === $record->state ) {
				$completion = OperationCompletion::rejected( new ContractError( 'temporarily_unavailable', $context, 'retry_original_request' ) );
				( new WpdbOperationRecordRepository( $session ) )->write_completion( $raw, $identity, $intent, $completion, null );
				$this->commit( $session );
				$this->authorize( $profile, $identity );
				return new OperationAttemptResult( $completion->outcome( $context, 'none' ), $completion );
			}
			$this->authorize( $profile, $identity );
			$this->rollback( $session );
			return $this->publish_result( $profile, $identity, $intent, $context, $this->record_result( $record, $context, true ) );
		} catch ( OperationRefusal $refusal ) {
			if ( $accepted_known ) {
				return $this->unknown( $context );
			}
			if ( null !== $session && $session->in_transaction() && ! $this->try_rollback( $session ) ) {
				return $this->unknown( $context );
			}
			return new OperationAttemptResult( OperationOutcome::rejected( $refusal->error( $context ) ) );
		} catch ( \Throwable ) {
			if ( null !== $session && $session->in_transaction() ) {
				$this->try_rollback( $session );
			}
			return $this->unknown( $context );
		} finally {
			$this->release( $session );
		}
	}

	private function prepare( OperationIdentity $identity, mixed $payload, RequestContext $context ): array|OperationAttemptResult {
		try {
			$profile = $this->registry->get( $identity->operation, $identity->operation_version );
		} catch ( \Throwable ) {
			return new OperationAttemptResult( OperationOutcome::rejected( new ContractError( 'unsupported_contract', $context, 'contact_support' ) ) );
		}
		try {
			$this->authorize( $profile, $identity );
			$command = $profile->validate_command( $identity, $payload );
			return [ $profile, $command ];
		} catch ( OperationRefusal $refusal ) {
			return new OperationAttemptResult( OperationOutcome::rejected( $refusal->error( $context ) ) );
		} catch ( \Throwable ) {
			return new OperationAttemptResult( OperationOutcome::rejected( new ContractError( 'invalid_input', $context, 'reload_and_submit' ) ) );
		}
	}

	private function open( OperationIdentity $identity ): OperationSession {
		$session = $this->factory->open();
		if ( $session->site_id() !== $identity->site_id ) {
			$session->retire();
			throw new OperationRefusal( 'not_authorized', 'contact_support' );
		}
		if ( $session->is_retired() || $session->in_transaction() ) {
			throw new OperationRefusal( 'temporarily_unavailable', 'contact_support' );
		}
		return $session;
	}

	private function begin_unit( OperationSession $session, OperationProfile $profile ): void {
		if ( ! $session->begin() ) {
			throw new OperationRefusal( 'temporarily_unavailable', 'retry_original_request' );
		}
		$participants = [
			WpdbOperationRecordRepository::table_name( $session, OperationStoreSchema::RECORDS_SUFFIX ),
			WpdbOperationRecordRepository::table_name( $session, OperationStoreSchema::CHANGES_SUFFIX ),
			...$profile->transactional_tables( $session ),
		];
		if ( ! $session->validate_tables( array_values( array_unique( $participants ) ) ) ) {
			throw new OperationStorageException();
		}
	}

	private function authorize( OperationProfile $profile, OperationIdentity $identity ): void {
		if ( true !== $profile->authorize( $identity ) ) {
			throw new OperationRefusal( 'not_authorized', 'contact_support' );
		}
	}

	/** @return array{array,OperationRecord} */
	private function locked_record( OperationSession $session, OperationProfile $profile, OperationIdentity $identity, CanonicalIntent $intent ): array {
		try {
			$raw = ( new WpdbOperationRecordRepository( $session ) )->lock( $identity );
			if ( null === $raw ) {
				throw new OperationUnconfirmedException();
			}
			$event = ( new WpdbOperationChangeRepository( $session ) )->find_for_operation(
				$identity->site_id,
				OperationRecord::positive_integer( $raw['id'] ?? null )
			);
			$record = OperationRecord::from_row( $raw, $profile, $event );
		} catch ( \Throwable ) {
			throw new OperationUnconfirmedException();
		}
		if ( ! $record->matches_identity( $identity ) ) {
			throw new OperationUnconfirmedException();
		}
		if ( ! hash_equals( $record->intent_hash, $intent->fingerprint() ) ) {
			throw new OperationRefusal( 'intent_conflict', 'reload_and_submit' );
		}
		return [ $raw, $record ];
	}

	private function commit( OperationSession $session ): void {
		try {
			$result = $session->commit();
		} catch ( \Throwable ) {
			$session->retire();
			throw new OperationUnconfirmedException();
		}
		if ( OperationCommitResult::Acknowledged === $result ) {
			return;
		}
		if ( OperationCommitResult::NotSent === $result && $this->try_rollback( $session ) ) {
			throw new OperationRefusal( 'temporarily_unavailable', 'retry_original_request' );
		}
		$session->retire();
		throw new OperationUnconfirmedException();
	}

	private function rollback( OperationSession $session ): void {
		if ( ! $this->try_rollback( $session ) ) {
			throw new OperationUnconfirmedException();
		}
	}

	private function try_rollback( OperationSession $session ): bool {
		try {
			if ( ! $session->is_retired() && $session->rollback() ) {
				return true;
			}
		} catch ( \Throwable ) {
		}
		$session->retire();
		return false;
	}

	private function record_rejection( OperationProfile $profile, OperationIdentity $identity, CanonicalIntent $intent, RequestContext $context, ContractError $error ): OperationAttemptResult {
		$session = null;
		try {
			$this->authorize( $profile, $identity );
			$session = $this->open( $identity );
			$this->readiness->assert_ready( $session );
			$this->begin_unit( $session, $profile );
			[ $raw, $record ] = $this->locked_record( $session, $profile, $identity, $intent );
			$this->authorize( $profile, $identity );
			if ( in_array( $record->state, [ 'accepted', 'not_applicable' ], true ) ) {
				$this->rollback( $session );
				return $this->record_result( $record, $context, true );
			}
			$completion = OperationCompletion::rejected( $error );
			( new WpdbOperationRecordRepository( $session ) )->write_completion( $raw, $identity, $intent, $completion, null );
			$this->commit( $session );
			return new OperationAttemptResult( $completion->outcome( $context, 'none' ), $completion );
		} catch ( OperationRefusal $refusal ) {
			if ( null !== $session && $session->in_transaction() && ! $this->try_rollback( $session ) ) {
				return $this->unknown( $context );
			}
			return new OperationAttemptResult( OperationOutcome::rejected( $refusal->error( $context ) ) );
		} catch ( \Throwable ) {
			if ( null !== $session && $session->in_transaction() ) {
				$this->try_rollback( $session );
			}
			return $this->unknown( $context );
		} finally {
			$this->release( $session );
		}
	}

	private function publish_result( OperationProfile $profile, OperationIdentity $identity, CanonicalIntent $intent, RequestContext $context, OperationAttemptResult $result ): OperationAttemptResult {
		try {
			$this->authorize( $profile, $identity );
		} catch ( \Throwable ) {
			return $this->unknown( $context );
		}
		if ( null === $result->completion || ! $result->outcome->publication_pending ) {
			return $result;
		}
		$session = null;
		try {
			$this->authorize( $profile, $identity );
			if ( ! $profile->publish( $identity, $result->completion ) ) {
				$this->authorize( $profile, $identity );
				return $result;
			}
			$this->authorize( $profile, $identity );
			$session = $this->open( $identity );
			$this->readiness->assert_ready( $session );
			$this->begin_unit( $session, $profile );
			[ $raw, $record ] = $this->locked_record( $session, $profile, $identity, $intent );
			if ( 'accepted' !== $record->state || $record->completion->to_json() !== $result->completion->to_json() ) {
				throw new OperationUnconfirmedException();
			}
			$this->authorize( $profile, $identity );
			if ( 'published' === $record->publication_state ) {
				$this->rollback( $session );
				return $this->record_result( $record, $context, $result->replayed );
			}
			( new WpdbOperationRecordRepository( $session ) )->mark_published( $raw, $identity, $intent );
			$this->commit( $session );
			$this->authorize( $profile, $identity );
			return new OperationAttemptResult( $result->completion->outcome( $context, 'published' ), $result->completion, $result->replayed );
		} catch ( OperationRefusal $refusal ) {
			if ( null !== $session && $session->in_transaction() ) {
				$this->try_rollback( $session );
			}
			if ( 'not_authorized' === $refusal->error( $context )->code ) {
				return $this->unknown( $context );
			}
			return $result;
		} catch ( \Throwable ) {
			if ( null !== $session && $session->in_transaction() ) {
				$this->try_rollback( $session );
			}
			try {
				$this->authorize( $profile, $identity );
			} catch ( \Throwable ) {
				return $this->unknown( $context );
			}
			return $result;
		} finally {
			$this->release( $session );
		}
	}

	private function record_result( OperationRecord $record, RequestContext $context, bool $replayed ): OperationAttemptResult {
		if ( null === $record->completion ) {
			throw new OperationUnconfirmedException();
		}
		return new OperationAttemptResult( $record->completion->outcome( $context, $record->publication_state ), $record->completion, $replayed );
	}

	private function unknown( RequestContext $context ): OperationAttemptResult {
		return new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $context, 'reconcile_original_request' ) ) );
	}

	private function release( ?OperationSession $session ): void {
		if ( null !== $session && ! $session->is_retired() ) {
			try {
				$session->retire();
			} catch ( \Throwable ) {
			}
		}
	}

	private function observe( string $phase, OperationIdentity $identity ): void {
		if ( null !== $this->observer ) {
			$this->observer->observe( $phase, $identity );
		}
	}
}
