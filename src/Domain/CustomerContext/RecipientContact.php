<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\CustomerContext;

/**
 * Fulfilment recipient / contact. Frozen on the order. Never used in cart,
 * package, or delivery-group identity.
 */
final class RecipientContact {

	public function __construct(
		public readonly string $first_name,
		public readonly string $last_name,
		public readonly string $company,
		public readonly string $phone
	) {
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	public static function fromInput( array $raw ): self {
		return new self(
			LocationNormalizer::collapse_whitespace( (string) ( $raw['first_name'] ?? '' ) ),
			LocationNormalizer::collapse_whitespace( (string) ( $raw['last_name'] ?? '' ) ),
			LocationNormalizer::collapse_whitespace( (string) ( $raw['company'] ?? '' ) ),
			LocationNormalizer::collapse_whitespace( (string) ( $raw['phone'] ?? $raw['phone_number'] ?? '' ) )
		);
	}

	public function isEmpty(): bool {
		return '' === $this->first_name
			&& '' === $this->last_name
			&& '' === $this->company
			&& '' === $this->phone;
	}

	/**
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return [
			'first_name' => $this->first_name,
			'last_name'  => $this->last_name,
			'company'    => $this->company,
			'phone'      => $this->phone,
		];
	}
}
