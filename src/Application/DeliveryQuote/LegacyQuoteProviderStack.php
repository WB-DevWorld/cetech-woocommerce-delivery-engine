<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\Persistence\LegacyQuoteSourceSnapshotReader;

/** Explicit internal stack; no hook, endpoint, writer or default provider registration. */
final class LegacyQuoteProviderStack {
	private QuoteNativeReceiptCapture $native;
	private LegacyQuoteSourceSnapshotReader $reader;
	private QuotePreparationAccess $access;
	public function __construct( private OperationConnectionFactory $factory, ?QuoteNativeReceiptCapture $native = null, ?LegacyQuoteSourceSnapshotReader $reader = null, ?QuotePreparationAccess $access = null ) { $this->native = $native ?? new QuoteNativeReceiptCapture(); $this->reader = $reader ?? new LegacyQuoteSourceSnapshotReader(); $this->access = $access ?? new NativeQuotePreparationAccess( $factory ); }

	/** The production path refreshes every member through the actual retained resolvers. */
	public function prepare_current( QuoteOwner $owner, QuoteContext $server_selection_context, array $legacy_groups ): LegacyQuotePreparedCapture {
		try { $revision = $this->access->observe( $owner ); } catch ( \Throwable ) { $revision = null; }
		if ( null === $revision || $revision < 1 ) { throw new \RuntimeException( 'Delivery quote preparation unavailable.' ); }
		$prepared = $this->finish( $owner, ( new LegacyQuoteNativeSourcePreparer( $this->factory, $this->reader ) )->prepare( $owner, $server_selection_context ), $legacy_groups );
		try { $confirmed = $this->access->confirm( $owner, $revision ); } catch ( \Throwable ) { $confirmed = false; }
		if ( ! $confirmed ) { throw new \RuntimeException( 'Delivery quote preparation unavailable.' ); }
		return $prepared;
	}

	/** Trusted internal physical fixtures may supply a complete explicit source plan. */
	public function prepare( QuoteOwner $owner, QuoteContext $context, LegacyQuoteSourcePlan $plan, array $legacy_groups ): LegacyQuotePreparedCapture {
		if ( ! $owner->equals( $plan->owner() ) || ! hash_equals( $context->digest(), $plan->context()->digest() ) ) { QuoteShape::invalid(); }
		return $this->finish( $owner, $this->read_source( $owner, $context, $plan ), $legacy_groups );
	}

	private function finish( QuoteOwner $owner, LegacyQuoteSourceSnapshot $snapshot, array $legacy_groups ): LegacyQuotePreparedCapture {
		$base = $snapshot->context(); $facts = $base->private_facts();
		if ( count( $legacy_groups ) !== count( $facts['groups'] ) || ! $snapshot->applicable_at( QuoteTime::now() ) ) { QuoteShape::invalid(); }
		$prices = ( new LegacyQuoteEngineCapture() )->capture( $base, $snapshot->active_repository() ); $requests = [];
		foreach ( $prices as $price ) {
			$id = $legacy_groups[$price['component_key']] ?? null; if ( ! is_string( $id ) ) { QuoteShape::invalid(); }
			$requests[] = new QuoteNativeGroupRequest( $price['component_key'], $id, $price['expected'] );
		}
		// The read owner is already retired. Native Woo reads/filters cannot run in it.
		$native = $this->native->capture( $owner, $requests );
		if ( ! $snapshot->applicable_at( QuoteTime::now() ) || ! $native->unchanged() || ! $snapshot->matches( $this->read_source( $owner, $base, $snapshot->plan() ) ) ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); }
		foreach ( $facts['groups'] as &$group ) {
			$group['candidate_digest'] = $snapshot->candidate_digest( $group['offer_id'], $group['destination_zone_id'], $facts['currency']['base'] );
			$group['candidate_count'] = $snapshot->candidate_count( $group['offer_id'], $group['destination_zone_id'], $facts['currency']['base'] );
			$group['policy_digest'] = $snapshot->policy_digest();
		} unset( $group );
		$context = $native->bind_context( QuoteContext::from_array( $facts ) ); $snapshot = $snapshot->bind_context( $context ); $groups = [];
		foreach ( $context->private_facts()['groups'] as $group ) {
			$receipt = $native->group( $group['component_key'] ); $final = $receipt->final_money()->facts();
			$groups[] = [ 'component_key' => $group['component_key'], 'customer_label' => $receipt->customer_label(), 'provider' => [ 'code' => LegacyFixedBaseQuoteProvider::CODE, 'version' => 1 ], 'policy_digest' => $snapshot->policy_digest(), 'list' => $final, 'final' => $final, 'tax' => $receipt->tax_money()->facts(), 'total' => $receipt->total_money()->facts(), 'promotion' => $receipt->promotion(), 'cost' => [ 'state' => 'unavailable', 'reason' => 'cost_provider_unavailable' ], 'route' => [ 'state' => 'not_recorded' ], 'native_tax_receipt' => $receipt->native_tax_receipt(), 'native_money_receipt' => $receipt->native_money_receipt() ];
		}
		return new LegacyQuotePreparedCapture( $owner, $context, QuoteTerms::from_array( [ 'format_version' => 1, 'groups' => $groups ] ), new LegacyQuoteCaptureGuard( $snapshot->guard(), new QuoteNativeReceiptGuard( $native ) ) );
	}

	/** Acknowledged release and retirement precede every native capture/disclosure. */
	private function read_source( QuoteOwner $owner, QuoteContext $context, LegacyQuoteSourcePlan $plan ): LegacyQuoteSourceSnapshot {
		$session = null; $begun = false;
		try {
			$session = $this->factory->open();
			if ( $session->site_id() !== $owner->site_id() || $session->is_retired() || $session->in_transaction() || ! $session->begin() ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } $begun = true;
			if ( ! $session->validate_tables( $plan->tables( $session ) ) ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); }
			$captured = $this->reader->capture( $session, $owner, $context, $plan );
			if ( ! $session->rollback() ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } $begun = false;
			if ( ! $session->retire() ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); }
			return $captured;
		} catch ( \Throwable ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } } }
	}
}
