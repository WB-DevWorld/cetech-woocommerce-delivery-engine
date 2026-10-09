<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Handoff;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteJson};
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseJson, PromiseShape, ServiceIdentity};

/** Registered native composition input, never parsed from a browser payload. Scope comes from native lines. */
final readonly class PromiseCaptureDemand {
	private function __construct( private array $facts ) {}
	public static function from_native( QuoteContext $base, array $facts ): self {
		$facts = PromiseJson::detach( $facts ); PromiseShape::fields( $facts, [ 'component_key', 'service_kind', 'service_code', 'origin_endpoint', 'origin_kind', 'destination_endpoint', 'destination_kind' ] );
		PromiseShape::digest( $facts['component_key'] ); PromiseShape::id( $facts['origin_endpoint'] ); PromiseShape::choice( $facts['origin_kind'], [ 'origin', 'dispatch', 'port', 'handover', 'pickup', 'doorstep' ] ); PromiseShape::id( $facts['destination_endpoint'] ); PromiseShape::choice( $facts['destination_kind'], [ 'doorstep', 'pickup', 'port', 'handover' ] );
		ServiceIdentity::from_array( [ 'format_version' => 1, 'kind' => $facts['service_kind'], 'code' => $facts['service_code'], 'customer_label' => 'Native service identity' ] );
		$group = self::group( $base, $facts['component_key'] );
		if ( 'known' !== $group['origin']['state'] || ! $base->checkout_acceptable() ) { PromiseShape::invalid(); }
		return new self( $facts );
	}
	public function component_key(): string { return $this->facts['component_key']; }
	public function service_endpoint(): array { return [ 'service_kind' => $this->facts['service_kind'], 'service_code' => $this->facts['service_code'], 'endpoint' => $this->facts['destination_endpoint'], 'endpoint_kind' => $this->facts['destination_kind'] ]; }
	public function origin( QuoteContext $base ): array { $group = self::group( $base, $this->component_key() ); return [ 'endpoint' => $this->facts['origin_endpoint'], 'endpoint_kind' => $this->facts['origin_kind'], 'identity_digest' => hash( 'sha256', 'cetech-promise-native-origin-v1:' . QuoteJson::encode( [ 'origin' => $group['origin'], 'component_key' => $this->component_key() ] ) ) ]; }
	public function destination( QuoteContext $base ): array { $group = self::group( $base, $this->component_key() ); return [ 'endpoint' => $this->facts['destination_endpoint'], 'endpoint_kind' => $this->facts['destination_kind'], 'identity_digest' => $group['endpoint_digest'] ]; }
	private static function group( QuoteContext $base, string $component ): array { foreach ( $base->private_facts()['groups'] as $group ) { if ( $component === $group['component_key'] ) { return $group; } } PromiseShape::invalid(); }
}
