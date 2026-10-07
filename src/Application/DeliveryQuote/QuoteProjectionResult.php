<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/** Detached finite projections. Private admin facts require explicit export. */
final class QuoteProjectionResult implements JsonSerializable {
	public const CONTRACT_VERSION = 1;
	private function __construct( private readonly string $purpose, private readonly array $fields ) {}

	public static function shopper( DeliveryQuote $quote, QuoteTime $at, RequestContext $request ): self {
		$reason = self::reason( $quote->reason_at( $at ) );
		return new self( 'shopper', [
			'contract_version' => self::CONTRACT_VERSION, 'decision_kind' => match ( $quote->header()->purpose() ) {
				'checkout' => 'delivery_quote', 'estimate' => 'delivery_estimate', default => self::invalid(),
			},
			'quote_id' => $quote->header()->id()->value(), 'status' => self::status( $quote->current_status( $at ) ),
			'currently_applicable' => $quote->usable_at( $at ), 'expires_at' => $quote->header()->expires_at()->iso_utc(),
			'customer_label' => self::label( $quote->customer_label() ), 'money' => self::money( $quote ),
			'reason_code' => $reason, 'recovery_action' => self::recovery( $reason ), 'correlation_id' => $request->correlation_id,
		] );
	}

	/** No quote ID, owner, material fingerprint, private provenance or money. */
	public static function diagnostic( DeliveryQuote $quote, QuoteTime $at, RequestContext $request ): self {
		$reason = self::reason( $quote->reason_at( $at ) );
		return new self( 'diagnostic', [
			'contract_version' => self::CONTRACT_VERSION, 'operation' => 'delivery_quote.read',
			'evaluated_at' => $at->iso_utc(), 'stored_state' => self::status( $quote->state() ),
			'current_status' => self::status( $quote->current_status( $at ) ), 'currently_applicable' => $quote->usable_at( $at ),
			'reason_code' => $reason, 'recovery_action' => self::recovery( $reason ), 'revision' => $quote->revision(),
			'correlation_id' => $request->correlation_id,
		] );
	}

	/** Internal final exporter; QuoteProjection supplies only currently authorized sections. */
	public static function admin( QuoteHeader $header, QuoteProjectionTarget $target, RequestContext $request, array $sections ): self {
		if ( ! $target->matches_header( $header ) || count( $sections ) > 3 || ! array_is_list( $sections ) ) { self::invalid(); }
		$private = [];
		foreach ( $sections as $section ) {
			if ( ! $section instanceof QuotePrivateSection || ! $section->matches( $target ) || isset( $private[ $section->section() ] ) ) { self::invalid(); }
			$private[ $section->section() ] = $section->private_fields();
		}
		return new self( 'admin', [
			'contract_version' => self::CONTRACT_VERSION, 'site_id' => $header->owner()->site_id(), 'quote_id' => $header->id()->value(),
			'profile' => $header->profile(), 'profile_version' => $header->profile_version(), 'header_revision' => $header->revision(),
			'created_at' => $header->created_at()->iso_utc(), 'expires_at' => $header->expires_at()->iso_utc(),
			'sections' => $private, 'correlation_id' => $request->correlation_id,
		] );
	}

	public function purpose(): string { return $this->purpose; }
	public function fields(): array { return $this->fields; }
	public function jsonSerialize(): array {
		if ( 'admin' === $this->purpose ) { throw new LogicException( 'Private quote explanation requires explicit authorized export.' ); }
		return $this->fields;
	}

	private static function money( DeliveryQuote $quote ): array {
		$terms = $quote->terms();
		if ( null === $terms ) {
			if ( 'stripped' !== $quote->state() ) { self::invalid(); }
			return [];
		}
		$components = $terms->display_money();
		if ( ! array_is_list( $components ) || count( $components ) < 1 || count( $components ) > 200 ) { self::invalid(); }
		$output = [];
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || array_diff( array_keys( $component ), [ 'component_key', 'customer_label', 'list', 'promotion', 'final', 'tax', 'rounded_tax', 'total', 'display_total' ] )
				|| count( $component ) !== 9 ) { self::invalid(); }
			$output[] = [ 'customer_label' => self::label( $component['customer_label'] ),
				'list_price' => self::money_fields( $component['list'] ), 'promotion' => self::promotion( $component['promotion'] ),
				'final_price' => self::money_fields( $component['final'] ), 'tax' => self::money_fields( $component['tax'] ),
				'rounded_tax' => ! isset( $component['rounded_tax'] ) ? null : self::money_fields( $component['rounded_tax'] ),
				'total' => self::money_fields( $component['total'] ),
				'display_total' => null === $component['display_total'] ? null : self::money_fields( $component['display_total'] ),
			];
		}
		return $output;
	}

	private static function money_fields( mixed $money ): array {
		if ( ! is_array( $money ) ) { self::invalid(); }
		return QuoteMoney::from_array( $money )->facts();
	}

	private static function promotion( mixed $promotion ): array {
		if ( ! is_array( $promotion ) || count( $promotion ) !== 2 || array_diff( [ 'state', 'amount' ], array_keys( $promotion ) )
			|| ! in_array( $promotion['state'], [ 'none', 'applied', 'unavailable' ], true ) ) { self::invalid(); }
		if ( 'unavailable' === $promotion['state'] ) {
			if ( null !== $promotion['amount'] ) { self::invalid(); }
			return [ 'state' => 'unavailable', 'amount' => null ];
		}
		return [ 'state' => $promotion['state'], 'amount' => self::money_fields( $promotion['amount'] ) ];
	}

	private static function label( mixed $label ): string {
		if ( ! is_string( $label ) || '' === trim( $label ) || strlen( $label ) > 120 || 1 !== preg_match( '//u', $label )
			|| preg_match( '/[\x00-\x1f\x7f<>]/', $label ) ) { self::invalid(); }
		return $label;
	}

	private static function status( string $status ): string {
		if ( ! in_array( $status, [ 'issued', 'accepted', 'invalidated', 'expired', 'stripped' ], true ) ) { self::invalid(); }
		return $status;
	}
	private static function reason( ?string $reason ): ?string {
		if ( null !== $reason && ! in_array( $reason, [ 'quote_expired', 'quote_invalidated', 'quote_unavailable' ], true ) ) { self::invalid(); }
		return $reason;
	}
	private static function recovery( ?string $reason ): ?string {
		return match ( $reason ) { 'quote_expired', 'quote_invalidated' => 'refresh_and_review', 'quote_unavailable' => 'retry_later', default => null };
	}
	private static function invalid(): never { throw new InvalidArgumentException( 'Invalid quote projection facts.' ); }
}
