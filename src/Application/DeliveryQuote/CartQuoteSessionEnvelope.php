<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;

/** Private original command, not a quote body, monetary cache, guard or capture capability. */
final readonly class CartQuoteSessionEnvelope implements \JsonSerializable {
	public const MAX_BYTES = 131072;
	public const MAX_GENERATION = 9007199254740991;
	public const PHASES = [ 'preparing', 'staged', 'issued', 'accepting', 'confirmed', 'failed' ];
	private function __construct( private array $facts ) {}
	public static function begin( QuotePreparationCommand $command, int $generation, int $expires_at ): self {
		return self::from_private_array( [ 'format_version' => 1, 'generation' => $generation, 'revision' => 1, 'expires_at' => $expires_at, 'phase' => 'preparing', 'preparation' => $command->to_private_array(), 'issue_context_json' => null, 'issue_intent_digest' => null, 'header_json' => null, 'reference' => null, 'rate_references' => [] ] );
	}
	public static function from_private_array( array $facts ): self {
		QuoteShape::fields( $facts, [ 'format_version', 'generation', 'revision', 'expires_at', 'phase', 'preparation', 'issue_context_json', 'issue_intent_digest', 'header_json', 'reference', 'rate_references' ] );
		QuoteShape::integer( $facts['format_version'], 1, 1 ); QuoteShape::integer( $facts['generation'], 1, self::MAX_GENERATION ); QuoteShape::integer( $facts['revision'], 1, self::MAX_GENERATION ); QuoteShape::integer( $facts['expires_at'] ); QuoteShape::choice( $facts['phase'], self::PHASES );
		$preparation = QuotePreparationCommand::from_private_array( QuoteShape::object( $facts['preparation'] ) );
		$present = null !== $facts['header_json'];
		foreach ( [ 'issue_context_json', 'issue_intent_digest', 'reference' ] as $field ) { if ( ( null !== $facts[$field] ) !== $present ) { QuoteShape::invalid(); } }
		if ( ! $present && ! in_array( $facts['phase'], [ 'preparing', 'failed' ], true ) ) { QuoteShape::invalid(); }
		if ( $present && 'preparing' === $facts['phase'] ) { QuoteShape::invalid(); }
		$rate_refs = QuoteShape::list( $facts['rate_references'], 200 ); if ( ! $present && [] !== $rate_refs ) { QuoteShape::invalid(); }
		if ( $present ) {
			if ( ! is_string( $facts['header_json'] ) || ! is_string( $facts['issue_context_json'] ) ) { QuoteShape::invalid(); }
			$header = QuoteHeader::from_json( $facts['header_json'] ); $reference = QuoteReference::from_array( QuoteShape::object( $facts['reference'] ) ); $context = QuoteContext::from_json( $facts['issue_context_json'] );
			$original = QuoteIssueCommand::create( $preparation->owner(), $context, $preparation->provider_code(), $preparation->provider_version(), $preparation->profile(), $preparation->profile_version(), $preparation->original_token() );
			QuoteShape::digest( $facts['issue_intent_digest'] );
			if ( ! hash_equals( $facts['issue_intent_digest'], $original->intent_digest() ) || ! $preparation->matches_issue( $original ) || ! $header->owner()->equals( $preparation->owner() ) || ! $header->matches_reference( $reference ) || $header->namespace_hashes() != $original->namespace_hashes( $header->id() ) || $header->material_digest() !== $context->digest() || $header->profile() !== $preparation->profile() || $header->profile_version() !== $preparation->profile_version() || $header->to_private_json() !== $facts['header_json'] || $context->to_private_json() !== $facts['issue_context_json'] ) { QuoteShape::invalid(); }
			$components = []; foreach ( $context->private_facts()['groups'] as $group ) { $components[$group['component_key']] = true; } $covered = []; $handles = [];
			foreach ( $rate_refs as $row ) { $ref = CartQuoteRateReference::from_private_array( QuoteShape::object( $row ) ); $key = $ref->component_key(); $handle = $ref->public_fields()['component_handle']; if ( ! isset( $components[$key] ) || isset( $covered[$key] ) || isset( $handles[$handle] ) || ! $ref->matches( $header, $key, $facts['generation'] ) ) { QuoteShape::invalid(); } $covered[$key] = true; $handles[$handle] = true; }
			if ( count( $covered ) !== count( $components ) ) { QuoteShape::invalid(); }
		}
		$json = self::encode( $facts ); return new self( json_decode( $json, true, 16, JSON_THROW_ON_ERROR ) );
	}
	public static function from_private_json( string $json ): self {
		if ( strlen( $json ) > self::MAX_BYTES ) { QuoteShape::invalid(); }
		try { $facts = json_decode( $json, true, 16, JSON_THROW_ON_ERROR ); if ( ! is_array( $facts ) ) { QuoteShape::invalid(); } $self = self::from_private_array( $facts ); if ( $self->to_private_json() !== $json ) { QuoteShape::invalid(); } return $self; } catch ( \Throwable ) { QuoteShape::invalid(); }
	}
	public function stage( QuoteDurableCommand $captured ): self {
		$original = $captured->original_issue(); $header = $captured->header(); $reference = $captured->reference();
		if ( 'preparing' !== $this->phase() || null === $captured->capture() || null === $original || null === $header || null === $reference || ! $this->preparation()->matches_issue( $original ) ) { QuoteShape::invalid(); }
		$rate_refs = []; foreach ( $original->context()->private_facts()['groups'] as $group ) { $rate_refs[] = CartQuoteRateReference::generate( $header, $group['component_key'], $this->generation() )->to_private_array(); }
		return self::from_private_array( array_replace( $this->facts, [ 'revision' => $this->revision() + 1, 'phase' => 'staged', 'issue_context_json' => $original->context()->to_private_json(), 'issue_intent_digest' => $original->intent_digest(), 'header_json' => $header->to_private_json(), 'reference' => $reference->public_fields(), 'rate_references' => $rate_refs ] ) );
	}
	public function with_phase( string $phase ): self {
		$allowed = match ( $this->phase() ) { 'preparing' => [ 'failed' ], 'staged' => [ 'issued', 'failed' ], 'issued' => [ 'accepting', 'failed' ], 'accepting' => [ 'confirmed', 'issued', 'failed' ], default => [] };
		if ( ! in_array( $phase, $allowed, true ) ) { QuoteShape::invalid(); }
		return self::from_private_array( array_replace( $this->facts, [ 'revision' => $this->revision() + 1, 'phase' => $phase ] ) );
	}
	public function owner(): QuoteOwner { return $this->preparation()->owner(); }
	public function generation(): int { return $this->facts['generation']; }
	public function revision(): int { return $this->facts['revision']; }
	public function expires_at(): int { return $this->facts['expires_at']; }
	public function phase(): string { return $this->facts['phase']; }
	public function pending(): bool { return in_array( $this->phase(), [ 'preparing', 'staged', 'accepting' ], true ); }
	public function preparation(): QuotePreparationCommand { return QuotePreparationCommand::from_private_array( $this->facts['preparation'] ); }
	public function original_issue(): ?QuoteIssueCommand { $p = $this->preparation(); return null === $this->facts['issue_context_json'] ? null : QuoteIssueCommand::create( $p->owner(), QuoteContext::from_json( $this->facts['issue_context_json'] ), $p->provider_code(), $p->provider_version(), $p->profile(), $p->profile_version(), $p->original_token() ); }
	public function header(): ?QuoteHeader { return null === $this->facts['header_json'] ? null : QuoteHeader::from_json( $this->facts['header_json'] ); }
	public function reference(): ?QuoteReference { return null === $this->facts['reference'] ? null : QuoteReference::from_array( $this->facts['reference'] ); }
	public function rate_references(): array { return array_map( static fn( array $facts ): CartQuoteRateReference => CartQuoteRateReference::from_private_array( $facts ), $this->facts['rate_references'] ); }
	public function to_private_json(): string { return self::encode( $this->facts ); }
	public function follows( ?self $old ): bool {
		if ( null === $old ) { return 1 === $this->generation() && 1 === $this->revision() && 'preparing' === $this->phase(); }
		if ( ! $old->owner()->equals( $this->owner() ) ) { return false; }
		if ( $this->generation() === $old->generation() + 1 ) { return ! $old->pending() && 1 === $this->revision() && 'preparing' === $this->phase(); }
		if ( $this->generation() !== $old->generation() || $this->revision() !== $old->revision() + 1 || $this->expires_at() !== $old->expires_at() || $this->facts['preparation'] !== $old->facts['preparation'] ) { return false; }
		if ( 'preparing' === $old->phase() && 'staged' === $this->phase() ) { return null === $old->header() && null !== $this->header(); }
		try { return $old->with_phase( $this->phase() )->to_private_json() === $this->to_private_json(); } catch ( \Throwable ) { return false; }
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Original cart quote credentials require explicit private storage.' ); }
	public function __serialize(): never { throw new \LogicException( 'Original cart quote credentials cannot be serialized generically.' ); }
	private static function encode( array $facts ): string { ksort( $facts, SORT_STRING ); $json = json_encode( $facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); if ( strlen( $json ) > self::MAX_BYTES ) { QuoteShape::invalid(); } return $json; }
}
