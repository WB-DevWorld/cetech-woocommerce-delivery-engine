<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Application\Order\{OrderDeliverySnapshot,QuoteNativeOrderFacts};

/** Separate immutable policy writer, authorized only by the native original journal. */
final class PolicyNativeOrderStager {
 private array$latch=[];
 public function __construct(private readonly OperationConnectionFactory$factory){}
 public function bind_line(string$key,\WC_Order_Item_Product$item):void{if($key===''||strlen($key)>256||isset($this->latch[$key])&&$this->latch[$key]!==$item)PolicyNativeOrderFacts::fail();$this->latch[$key]=$item;}
 /** @return array{stage:PolicyNativeOrderStage,prepared:CustomerPolicyPreparedCheckout} */
 public function stage(\WC_Order$order,CustomerPolicyPreparedCheckout$prepared,callable$authorize):array{
  if(true!==$authorize())PolicyNativeOrderFacts::fail();$native=PolicyNativeOrderFacts::native($order,true);$legacy=PolicyNativeOrderFacts::legacy($order);$binding=$prepared->binding();
  if(null===$binding){$binding=$this->binding($order,$prepared,$native,$legacy);}
  $before=PolicyNativeOrderStage::capture($this->factory,$order,$prepared,$binding,'before_write',$authorize);
  $prepared=$prepared->bind_native($before);$plan=PolicyNativeOrderStage::derive_plan($prepared,$binding);
  $guard=function()use($order,$native,$legacy,$authorize):void{if(true!==$authorize())PolicyNativeOrderFacts::fail();$commerce=QuoteNativeOrderFacts::native($order);$baseline=$native;foreach(['site_id','order_id','status','paid','paid_at','transaction_id']as$k)unset($baseline[$k]);if($commerce!==$baseline||$order->is_paid()||null!==$order->get_date_paid('edit')||$order->get_transaction_id('edit')!=='')PolicyNativeOrderFacts::fail();if($order->get_meta(OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT,true)!==$legacy['package'])PolicyNativeOrderFacts::fail();foreach($order->get_items('line_item')as$id=>$item)if(($legacy['lines'][(int)$id]??null)!==$item->get_meta(OrderDeliverySnapshot::META_LINE_SNAPSHOT,true))PolicyNativeOrderFacts::fail();};
  foreach($order->get_items('line_item')as$id=>$item){$guard();$this->missing($item,$plan['items'][(int)$id],$guard);$guard();}
  $this->missing($order,$plan['order'],$guard);$guard();$stage=PolicyNativeOrderStage::capture($this->factory,$order,$prepared,$binding,'staged',$authorize);return['stage'=>$stage,'prepared'=>$prepared];
 }
 private function binding(\WC_Order$order,CustomerPolicyPreparedCheckout$p,array$native,array$legacy):array{
  $items=$order->get_items('line_item');$originals=$p->lines();if(count($items)!==count($originals)||count($this->latch)!==count($originals))PolicyNativeOrderFacts::fail();$seen=[];$lines=[];
  foreach($originals as$key=>$original){$item=$this->latch[$key]??null;if(!$item instanceof \WC_Order_Item_Product||$item->get_id()<1||!isset($items[$item->get_id()])||$items[$item->get_id()]!==$item||isset($seen[$item->get_id()])||$item->get_order_id('edit')!==$order->get_id())PolicyNativeOrderFacts::fail();$seen[$item->get_id()]=true;$lines[]=['cart_key'=>$key,'item_id'=>$item->get_id(),'line_uuid'=>$original['line_uuid'],'line'=>PolicyNativeOrderFacts::witness($legacy['lines'][$item->get_id()])];}
  usort($lines,static fn($a,$b)=>$a['item_id']<=>$b['item_id']);$b=['format'=>1,'site_id'=>$p->site_id(),'order_id'=>$order->get_id(),'order_key'=>$native['order_key'],'route'=>$p->route(),'hpos'=>PolicyNativeOrderFacts::hpos(),'native_digest'=>PolicyNativeOrderFacts::digest($native),'package'=>PolicyNativeOrderFacts::witness($legacy['package']),'lines'=>$lines,'manifest_digest'=>'','packet_digests'=>[]];$plan=PolicyNativeOrderStage::derive_plan($p,$b);$b['manifest_digest']=$plan['manifest']->digest();foreach($plan['packets']as$id=>$packet)$b['packet_digests'][]=['item_id'=>$id,'sha256'=>$packet->digest()];return$b;
 }
 private function missing(object$object,array$expected,callable$guard):void{
  $rows=PolicyNativeOrderFacts::metadata($object);$found=[];foreach($rows as$row){if(!PolicyNativeOrderFacts::own_key($row['key']))continue;if(isset($found[$row['key']])||!array_key_exists($row['key'],$expected)||$row['value']!==$expected[$row['key']])PolicyNativeOrderFacts::fail();$found[$row['key']]=true;}$missing=array_diff_key($expected,$found);if($missing===[])return;$guard();foreach($missing as$key=>$value)$object->add_meta_data($key,$value,false);$guard();$object->save();$guard();$actual=PolicyNativeOrderFacts::metadata($object);foreach($expected as$key=>$value)if(PolicyNativeOrderFacts::values($actual,$key)!==[$value])PolicyNativeOrderFacts::fail();
 }
}
