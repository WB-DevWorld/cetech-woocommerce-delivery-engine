<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\ServicePromise\Shipment;
use CetechDeliveryEngine\Application\ServicePromise\Shipment\ShipmentPromiseOperationProfile;
use CetechDeliveryEngine\Domain\Operation\OperationRecord;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseJson,PromiseShape};
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseSiteBinding,PromiseStorageCodec};
use CetechDeliveryEngine\Infrastructure\Persistence\ShipmentPromiseSchema;

/** Strict private durable row and both acknowledged C03 authority backlinks. */
final readonly class ShipmentPromiseStored implements \JsonSerializable {
 private function __construct(private array $row,private PromiseHistoricalPacket $packet,private ?ShipmentCurrentPromise $current){}
 public static function from_row(array $row,PromiseSiteBinding $binding):self {
  $detached=[];foreach($row as $key=>$value){if(!is_scalar($value)&&null!==$value){PromiseShape::invalid();}$detached[$key]=$value;}$row=$detached;PromiseShape::fields($row,array_keys(ShipmentPromiseSchema::columns()));$binding->assert_row($row);foreach(['id','site_id','shipment_id','order_id','revision'] as $field){$row[$field]=PromiseStorageCodec::integer($row[$field],1);}if($row['revision']<2){PromiseShape::invalid();}
  if(!is_string($row['delivery_group_id'])||''===$row['delivery_group_id']||strlen($row['delivery_group_id'])>191||1!==preg_match('/\A[\x21-\x7e]+\z/D',$row['delivery_group_id'])){PromiseShape::invalid();}
  foreach(['original_digest','packet_digest','original_namespace_hash','original_intent_hash'] as $field){PromiseShape::digest($row[$field]);}foreach(['original_json','packet_json'] as $field){PromiseStorageCodec::bytes($row[$field],65536);}PromiseShape::instant($row['created_at']);$updated=PromiseShape::instant($row['updated_at']);if($updated->compare(PromiseShape::instant($row['created_at']))<0){PromiseShape::invalid();}
  if(!hash_equals($row['original_digest'],hash('sha256','cetech-shipment-original-promise-reference-v1:'.$row['original_json']))){PromiseShape::invalid();}$original=PromiseJson::decode($row['original_json'],65536);if(PromiseJson::encode($original,65536)!==$row['original_json']){PromiseShape::invalid();}
  $packet=PromiseHistoricalPacket::from_json($row['packet_json']);if($packet->input_facts()['site_id']!==$binding->site_key()||$packet->digest()!==$row['packet_digest']){PromiseShape::invalid();}self::assert_original($original,$row,$packet);
  $current=null;if(null===$row['current_json']){if(2!==$row['revision']||$row['updated_at']!==$row['created_at']||null!==$row['current_digest']||null!==$row['current_namespace_hash']||null!==$row['current_intent_hash']){PromiseShape::invalid();}}
  else{foreach(['current_digest','current_namespace_hash','current_intent_hash'] as $field){PromiseShape::digest($row[$field]);}$current=ShipmentCurrentPromise::from_json(PromiseStorageCodec::bytes($row['current_json'],65536));if($row['revision']<3||$current->digest()!==$row['current_digest']||$current->private_facts()['original_packet_digest']!==$row['packet_digest']){PromiseShape::invalid();}}
  return new self($row,$packet,$current);
 }
 /** The private original is a closed carrier, even before its receipt is loaded. */
 private static function assert_original(array $original,array $row,PromiseHistoricalPacket $packet):void {
  PromiseShape::fields($original,['format','shipment','promise']);PromiseShape::integer($original['format'],1,1);
  $shipment=PromiseShape::object($original['shipment']);PromiseShape::fields($shipment,['shipment_id','order_id','delivery_group_id']);PromiseShape::integer($shipment['shipment_id'],1);PromiseShape::integer($shipment['order_id'],1);
  if(PromiseJson::encode($shipment)!==PromiseJson::encode(['shipment_id'=>$row['shipment_id'],'order_id'=>$row['order_id'],'delivery_group_id'=>$row['delivery_group_id']])){PromiseShape::invalid();}
  $promise=PromiseShape::object($original['promise']);PromiseShape::fields($promise,['format','component_key','input_digest','result_digest','historical_packet_digest','result_state','commitment_state','original','quote','snapshot','final_event','linkage_digest']);PromiseShape::integer($promise['format'],1,1);
  foreach(['component_key','input_digest','result_digest','historical_packet_digest','linkage_digest'] as $field){PromiseShape::digest($promise[$field]);}PromiseShape::choice($promise['result_state'],['absolute_window','relative_window','unavailable','ineligible']);PromiseShape::choice($promise['commitment_state'],['accepted','recorded_refusal']);
  $public=PromiseShape::object($promise['original']);PromiseShape::fields($public,['views','customer_text']);
  $quote=PromiseShape::object($promise['quote']);PromiseShape::fields($quote,['site_id','quote_id','body_digest','promise_packet_digest']);PromiseShape::integer($quote['site_id'],1);foreach(['body_digest','promise_packet_digest'] as $field){PromiseShape::digest($quote[$field]);}self::uuid($quote['quote_id']);
  $snapshot=PromiseShape::object($promise['snapshot']);PromiseShape::fields($snapshot,['order_id','placement_id','snapshot_digest','context_digest']);PromiseShape::integer($snapshot['order_id'],1);self::uuid($snapshot['placement_id']);foreach(['snapshot_digest','context_digest'] as $field){PromiseShape::digest($snapshot[$field]);}
  $event=PromiseShape::object($promise['final_event']);PromiseShape::fields($event,['kind','occurred_at','source_receipt_digest']);PromiseShape::choice($event['kind'],['q06_placement_sealed']);PromiseShape::digest($event['source_receipt_digest']);$sealed=PromiseShape::instant($event['occurred_at']);if($sealed->sql()!==$event['occurred_at']){PromiseShape::invalid();}
  $input=$packet->input_facts();$result=$packet->result_facts();$saved=$packet->private_facts();$component=hash('sha256','cetech-cart-quote-component-v1:'.$row['delivery_group_id']);$accepted=in_array($result['state'],['absolute_window','relative_window'],true);
  if($promise['component_key']!==$component||$input['material']['group_id']!==$component||$promise['historical_packet_digest']!==$packet->digest()||PromiseJson::encode($public)!==PromiseJson::encode($packet->public_facts())||($accepted?'accepted':'recorded_refusal')!==$promise['commitment_state']||$promise['result_state']!==$result['state']||(!$accepted&&$input['policy']['promise_required'])||$quote['site_id']!==$row['site_id']||$snapshot['order_id']!==$row['order_id']||$promise['input_digest']!==$saved['input_digest']||$promise['result_digest']!==$saved['result_digest']){PromiseShape::invalid();}
 }
 private static function uuid(mixed $value):void {if(!is_string($value)){PromiseShape::invalid();}QuoteId::from_string($value);}
 public function row():array{return $this->row;}
 public function packet():PromiseHistoricalPacket{return $this->packet;}
 public function current():?ShipmentCurrentPromise{return $this->current;}
 public function original_public():array{$facts=PromiseJson::decode($this->row['original_json'],65536);return $facts['promise']['original'];}
 /** Loader must return exactly one finite original record/event for the supplied namespace. */
 public function assert_receipts(callable $loader):void {
  foreach(['original','current'] as $role){if('current'===$role&&null===$this->current){continue;}[$row,$event]=$loader($this->row[$role.'_namespace_hash']);$profile=ShipmentPromiseOperationProfile::receipt_profile('original'===$role?ShipmentPromiseCommand::CREATE:ShipmentPromiseCommand::UPDATE);$record=OperationRecord::from_row($row,$profile,$event);$result=$record->completion?->result;$expected='original'===$role?2:$this->row['revision'];
   $target_key='shipment-promise:'.$this->row['order_id'].':'.hash('sha256',$this->row['delivery_group_id']);if('accepted'!==$record->state||'none'!==$record->publication_state||$record->namespace_hash!==$this->row[$role.'_namespace_hash']||$record->intent_hash!==$this->row[$role.'_intent_hash']||$record->target_hash!==hash('sha256','cetech-operation-target-v1:'.$target_key)||($result['shipment_id']??null)!==$this->row['shipment_id']||($result['order_id']??null)!==$this->row['order_id']||($result['original_digest']??null)!==$this->row['original_digest']||($result['packet_digest']??null)!==$this->row['packet_digest']||($result['revision']??null)!==$expected||$record->event?->after_revision!==$expected||$record->event?->target['group_hash']!==hash('sha256',$this->row['delivery_group_id'])||($result['accepted_at']??null)!==\CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::parse($this->row['original'===$role?'created_at':'updated_at'])->epoch_microseconds()){PromiseShape::invalid();}
   if('current'===$role&&(($result['current_digest']??null)!==$this->row['current_digest']||$record->event?->reason_code!==$this->current->private_facts()['source'])){PromiseShape::invalid();}
  }
 }
 public function jsonSerialize():never{throw new \LogicException('Authorized shipment promise projection required.');}
 public function __serialize():never{throw new \LogicException('Private shipment promise row cannot be serialized.');}
 public function __unserialize(array $data):never{throw new \LogicException('Private shipment promise row requires strict decoding.');}
}
