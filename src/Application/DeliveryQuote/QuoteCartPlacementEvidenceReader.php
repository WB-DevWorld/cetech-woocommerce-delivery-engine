<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;

/** Resolves the private confirmed session only; shopper fields and request IDs are never authority. */
final class QuoteCartPlacementEvidenceReader {
	public function __construct( private CartQuoteEnvironment $environment, private CartQuoteSessionStore $sessions, private OperationConnectionFactory $factory, private ?OperationReadiness $readiness = null, private ?EmergencyControlStore $control = null, private ?QuotePrivatePublication $publication = null ) {}
	public function current( RequestContext $request ): ?QuotePlacementEvidence {
		try {
			$draft = $this->environment->draft();
			if ( null === $draft || ! $this->environment->authorize( $draft->owner(), 'delivery_quote.read' ) ) { return null; }
			$envelope = $this->sessions->load( $draft->owner() );
			if ( null === $envelope || 'confirmed' !== $envelope->phase() || ! $envelope->owner()->equals( $draft->owner() ) || ! hash_equals( $envelope->preparation()->draft_digest(), $draft->draft_digest() ) ) { return null; }
			$original = $envelope->original_issue(); $header = $envelope->header(); $reference = $envelope->reference();
			if ( null === $original || null === $header || null === $reference || ! $header->owner()->equals( $draft->owner() ) || ! $header->matches_reference( $reference ) || ! hash_equals( $header->material_digest(), $original->context()->digest() ) ) { return null; }
			$current = $this->environment->evidence( $original, $header, $draft );
			if ( null === $current || ! $this->environment->authorize( $draft->owner(), 'delivery_quote.read' ) ) { return null; }
			$service = new QuoteDurableService( $this->factory, new QuoteProviderRegistry(), [ $this->environment, 'authorize' ], null, $this->readiness, null, $this->control, $current->guard, $this->publication );
			$read = $service->current( $draft->owner(), $reference, $current->current_context, $request );
			$fresh = $this->environment->draft(); $fresh_envelope = $this->sessions->load( $draft->owner() );
			if ( 'ready' !== $read->status || null !== $read->reason || null === $read->quote || null === $read->evaluated_at || $read->evaluated_at->epoch_microseconds() >= $envelope->expires_at() * 1000000 || null === $fresh || ! $fresh->owner()->equals( $draft->owner() ) || ! hash_equals( $draft->draft_digest(), $fresh->draft_digest() ) || $fresh_envelope?->to_private_json() !== $envelope->to_private_json() || ! $this->environment->authorize( $draft->owner(), 'delivery_quote.read' ) ) { return null; }
			$tax_source = $current->guard instanceof QuoteNativeTaxEvidenceGuard ? $current->guard->tax_source() : null; if ( $this->environment instanceof NativeCartQuoteEnvironment && null === $tax_source ) { return null; }
			return new QuotePlacementEvidence( $read->quote, $reference, $header, $current->current_context, $current->guard, [ $this->environment, 'authorize' ], $read->evaluated_at, $draft, native_tax_source: $tax_source );
		} catch ( \Throwable ) { return null; }
	}
}
