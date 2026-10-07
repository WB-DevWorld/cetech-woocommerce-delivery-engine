<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
final readonly class QuoteRetentionResult implements \JsonSerializable {
 public function __construct(public OperationAttemptResult $attempt,public QuoteRetentionRequest $request,public ?QuoteRetentionCheckpoint $checkpoint=null) { if(null!==$checkpoint&&!in_array($attempt->completion?->state,['accepted','not_applicable'],true)) { throw new \InvalidArgumentException('Unconfirmed retention checkpoint.'); } }
 public function safe():array { return ['state'=>$this->attempt->outcome->state,'complete'=>$this->checkpoint?->complete()??false,'progress'=>$this->checkpoint?->safe()]; }
 public function __serialize():array { throw new \LogicException('An explicit quote retention projection is required.'); }
 public function __unserialize(array $data):void { throw new \LogicException('Quote retention results require validated completion facts.'); }
 public function jsonSerialize():never { throw new \LogicException('An explicit quote retention projection is required.'); }
}
