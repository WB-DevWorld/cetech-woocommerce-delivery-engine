<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteOwner,QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository,DeliveryQuoteSchema};

/** Acknowledged read-only proof allowing explicit new review to retire one terminal original pointer. */
final readonly class QuotePlacementNoEffectDisposition {
	private \Closure $authorizer;
	public function __construct( private OperationConnectionFactory $factory, private OperationReadiness $readiness, callable $authorize ) { $this->authorizer = \Closure::fromCallable( $authorize ); }
	public function known( QuotePlacementEvidence $replacement, QuoteStoredRow $original, int $order_id, string $placement_id, ?QuoteBinding $expected, QuotePlacementNoEffectEvidenceGuard $native ): bool {
		$owner = $replacement->owner(); $session = null; $begun = false;
		try {
			if ( ! $this->authorized( $owner ) || ! $replacement->authorize( $owner, 'delivery_quote.read' ) || ! $owner->equals( $original->header()->owner() )
				|| $replacement->header()->id()->equals( $original->header()->id() ) || null === $original->accepted_at() || null === $original->context() || null === $original->terms()
				|| $order_id < 1 || $native->site_id() !== $owner->site_id() || $native->order_id() !== $order_id || ! $native->unchanged()
				|| $placement_id !== QuotePlacementService::placement_id_for( $owner, $original->header() ) ) { return false; }
			if ( null !== $expected && ( 'prepared' !== $expected->state() || 1 !== $expected->revision() || $expected->row()['order_id'] !== $order_id
				|| $expected->row()['placement_uuid'] !== $placement_id || QuoteBinding::from_row( $expected->row(), $original )->row() !== $expected->row() ) ) { return false; }
			$session = $this->factory->open();
			if ( $session->site_id() !== $owner->site_id() || $session->is_retired() || $session->in_transaction() ) { return false; }
			$this->readiness->assert_ready( $session ); if ( ! $session->begin() ) { return false; } $begun = true;
			$tables = array_values( array_unique( [ ...DeliveryQuoteSchema::tables( $session->table_prefix() ), ...$native->tables( $session ), ...$replacement->guard()->tables( $session ) ] ) );
			if ( ! $session->validate_tables( $tables ) ) { return false; }
			$repository = new DeliveryQuoteRepository( $session ); $found = $repository->find_quote( $original->header()->id() ); $next = $repository->find_quote( $replacement->header()->id() );
			if ( null === $found || null === $next || $found->row() !== $original->row() || $next->row() !== $replacement->quote_record()->row() ) { return false; }
			$binding = $repository->find_binding( $found );
			if ( ! self::same_binding( $binding, $expected ) || null !== $repository->find_binding( $next ) ) { return false; }
			$verifier = new QuoteReceiptVerifier(); $receipts = $verifier->lock_original_disposition( $session, $original->header(), $placement_id, $replacement->header() );
			// The immutable namespace set precedes native participants and quote target locks.
			if ( ! $native->unchanged() || ! $native->verify( $session, $found, $binding ) || ! $replacement->guard()->verify( $session, $owner, $replacement->current_context() ) ) { return false; }
			$ids = [ $original->header()->id()->value() => $original->header()->id(), $replacement->header()->id()->value() => $replacement->header()->id() ]; ksort( $ids, SORT_STRING ); $locked = [];
			foreach ( $ids as $key => $id ) { $locked[$key] = $repository->find_quote( $id, true ); }
			$found = $locked[$original->header()->id()->value()]; $next = $locked[$replacement->header()->id()->value()];
			if ( null === $found || null === $next || $found->row() !== $original->row() || $next->row() !== $replacement->quote_record()->row() || 'accepted' !== $next->state()
				|| ! $next->header()->matches_reference( $replacement->reference() ) || ! $next->header()->valid_at( QuoteOperationProfile::time( $session ) ) ) { return false; }
			$current = $repository->find_binding( $found, true );
			if ( ! self::same_binding( $current, $expected ) || null !== $repository->find_binding( $next, true ) || ! $verifier->verify( $next, $receipts['replacement'] )
				|| ! $verifier->original_placement_rejected( $found, $receipts['original'], $placement_id, $current ) || ! $native->verify( $session, $found, $current )
				|| ! $replacement->guard()->verify( $session, $owner, $replacement->current_context() ) || ! $native->unchanged() || $session->is_retired() || ! $session->rollback() ) { return false; }
			$begun = false;
			return $session->retire() && $session->is_retired() && $native->unchanged() && $this->authorized( $owner ) && $replacement->authorize( $owner, 'delivery_quote.read' );
		} catch ( \Throwable ) { return false; }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } } }
	}
	private static function same_binding( ?QuoteBinding $a, ?QuoteBinding $b ): bool { return ( null === $a ) === ( null === $b ) && ( null === $a || $a->row() === $b->row() ); }
	private function authorized( QuoteOwner $owner ): bool { try { return true === ( $this->authorizer )( $owner, 'delivery_quote.read' ); } catch ( \Throwable ) { return false; } }
}
