<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionBudgetAccountStore;
use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Explicitly provisioned ledger. All locks and writes belong to the caller's transaction. */
final class SqlFixedPromotionBudgetStore implements FixedPromotionBudgetAccountStore {
	public function tables( OperationSession $session ): array {
		$this->live( $session, $session->site_id(), $session->table_prefix() );
		return FixedPromotionBudgetSchema::tables( $session->table_prefix() );
	}

	public function lock_account( OperationSession $session, string $account_key ): ?FixedPromotionBudgetState {
		[ $site, $prefix ] = $this->ready( $session ); $this->key( $account_key );
		$row = $this->account_row( $session, $site, $prefix, $account_key );
		return null === $row ? null : $this->account( $row );
	}

	public function lock_reservation( OperationSession $session, string $claim_key ): ?FixedPromotionBudgetReservation {
		[ $site, $prefix ] = $this->ready( $session ); $this->key( $claim_key );
		$table = FixedPromotionBudgetSchema::table( $prefix, FixedPromotionBudgetSchema::RESERVATIONS_SUFFIX );
		// Discovery grants no authority and may conservatively miss a newer row in RR.
		// Never lock a claim ahead of its immutable account or retain lock capabilities.
		$discovered = $this->read( $session, $site, $prefix, $session->prepare( "SELECT account_key FROM `{$table}` WHERE site_id = %d AND claim_key = %s LIMIT 1", $site, $claim_key ) );
		if ( null === $discovered ) { throw new OperationStorageException(); }
		if ( array_keys( $discovered ) !== [ 'account_key' ] || ! is_string( $discovered['account_key'] ) ) { throw new OperationStorageException(); }
		$this->key( $discovered['account_key'] );
		if ( null === $this->account_row( $session, $site, $prefix, $discovered['account_key'] ) ) { throw new OperationStorageException(); }
		$row = $this->reservation_row( $session, $site, $prefix, $claim_key );
		if ( null === $row || ! hash_equals( $discovered['account_key'], $row['account_key'] ) ) { throw new OperationStorageException(); }
		return $this->reservation( $row );
	}


	/** Current exact lookup when the request already proves the immutable account identity. */
	public function lock_reservation_for_account( OperationSession $session, string $claim_key, string $account_key ): ?FixedPromotionBudgetReservation {
		[ $site, $prefix ] = $this->ready( $session ); $this->key( $claim_key ); $this->key( $account_key );
		if ( null === $this->account_row( $session, $site, $prefix, $account_key ) ) { throw new OperationStorageException(); }
		$row = $this->reservation_row( $session, $site, $prefix, $claim_key );
		if ( null === $row ) { return null; }
		if ( ! hash_equals( $account_key, $row['account_key'] ) ) { throw new OperationStorageException(); }
		return $this->reservation( $row );
	}

	/** Explicit zero-state provisioning only; the caller supplies funding and authorization. */
	public function insert_account( OperationSession $session, FixedPromotionBudgetState $state ): bool {
		[ $site, $prefix ] = $this->ready( $session );
		if ( $site !== $state->site_id() || 1 !== $state->revision() || ! $state->held()->zero() || ! $state->spent()->zero() ) { throw new OperationStorageException(); }
		$values = FixedPromotionBudgetRecordCodec::account_values( $state );
		return $this->insert( $session, $site, $prefix, FixedPromotionBudgetSchema::ACCOUNTS_SUFFIX, $values );
	}

	public function replace_account( OperationSession $session, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool {
		[ $site, $prefix ] = $this->ready( $session ); $this->account_transition( $expected, $next );
		if ( $site !== $expected->site_id() ) { throw new OperationStorageException(); }
		$row = $this->account_row( $session, $site, $prefix, $expected->account_key() );
		if ( null === $row || ! hash_equals( $this->account( $row )->to_private_json(), $expected->to_private_json() ) ) { return false; }
		return $this->cas( $session, $site, $prefix, FixedPromotionBudgetSchema::ACCOUNTS_SUFFIX, $row['id'], FixedPromotionBudgetRecordCodec::account_values( $expected ), FixedPromotionBudgetRecordCodec::account_values( $next ) );
	}

	public function insert_reservation( OperationSession $session, FixedPromotionBudgetReservation $reservation ): bool {
		[ $site, $prefix ] = $this->ready( $session ); $request = $reservation->request();
		if ( $site !== $request->site_id() || 'held' !== $reservation->state() || 1 !== $reservation->revision() ) { throw new OperationStorageException(); }
		$account_row = $this->account_row( $session, $site, $prefix, $request->account_key() );
		if ( null === $account_row ) { throw new OperationStorageException(); }
		$account = $this->account( $account_row );
		if ( $account->funding_source() !== $request->funding_source() || $account->held()->currency() !== $request->amount()->currency() || $account->held()->precision() !== $request->amount()->precision() || $account->held()->compare( $request->amount() ) < 0 ) { throw new OperationStorageException(); }
		return $this->insert( $session, $site, $prefix, FixedPromotionBudgetSchema::RESERVATIONS_SUFFIX, FixedPromotionBudgetRecordCodec::reservation_values( $reservation ) );
	}

	public function replace_reservation( OperationSession $session, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool {
		[ $site, $prefix ] = $this->ready( $session ); $this->reservation_transition( $expected, $next ); $request = $expected->request();
		if ( $site !== $request->site_id() ) { throw new OperationStorageException(); }
		$account_row = $this->account_row( $session, $site, $prefix, $request->account_key() );
		if ( null === $account_row ) { throw new OperationStorageException(); }
		$account = $this->account( $account_row );
		if ( $account->funding_source() !== $request->funding_source() || $account->held()->currency() !== $request->amount()->currency() || $account->held()->precision() !== $request->amount()->precision() ) { throw new OperationStorageException(); }
		$row = $this->reservation_row( $session, $site, $prefix, $request->claim_key() );
		if ( null === $row || ! hash_equals( $this->reservation( $row )->to_private_json(), $expected->to_private_json() ) ) { return false; }
		return $this->cas( $session, $site, $prefix, FixedPromotionBudgetSchema::RESERVATIONS_SUFFIX, $row['id'], FixedPromotionBudgetRecordCodec::reservation_values( $expected ), FixedPromotionBudgetRecordCodec::reservation_values( $next ) );
	}

	private function account_row( OperationSession $s, int $site, string $prefix, string $key ): ?array {
		return $this->row( $s, $site, $prefix, FixedPromotionBudgetSchema::ACCOUNTS_SUFFIX, 'account_key', $key );
	}
	private function reservation_row( OperationSession $s, int $site, string $prefix, string $key ): ?array {
		return $this->row( $s, $site, $prefix, FixedPromotionBudgetSchema::RESERVATIONS_SUFFIX, 'claim_key', $key );
	}
	private function row( OperationSession $s, int $site, string $prefix, string $suffix, string $key_column, string $key ): ?array {
		$table = FixedPromotionBudgetSchema::table( $prefix, $suffix );
		$row = $this->read( $s, $site, $prefix, $s->prepare( 'SELECT ' . FixedPromotionBudgetRecordCodec::projection( $suffix ) . " FROM `{$table}` WHERE site_id = %d AND `{$key_column}` = %s LIMIT 1 FOR UPDATE", $site, $key ) );
		if ( null === $row ) { return null; }
		try { $row = FixedPromotionBudgetRecordCodec::bounded_row( $suffix, $row );
			$record = FixedPromotionBudgetSchema::ACCOUNTS_SUFFIX === $suffix ? $this->account( $row ) : $this->reservation( $row );
			$record_site = $record instanceof FixedPromotionBudgetState ? $record->site_id() : $record->request()->site_id();
			if ( $site !== $record_site || ! hash_equals( $key, (string) $row[$key_column] ) ) { throw new OperationStorageException(); }
			return $row;
		} catch ( \Throwable ) { throw new OperationStorageException(); }
	}
	private function account( array $row ): FixedPromotionBudgetState { try { return FixedPromotionBudgetRecordCodec::account_from_row( $row ); } catch ( \Throwable ) { throw new OperationStorageException(); } }
	private function reservation( array $row ): FixedPromotionBudgetReservation { try { return FixedPromotionBudgetRecordCodec::reservation_from_row( $row ); } catch ( \Throwable ) { throw new OperationStorageException(); } }

	private function insert( OperationSession $s, int $site, string $prefix, string $suffix, array $values ): bool {
		$table = FixedPromotionBudgetSchema::table( $prefix, $suffix ); $columns = implode( ',', array_map( static fn( string $k ): string => "`{$k}`", array_keys( $values ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		$this->live( $s, $site, $prefix ); $result = $s->query( $s->prepare( "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})", ...array_map( static fn( mixed $value ): string => (string) $value, array_values( $values ) ) ) ); $this->live( $s, $site, $prefix );
		if ( false === $result && 1062 === $s->errno() ) { return false; }
		if ( 1 !== $result || $s->insert_id() < 1 ) { throw new OperationStorageException(); }
		return true;
	}
	private function cas( OperationSession $s, int $site, string $prefix, string $suffix, mixed $id, array $expected, array $next ): bool {
		$table = FixedPromotionBudgetSchema::table( $prefix, $suffix ); $set = []; $where = [ 'id = %d' ]; $args = [];
		foreach ( $next as $key => $value ) { $set[] = "`{$key}` = %s"; $args[] = (string) $value; }
		$args[] = WpdbOperationRecordRepository::positive_integer( $id );
		foreach ( $expected as $key => $value ) { $where[] = "BINARY `{$key}` = BINARY %s"; $args[] = (string) $value; }
		$this->live( $s, $site, $prefix ); $result = $s->query( $s->prepare( "UPDATE `{$table}` SET " . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $where ), ...$args ) ); $this->live( $s, $site, $prefix );
		if ( false === $result || $result > 1 ) { throw new OperationStorageException(); }
		return 1 === $result;
	}
	private function read( OperationSession $s, int $site, string $prefix, string $sql ): ?array {
		$this->live( $s, $site, $prefix ); $row = $s->get_row( $sql ); $this->live( $s, $site, $prefix );
		if ( false === $row || 0 !== $s->errno() ) { throw new OperationStorageException(); } return $row;
	}
	private function ready( OperationSession $s ): array {
		$site = $s->site_id(); $prefix = $s->table_prefix(); $this->live( $s, $site, $prefix );
		try {
			if ( ! $s->validate_tables( FixedPromotionBudgetSchema::tables( $prefix ) ) ) { throw new OperationStorageException(); }
			$this->live( $s, $site, $prefix ); ( new FixedPromotionBudgetReadiness() )->assert_ready( $s ); $this->live( $s, $site, $prefix );
		} catch ( \Throwable ) { throw new OperationStorageException(); }
		return [ $site, $prefix ];
	}
	private function live( OperationSession $s, int $site, string $prefix ): void {
		if ( $site < 1 || $s->site_id() !== $site || $s->table_prefix() !== $prefix || $s->is_retired() || ! $s->in_transaction() ) { throw new OperationStorageException(); }
		FixedPromotionBudgetSchema::tables( $prefix );
	}
	private function key( string $key ): void { if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $key ) ) { throw new OperationStorageException(); } }
	private function account_transition( FixedPromotionBudgetState $a, FixedPromotionBudgetState $b ): void {
		try {
			$af = $a->private_facts(); $bf = $b->private_facts(); foreach ( [ 'revision', 'held', 'spent' ] as $k ) { unset( $af[$k], $bf[$k] ); }
			if ( $af !== $bf || $a->revision() === PHP_INT_MAX || $b->revision() !== $a->revision() + 1 ) { throw new OperationStorageException(); }
			if ( 0 === $b->spent()->compare( $a->spent() ) ) {
				$candidate = $b->held()->compare( $a->held() ) >= 0 ? $a->reserve( $b->held()->subtract( $a->held() ) ) : $a->release( $a->held()->subtract( $b->held() ) );
			} else { $candidate = $a->commit( $b->spent()->subtract( $a->spent() ) ); }
			if ( ! hash_equals( $candidate->to_private_json(), $b->to_private_json() ) ) { throw new OperationStorageException(); }
		} catch ( \Throwable ) { throw new OperationStorageException(); }
	}
	private function reservation_transition( FixedPromotionBudgetReservation $a, FixedPromotionBudgetReservation $b ): void {
		try {
			if ( 'held' !== $a->state() || 1 !== $a->revision() || 2 !== $b->revision() ) { throw new OperationStorageException(); }
			$candidate = match ( $b->state() ) { 'committed' => $a->commit( $b->changed_at() ), 'released' => $a->release( $b->changed_at() ), default => throw new OperationStorageException() };
			if ( ! hash_equals( $candidate->to_private_json(), $b->to_private_json() ) ) { throw new OperationStorageException(); }
		} catch ( \Throwable ) { throw new OperationStorageException(); }
	}
}
