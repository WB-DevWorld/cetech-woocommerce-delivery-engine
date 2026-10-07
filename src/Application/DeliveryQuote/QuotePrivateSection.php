<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/** Finite target-bound facts loaded independently under their own current grant. */
final class QuotePrivateSection implements JsonSerializable {
	public const SECTIONS = [ 'cost', 'origin', 'rate' ];
	private const UNAVAILABLE_REASONS = [ 'not_recorded', 'unknown', 'source_unavailable', 'unsupported_provider', 'cost_provider_unavailable', 'unsupported_context' ];

	private function __construct( private readonly QuoteProjectionTarget $target, private readonly string $section, private readonly array $facts ) {}

	public static function cost_known( QuoteProjectionTarget $target, QuoteMoney $money, string $provider, int $version ): self {
		self::provider( $provider, $version );
		return new self( $target, 'cost', [ 'status' => 'known', 'money' => $money->facts(), 'provider' => $provider, 'provider_version' => $version ] );
	}

	public static function unavailable( QuoteProjectionTarget $target, string $section, string $reason ): self {
		if ( ! in_array( $section, self::SECTIONS, true ) || ! in_array( $reason, self::UNAVAILABLE_REASONS, true ) ) { self::invalid(); }
		return new self( $target, $section, [ 'status' => 'unavailable', 'reason_code' => $reason ] );
	}

	public static function origin_recorded( QuoteProjectionTarget $target, ?int $supplier_id, ?int $origin_id, ?int $profile_id ): self {
		foreach ( [ $supplier_id, $origin_id, $profile_id ] as $id ) { if ( null !== $id && $id < 1 ) { self::invalid(); } }
		return new self( $target, 'origin', [ 'status' => 'recorded', 'supplier_id' => $supplier_id, 'origin_id' => $origin_id, 'profile_id' => $profile_id ] );
	}

	public static function rate_recorded( QuoteProjectionTarget $target, int $rate_card_id, int $zone_id, string $policy_digest, string $provider, int $version ): self {
		self::provider( $provider, $version );
		if ( $rate_card_id < 1 || $zone_id < 1 || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $policy_digest ) ) { self::invalid(); }
		return new self( $target, 'rate', [ 'status' => 'recorded', 'rate_card_id' => $rate_card_id, 'destination_zone_id' => $zone_id,
			'policy_digest' => $policy_digest, 'provider' => $provider, 'provider_version' => $version ] );
	}

	public function section(): string { return $this->section; }
	public function matches( QuoteProjectionTarget $target ): bool { return $this->target->equals( $target ); }
	public function private_fields(): array { return $this->facts; }
	public function jsonSerialize(): never { throw new LogicException( 'Private quote section requires an authorized projection.' ); }

	private static function provider( string $provider, int $version ): void {
		if ( $version < 1 || 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/D', $provider ) ) { self::invalid(); }
	}
	private static function invalid(): never { throw new InvalidArgumentException( 'Invalid private quote section.' ); }
}
