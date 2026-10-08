<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbOperationRecordRepository;

/** Dedicated pre-preparation gate. It never creates a C03 row, reads material sources or invokes a provider. */
final class QuotePreparationGate {
	private readonly \Closure $authorize;
	private readonly ?\Closure $readiness;
	private readonly EmergencyControlStore $control;
	private readonly bool $standard_route;
	public function __construct( private readonly OperationConnectionFactory $factory, callable $authorize, ?callable $readiness = null, ?EmergencyControlStore $control = null ) {
		$this->authorize = \Closure::fromCallable( $authorize ); $this->readiness = null === $readiness ? null : \Closure::fromCallable( $readiness );
		$this->control = $control ?? new EmergencyControlStore(); $this->standard_route = null === $control;
	}
	public function admit( QuotePreparationCommand $command, QuotePreparationAttempt $attempt ): QuotePreparationResult {
		if ( ! $this->authorized( $command->owner() ) ) { $attempt->deny(); return new QuotePreparationResult( 'denied', 'not_authorized' ); }
		if ( ! $attempt->begin( $command ) ) { return $this->inspect( $command ); }
		return $this->owned( $command, function( OperationSession $session, string $table ) use ( $command, $attempt ): array {
			// C07 is locked on this same owner before counters. The final issue unit rechecks it.
			if ( ! $this->control_enabled( $session, $command->owner()->site_id() ) ) { return [ 'denied', 'checkout_suspended', null, false ]; }
			$now = $this->now( $session ); $window = self::minute( $now ); $owner = $command->owner();
			// Global serialization first, then the exact current principal/session minute.
			$site = $this->counter( $session, $table, 'site_minute', QuoteBudgetSlot::site_slot_key( $owner->site_id() ), $window );
			$personal = $this->counter( $session, $table, 'session_minute', QuoteBudgetSlot::session_slot_key( $owner ), $window );
			$slot = $this->admission( $session, $table, $command ); $now = $this->now( $session );
			if ( null !== $slot ) { return $this->recorded( $session, $command, $slot, $now ); }
			if ( ( null !== $site && $site->row()['attempt_count'] >= 200 ) || ( null !== $personal && $personal->row()['attempt_count'] >= 20 ) ) { return [ 'denied', 'budget_exhausted', null, false ]; }
			// If a wait crossed the minute boundary, do not charge a newly recaptured window.
			if ( ! $window->equals( self::minute( $now ) ) ) { return [ 'denied', 'storage_unavailable', null, false ]; }
			$this->require_authorized( $owner );
			$this->increment( $session, $table, $site, $owner, 'site_minute', $window, $now );
			$this->increment( $session, $table, $personal, $owner, 'session_minute', $window, $now );
			$row = self::base( $owner->site_id(), 'admission', QuoteBudgetSlot::admission_slot_key( $command->identity()->namespace_digest() ), $window, $now );
			$row['principal_hash'] = $owner->facts()['principal_hash']; $row['attempt_count'] = 1; $row['admission_namespace_hash'] = $command->identity()->namespace_digest(); $row['admission_intent_digest'] = $command->intent_digest();
			$row['server_attempt_digest'] = $attempt->digest(); $row['lease_expires_at'] = $now->plus_seconds( 60 )->sql(); $row['lease_state'] = 'granted';
			$slot = $this->insert( $session, $table, QuoteBudgetSlot::from_row( $row ) );
			$this->require_authorized( $owner ); return [ 'preparation_allowed', 'admitted', $slot, true ];
		}, $attempt );
	}
	/** No reconstructed request or repeated grant can obtain a preparation capability. */
	public function inspect( QuotePreparationCommand $command ): QuotePreparationResult {
		return $this->owned( $command, function( OperationSession $session, string $table ) use ( $command ): array {
			$slot = $this->admission( $session, $table, $command ); return null === $slot ? [ 'denied', 'no_admission', null, false ] : $this->recorded( $session, $command, $slot, $this->now( $session ) );
		} );
	}
	/** An explicit fresh-owner read; never increments a counter or renews a lease. */
	public function reconcile( QuotePreparationCommand $command, ?QuotePreparationAttempt $attempt = null ): QuotePreparationResult {
		return $this->owned( $command, function( OperationSession $session, string $table ) use ( $command, $attempt ): array {
			// Recovering a pre-preparation capability is a new current guard, not historical disclosure.
			if ( null !== $attempt && $attempt->may_reconcile_preparation( $command ) && ! $this->control_enabled( $session, $command->owner()->site_id() ) ) { return [ 'denied', 'checkout_suspended', null, false ]; }
			$slot = $this->admission( $session, $table, $command ); if ( null === $slot ) { return [ 'denied', 'no_admission', null, false ]; }
			$now = $this->now( $session ); $recorded = $this->recorded( $session, $command, $slot, $now );
			if ( 'lease_expired' === $recorded[1] ) {
				$this->require_authorized( $command->owner() ); $row = $slot->row(); $row['lease_state'] = 'terminated'; ++$row['revision']; $row['last_seen_at'] = $now->sql();
				$this->replace( $session, $table, $slot, QuoteBudgetSlot::from_row( $row ), [ 'lease_state', 'revision', 'last_seen_at' ] );
				return [ 'denied', 'lease_terminated', null, true ];
			}
			if ( 'pending' === $recorded[0] && null !== $attempt && $attempt->may_reconcile_preparation( $command ) && $slot->row()['server_attempt_digest'] === $attempt->digest() ) { return [ 'preparation_allowed', 'admitted', $slot, false ]; }
			return $recorded;
		}, $attempt );
	}
	/** Known failure before handoff only; a possible C03 outcome uses original reconciliation. */
	public function terminate( QuotePreparationCommand $command, QuotePreparationLease $lease ): QuotePreparationResult {
		if ( ! $this->authorized( $command->owner() ) ) { return new QuotePreparationResult( 'denied', 'not_authorized' ); }
		if ( ! $lease->claim_termination( $command ) ) { return new QuotePreparationResult( 'pending', 'original_pending' ); }
		return $this->owned( $command, function( OperationSession $session, string $table ) use ( $command, $lease ): array {
			// A known pause can close operational work. Unknown current storage still refuses.
			$this->control_enabled( $session, $command->owner()->site_id() );
			$slot = $this->admission( $session, $table, $command );
			if ( null === $slot ) { return [ 'denied', 'no_admission', null, false ]; }
			$now = $this->now( $session ); $recorded = $this->recorded( $session, $command, $slot, $now );
			if ( 'granted' !== $slot->row()['lease_state'] ) { return $recorded; }
			if ( 'intent_conflict' === $recorded[1] || $slot->row() !== $lease->slot()->row() || $slot->row()['server_attempt_digest'] !== $lease->server_attempt_digest() ) { return [ 'denied', 'intent_conflict', null, false ]; }
			$this->require_authorized( $command->owner() ); $row = $slot->row(); $row['lease_state'] = 'terminated'; ++$row['revision']; $row['last_seen_at'] = $now->sql();
			$this->replace( $session, $table, $slot, QuoteBudgetSlot::from_row( $row ), [ 'lease_state', 'revision', 'last_seen_at' ] );
			return [ 'denied', 'lease_terminated', null, true ];
		} );
	}

	/** Unit result is withheld until rollback/commit and retirement are acknowledged. */
	private function owned( QuotePreparationCommand $command, callable $work, ?QuotePreparationAttempt $attempt = null ): QuotePreparationResult {
		if ( ! $this->authorized( $command->owner() ) ) { $attempt?->deny(); return new QuotePreparationResult( 'denied', 'not_authorized' ); }
		$session = null; $uncertain = false; $owns_unit = false; $commit_started = false; $descriptor = [ 'denied', 'storage_unavailable', null, false ];
		try {
			$session = $this->factory->open();
			if ( $session->site_id() !== $command->owner()->site_id() || $session->is_retired() || $session->in_transaction() || ! $session->begin() ) { throw new \RuntimeException(); }
			$owns_unit = true;
			$this->require_authorized( $command->owner() );
			if ( null === $this->readiness ) { ( new DeliveryQuoteReadiness( $session ) )->assert_ready(); } else { ( $this->readiness )( $session ); }
			$table = WpdbOperationRecordRepository::table_name( $session, DeliveryQuoteSchema::BUDGET_SUFFIX );
			if ( ! $session->validate_tables( [ $table ] ) ) { throw new \RuntimeException(); }
			$descriptor = $work( $session, $table ); $this->require_authorized( $command->owner() );
			if ( $descriptor[3] ) {
				$commit_started = true; $commit = $session->commit();
				if ( OperationCommitResult::Acknowledged !== $commit ) {
					if ( OperationCommitResult::NotSent === $commit && $session->in_transaction() && $session->rollback() ) { $descriptor = [ 'denied', 'commit_not_sent', null, false ]; $commit_started = false; }
					else { $uncertain = true; }
				} else { $commit_started = false; }
			} elseif ( ! $session->rollback() ) { $uncertain = true; }
		} catch ( \DomainException ) {
			$descriptor = [ 'denied', 'not_authorized', null, false ]; if ( null !== $session && $owns_unit && $session->in_transaction() && ! $this->rollback( $session ) ) { $uncertain = true; }
		} catch ( \Throwable ) {
			$descriptor = [ 'denied', 'storage_unavailable', null, false ];
			if ( $commit_started || ( null !== $session && ( $session->is_retired() || ( $owns_unit && $session->in_transaction() && ! $this->rollback( $session ) ) ) ) ) { $uncertain = true; }
		} finally {
			if ( null !== $session ) {
				try { if ( $owns_unit && $session->in_transaction() && ! $session->rollback() ) { $uncertain = true; } } catch ( \Throwable ) { $uncertain = true; }
				try { if ( ! $session->retire() || ! $session->is_retired() ) { $uncertain = true; } } catch ( \Throwable ) { $uncertain = true; }
			}
		}
		if ( $uncertain ) { $attempt?->uncertain(); return new QuotePreparationResult( 'unconfirmed', 'outcome_unconfirmed' ); }
		if ( ! $this->authorized( $command->owner() ) ) { $attempt?->deny(); return new QuotePreparationResult( 'denied', 'not_authorized' ); }
		[ $status, $reason, $slot ] = $descriptor;
		if ( 'preparation_allowed' === $status ) {
			if ( null === $attempt || ! $attempt->confirm() || ! $slot instanceof QuoteBudgetSlot ) { return new QuotePreparationResult( 'pending', 'original_pending' ); }
			return new QuotePreparationResult( $status, $reason, QuotePreparationLease::confirmed( $slot, $command, $attempt ) );
		}
		$attempt?->deny(); return new QuotePreparationResult( $status, $reason, null, 'completed' === $status ? $slot : null );
	}
	private function admission( OperationSession $session, string $table, QuotePreparationCommand $command ): ?QuoteBudgetSlot {
		$row = $this->one( $session, $this->select( $table ) . $session->prepare( ' WHERE site_id=%d AND admission_namespace_hash=%s LIMIT 2 FOR UPDATE', $command->owner()->site_id(), $command->identity()->namespace_digest() ) );
		if ( null === $row ) { return null; }
		$parent = null; if ( 'consumed' === ( $row['lease_state'] ?? null ) ) { $parent = ( new DeliveryQuoteRepository( $session ) )->find_quote( QuoteId::from_string( $row['consumed_quote_uuid'] ), true ); }
		$slot = QuoteBudgetSlot::from_row( $row, $parent );
		if ( 'admission' !== $slot->kind() || $slot->site_id() !== $command->owner()->site_id() || $slot->row()['admission_namespace_hash'] !== $command->identity()->namespace_digest() ) { throw new \RuntimeException(); }
		return $slot;
	}
	private function recorded( OperationSession $session, QuotePreparationCommand $command, QuoteBudgetSlot $slot, QuoteTime $now ): array {
		$row = $slot->row();
		if ( $row['principal_hash'] !== $command->owner()->facts()['principal_hash'] || $row['admission_intent_digest'] !== $command->intent_digest() ) { return [ 'denied', 'intent_conflict', null, false ]; }
		if ( 'consumed' === $row['lease_state'] ) { return [ 'completed', 'original_completed', QuoteId::from_string( $row['consumed_quote_uuid'] ), false ]; }
		if ( 'terminated' === $row['lease_state'] ) { return [ 'denied', 'lease_terminated', null, false ]; }
		if ( $now->compare( QuoteTime::parse( $row['created_at'] ) ) < 0 ) { throw new \RuntimeException(); }
		return $now->compare( QuoteTime::parse( $row['lease_expires_at'] ) ) >= 0 ? [ 'denied', 'lease_expired', null, false ] : [ 'pending', 'original_pending', null, false ];
	}
	private function counter( OperationSession $session, string $table, string $kind, string $key, QuoteTime $window ): ?QuoteBudgetSlot {
		$row = $this->one( $session, $this->select( $table ) . $session->prepare( " WHERE site_id=%d AND purpose='delivery_quote.issue' AND slot_kind=%s AND slot_key=%s AND window_start=%s LIMIT 2 FOR UPDATE", $session->site_id(), $kind, $key, $window->sql() ) );
		if ( null === $row ) { return null; } $slot = QuoteBudgetSlot::from_row( $row );
		if ( $slot->site_id() !== $session->site_id() || $slot->kind() !== $kind || $slot->row()['slot_key'] !== $key || $slot->row()['window_start'] !== $window->sql() ) { throw new \RuntimeException(); } return $slot;
	}
	private function increment( OperationSession $session, string $table, ?QuoteBudgetSlot $previous, QuoteOwner $owner, string $kind, QuoteTime $window, QuoteTime $now ): void {
		if ( null === $previous ) {
			$row = self::base( $owner->site_id(), $kind, 'site_minute' === $kind ? QuoteBudgetSlot::site_slot_key( $owner->site_id() ) : QuoteBudgetSlot::session_slot_key( $owner ), $window, $now );
			$row['principal_hash'] = 'site_minute' === $kind ? null : $owner->facts()['principal_hash']; $row['attempt_count'] = 1; $this->insert( $session, $table, QuoteBudgetSlot::from_row( $row ) ); return;
		}
		$row = $previous->row(); if ( 'session_minute' === $kind && $row['principal_hash'] !== $owner->facts()['principal_hash'] ) { throw new \RuntimeException(); }
		++$row['attempt_count']; ++$row['revision']; $row['last_seen_at'] = $now->sql(); $this->replace( $session, $table, $previous, QuoteBudgetSlot::from_row( $row ), [ 'attempt_count', 'revision', 'last_seen_at' ] );
	}
	private function insert( OperationSession $session, string $table, QuoteBudgetSlot $slot ): QuoteBudgetSlot {
		$row = $slot->row(); unset( $row['id'] ); [ $columns, $formats, $args ] = self::parameters( $row );
		if ( 1 !== $session->query( $session->prepare( "INSERT INTO `{$table}` (" . implode( ',', $columns ) . ') VALUES (' . implode( ',', $formats ) . ')', ...$args ) ) || $session->insert_id() < 1 ) { throw new \RuntimeException(); }
		$row['id'] = $session->insert_id(); $ordered = []; foreach ( QuoteBudgetSlot::FIELDS as $field ) { $ordered[$field] = $row[$field]; } return QuoteBudgetSlot::from_row( $ordered );
	}
	private function replace( OperationSession $session, string $table, QuoteBudgetSlot $expected, QuoteBudgetSlot $next, array $mutable ): void {
		[ $columns, $formats, $args ] = self::parameters( array_intersect_key( $next->row(), array_fill_keys( $mutable, true ) ) ); $set = []; foreach ( $columns as $i => $column ) { $set[] = $column . '=' . $formats[$i]; } $where = [];
		foreach ( $expected->row() as $column => $value ) { if ( null === $value ) { $where[] = "`{$column}` IS NULL"; } else { $where[] = ( is_string( $value ) ? 'BINARY ' : '' ) . "`{$column}`=" . ( is_int( $value ) ? '%d' : '%s' ); $args[] = $value; } }
		if ( 1 !== $session->query( $session->prepare( "UPDATE `{$table}` SET " . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $where ), ...$args ) ) ) { throw new \RuntimeException(); }
	}
	private static function parameters( array $row ): array { $columns = []; $formats = []; $args = []; foreach ( $row as $key => $value ) { $columns[] = "`{$key}`"; if ( null === $value ) { $formats[] = 'NULL'; } else { $formats[] = is_int( $value ) ? '%d' : '%s'; $args[] = $value; } } return [ $columns, $formats, $args ]; }
	private function select( string $table ): string { return 'SELECT ' . implode( ',', array_map( static fn( string $field ): string => "`{$field}`", QuoteBudgetSlot::FIELDS ) ) . " FROM `{$table}`"; }
	private function one( OperationSession $session, string $sql ): ?array { $rows = $session->get_results( $sql ); if ( false === $rows || ! array_is_list( $rows ) || count( $rows ) > 1 || ( [] !== $rows && ! is_array( $rows[0] ) ) ) { throw new \RuntimeException(); } return $rows[0] ?? null; }
	private function now( OperationSession $session ): QuoteTime { $row = $session->get_row( 'SELECT UTC_TIMESTAMP(6) AS utc' ); if ( ! is_array( $row ) || array_keys( $row ) !== [ 'utc' ] || ! is_string( $row['utc'] ) ) { throw new \RuntimeException(); } return QuoteTime::parse( $row['utc'] ); }
	private function control_enabled( OperationSession $session, int $site ): bool { if ( $this->standard_route ) { $this->control->assert_standard_wordpress_route( $session ); } $this->control->assert_ready( $session, $site ); return $this->control->current( $session )->enabled(); }
	private static function minute( QuoteTime $now ): QuoteTime { $value = $now->epoch_microseconds(); return QuoteTime::from_epoch_microseconds( intdiv( $value, 60000000 ) * 60000000 ); }
	private static function base( int $site, string $kind, string $key, QuoteTime $window, QuoteTime $now ): array { return [ 'id' => 1, 'site_id' => $site, 'format_version' => 1, 'purpose' => 'delivery_quote.issue', 'slot_kind' => $kind, 'slot_key' => $key, 'window_start' => $window->sql(), 'principal_hash' => null, 'attempt_count' => 0, 'revision' => 1, 'created_at' => $now->sql(), 'last_seen_at' => $now->sql(), 'admission_namespace_hash' => null, 'admission_intent_digest' => null, 'server_attempt_digest' => null, 'lease_expires_at' => null, 'lease_state' => null, 'consumed_quote_uuid' => null, 'consumed_at' => null ]; }
	private function authorized( QuoteOwner $owner ): bool { try { return true === ( $this->authorize )( $owner, 'delivery_quote.issue' ); } catch ( \Throwable ) { return false; } }
	private function require_authorized( QuoteOwner $owner ): void { if ( ! $this->authorized( $owner ) ) { throw new \DomainException(); } }
	private function rollback( OperationSession $session ): bool { try { return $session->rollback(); } catch ( \Throwable ) { return false; } }
}
