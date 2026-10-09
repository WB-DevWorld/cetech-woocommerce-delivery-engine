<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Operation\{DatabaseOperationReadiness,OperationReadiness};
use CetechDeliveryEngine\Application\Order\{OrderDeliveryPackageReadResult,OrderDeliverySnapshotReader,QuoteNativeOrderFacts,QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteId,QuoteJson,QuoteOwner,QuoteReference};
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository,EmergencyControlStore};

/** Native exact-order access precedes all private quote resolution, including empty-cart order-pay. */
final class QuoteSavedOrderPlacementEvidenceReader {
	private \Closure $native_authorizer;
	private OperationReadiness $readiness;
	public function __construct( private OperationConnectionFactory $factory, private QuoteNativeOrderStager $stager, callable $authorize_order, ?OperationReadiness $readiness = null, private ?EmergencyControlStore $control = null ) { $this->native_authorizer = \Closure::fromCallable( $authorize_order ); $this->readiness = $readiness ?? new DatabaseOperationReadiness(); }
	/** Historical binding state after native exact-order authorization; this is never new admission. */
	public function read_binding( \WC_Order $order ): ?QuoteBinding {
		$session = null; $begun = false;
		try {
			$authorization = QuoteSavedOrderAuthorization::capture( $order, $this->native_authorizer ); $id = $order->get_id(); if ( ! is_int( $id ) || $id < 1 ) { return null; }
			$private_reference = $order->get_meta( QuoteNativeOrderFacts::META_REFERENCE, true ); if ( ! is_string( $private_reference ) || '' === $private_reference || strlen( $private_reference ) > 4096 ) { return null; }
			$reference = QuoteReference::from_array( QuoteJson::decode( $private_reference, 4096 ) );
			$session = $this->factory->open(); if ( $session->is_retired() || $session->in_transaction() || ! $session->begin() ) { return null; } $begun = true; $this->readiness->assert_ready( $session );
			$repository = new DeliveryQuoteRepository( $session ); $quote = $repository->find_quote( $reference->id() ); if ( null === $quote || 'accepted' !== $quote->state() || ! $quote->header()->matches_reference( $reference ) || ! $authorization->unchanged() ) { return null; }
			$binding = $repository->find_binding( $quote ); if ( null === $binding || $binding->row()['order_id'] !== $id || ! $authorization->unchanged() ) { return null; }
			$verifier = new QuoteReceiptVerifier(); $receipts = $verifier->lock( $session, $quote->header(), $binding ); $locked_quote = $repository->find_quote( $reference->id(), true ); $locked_binding = null === $locked_quote ? null : $repository->find_binding( $locked_quote, true );
			if ( null === $locked_quote || null === $locked_binding || $locked_quote->row() !== $quote->row() || $locked_binding->row() !== $binding->row() || ! $verifier->verify( $locked_quote, $receipts, $locked_binding ) || ! $authorization->unchanged() || ! $session->rollback() ) { return null; } $begun = false;
			if ( ! $session->retire() || true !== ( $this->native_authorizer )( $order ) || ! $authorization->unchanged() ) { return null; } return $binding;
		} catch ( \Throwable ) { return null; }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } try { $session->retire(); } catch ( \Throwable ) {} } }
	}
	public function read( \WC_Order $order, RequestContext $request ): ?QuotePlacementEvidence {
		$session = null; $begun = false;
		try {
			$authorization = QuoteSavedOrderAuthorization::capture( $order, $this->native_authorizer );
			$id = $order->get_id(); if ( ! is_int( $id ) || $id < 1 || $order->is_paid() ) { return null; }
			$package = ( new OrderDeliverySnapshotReader() )->read_package( $order );
			if ( OrderDeliveryPackageReadResult::ERROR_NONE !== $package->error || 'recorded' !== $package->delivery_quote?->status || null === $package->delivery_quote->envelope ) { return null; }
			$captured = $package->delivery_quote->envelope->private_facts();
			$session = $this->factory->open(); if ( $session->is_retired() || $session->in_transaction() || ! $session->begin() ) { return null; } $begun = true; $this->readiness->assert_ready( $session );
			$control = $this->control ?? new EmergencyControlStore(); $control->assert_ready( $session, $session->site_id() ); if ( ! $session->validate_tables( [ $control->options_table( $session ) ] ) || ! $control->current( $session )->enabled() ) { return null; }
			$repository = new DeliveryQuoteRepository( $session ); $quote = $repository->find_quote( QuoteId::from_string( $captured['quote_id'] ) );
			if ( null === $quote || 'accepted' !== $quote->state() || null === $quote->context() || null === $quote->terms() || ! $quote->header()->valid_at( QuoteOperationProfile::time( $session ) ) || ! $authorization->unchanged() ) { return null; }
			$binding = $repository->find_binding( $quote );
			if ( null === $binding || 'sealed' !== $binding->state() || $binding->row()['order_id'] !== $id || $binding->row()['placement_uuid'] !== $captured['placement_id'] || $quote->header()->body_digest() !== $captured['body_digest'] || $quote->header()->material_digest() !== $captured['material_digest'] || $binding->row()['context_digest'] !== $captured['context_digest'] ) { return null; }
			if ( ! $session->rollback() ) { return null; } $begun = false; if ( ! $session->retire() ) { return null; }
			if ( true !== ( $this->native_authorizer )( $order ) || ! $authorization->unchanged() ) { return null; }
			$private_reference = $order->get_meta( QuoteNativeOrderFacts::META_REFERENCE, true );
			if ( ! is_string( $private_reference ) || strlen( $private_reference ) > 4096 ) { return null; }
			$reference = QuoteReference::from_array( QuoteJson::decode( $private_reference, 4096 ) ); if ( ! $quote->header()->matches_reference( $reference ) ) { return null; }
			$draft = $this->stager->load_draft( $order, $quote->header()->owner() ); $tax_source = $this->stager->load_tax_source( $order ); $saved = $this->stager->saved_guard( $order, $quote, $binding );
			$current = ( new QuoteSavedOrderNativeEvidence( $this->factory ) )->capture( $order, $quote, $binding, $draft, $authorization, $saved, $tax_source ); if ( null === $current ) { return null; }
			$owner = $quote->header()->owner(); $authorize = static function( QuoteOwner $requested, string $operation ) use ( $owner, $authorization ): bool { return $requested->equals( $owner ) && in_array( $operation, [ 'delivery_quote.read', 'delivery_quote.bind', 'delivery_quote.verify_binding', 'delivery_quote.seal' ], true ) && $authorization->unchanged(); };
			$read = ( new QuoteDurableService( $this->factory, new QuoteProviderRegistry(), $authorize, null, $this->readiness, null, $this->control, $current->guard ) )->current( $owner, $reference, $current->current_context, $request );
			if ( 'ready' !== $read->status || null !== $read->reason || null === $read->quote || null === $read->evaluated_at || $read->quote->row() !== $quote->row() || true !== ( $this->native_authorizer )( $order ) || ! $authorization->unchanged() ) { return null; }
			return new QuotePlacementEvidence( $read->quote, $reference, $quote->header(), $current->current_context, $current->guard, $authorize, $read->evaluated_at, $draft, $binding, $saved, $tax_source );
		} catch ( \Throwable ) { return null; }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } try { $session->retire(); } catch ( \Throwable ) {} } }
	}
}
