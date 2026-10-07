<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
/** No cached quote is authoritative. Invalidation failure preserves durable acceptance. */
final class QuoteAdvisoryPublication implements QuotePrivatePublication {
	public const GROUP = 'cetech_de_quote_private_v1';
	private ?\Closure $delete; private ?\Closure $get;
	/** Test/native adapter seams remain delete/read only; no put/replace operation exists. */
	public function __construct( ?callable $delete = null, ?callable $get = null ) {
		if ( ( null === $delete ) !== ( null === $get ) ) { throw new \InvalidArgumentException( 'Incomplete private cache adapter.' ); }
		$this->delete = null === $delete ? null : \Closure::fromCallable( $delete ); $this->get = null === $get ? null : \Closure::fromCallable( $get );
	}
	public function invalidate( int $site_id, string $owner_digest, QuoteId $quote_id ): bool {
		try {
			QuoteShape::integer( $site_id ); QuoteShape::digest( $owner_digest );
			if ( null === $this->delete && ( ! function_exists( 'wp_cache_delete' ) || ! function_exists( 'wp_cache_get' ) ) ) { return false; }
			$key = self::key( $site_id, $owner_digest, $quote_id );
			if ( null === $this->delete ) { wp_cache_delete( $key, self::GROUP ); } else { ( $this->delete )( $key, self::GROUP ); }
			$found = null; if ( null === $this->get ) { wp_cache_get( $key, self::GROUP, true, $found ); } else { ( $this->get )( $key, self::GROUP, true, $found ); } return false === $found;
		} catch ( \Throwable ) { return false; }
	}
	public static function key( int $site_id, string $owner_digest, QuoteId $quote_id ): string { QuoteShape::integer( $site_id ); QuoteShape::digest( $owner_digest ); return hash( 'sha256', 'cetech-private-quote-invalidation-v1:' . $site_id . ':' . $owner_digest . ':' . $quote_id->value() ); }
}
