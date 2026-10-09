<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Handoff;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteTerms};

/** Detached output only. Issue/admission still require native grants, adoption and current owned fences. */
final readonly class PromiseNativeCapture {
	public function __construct( private QuoteContext $base, private string $site_key, private array $captures, private array $packets ) {}
	public function context_captures(): array { return $this->captures; }
	public function promise_groups(): array { return $this->packets; }
	public function context(): QuoteContext { return QuoteContext::from_base_promises( $this->base, $this->site_key, $this->captures ); }
	public function terms( QuoteTerms $base_terms ): QuoteTerms { return QuoteTerms::from_base_promises( $base_terms, $this->packets ); }
}
