<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
/** Trusted server grouping and engine amount, never a posted price. */
final readonly class QuoteNativeGroupRequest implements \JsonSerializable {
 public function __construct(public string $component_key,public string $legacy_group_id,public QuoteMoney $expected_ex_tax) {
  QuoteShape::digest($component_key);
  if (strlen($legacy_group_id)>255 || ''===$legacy_group_id || preg_match('/[\x00-\x1f\x7f<>]/',$legacy_group_id)) { QuoteShape::invalid(); }
 }
 public function jsonSerialize(): never { throw new \LogicException('An authorized native quote projection is required.'); }
 public function __unserialize(array $data): never {throw new \LogicException('Native quote requests cannot be reconstructed generically.');}
 public function __serialize(): never { throw new \LogicException('Native quote requests cannot be serialized generically.'); }
}
