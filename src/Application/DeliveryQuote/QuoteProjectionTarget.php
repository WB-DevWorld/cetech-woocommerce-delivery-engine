<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use JsonSerializable;
use LogicException;

/** Exact server-resolved private quote identity. A target never grants access. */
final class QuoteProjectionTarget implements JsonSerializable {
	private function __construct(
		private readonly int $site,
		private readonly string $quote,
		private readonly string $profile,
		private readonly int $profile_version,
		private readonly string $owner_digest,
		private readonly string $body_digest,
		private readonly string $material_digest,
		private readonly string $header_digest,
	) {}

	public static function from_header( QuoteHeader $header ): self {
		return new self( $header->owner()->site_id(), $header->id()->value(), $header->profile(), $header->profile_version(),
			$header->owner()->digest(), $header->body_digest(), $header->material_digest(), hash( 'sha256', $header->to_private_json() ) );
	}

	public function site_id(): int { return $this->site; }
	public function quote_id(): string { return $this->quote; }
	public function profile(): string { return $this->profile; }
	public function profile_version(): int { return $this->profile_version; }

	public function equals( self $other ): bool {
		return $this->site === $other->site && $this->quote === $other->quote && $this->profile === $other->profile
			&& $this->profile_version === $other->profile_version
			&& hash_equals( $this->owner_digest, $other->owner_digest )
			&& hash_equals( $this->body_digest, $other->body_digest )
			&& hash_equals( $this->material_digest, $other->material_digest )
			&& hash_equals( $this->header_digest, $other->header_digest );
	}

	public function matches_header( QuoteHeader $header ): bool { return $this->equals( self::from_header( $header ) ); }

	public function jsonSerialize(): never { throw new LogicException( 'Private quote target requires an authorized projection.' ); }
}
