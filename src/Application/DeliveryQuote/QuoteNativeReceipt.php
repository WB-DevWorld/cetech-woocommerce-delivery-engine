<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
/** Captured native facts plus a pure request-state fence. No arbitrary public dump. */
final readonly class QuoteNativeReceipt implements \JsonSerializable {
 private QuoteOwner $owner;private QuoteNativeState $state;private array $groups;private array $members;private QuoteNativeCaptureSource $source;private string $context_digest;private string $money_digest;
 public function __construct(QuoteOwner $owner,QuoteNativeState $state,array $groups,array $members,QuoteNativeCaptureSource $source,string $context_digest,string $money_digest) {
  foreach($groups as $key=>$group){QuoteShape::digest($key);if(!$group instanceof QuoteNativeGroupReceipt){QuoteShape::invalid();}}
  QuoteShape::digest($context_digest);QuoteShape::digest($money_digest);$this->owner=$owner;$this->state=$state;$this->groups=array_map(static fn(QuoteNativeGroupReceipt $group):QuoteNativeGroupReceipt=>$group,$groups);$this->members=QuoteJson::detach(['members'=>$members])['members'];$this->source=$source;$this->context_digest=$context_digest;$this->money_digest=$money_digest;
 }
 public function currency_facts():array{$s=$this->state->facts();return ['base'=>$s['currency'],'presentment'=>$s['currency'],'charged'=>$s['currency'],'precision'=>$s['display_precision']];}
 public function tax_facts():array{return ['context_digest'=>$this->context_digest,'native_money_digest'=>$this->money_digest];}
 public function destination_facts():array{$s=$this->state->facts();return ['kind'=>'full','digest'=>$s['destination_digest'],'key_epoch'=>$s['key_epoch']];}
 public function group(string $component_key):QuoteNativeGroupReceipt{if(!isset($this->groups[$component_key])){QuoteShape::invalid();}return $this->groups[$component_key];}
 public function guard():QuoteCurrentEvidenceGuard{return new QuoteNativeReceiptGuard($this);}
 public function matches_owner(QuoteOwner $owner):bool{return $this->owner->equals($owner);}
 public function source_facts():array{return $this->state->facts()['source_facts'];}
 public function tax_source():QuoteNativeTaxSource{return QuoteNativeTaxSource::from_native_state($this->state);}
 public function bind_context(QuoteContext $context):QuoteContext {
  $facts=$context->private_facts();
  if(QuoteJson::encode($facts['currency'])!==QuoteJson::encode($this->currency_facts())||QuoteJson::encode($facts['destination'])!==QuoteJson::encode($this->destination_facts())||$this->owner->key_epoch()!==$facts['destination']['key_epoch']||count($facts['groups'])!==count($this->groups)){QuoteShape::invalid();}
  $lines=[];foreach($facts['lines'] as $line){$lines[$line['line_key']]=$line;}
  foreach($facts['groups'] as $group){$key=$group['component_key'];if(!isset($this->members[$key])){QuoteShape::invalid();}$actual=[];foreach($this->members[$key] as $member){$line=$lines[$member['line_key']]??null;if(null===$line||$line['component_key']!==$key||$line['product_id']!==$member['product_id']||$line['variation_id']!==$member['variation_id']||self::decimal($line['quantity'])!==self::decimal($member['quantity'])){QuoteShape::invalid();}$actual[]=$member['line_key'];}sort($actual,SORT_STRING);$expected=$group['line_keys'];sort($expected,SORT_STRING);if($actual!==$expected){QuoteShape::invalid();}}
  $facts['tax']=$this->tax_facts();return QuoteContext::from_array($facts);
 }
 private static function decimal(string $value):string{$out=rtrim(rtrim($value,'0'),'.');return str_contains($value,'.')?(''===$out?'0':$out):$value;}
 /** Native objects/session and registered monetary effects are prewarmed. No IO here. */
 public function unchanged():bool {return $this->source->unchanged();}
 public function jsonSerialize():never{throw new \LogicException('An authorized native quote projection is required.');}
 public function __serialize():never{throw new \LogicException('Native quote receipts cannot be serialized generically.');}
 public function __unserialize(array $data):never{throw new \LogicException('Native quote receipts cannot be reconstructed generically.');}
}
