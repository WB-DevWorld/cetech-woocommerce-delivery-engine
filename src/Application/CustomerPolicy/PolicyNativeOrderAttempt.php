<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy;

use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyNativePaymentLocalBinding;

/** Request-local initial-write permission; its permanent marker is refusal-only. */
final class PolicyNativeOrderAttempt implements \JsonSerializable {
 private bool $active=true;
 private bool $aligned=false;
 private function __construct(private readonly PolicyNativeOrderStager$issuer,private readonly \WC_Order$order,private readonly CustomerPolicyPreparedCheckout$prepared,private readonly array$latch){}
 public static function begin(PolicyNativeOrderStager$issuer,\WC_Order$order,CustomerPolicyPreparedCheckout$p,array$latch):self{
  if($p->state()!=='frozen'||$p->binding()!==null||($GLOBALS['blog_id']??null)!==$p->site_id()||!in_array($p->route(),['classic','store_api'],true))self::fail();
  if($p->route()==='classic'?$order->get_id()!==0:$order->get_status('edit')!=='checkout-draft')self::fail();
  $claim=new self($issuer,$order,$p,$latch);$claim->native(true);if(self::policy_rows($order)!==[])self::fail();foreach($latch as$item)if(self::policy_rows($item)!==[])self::fail();
  // Native Classic orders begin with an empty edit status. Initialize only this
  // verified new object; native stores otherwise default the SQL row alone.
  if($order->get_status('edit')===''){if($order->get_status('view')!=='pending'||$p->route()!=='classic'||$order->get_id()!==0||$order->get_status('edit')!=='')self::fail();$claim->native(true);if(self::policy_rows($order)!==[])self::fail();foreach($latch as$item)if(self::policy_rows($item)!==[])self::fail();$transition=$order->set_status('pending');if($transition!==['from'=>'pending','to'=>'pending']||$p->route()!=='classic'||$order->get_id()!==0)self::fail();$claim->native();if(self::policy_rows($order)!==[])self::fail();foreach($latch as$item)if(self::policy_rows($item)!==[])self::fail();}$claim->native();if($p->route()==='classic'&&$order->get_id()!==0)self::fail();
  $order->add_meta_data(PolicyNativeOrderFacts::META_ATTEMPT,$p->attempt_id(),false);if(!$claim->allows($issuer,$order,$p)||$p->route()==='classic'&&$order->get_id()!==0)self::fail();return$claim;
 }
 public function allows(PolicyNativeOrderStager$issuer,\WC_Order$order,CustomerPolicyPreparedCheckout$p):bool{
  try{if(!$this->active||$this->issuer!==$issuer||$this->order!==$order||$this->prepared!==$p)return false;$this->native();if(self::policy_rows($order)!==[[PolicyNativeOrderFacts::META_ATTEMPT,$p->attempt_id()]])return false;foreach($this->latch as$item)if(self::policy_rows($item)!==[])return false;return true;}catch(\Throwable){return false;}
 }
 public function revoke():void{$this->active=false;}
 public function issued_for(\WC_Order$order,CustomerPolicyPreparedCheckout$p):bool{return$this->issuer->owns_attempt($this,$order,$p)&&$this->allows($this->issuer,$order,$p);}
 /** A first producer acknowledges Woo's own HPOS read order before any immutable capture. */
 public function align_initial_metadata(\WC_Order$order,CustomerPolicyPreparedCheckout$p):void{
  if(!$this->issued_for($order,$p)||$p->state()!=='frozen'||$p->binding()!==null)self::fail();if(!PolicyNativeOrderFacts::hpos())return;if($this->aligned)self::fail();
  $read=new \ReflectionMethod($order,'read_meta_data');if($read->getDeclaringClass()->getName()!=='WC_Data'||!$read->isPublic()||$read->isStatic())self::fail();$store=$order->get_data_store();$class='Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore';
  if(!is_object($store)||get_class($store)!=='WC_Data_Store'||ltrim($store->get_current_class_name(),'\\')!==$class)self::fail();$store_name=self::raw($store,'current_class_name',['WC_Data_Store']);$instance=self::raw($store,'instance',['WC_Data_Store']);if(!is_string($store_name)||ltrim($store_name,'\\')!==$class||!is_object($instance)||get_class($instance)!==$class)self::fail();foreach(['read_meta','filter_raw_meta_data']as$method){$m=new \ReflectionMethod($instance,$method);if($m->getDeclaringClass()->getName()!==$class||!$m->isPublic()||$m->isStatic())self::fail();}
  $before=self::acknowledged_rows($order);$expected=[];$indices=[];$seen=0;
  foreach($before as$index=>$row){if(in_array($row['key'],['_billing_address_index','_shipping_address_index'],true)){if(isset($indices[$row['key']])||!is_string($row['value']))self::fail();$indices[$row['key']]=$row;++$seen;continue;}$position=$index+2-$seen;if(!is_int($position)||$position<2||isset($expected[$position]))self::fail();$expected[$position]=$row;}
  if(count($indices)!==2||array_keys($indices)!==['_billing_address_index','_shipping_address_index'])self::fail();$expected=[0=>$indices['_billing_address_index'],1=>$indices['_shipping_address_index']]+$expected;$state=$this->alignment_state();$order->read_meta_data(true);
  if(!PolicyNativeOrderFacts::hpos()||!$this->issued_for($order,$p))self::fail();
  // No callbacks follow this last fence: even an authority getter may be filtered.
  if(self::raw($order,'data_store',['WC_Data'])!==$store||self::raw($store,'instance',['WC_Data_Store'])!==$instance||self::raw($store,'current_class_name',['WC_Data_Store'])!==$store_name||self::acknowledged_rows($order)!==$expected||$this->alignment_state()!==$state)self::fail();$this->aligned=true;
 }
 private static function acknowledged_rows(object$o):array{
  $members=self::raw($o,'meta_data',['WC_Data']);if(!is_array($members)||count($members)>PolicyNativeOrderFacts::MAX_META_ROWS)self::fail();$out=[];$ids=[];$bytes=0;$nodes=0;
  foreach($members as$index=>$member){if(!is_int($index)||$index<0||!is_object($member)||get_class($member)!=='WC_Meta_Data')self::fail();$data=self::raw($member,'data',['WC_Meta_Data']);$current=self::raw($member,'current_data',['WC_Meta_Data']);if(!is_array($data)||$data!==$current||count($data)!==3||array_diff(['id','key','value'],array_keys($data))||!is_int($data['id'])||$data['id']<1||isset($ids[$data['id']])||!is_string($data['key'])||strlen($data['key'])>255||$data['value']===null)self::fail();$ids[$data['id']]=true;self::bounded_value($data,0,$nodes,$bytes);$out[$index]=['id'=>$data['id'],'key'=>$data['key'],'value'=>$data['value']];}return$out;
 }
 /** Root metadata alone may reload; every other native raw business coordinate stays exact. */
 private function alignment_state():string{
  $root=$this->order;$items=self::raw($root,'items',['WC_Abstract_Order']);if(!is_array($items))self::fail();$objects=['root'=>$root];$groups=[];foreach($items as$group=>$members){if(!in_array($group,['line_items','shipping_lines','tax_lines','fee_lines','coupon_lines'],true)||!is_array($members)||count($members)>PolicyNativeOrderFacts::MAX_LINES)self::fail();foreach($members as$key=>$item){if(!is_object($item)||!in_array(get_class($item),['WC_Order_Item_Product','WC_Order_Item_Shipping','WC_Order_Item_Tax'],true))self::fail();$name=$group.':'.$key;$groups[$group][$key]=spl_object_id($item);$objects[$name]=$item;}}
  $nodes=0;$bytes=0;$facts=self::bounded_value(['site'=>$GLOBALS['blog_id']??null,'groups'=>$groups],0,$nodes,$bytes,true);$facts['objects']=[];
  foreach($objects as$name=>$o){$entry=['object'=>spl_object_id($o),'class'=>get_class($o),'id'=>self::raw($o,'id',['WC_Data']),'data'=>self::raw($o,'data',['WC_Data','WC_Abstract_Order','WC_Order','WC_Order_Item','WC_Order_Item_Product','WC_Order_Item_Shipping','WC_Order_Item_Tax']),'changes'=>self::raw($o,'changes',['WC_Data'])];if($o!==$root){$entry['metadata']=self::acknowledged_rows($o);$entry['members']=array_map(static fn(object$m):int=>spl_object_id($m),self::raw($o,'meta_data',['WC_Data']));}$bytes+=strlen($name);$facts['objects'][$name]=self::bounded_value($entry,1,$nodes,$bytes,true);}
  return hash('sha256',serialize($facts));
 }
 private static function bounded_value(mixed$value,int$depth,int&$nodes,int&$bytes,bool$dates=false):mixed{
  if($depth>24||++$nodes>262144)self::fail();if(is_array($value)){$out=[];foreach($value as$key=>$child){$bytes+=is_string($key)?strlen($key):16;if($bytes>PolicyNativeOrderFacts::MAX_PHYSICAL_BYTES)self::fail();$out[$key]=self::bounded_value($child,$depth+1,$nodes,$bytes,$dates);}return$out;}
  if($dates&&is_object($value)&&in_array(get_class($value),['WC_DateTime','DateTime','DateTimeImmutable'],true))return['date_class'=>get_class($value),'object'=>spl_object_id($value),'raw'=>self::bounded_value((array)$value,$depth+1,$nodes,$bytes,false)];if(!is_null($value)&&!is_bool($value)&&!is_int($value)&&!is_string($value)&&(!is_float($value)||!is_finite($value)))self::fail();$bytes+=is_string($value)?strlen($value):16;if($bytes>PolicyNativeOrderFacts::MAX_PHYSICAL_BYTES)self::fail();return$value;
 }
 private static function raw(object$o,string$name,array$owners):mixed{$p=(new \ReflectionObject($o))->getProperty($name);if(!in_array($p->getDeclaringClass()->getName(),$owners,true)||$p->isStatic()||!$p->isInitialized($o))self::fail();return method_exists($p,'getRawValue')?$p->getRawValue($o):$p->getValue($o);}
 private function native(bool$before_initialization=false):void{
  $o=$this->order;$p=$this->prepared;$status=$o->get_status('edit');$new_classic=$before_initialization&&$p->route()==='classic'&&$o->get_id()===0&&$status==='';if(!in_array(get_class($o),['WC_Order','Automattic\\WooCommerce\\Admin\\Overrides\\Order'],true)||($GLOBALS['blog_id']??null)!==$p->site_id()||$o->is_paid()||$o->get_date_paid('edit')!==null||$o->get_transaction_id('edit')!==''||(!$new_classic&&!in_array($status,['pending','failed','checkout-draft'],true)))self::fail();
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
