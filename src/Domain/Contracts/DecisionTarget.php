<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

/** Internal target identity. Possession of this value grants no read authority. */
final readonly class DecisionTarget {

	public function __construct(
		public int $site_id,
		public int $product_id,
		public ?int $variation_id = null,
		public ?string $slice_key = null
	) {
		if ( $this->site_id < 1 || $this->product_id < 1 || ( null !== $this->variation_id && $this->variation_id < 1 )
			|| ( null !== $this->slice_key && '' !== $this->slice_key && 1 !== preg_match( '/\A[a-z][a-z0-9_-]{0,63}\z/D', $this->slice_key ) )
		) {
			throw new \InvalidArgumentException( 'Decision target identity is invalid.' );
		}
	}

	public function equals( self $other ): bool {
		return $this->site_id === $other->site_id
			&& $this->product_id === $other->product_id
			&& $this->variation_id === $other->variation_id
			&& $this->slice_key === $other->slice_key;
	}

	/** Site is separately included when matching authorization and read context. */
	public function key(): string {
		return 'product:' . $this->product_id
			. ( null !== $this->variation_id ? ':variation:' . $this->variation_id : '' )
			. ( null !== $this->slice_key ? ':slice:' . $this->slice_key : '' );
	}
}
