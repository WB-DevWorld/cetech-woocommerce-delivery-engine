<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Application\DeliveryQuote\QuotePrivatePublication;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
/** Reviewed finite descriptors only; no session selection or native object-cache claim. */
final class QuoteLifecycleProofPublication implements QuotePrivatePublication {
 public bool $allow=true; public array $calls=[];
 public function invalidate(int $site_id,string $owner_digest,QuoteId $quote_id):bool { $this->calls[]=['site_id'=>$site_id,'owner_digest'=>$owner_digest,'quote_id'=>$quote_id->value()];return $this->allow; }
}
