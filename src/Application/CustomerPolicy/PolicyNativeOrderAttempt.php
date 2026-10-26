<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy;

use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyNativePaymentLocalBinding;

/** Request-local initial-write permission; its permanent marker is refusal-only. */
final class PolicyNativeOrderAttempt implements \JsonSerializable {
 private bool $active=true;
 private function __construct(private readonly PolicyNativeOrderStager$issuer,private readonly \WC_Order$order,private readonly CustomerPolicyPreparedCheckout$prepared,private readonly array$latch){}
 public static function begin(PolicyNativeOrderStager$issuer,\WC_Order$order,CustomerPolicyPreparedCheckout$p,array$latch):self{
  if($p->state()!=='frozen'||$p->binding()!==null||($GLOBALS['blog_id']??null)!==$p->site_id()||!in_array($p->route(),['classic','store_api'],true))self::fail();
  if($p->route()==='classic'?$order->get_id()!==0:$order->get_status('edit')!=='checkout-draft')self::fail();
  $claim=new self($issuer,$order,$p,$latch);$claim->native();if(self::policy_rows($order)!==[])self::fail();foreach($latch as$item)if(self::policy_rows($item)!==[])self::fail();
  $order->add_meta_data(PolicyNativeOrderFacts::META_ATTEMPT,$p->attempt_id(),false);if(!$claim->allows($issuer,$order,$p))self::fail();return$claim;
 }
 public function allows(PolicyNativeOrderStager$issuer,\WC_Order$order,CustomerPolicyPreparedCheckout$p):bool{
  try{if(!$this->active||$this->issuer!==$issuer||$this->order!==$order||$this->prepared!==$p)return false;$this->native();if(self::policy_rows($order)!==[[PolicyNativeOrderFacts::META_ATTEMPT,$p->attempt_id()]])return false;foreach($this->latch as$item)if(self::policy_rows($item)!==[])return false;return true;}catch(\Throwable){return false;}
 }
 public function revoke():void{$this->active=false;}
 public function issued_for(\WC_Order$order,CustomerPolicyPreparedCheckout$p):bool{return$this->issuer->owns_attempt($this,$order,$p)&&$this->allows($this->issuer,$order,$p);}
 private function native():void{
  $o=$this->order;$p=$this->prepared;if(!in_array(get_class($o),['WC_Order','Automattic\\WooCommerce\\Admin\\Overrides\\Order'],true)||($GLOBALS['blog_id']??null)!==$p->site_id()||$o->is_paid()||$o->get_date_paid('edit')!==null||$o->get_transaction_id('edit')!==''||!in_array($o->get_status('edit'),['pending','failed','checkout-draft'],true))self::fail();
  $items=$o->get_items('line_item');$lines=$p->lines();if(!is_array($items)||count($items)!==count($lines)||count($this->latch)!==count($lines)||$lines===[])self::fail();$used=[];
  foreach($lines as$key=>$line){$item=$this->latch[$key]??null;$f=$line['frame'];if(!$item instanceof \WC_Order_Item_Product||get_class($item)!=='WC_Order_Item_Product'||!in_array($item,$items,true)||isset($used[spl_object_id($item)])||$item->get_order_id('edit')!==$o->get_id()||$o->get_customer_id('edit')!==$f['actor_id']||$item->get_product_id()!==$f['product_id']||$item->get_variation_id()!==$f['variation_id']||PolicyNativeOrderFacts::quantity($item->get_quantity('edit'))!==$f['quantity'])self::fail();$used[spl_object_id($item)]=true;}
 }
 /** Own raw keys are inspected before save can assign metadata IDs. No payload is evaluated. */
 private static function policy_rows(object$o):array{
  if(!$o instanceof \WC_Data)self::fail();$o->get_meta_data();$rp=new \ReflectionProperty(\WC_Data::class,'meta_data');$rows=method_exists($rp,'getRawValue')?$rp->getRawValue($o):$rp->getValue($o);if(!is_array($rows)||count($rows)>PolicyNativeOrderFacts::MAX_META_ROWS)self::fail();$out=[];
  foreach($rows as$m){if(!is_object($m)||get_class($m)!=='WC_Meta_Data')self::fail();$maps=[];foreach(['data','current_data']as$field){$r=new \ReflectionProperty(\WC_Meta_Data::class,$field);$v=method_exists($r,'getRawValue')?$r->getRawValue($m):$r->getValue($m);if(!is_array($v)||!is_string($v['key']??null)||strlen($v['key'])>255)self::fail();$maps[]=$v;PolicyNativeOrderFacts::foreign([['key'=>$v['key']]]);}if(PolicyNativeOrderFacts::own_key($maps[0]['key'])||PolicyNativeOrderFacts::own_key($maps[1]['key'])){if($maps[0]!==$maps[1])self::fail();$out[]=[$maps[1]['key'],$maps[1]['value']??null];}}
  return$out;
 }
 /** Fresh exact frozen-original and marker-only native observation, with no repair authority. */
 public static function unbound_fence(OperationConnectionFactory$f,\WC_Order$o,CustomerPolicyPreparedCheckout$p,callable$authorize):\Closure{
  if($p->state()!=='frozen'||$p->binding()!==null||true!==$authorize())self::fail();$native=PolicyNativeOrderFacts::native($o,true);if($native['site_id']!==$p->site_id())self::fail();foreach($p->lines()as$line)if($line['frame']['actor_id']!==$native['customer_id'])self::fail();
  if(self::policy_rows($o)!==[[PolicyNativeOrderFacts::META_ATTEMPT,$p->attempt_id()]])self::fail();$object_marker=array_values(array_filter(PolicyNativeOrderFacts::metadata($o),static fn(array$r):bool=>PolicyNativeOrderFacts::own_key($r['key'])));if(count($object_marker)!==1||$object_marker[0]['id']<1)self::fail();foreach($o->get_items('line_item')as$item)if(self::policy_rows($item)!==[])self::fail();$local=PolicyNativePaymentLocalBinding::capture($o);$hpos=PolicyNativeOrderFacts::hpos();$s=null;$active=false;$retired=false;
  try{$s=$f->open();$prefix=$s->table_prefix();if($s->site_id()!==$p->site_id()||$s->is_retired()||$s->in_transaction()||!$s->begin())self::fail();$active=true;$tables=[$prefix.'options',$prefix.'woocommerce_order_items',$prefix.'woocommerce_order_itemmeta',...($hpos?[$prefix.'wc_orders',$prefix.'wc_orders_meta',$prefix.'wc_order_addresses',$prefix.'wc_order_operational_data']:[$prefix.'posts',$prefix.'postmeta'])];if(!$s->validate_tables($tables))self::fail();$physical=PolicyNativeOrderFacts::read_physical($s,$native['order_id'],$hpos);PolicyNativeOrderFacts::assert_physical($physical,$native,$hpos);$marker=[];foreach($physical['order_meta']as$r)if(PolicyNativeOrderFacts::own_key($r['meta_key']))$marker[]=[$r['meta_key'],$r['meta_value']];if($marker!==[[PolicyNativeOrderFacts::META_ATTEMPT,$p->attempt_id()]])self::fail();$physical_marker=array_values(array_filter($physical['order_meta'],static fn(array$r):bool=>PolicyNativeOrderFacts::own_key($r['meta_key'])));if((string)$physical_marker[0]['meta_id']!==(string)$object_marker[0]['id'])self::fail();foreach($physical['item_meta']as$r)if(PolicyNativeOrderFacts::own_key($r['meta_key']))self::fail();if(!$p->verify_unbound_original($s,$native['order_id'])||!$local->unchanged()||!$s->rollback())self::fail();$active=false;if($s->in_transaction()||!$s->retire()||!$s->is_retired())self::fail();$retired=true;if(!$local->unchanged()||PolicyNativeOrderFacts::hpos()!==$hpos||$s->site_id()!==$p->site_id()||$s->table_prefix()!==$prefix||true!==$authorize())self::fail();return$local->unchanged(...);
  }finally{if($s!==null){if($active)try{$s->rollback();}catch(\Throwable){}if(!$retired)try{$s->retire();}catch(\Throwable){}}}
 }
 private static function fail():never{PolicyNativeOrderFacts::fail();}
 public function jsonSerialize():never{throw new \LogicException('Native policy attempt permission is private.');}public function __serialize():never{throw new \LogicException('Native policy attempt permission is private.');}public function __unserialize(array$data):never{throw new \LogicException('Native policy attempt permission is private.');}public function __clone():void{throw new \LogicException('Native policy attempt permission is private.');}public function __debugInfo():array{return['native_policy_attempt'=>'private'];}
}
