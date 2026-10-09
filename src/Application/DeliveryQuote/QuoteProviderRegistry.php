<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;

/** No discovery, plugin wildcards, business defaults or live provider registration. */
final class QuoteProviderRegistry {
	private array $providers = [];

	/** @param list<QuoteProviderInterface> $providers */
	public function __construct( array $providers = [] ) {
		QuoteShape::list( $providers, 16 );
		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof QuoteProviderInterface ) { QuoteShape::invalid(); }
			$code = QuoteShape::machine( $provider->code() ); $version = QuoteShape::integer( $provider->version(), 1, 1000000 );
			$profile = QuoteShape::machine( $provider->profile() ); $profile_version = QuoteShape::integer( $provider->profile_version(), 1, 1000000 );
			$source = $provider->evidence_providers(); $evidence = [];
			if ( count( $source ) > 16 ) { QuoteShape::invalid(); }
			foreach ( $source as $name => $versions ) { QuoteShape::machine( $name ); QuoteShape::list( $versions, 16, 1 ); $detached = []; foreach ( $versions as $v ) { $detached[] = QuoteShape::integer( $v, 1, 1000000 ); } if ( count( array_unique( $detached ) ) !== count( $detached ) ) { QuoteShape::invalid(); } $evidence[$name] = $detached; }
			$key = self::key( $code, $version, $profile, $profile_version ); if ( isset( $this->providers[$key] ) ) { QuoteShape::invalid(); }
			$this->providers[$key] = [ 'provider' => $provider, 'code' => $code, 'version' => $version, 'evidence' => $evidence ];
		}
	}

	public function get( string $code, int $version, string $profile, int $profile_version ): QuoteProviderInterface {
		return $this->entry( $code, $version, $profile, $profile_version )['provider'];
	}

	public function capture( string $code, int $version, string $profile, int $profile_version, QuoteContext $context ): QuoteTerms {
		$entry = $this->entry( $code, $version, $profile, $profile_version );
		$terms = QuoteTerms::from_json( $entry['provider']->capture( $context )->to_private_json() );
		if ( ServicePromiseQuoteProvider::PROFILE === $profile && ( LegacyFixedBaseQuoteProvider::CODE !== $code || 1 !== $version || 1 !== $profile_version || 2 !== $context->format_version() || 2 !== $terms->format_version() ) ) { QuoteShape::invalid(); }
		$captured = $context->private_facts(); $groups = []; foreach ( $captured['groups'] as $group ) { $groups[$group['component_key']] = $group; }
		$facts = $terms->private_facts(); if ( count( $facts['groups'] ) !== count( $groups ) ) { QuoteShape::invalid(); }
		foreach ( $facts['groups'] as $term ) {
			$component = $groups[$term['component_key']] ?? null;
			if ( null === $component || $term['policy_digest'] !== $component['policy_digest'] || $term['provider'] !== [ 'code' => $entry['code'], 'version' => $entry['version'] ] ) { QuoteShape::invalid(); }
			foreach ( [ 'list', 'final', 'tax', 'total' ] as $field ) {
				if ( $term[$field]['currency'] !== $captured['currency']['charged'] ) { QuoteShape::invalid(); }
			}
			foreach ( [ 'promotion', 'cost', 'route' ] as $field ) {
				if ( isset( $term[$field]['provider'] ) && ! in_array( $term[$field]['provider']['version'], $entry['evidence'][$term[$field]['provider']['code']] ?? [], true ) ) { QuoteShape::invalid(); }
			}
			foreach ( [ 'promotion', 'cost' ] as $field ) {
				if ( isset( $term[$field]['amount'] ) && $term[$field]['amount']['currency'] !== $captured['currency']['charged'] ) { QuoteShape::invalid(); }
			}
			if ( 'recorded' === $term['native_tax_receipt']['state'] && $term['native_tax_receipt']['context_digest'] !== $captured['tax']['context_digest'] ) { QuoteShape::invalid(); }
			if ( 'recorded' === $term['native_money_receipt']['state'] && $term['native_money_receipt']['evidence_digest'] !== $captured['tax']['native_money_digest'] ) { QuoteShape::invalid(); }
		}
		return $terms;
	}

	private function entry( string $code, int $version, string $profile, int $profile_version ): array {
		$key = self::key( QuoteShape::machine( $code ), QuoteShape::integer( $version, 1, 1000000 ), QuoteShape::machine( $profile ), QuoteShape::integer( $profile_version, 1, 1000000 ) );
		if ( ! isset( $this->providers[$key] ) ) { QuoteShape::invalid(); }
		return $this->providers[$key];
	}
	private static function key( string $code, int $version, string $profile, int $profile_version ): string { return $code . '/' . $version . '/' . $profile . '/' . $profile_version; }
}
