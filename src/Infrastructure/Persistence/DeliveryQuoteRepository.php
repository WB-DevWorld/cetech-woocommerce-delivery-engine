<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStorageCodec;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Same-site storage primitives only; caller owns transaction, locks and completion. */
final class DeliveryQuoteRepository {
	private array $tables;
	private readonly DeliveryQuoteReadiness $readiness;
	public function __construct( private readonly OperationSession $session, ?DeliveryQuoteReadiness $readiness = null ) {
		$this->readiness = $readiness ?? new DeliveryQuoteReadiness( $session ); $this->tables = [];
		foreach ( DeliveryQuoteSchema::SUFFIXES as $suffix ) { $this->tables[$suffix] = WpdbOperationRecordRepository::table_name( $session, $suffix ); }
	}
	public function table_names(): array { return array_values( $this->tables ); }
	public function find_quote( QuoteId $id, bool $lock = false ): ?QuoteStoredRow {
		$this->assert_ready(); $suffix = 'delivery_quotes';
		$row = $this->one( $this->select( $suffix ) . $this->session->prepare( ' WHERE site_id=%d AND quote_uuid=%s LIMIT 2' . ( $lock ? ' FOR UPDATE' : '' ), $this->session->site_id(), $id->value() ) );
		if ( null === $row ) { return null; } $quote = $this->quote( $row ); if ( ! $id->equals( $quote->header()->id() ) ) { self::fail(); } return $quote;
	}
	public function max_quote_id(): int {
		$this->assert_ready(); $table = $this->tables['delivery_quotes']; $row = $this->one( $this->session->prepare( "SELECT MAX(id) AS ceiling,0 AS payload_oversized FROM `{$table}` WHERE site_id=%d", $this->session->site_id() ) );
		if ( null === $row || ! array_key_exists( 'ceiling', $row ) ) { self::fail(); }
		try { return null === $row['ceiling'] ? 0 : QuoteStorageCodec::integer( $row['ceiling'], 0 ); } catch ( \Throwable ) { self::fail(); }
	}
	/** Fixed explicit ceiling and at most100 inspected rows; no cleanup eligibility claim. */
	public function quote_page( int $ceiling, int $after_id = 0, int $limit = 100 ): array {
		if ( $ceiling < 0 || $after_id < 0 || $limit < 1 || $limit > 100 ) { self::fail(); } $this->assert_ready();
		$rows = $this->many( $this->select( 'delivery_quotes' ) . $this->session->prepare( ' WHERE site_id=%d AND id>%d AND id<=%d ORDER BY id ASC LIMIT %d', $this->session->site_id(), $after_id, $ceiling, $limit ) );
		if ( count( $rows ) > $limit ) { self::fail(); } $out = []; $previous = $after_id;
		foreach ( $rows as $row ) { $quote = $this->quote( $row ); if ( $quote->id() <= $previous || $quote->id() > $ceiling ) { self::fail(); } $previous = $quote->id(); $out[] = $quote; }
		return $out;
	}
	public function insert_quote( QuoteStoredRow $quote ): int {
		if ( 'issued' !== $quote->state() || 1 !== $quote->revision() ) { self::fail(); }
		return $this->insert( 'delivery_quotes', $quote->row() );
	}
	public function replace_quote( QuoteStoredRow $expected, QuoteStoredRow $next ): bool {
		$a = $expected->row(); $b = $next->row();
		$this->immutable( $a, $b, [ 'state', 'revision', 'retention_revision', 'private_body_json', 'accepted_at', 'transition_at' ] );
		$allowed = [ 'issued' => [ 'accepted', 'invalidated', 'stripped' ], 'accepted' => [ 'invalidated' ], 'invalidated' => [ 'stripped' ], 'stripped' => [] ];
		if ( ! in_array( $b['state'], $allowed[$a['state']], true ) || $b['revision'] !== $a['revision'] + 1 || ( null !== $a['accepted_at'] && $a['accepted_at'] !== $b['accepted_at'] )
			|| ( 'stripped' !== $b['state'] && $a['private_body_json'] !== $b['private_body_json'] ) || $b['retention_revision'] !== $a['retention_revision'] + ( 'stripped' === $b['state'] ? 1 : 0 )
			|| ( null !== $a['transition_at'] && QuoteStorageCodec::time( $b['transition_at'] )->compare( QuoteStorageCodec::time( $a['transition_at'] ) ) < 0 ) ) { self::fail(); }
		return $this->replace( 'delivery_quotes', $a, $b, [ 'state', 'revision', 'retention_revision', 'private_body_json', 'accepted_at', 'transition_at' ] );
	}
	public function find_binding( QuoteStoredRow $parent, bool $lock = false ): ?QuoteBinding {
		$this->assert_ready( $parent->site_id() ); $current_parent = $this->find_quote( $parent->header()->id(), $lock ); if ( null === $current_parent ) { self::fail(); }
		$row = $this->one( $this->select( 'delivery_quote_bindings' ) . $this->session->prepare( ' WHERE site_id=%d AND quote_uuid=%s LIMIT 2' . ( $lock ? ' FOR UPDATE' : '' ), $this->session->site_id(), $parent->header()->id()->value() ) );
		return null === $row ? null : $this->binding( $row, $current_parent );
	}
	/** Read-only resolution. Locking callers resolve and lock the quote before binding. */
	public function find_binding_by_placement( QuoteId $placement ): ?QuoteBinding {
		$this->assert_ready(); $row = $this->one( $this->select( 'delivery_quote_bindings' ) . $this->session->prepare( ' WHERE site_id=%d AND placement_uuid=%s LIMIT 2', $this->session->site_id(), $placement->value() ) );
		if ( null === $row ) { return null; }
		try { $id = QuoteStorageCodec::uuid( $row['quote_uuid'] ?? null ); } catch ( \Throwable ) { self::fail(); }
		$quote = $this->find_quote( $id ); if ( null === $quote ) { self::fail(); } $binding = $this->binding( $row, $quote ); if ( $binding->row()['placement_uuid'] !== $placement->value() ) { self::fail(); } return $binding;
	}
	public function insert_binding( QuoteBinding $binding ): int {
		if ( 'prepared' !== $binding->state() || 1 !== $binding->revision() ) { self::fail(); }
		$this->assert_ready( $binding->site_id() ); $parent = $this->physical_quote( $binding->row()['quote_uuid'], true ); $this->binding( $binding->row(), $parent );
		return $this->insert( 'delivery_quote_bindings', $binding->row() );
	}
	public function replace_binding( QuoteBinding $expected, QuoteBinding $next ): bool {
		$a = $expected->row(); $b = $next->row(); $mutable = [ 'state', 'revision', 'snapshot_digest', 'context_digest', 'verified_at', 'sealed_at' ]; $this->immutable( $a, $b, $mutable );
		if ( 'prepared' !== $a['state'] || $b['revision'] !== $a['revision'] + 1 || ( 1 === $a['revision'] && ( 'prepared' !== $b['state'] || 2 !== $b['revision'] ) ) || ( 2 === $a['revision'] && ( 'sealed' !== $b['state'] || 3 !== $b['revision'] ) ) ) { self::fail(); }
		if ( null !== $a['verified_at'] ) { foreach ( [ 'snapshot_digest', 'context_digest', 'verified_at' ] as $field ) { if ( $a[$field] !== $b[$field] ) { self::fail(); } } }
		$this->assert_ready( $expected->site_id() ); $parent = $this->physical_quote( $a['quote_uuid'], true ); $this->binding( $a, $parent ); $this->binding( $b, $parent );
		return $this->replace( 'delivery_quote_bindings', $a, $b, $mutable );
	}
	public function find_budget( string $kind, string $key, QuoteTime $window, bool $lock = false ): ?QuoteBudgetSlot {
		try { QuoteShape::choice( $kind, [ 'site_minute', 'session_minute', 'admission' ] ); QuoteShape::digest( $key ); } catch ( \Throwable ) { self::fail(); } $this->assert_ready();
		$row = $this->one( $this->select( 'delivery_quote_budget_windows' ) . $this->session->prepare( " WHERE site_id=%d AND purpose='delivery_quote.issue' AND slot_kind=%s AND slot_key=%s AND window_start=%s LIMIT 2" . ( $lock ? ' FOR UPDATE' : '' ), $this->session->site_id(), $kind, $key, $window->sql() ) );
		if ( null === $row ) { return null; } $slot = $this->budget( $row, $lock ); $facts = $slot->row(); if ( $kind !== $facts['slot_kind'] || $key !== $facts['slot_key'] || $window->sql() !== $facts['window_start'] ) { self::fail(); } return $slot;
	}
	public function find_admission( string $namespace, bool $lock = false ): ?QuoteBudgetSlot {
		try { QuoteShape::digest( $namespace ); } catch ( \Throwable ) { self::fail(); } $this->assert_ready();
		$row = $this->one( $this->select( 'delivery_quote_budget_windows' ) . $this->session->prepare( ' WHERE site_id=%d AND admission_namespace_hash=%s LIMIT 2' . ( $lock ? ' FOR UPDATE' : '' ), $this->session->site_id(), $namespace ) );
		if ( null === $row ) { return null; } $slot = $this->budget( $row, $lock ); if ( 'admission' !== $slot->kind() || $namespace !== $slot->row()['admission_namespace_hash'] ) { self::fail(); } return $slot;
	}
	public function insert_budget( QuoteBudgetSlot $slot ): int {
		if ( 1 !== $slot->revision() || ( 'admission' === $slot->kind() && 'granted' !== $slot->row()['lease_state'] ) ) { self::fail(); }
		return $this->insert( 'delivery_quote_budget_windows', $slot->row() );
	}
	public function replace_budget( QuoteBudgetSlot $expected, QuoteBudgetSlot $next ): bool {
		$a = $expected->row(); $b = $next->row(); $mutable = [ 'attempt_count', 'revision', 'last_seen_at', 'lease_state', 'consumed_quote_uuid', 'consumed_at' ]; $this->immutable( $a, $b, $mutable );
		if ( $a['revision'] === PHP_INT_MAX || $b['revision'] !== $a['revision'] + 1 || QuoteStorageCodec::time( $b['last_seen_at'] )->compare( QuoteStorageCodec::time( $a['last_seen_at'] ) ) < 0 ) { self::fail(); }
		if ( 'admission' === $a['slot_kind'] ) {
			if ( 'granted' !== $a['lease_state'] || ! in_array( $b['lease_state'], [ 'consumed', 'terminated' ], true ) || $a['attempt_count'] !== $b['attempt_count'] ) { self::fail(); }
			if ( 'consumed' === $b['lease_state'] ) {
				$this->assert_ready( $expected->site_id() ); $current = $this->find_admission( $a['admission_namespace_hash'], true ); if ( null === $current || $current->row() !== $a ) { return false; }
				$parent = $this->physical_quote( $b['consumed_quote_uuid'], true ); try { QuoteBudgetSlot::from_row( $b, $parent ); } catch ( \Throwable ) { self::fail(); }
			}
		} elseif ( $b['attempt_count'] !== $a['attempt_count'] + 1 ) { self::fail(); }
		return $this->replace( 'delivery_quote_budget_windows', $a, $b, $mutable );
	}

	private function assert_ready( ?int $site = null ): void {
		if ( $this->session->is_retired() || ! $this->session->in_transaction() || ( null !== $site && $site !== $this->session->site_id() ) ) { self::fail(); }
		try { $this->readiness->assert_ready(); if ( ! $this->session->validate_tables( $this->table_names() ) ) { self::fail(); } } catch ( \Throwable ) { self::fail(); }
	}
	/** Limit large corrupt values before they cross the native transport boundary. */
	private function select( string $suffix ): string {
		$fields = match ( $suffix ) { 'delivery_quotes' => QuoteStoredRow::FIELDS, 'delivery_quote_bindings' => QuoteBinding::FIELDS, 'delivery_quote_budget_windows' => QuoteBudgetSlot::FIELDS };
		$budgets = match ( $suffix ) { 'delivery_quotes' => [ 'header_json' => 4096, 'private_body_json' => 65536 ], 'delivery_quote_bindings' => [ 'mapping_json' => 65536 ], default => [] };
		$columns = []; $overflow = [];
		foreach ( $fields as $field ) {
			if ( isset( $budgets[$field] ) ) { $limit = $budgets[$field]; $columns[] = "CASE WHEN OCTET_LENGTH(`{$field}`)<={$limit} THEN `{$field}` ELSE NULL END AS `{$field}`"; $overflow[] = "OCTET_LENGTH(`{$field}`)>{$limit}"; }
			else { $columns[] = "`{$field}`"; }
		}
		$columns[] = ( [] === $overflow ? '0' : 'CASE WHEN ' . implode( ' OR ', $overflow ) . ' THEN 1 ELSE 0 END' ) . ' AS payload_oversized';
		return 'SELECT ' . implode( ',', $columns ) . " FROM `{$this->tables[$suffix]}`";
	}
	private function many( string $sql ): array {
		try { $rows = $this->session->get_results( $sql ); } catch ( \Throwable ) { self::fail(); }
		if ( false === $rows || ! array_is_list( $rows ) ) { self::fail(); }
		foreach ( $rows as &$row ) {
			if ( ! is_array( $row ) ) { self::fail(); }
			if ( ! array_key_exists( 'payload_oversized', $row ) || ( 0 !== $row['payload_oversized'] && '0' !== $row['payload_oversized'] ) ) { self::fail(); } unset( $row['payload_oversized'] );
		} unset( $row ); return $rows;
	}
	private function one( string $sql ): ?array { $rows = $this->many( $sql ); if ( count( $rows ) > 1 ) { self::fail(); } return $rows[0] ?? null; }
	private function quote( array $row ): QuoteStoredRow { try { $quote = QuoteStoredRow::from_row( $row ); if ( $quote->site_id() !== $this->session->site_id() ) { self::fail(); } return $quote; } catch ( \Throwable ) { self::fail(); } }
	private function binding( array $row, QuoteStoredRow $parent ): QuoteBinding { try { return QuoteBinding::from_row( $row, $parent ); } catch ( \Throwable ) { self::fail(); } }
	private function physical_quote( string $uuid, bool $lock ): QuoteStoredRow {
		try { $id = QuoteId::from_string( $uuid ); } catch ( \Throwable ) { self::fail(); } $quote = $this->find_quote( $id, $lock ); if ( null === $quote ) { self::fail(); } return $quote;
	}
	private function budget( array $row, bool $lock ): QuoteBudgetSlot {
		try {
			$parent = null; if ( 'consumed' === ( $row['lease_state'] ?? null ) ) { $parent = $this->find_quote( QuoteStorageCodec::uuid( $row['consumed_quote_uuid'] ?? null ), $lock ); }
			$slot = QuoteBudgetSlot::from_row( $row, $parent ); if ( $slot->site_id() !== $this->session->site_id() ) { self::fail(); } return $slot;
		} catch ( \Throwable ) { self::fail(); }
	}
	private function insert( string $suffix, array $row ): int {
		$this->assert_ready( $row['site_id'] ); unset( $row['id'] ); [ $columns, $formats, $args ] = $this->parameters( $row );
		$sql = $this->session->prepare( "INSERT INTO `{$this->tables[$suffix]}` (" . implode( ',', $columns ) . ') VALUES (' . implode( ',', $formats ) . ')', ...$args );
		try { $affected = $this->session->query( $sql ); $id = $this->session->insert_id(); } catch ( \Throwable ) { self::fail(); }
		if ( 1 !== $affected || $id < 1 ) { self::fail(); } return $id;
	}
	private function replace( string $suffix, array $expected, array $next, array $mutable ): bool {
		$this->assert_ready( $expected['site_id'] ); $values = array_intersect_key( $next, array_fill_keys( $mutable, true ) ); [ $columns, $formats, $args ] = $this->parameters( $values );
		$set = []; foreach ( $columns as $i => $column ) { $set[] = $column . '=' . $formats[$i]; } $where = [];
		foreach ( $expected as $column => $value ) {
			if ( null === $value ) { $where[] = "`{$column}` IS NULL"; }
			else { $where[] = ( is_string( $value ) ? 'BINARY ' : '' ) . "`{$column}`=" . ( is_int( $value ) ? '%d' : '%s' ); $args[] = $value; }
		}
		$sql = $this->session->prepare( "UPDATE `{$this->tables[$suffix]}` SET " . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $where ), ...$args );
		try { $affected = $this->session->query( $sql ); } catch ( \Throwable ) { self::fail(); }
		if ( false === $affected || $affected < 0 || $affected > 1 ) { self::fail(); } return 1 === $affected;
	}
	private function parameters( array $values ): array {
		$columns = []; $formats = []; $args = [];
		foreach ( $values as $column => $value ) { $columns[] = "`{$column}`"; if ( null === $value ) { $formats[] = 'NULL'; } else { $formats[] = is_int( $value ) ? '%d' : '%s'; $args[] = $value; } }
		return [ $columns, $formats, $args ];
	}
	private function immutable( array $a, array $b, array $mutable ): void {
		if ( $a['site_id'] !== $b['site_id'] || $a['id'] !== $b['id'] ) { self::fail(); }
		foreach ( $a as $field => $value ) { if ( ! in_array( $field, $mutable, true ) && $value !== $b[$field] ) { self::fail(); } }
	}
	private static function fail(): never { throw new OperationStorageException(); }
}
