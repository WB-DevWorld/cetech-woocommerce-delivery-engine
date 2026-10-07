<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdmissionGate;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableService;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProviderRegistry;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;

/** Explicit internal fixture wiring; no production Plugin or shopper adopter. */
final class QuoteLifecycleProofStack {
	public readonly QuoteLifecycleProofFactory $factory;
	public readonly QuoteLifecycleProofProvider $provider;
	public readonly QuoteAdmissionGate $gate;
	public readonly QuoteDurableService $service;
	public readonly QuoteLifecycleProofPublication $publication;
	public function __construct( string $prefix, int $clock = QuoteLifecycleProofFactory::NOW, ?callable $configure = null, ?OperationPhaseObserver $observer = null, ?callable $authorize = null ) {
		$this->factory = new QuoteLifecycleProofFactory( $prefix ); $this->factory->clock = $clock;
		$this->factory->configure = null === $configure ? null : \Closure::fromCallable( $configure );
		$this->provider = new QuoteLifecycleProofProvider();
		$this->publication = new QuoteLifecycleProofPublication();
		$authorize ??= static fn( QuoteOwner $owner, string $operation ): bool => 1 === $owner->site_id() && str_starts_with( $operation, 'delivery_quote.' );
		$this->gate = new QuoteAdmissionGate( $this->factory, $authorize, null, new EmergencyControlStore( static fn(): bool => false ) );
		$this->service = new QuoteDurableService( $this->factory, new QuoteProviderRegistry( [ $this->provider ] ), $authorize, $this->gate, null, $observer, new EmergencyControlStore( static fn(): bool => false ), new QuoteLifecycleProofEvidence(), $this->publication );
	}
	public static function command( string $token = 'original', ?QuoteOwner $owner = null, ?QuoteContext $context = null ): QuoteIssueCommand {
		return QuoteIssueCommand::create( $owner ?? QuoteFixtures::owner(), $context ?? QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $token );
	}
}
