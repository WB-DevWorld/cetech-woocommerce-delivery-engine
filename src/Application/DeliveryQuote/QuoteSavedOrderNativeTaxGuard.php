<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteContext,QuoteJson,QuoteOwner};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Captured outside locks; final verification contains fixed SQL and pure registration checks only. */
final readonly class QuoteSavedOrderNativeTaxGuard implements QuoteCurrentEvidenceGuard {
	public function __construct( private QuoteOwner $owner, private QuoteContext $context, private array $physical, private QuoteSavedOrderAuthorization $authorization, private QuotePlacementSavedEvidenceGuard $saved, private QuoteBinding $binding ) {}
	public function tables( OperationSession $session ): array {
		$selectors = $this->physical['selectors']; if ( $selectors['site_id'] !== $session->site_id() || $selectors['table_prefix'] !== $session->table_prefix() ) { throw new \RuntimeException( 'Saved quote evidence unavailable.' ); } $p = $session->table_prefix();
		return array_values( array_unique( [ $p . 'options', $p . 'woocommerce_tax_rates', $p . 'wc_tax_rate_classes', $p . 'woocommerce_tax_rate_locations', $p . 'woocommerce_shipping_zone_methods', $p . 'woocommerce_sessions', $p . 'usermeta', ...$this->saved->tables( $session ) ] ) );
	}
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool {
		try {
			if ( ! $owner->equals( $this->owner ) || ! hash_equals( $context->digest(), $this->context->digest() ) || ! $this->authorization->unchanged() || ! QuoteSavedOrderNativeEvidence::hooks_supported() ) { return false; }
			$current = QuoteNativeReceiptGuard::read_current( $this->physical['selectors'], $session );
			return hash_equals( QuoteJson::encode( $this->physical ), QuoteJson::encode( $current ) ) && $this->saved->verify( $session, $this->binding ) && $this->authorization->unchanged() && QuoteSavedOrderNativeEvidence::hooks_supported();
		} catch ( \Throwable ) { return false; }
	}
}
