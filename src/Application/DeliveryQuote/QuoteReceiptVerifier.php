<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\Operation\OperationRecord;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbOperationRecordRepository;

/** Exact producer receipts only, bounded in SQL; no latest-audit/history heuristics. */
final class QuoteReceiptVerifier {
	/** Readers/retention collect all referenced namespaces in one global digest order. */
	public function lock( OperationSession $session, QuoteHeader $header, ?QuoteBinding $binding = null, ?array $purposes = null ): array {
		return $this->collect( $session, $header, $binding, $purposes, true );
	}
	/** Immutable terminal receipts use the same read view; never reverse namespace locks. */
	public function prerequisites( OperationSession $session, QuoteHeader $header, ?QuoteBinding $binding, array $purposes ): array {
		return $this->collect( $session, $header, $binding, $purposes, false );
	}
	private function collect( OperationSession $session, QuoteHeader $header, ?QuoteBinding $binding, ?array $purposes, bool $lock ): array {
		$names = $header->namespace_hashes(); if ( null !== $binding ) { $b = $binding->row(); $names += [ 'bind' => $b['bind_namespace_hash'], 'seal' => $b['seal_namespace_hash'] ]; }
		if ( null !== $purposes ) { $names = array_intersect_key( $names, array_flip( $purposes ) ); }
		asort( $names, SORT_STRING ); $out = []; foreach ( $names as $purpose => $namespace ) { $out[$purpose] = $this->read( $session, $namespace, 'delivery_quote.' . $purpose, $lock ); } return $out;
	}
	public function verify( QuoteStoredRow $quote, array $records, ?QuoteBinding $binding = null ): bool {
		if ( ! $this->link( $records['issue'] ?? null, $quote, 'issue', 1, 'issued' ) ) { return false; }
		// Immutable completions cannot be contradicted by a syntactically valid rollback.
		if ( ( $records['accept'] ?? null ) instanceof OperationRecord && 'accepted' === $records['accept']->state && null === $quote->accepted_at() ) { return false; }
		if ( ( $records['invalidate'] ?? null ) instanceof OperationRecord && 'accepted' === $records['invalidate']->state && ! in_array( $quote->state(), [ 'invalidated', 'stripped' ], true ) ) { return false; }
		if ( null !== $quote->accepted_at() && ! $this->link( $records['accept'] ?? null, $quote, 'accept', 2, 'accepted', $quote->accepted_at()->epoch_microseconds() ) ) { return false; }
		if ( 'accepted' === $quote->state() && null === $quote->accepted_at() ) { return false; }
		if ( 'invalidated' === $quote->state() && ! $this->link( $records['invalidate'] ?? null, $quote, 'invalidate', $quote->revision(), 'invalidated', \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::parse( $quote->row()['transition_at'] )->epoch_microseconds() ) ) { return false; }
		if ( null !== $binding ) {
			if ( ( $records['seal'] ?? null ) instanceof OperationRecord && 'accepted' === $records['seal']->state && 'sealed' !== $binding->state() ) { return false; }
			foreach ( 'sealed' === $binding->state() ? [ 'bind', 'seal' ] : [ 'bind' ] as $purpose ) {
				$r = $records[$purpose] ?? null; $b = $binding->row();
				if ( ! $r instanceof OperationRecord || 'accepted' !== $r->state || ! $this->common( $r, $quote, $b[$purpose . '_namespace_hash'] ) ) { return false; }
				$f = $r->completion->result;
				foreach ( [ 'binding_id' => $binding->id(), 'placement_id' => $b['placement_uuid'], 'order_id' => $b['order_id'], 'manifest_digest' => $b['managed_group_manifest_digest'], 'binding_revision' => 'bind' === $purpose ? 1 : 3, 'state' => 'bind' === $purpose ? 'prepared' : 'sealed' ] as $field => $value ) { if ( $f[$field] !== $value ) { return false; } }
			}
		}
		return true;
	}
	public function issue_verified( QuoteStoredRow $quote, array $records ): bool { return $this->link( $records['issue'] ?? null, $quote, 'issue', 1, 'issued' ); }
	private function link( mixed $record, QuoteStoredRow $quote, string $purpose, int $revision, string $state, ?int $at = null ): bool {
		if ( ! $record instanceof OperationRecord || 'accepted' !== $record->state || ! $this->common( $record, $quote, $quote->header()->namespace_hashes()[$purpose] ) ) { return false; }
		$r = $record->completion->result; return $r['quote_revision'] === $revision && $r['state'] === $state && ( null === $at || $r['completed_at'] === $at );
	}
	private function common( OperationRecord $record, QuoteStoredRow $quote, string $namespace ): bool {
		$r = $record->completion?->result; return null !== $r && $record->site_id === $quote->site_id() && $record->namespace_hash === $namespace && $r['namespace_hash'] === $namespace && $r['quote_id'] === $quote->header()->id()->value() && $r['body_digest'] === $quote->header()->body_digest() && $r['owner_digest'] === $quote->header()->owner()->digest() && $record->completion->publication['site_id'] === $quote->site_id();
	}
	private function read( OperationSession $s, string $namespace, string $operation, bool $lock ): ?OperationRecord {
		if ( ! $s->in_transaction() || $s->is_retired() ) { throw new OperationStorageException(); }
		$table = WpdbOperationRecordRepository::table_name( $s, 'operation_records' ); $events = WpdbOperationRecordRepository::table_name( $s, 'operation_changes' );
		$sql = "SELECT id,site_id,namespace_hash,intent_hash,namespace_format,intent_format,record_format,operation,operation_version,target_hash,state,publication_state,CASE WHEN OCTET_LENGTH(completion_json)<=16384 THEN completion_json ELSE NULL END AS completion_json,audit_id,row_version,created_at,updated_at,completed_at,CASE WHEN completion_json IS NOT NULL AND OCTET_LENGTH(completion_json)>16384 THEN 1 ELSE 0 END AS oversized FROM `{$table}` WHERE site_id=%d AND namespace_hash=%s LIMIT 2" . ( $lock ? ' FOR UPDATE' : '' );
		$rows = $s->get_results( $s->prepare( $sql, $s->site_id(), $namespace ) ); if ( ! is_array( $rows ) || count( $rows ) > 1 ) { throw new OperationStorageException(); } if ( [] === $rows ) { return null; }
		$row = $rows[0]; if ( ! is_array( $row ) || ! array_key_exists( 'oversized', $row ) || '0' !== (string) $row['oversized'] ) { throw new OperationStorageException(); } unset( $row['oversized'] );
		$sql = "SELECT id,site_id,operation_id,event_format,CASE WHEN OCTET_LENGTH(event_json)<=16384 THEN event_json ELSE NULL END AS event_json,created_at,CASE WHEN OCTET_LENGTH(event_json)>16384 THEN 1 ELSE 0 END AS oversized FROM `{$events}` WHERE site_id=%d AND operation_id=%d LIMIT 2";
		$rows = $s->get_results( $s->prepare( $sql, $s->site_id(), WpdbOperationRecordRepository::positive_integer( $row['id'] ) ) ); if ( ! is_array( $rows ) || count( $rows ) > 1 ) { throw new OperationStorageException(); } $event = $rows[0] ?? null;
		if ( null !== $event ) { if ( ! is_array( $event ) || ! array_key_exists( 'oversized', $event ) || '0' !== (string) $event['oversized'] ) { throw new OperationStorageException(); } unset( $event['oversized'] ); }
		try { return OperationRecord::from_row( $row, QuoteOperationProfile::registry()->get( $operation, 1 ), $event ); } catch ( \Throwable ) { throw new OperationStorageException(); }
	}
}
