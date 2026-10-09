<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Bootstrap;

use CetechDeliveryEngine\Application\DeliveryQuote\{LegacyFixedBaseQuoteProvider,NativeCartQuoteEnvironment,NativeCartQuotePreparation,PromiseQuotePlacementActivation,ServicePromiseQuoteProvider};
use CetechDeliveryEngine\Application\ServicePromise\Handoff\PromiseNativeCaptureService;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Integrations\ServicePromise\NativePromiseCaptureAuthorizer;

/** Lazy native composition. Resolving the service graph neither reads SQL nor adopts a profile. */
final class PromiseQuoteComposition {
	private ?NativeCartQuoteEnvironment $environment = null;
	private ?PromiseNativeCaptureService $capture = null;
	private ?string $configuration = null;
	public function __construct( private OperationConnectionFactory $factory, private PromiseQuotePlacementActivation $activation ) {}
	public function environment(): NativeCartQuoteEnvironment {
		return $this->environment ??= new NativeCartQuoteEnvironment( $this->factory, profile_selector: [ $this, 'profile' ] );
	}
	public function profile(): string {
		if ( ! $this->activation->requested() ) { return LegacyFixedBaseQuoteProvider::CODE; }
		$configuration = $this->activation->configuration();
		if ( null === $configuration ) { throw new \RuntimeException( 'Required promise adoption is unavailable.' ); }
		$this->install( $configuration );
		return ServicePromiseQuoteProvider::PROFILE;
	}
	/** Saved-order history needs no current capture; new-profile payment requires active configuration. */
	public function capture_service(): ?PromiseNativeCaptureService {
		try {
			$configuration = $this->activation->configuration();
			if ( null === $configuration ) { return null; }
			$this->install( $configuration );
			return $this->capture;
		} catch ( \Throwable ) { return null; }
	}
	private function install( array $configuration ): void {
		$bytes = QuoteJson::encode( [ 'binding' => $configuration['binding']->private_facts(), 'registry' => $configuration['registry']->private_facts(), 'revision' => $configuration['revision'] ], PromiseQuotePlacementActivation::MAX_BYTES );
		if ( null !== $this->configuration ) {
			if ( $this->configuration !== $bytes ) { throw new \RuntimeException( 'Required promise configuration changed.' ); }
			return;
		}
		$environment = $this->environment();
		$capture = new PromiseNativeCaptureService( $configuration['binding'], $this->factory, new NativePromiseCaptureAuthorizer( $environment ) );
		$environment->set_preparation( new NativeCartQuotePreparation( $this->factory, null, $capture, $configuration['registry'] ) );
		$this->capture = $capture; $this->configuration = $bytes;
	}
}
