<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\ServicePromise\Shipment;
use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuoteSealLinkage;
use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope;
use CetechDeliveryEngine\Domain\Contracts\{CanonicalIntent,OperationIdentity};
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseShape,PromiseJson};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Domain\Shipment\{Shipment,ShipmentItem};

/** Typed internal command; identity and shape alone never grant native order/staff authority. */
final readonly class ShipmentPromiseCommand implements OperationCommand {
 public const CREATE='shipment.promise.create'; public const UPDATE='shipment.promise.update';
 private function __construct(public OperationIdentity $identity,public PromiseSiteBinding $binding,public Shipment $shipment,public array $items,public ?DeliveryQuoteSnapshotEnvelope $envelope,public ?PromiseQuoteSealLinkage $linkage,public ?ShipmentCurrentPromise $current,public int $expected_revision,public ShipmentEventSource $source,public ?int $actor_user_id,public ?ShipmentPaidEvidence $paid,private CanonicalIntent $canonical){}
 public static function create(OperationIdentity $identity,PromiseSiteBinding $binding,Shipment $draft,array $items,DeliveryQuoteSnapshotEnvelope $envelope,PromiseQuoteSealLinkage $linkage,ShipmentEventSource $source,?int $actor,?ShipmentPaidEvidence $paid=null):self {
  self::identity($identity,$binding,$draft,self::CREATE);if((null===$actor?ShipmentEventSource::System:ShipmentEventSource::Staff)!==$source){PromiseShape::invalid();}if($draft->id!==0||!$envelope->is_promise()||'delivery'!==$draft->fulfilment_choice){PromiseShape::invalid();}
  PromiseShape::list($items,1,600);$itemfacts=[];foreach($items as $item){if(!$item instanceof ShipmentItem||$item->order_id!==$draft->order_id){PromiseShape::invalid();}$itemfacts[]=get_object_vars($item);}
  $facts=get_object_vars($draft);$facts['status']=$draft->status->value;unset($facts['created_at'],$facts['updated_at']);if(null!==$actor){PromiseShape::integer($actor,1);if(null!==$paid){PromiseShape::invalid();}}elseif(null===$paid||$paid->order_id!==$draft->order_id){PromiseShape::invalid();}
  $intent=CanonicalIntent::from_command($identity,['order_id'=>$draft->order_id,'group_hash'=>hash('sha256',$draft->delivery_group_id)],['revision'=>0],['shipment'=>$facts,'items'=>$itemfacts,'envelope_digest'=>hash('sha256',$envelope->to_private_json()),'linkage_digest'=>$linkage->digest(),'source'=>$source->value,'actor'=>$actor,'paid'=>$paid?->private_facts()]);
  return new self($identity,$binding,$draft,$items,$envelope,$linkage,null,0,$source,$actor,$paid,$intent);
 }
 public static function update(OperationIdentity $identity,PromiseSiteBinding $binding,Shipment $shipment,int $revision,ShipmentCurrentPromise $current,?int $actor,?ShipmentPaidEvidence $paid=null):self {
  self::identity($identity,$binding,$shipment,self::UPDATE);PromiseShape::integer($shipment->id,1);PromiseShape::integer($revision,2,PHP_INT_MAX-1);$facts=$current->private_facts();
  if('payment_confirmed'===$facts['source']){if(null===$paid||$paid->order_id!==$shipment->order_id||$paid->paid_at->sql()!==$facts['event_at']||$paid->event_key($shipment->delivery_group_id)!==$facts['event_key']||null!==$actor){PromiseShape::invalid();}}
  else{PromiseShape::integer($actor,1);if(null!==$paid){PromiseShape::invalid();}}
  $intent=CanonicalIntent::from_command($identity,['shipment_id'=>$shipment->id,'order_id'=>$shipment->order_id,'group_hash'=>hash('sha256',$shipment->delivery_group_id)],['revision'=>$revision],['current_digest'=>$current->digest(),'actor'=>$actor,'paid'=>$paid?->private_facts()]);
  return new self($identity,$binding,$shipment,[],null,null,$current,$revision,null===$actor?ShipmentEventSource::System:ShipmentEventSource::Staff,$actor,$paid,$intent);
 }
 public static function target_key(Shipment $shipment):string{return 'shipment-promise:'.$shipment->order_id.':'.hash('sha256',$shipment->delivery_group_id);}
 private static function identity(OperationIdentity $identity,PromiseSiteBinding $binding,Shipment $shipment,string $operation):void{if($identity->site_id!==$binding->native_site_id()||$identity->operation!==$operation||1!==$identity->operation_version||$identity->target_key!==self::target_key($shipment)){PromiseShape::invalid();}}
 public function intent():CanonicalIntent{return $this->canonical;}
 public function __serialize():never{throw new \LogicException('Private shipment command serialization forbidden.');}
}
