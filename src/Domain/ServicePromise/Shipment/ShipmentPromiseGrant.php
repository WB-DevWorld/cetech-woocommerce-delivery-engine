<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\ServicePromise\Shipment;
use CetechDeliveryEngine\Application\Order\QuoteNativeOrderStageResult;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};

/** Request-local exact native authority plus retired physical capture. Never serialized or stored. */
final readonly class ShipmentPromiseGrant {
 private function __construct(private ShipmentPromiseCommand $command,private array $physical,private bool $hpos){}
 public static function capture(ShipmentPromiseCommand $command,\WC_Order $order,callable $authority,OperationConnectionFactory $factory,OperationReadiness $readiness):self {
  $action=ShipmentPromiseCommand::CREATE===$command->identity->operation?'create':(null!==$command->paid?'payment_confirmed':'update_current');
  if($order->get_id()!==$command->shipment->order_id||true!==$authority($order,$action,$command->actor_user_id)){throw new \RuntimeException('Shipment native authority unavailable.');}
  $order_meta=[];$line_meta=[];foreach([\CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT,\CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION,\CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope::META_FORMAT,\CetechDeliveryEngine\Application\Order\QuoteNativeOrderFacts::META_REFERENCE] as $key){$order_meta[$key]=$order->get_meta($key,true);}foreach($order->get_items('line_item') as $line){foreach([\CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT,\CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION,\CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope::META_FORMAT] as $key){$line_meta[$line->get_id()][$key]=$line->get_meta($key,true);}}$hpos=class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')&&\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();$session=null;
  try{$session=$factory->open();$command->binding->assert_session($session);$readiness->assert_ready($session);if($session->in_transaction()||!$session->begin()){throw new \RuntimeException();}$probe=new self($command,[],$hpos);if(!$session->validate_tables($probe->tables($session))){throw new \RuntimeException();}$physical=QuoteNativeOrderStageResult::read_physical($session,$order->get_id(),$hpos,true);QuoteNativeOrderStageResult::assert_snapshot_rows($physical,$order_meta,$line_meta);if(ShipmentPromiseCommand::CREATE===$command->identity->operation){self::assert_items($command,$physical);}if(!$session->rollback()||!$session->retire()||true!==$authority($order,$action,$command->actor_user_id)){throw new \RuntimeException();}return new self($command,$physical,$hpos);}
  finally{if(null!==$session&&!$session->is_retired()){if($session->in_transaction()){try{$session->rollback();}catch(\Throwable){}}try{$session->retire();}catch(\Throwable){}}}
 }
 private static function assert_items(ShipmentPromiseCommand $command,array $physical):void {
  $items=[];foreach($physical['items'] as $item){if('line_item'===$item['order_item_type']){$items[(int)$item['order_item_id']]=$item;}}$meta=[];foreach($physical['item_meta'] as $item){$meta[(int)$item['order_item_id']][$item['meta_key']][]=$item['meta_value'];}
  $native_group=[];foreach($items as $id=>$item){$snapshot=$meta[$id][\CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT]??[];if([]===$snapshot){continue;}if(1!==count($snapshot)){throw new \RuntimeException('Saved shipment group unavailable.');}$facts=\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::decode($snapshot[0]);if(($facts['delivery_group_id']??null)===$command->shipment->delivery_group_id){\CetechDeliveryEngine\Application\Order\PromiseSnapshotCore::line($facts,$command->envelope);$native_group[]=$id;}}$actual=array_map(static fn($item):int=>$item->order_item_id,$command->items);sort($actual);sort($native_group);if($actual!==$native_group){throw new \RuntimeException('Saved shipment group unavailable.');}
  foreach($command->items as $item){$native=$items[$item->order_item_id]??null;$values=$meta[$item->order_item_id]??[];if(null===$native||$native['order_item_name']!==$item->product_name_snapshot||($values['_qty']??[])!==[(string)$item->quantity]||($values['_product_id']??[])!==[(string)($item->product_id??0)]||($values['_variation_id']??[])!==[(string)($item->variation_id??0)]){throw new \RuntimeException('Saved shipment item identity unavailable.');}}
 }
 public function permits(OperationIdentity $identity,ShipmentPromiseCommand $command):bool{return $command===$this->command&&$identity===$command->identity;}
 public function tables(OperationSession $session):array{$p=$session->table_prefix();return [...[$p.'woocommerce_order_items',$p.'woocommerce_order_itemmeta'],...($this->hpos?[$p.'wc_orders',$p.'wc_orders_meta',$p.'wc_order_addresses',$p.'wc_order_operational_data']:[$p.'posts',$p.'postmeta'])];}
 public function verify(OperationSession $session):bool{try{return $this->physical===QuoteNativeOrderStageResult::read_physical($session,$this->command->shipment->order_id,$this->hpos,true);}catch(\Throwable){return false;}}
 public function __serialize():never{throw new \LogicException('Native shipment grant is request-local.');}
 public function __unserialize(array $data):never{throw new \LogicException('Native shipment grant requires capture.');}
}
