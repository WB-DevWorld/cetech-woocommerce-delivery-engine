<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/** Explicit internal review actions; no checkout placement or passive acceptance. */
interface CartQuoteReviewService {
	public function current( RequestContext $request ): CartQuoteResult;
	public function refresh( string $original_token, int $expected_generation, RequestContext $request ): CartQuoteResult;
	public function confirm( int $expected_generation, RequestContext $request ): CartQuoteResult;
	public function retry( int $expected_generation, RequestContext $request ): CartQuoteResult;
}
