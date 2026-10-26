<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy;

use CetechDeliveryEngine\Application\Order\{OrderDeliverySnapshot,OrderDeliverySnapshotReader,QuoteNativeOrderFacts};
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Detached native commerce and duplicate-aware raw metadata; no policy or product lookup. */
final class PolicyNativeOrderFacts {
 public const META_LINE_KEY='_cetech_de_policy_line_key';
 public const META_PACKET='_cetech_de_policy_packet';
 public const META_FORMAT='_cetech_de_policy_format';
 public const META_MANIFEST='_cetech_de_policy_manifest';
 public const META_MANIFEST_FORMAT='_cetech_de_policy_manifest_format';
 public const FORMAT='1'; public const MAX_LINES=200; public const MAX_META_ROWS=12000; public const MAX_PHYSICAL_BYTES=4194304;
 public const OWN_KEYS=[self::META_LINE_KEY,self::META_PACKET,self::META_FORMAT,self::META_MANIFEST,self::META_MANIFEST_FORMAT];
 public const ITEM_KEYS=[self::META_LINE_KEY,self::META_PACKET,self::META_FORMAT];
 public const ORDER_KEYS=[self::META_MANIFEST,self::META_MANIFEST_FORMAT];
 public static function own_key(string$key):bool{return 0===strncasecmp($key,'_cetech_de_policy_',18);}
 public static function metadata(object$object):array{
  if(!class_exists('WC_Data',false)||!$object instanceof \WC_Data||!method_exists($object,'get_meta_data'))self::fail();
  $object->get_meta_data();$p=new \ReflectionProperty(\WC_Data::class,'meta_data');$members=method_exists($p,'getRawValue')?$p->getRawValue($object):$p->getValue($object);
  if(!is_array($members)||count($members)>self::MAX_META_ROWS)self::fail();$out=[];
  foreach($members as$member){if(!is_object($member)||get_class($member)!=='WC_Meta_Data')self::fail();$a=new \ReflectionProperty(\WC_Meta_Data::class,'data');$b=new \ReflectionProperty(\WC_Meta_Data::class,'current_data');$before=method_exists($a,'getRawValue')?$a->getRawValue($member):$a->getValue($member);$row=method_exists($b,'getRawValue')?$b->getRawValue($member):$b->getValue($member);
   if(!is_array($before)||!is_array($row)||count($row)!==3||array_diff(['id','key','value'],array_keys($row))||!is_int($row['id'])||$row['id']<0||!is_string($row['key'])||strlen($row['key'])>255)self::fail();
   foreach([$before['key']??null,$row['key']]as$key){if(is_string($key)&&(self::own_key($key)||in_array($key,[OrderDeliverySnapshot::META_LINE_SNAPSHOT,OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION,OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT,OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION],true))&&$before!==$row)self::fail();}
   $out[]=['id'=>$row['id'],'key'=>$row['key'],'value'=>$row['value']];
  }return$out;
 }
 public static function values(array$rows,string$key):array{$out=[];foreach($rows as$row)if(($row['key']??null)===$key)$out[]=$row['value'];return$out;}
 public static function one(array$rows,string$key):mixed{$v=self::values($rows,$key);if(count($v)!==1)self::fail();return$v[0];}
 public static function attempted(\WC_Order$order):bool{
  if(!class_exists('WC_Data',false)||!$order instanceof \WC_Data){try{foreach(self::OWN_KEYS as$key)if(method_exists($order,'meta_exists')&&$order->meta_exists($key))return true;}catch(\Throwable){}return false;}
  try{foreach([$order,...array_values($order->get_items('line_item'))]as$object)foreach(self::metadata($object)as$row)if(self::own_key($row['key']))return true;return false;}catch(\Throwable){return true;}
 }
 public static function hpos():bool{return class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')&&\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();}
 public static function quantity(mixed$value):string{if(is_float($value)&&is_finite($value)&&floor($value)===$value&&$value<=2147483647)$value=(int)$value;if(is_int($value))$value=(string)$value;if(!is_string($value)||1!==preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)||strlen($value)>10||(strlen($value)===10&&strcmp($value,'2147483647')>0))self::fail();return$value;}
 public static function native(\WC_Order$order,bool$unpaid=false):array{
  if(!in_array(get_class($order),['WC_Order','Automattic\\WooCommerce\\Admin\\Overrides\\Order'],true)||!class_exists('WC_Data',false)||!$order instanceof \WC_Data||$order->get_id()<1)self::fail();
  if(method_exists($order,'get_data_store')){$store=$order->get_data_store();if(!is_object($store)||get_class($store)!=='WC_Data_Store')self::fail();$class=$store->get_current_class_name();$expected=self::hpos()?'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore':'WC_Order_Data_Store_CPT';if(ltrim($class,'\\')!==$expected)self::fail();}
  foreach(['line_item'=>'WC_Order_Item_Product','shipping'=>'WC_Order_Item_Shipping','tax'=>'WC_Order_Item_Tax']as$type=>$class){$items=$order->get_items($type);if(!is_array($items)||count($items)>self::MAX_LINES||($type==='line_item'&&[]===$items))self::fail();foreach($items as$id=>$item){if(!is_object($item)||get_class($item)!==$class||$item->get_id()!==(int)$id||$item->get_id()<1||$item->get_order_id('edit')!==$order->get_id())self::fail();if($type==='line_item')self::quantity($item->get_quantity('edit'));self::metadata($item);}}
  self::metadata($order);$facts=QuoteNativeOrderFacts::native($order); // Neutral legacy commerce projection, no quote grant or binding.
  $site=$GLOBALS['blog_id']??null;if(!is_int($site)||$site<1)self::fail();$status=$order->get_status('edit');$paid=$order->is_paid();$date=$order->get_date_paid('edit');$transaction=$order->get_transaction_id('edit');
  if(!is_string($status)||!is_bool($paid)||!is_string($transaction)||strlen($transaction)>256||null!==$date&&(!is_object($date)||get_class($date)!=='WC_DateTime'))self::fail();
  if($unpaid&&($paid||null!==$date||''!==$transaction||!in_array($status,['pending','failed','checkout-draft'],true)))self::fail();
  return['site_id'=>$site,'order_id'=>$order->get_id(),'status'=>$status,'paid'=>$paid,'paid_at'=>null===$date?null:gmdate('Y-m-d H:i:s',$date->getTimestamp()),'transaction_id'=>$transaction,...$facts];
 }
 public static function digest(array$facts):string{return hash('sha256','cetech-customer-policy-native-v1:'.QuoteNativeOrderFacts::encode($facts));}
 public static function witness(string$bytes):array{return['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];}
 public static function matches_witness(string$bytes,array$witness):bool{return self::witness($bytes)===$witness;}
 public static function foreign(array$rows):void{foreach($rows as$row){$key=$row['key']??$row['meta_key']??null;if(is_string($key))$key=strtolower($key);if(is_string($key)&&($key==='_cetech_de_delivery_quote_format'||str_starts_with($key,'_cetech_de_quote_')||str_starts_with($key,'_cetech_de_promotion_')))self::fail();}}
 public static function legacy(\WC_Order$order):array{
  $rows=self::metadata($order);self::foreign($rows);$package=self::one($rows,OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT);$version=self::one($rows,OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION);
  if(!is_string($package)||strlen($package)>1048576||!in_array($version,['2',2],true))self::fail();$reader=new OrderDeliverySnapshotReader();$p=$reader->read_package($order);if(null===$p->snapshot||$p->error!==''||$p->snapshot->snapshot_version!=='2'||null!==$p->delivery_quote)self::fail();$lines=[];$contexts=[];
  foreach($order->get_items('line_item')as$id=>$item){$r=self::metadata($item);self::foreign($r);$raw=self::one($r,OrderDeliverySnapshot::META_LINE_SNAPSHOT);$v=self::one($r,OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION);if(!is_string($raw)||strlen($raw)>1048576||!in_array($v,['2',2],true))self::fail();$read=$reader->read_line($item);$line=$read->snapshot;if(null===$line||$read->error!==''||$line->snapshot_version!=='2'||null!==$read->delivery_quote||$line->product_id!==$item->get_product_id()||($line->variation_id??0)!==$item->get_variation_id()||(string)$line->quantity!==self::quantity($item->get_quantity())||$line->fulfilment_choice!=='delivery'||($line->delivery_offer_id??0)<1)self::fail();
   $context=CustomerCartContext::delivery($line->delivery_offer_id,null===$line->matching_location?null:\CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation::fromArray($line->matching_location),null===$line->delivery_address?null:\CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress::fromArray($line->delivery_address));
   $lines[(int)$id]=$raw;$contexts[(int)$id]=['product_id'=>$line->product_id,'variation_id'=>$line->variation_id??0,'quantity'=>(string)$line->quantity,'real_offer_id'=>$line->delivery_offer_id,'context_digest'=>\CetechDeliveryEngine\Integrations\CustomerPolicy\NativeShopperPolicyAuthority::context_digest($context)];
  }ksort($lines,SORT_NUMERIC);return['package'=>$package,'lines'=>$lines,'contexts'=>$contexts];
 }
 /** One bounded raw census; no getters, serializers, product data or callbacks in SQL. */
 public static function read_physical(OperationSession$s,int$id,bool$hpos):array{
  if($id<1||!$s->in_transaction()||$s->is_retired())self::fail();$p=$s->table_prefix();if(1!==preg_match('/\A[A-Za-z0-9_]{1,30}\z/D',$p))self::fail();
  $root=$hpos?"`{$p}wc_orders_meta` WHERE order_id=%d":"`{$p}postmeta` WHERE post_id=%d";
  $bad="LEFT(meta_key,18)='_cetech_de_policy_' AND (meta_value IS NULL OR OCTET_LENGTH(meta_value)>CASE meta_key WHEN '_cetech_de_policy_line_key' THEN 256 WHEN '_cetech_de_policy_format' THEN 32 WHEN '_cetech_de_policy_manifest_format' THEN 32 WHEN '_cetech_de_policy_manifest' THEN 65536 ELSE 327680 END)";
  $item_bad=str_replace(['meta_key','meta_value'],['m.meta_key','m.meta_value'],$bad);
  $counts=$s->get_results($s->prepare("SELECT 1 AS policy_budget_format,(SELECT COUNT(*) FROM {$root}) AS root_rows,(SELECT COALESCE(SUM(COALESCE(OCTET_LENGTH(meta_key),256)+COALESCE(OCTET_LENGTH(meta_value),4194305)),0) FROM {$root}) AS root_bytes,(SELECT COUNT(*) FROM `{$p}woocommerce_order_itemmeta` m INNER JOIN `{$p}woocommerce_order_items` i ON i.order_item_id=m.order_item_id WHERE i.order_id=%d) AS item_rows,(SELECT COALESCE(SUM(COALESCE(OCTET_LENGTH(m.meta_key),256)+COALESCE(OCTET_LENGTH(m.meta_value),4194305)),0) FROM `{$p}woocommerce_order_itemmeta` m INNER JOIN `{$p}woocommerce_order_items` i ON i.order_item_id=m.order_item_id WHERE i.order_id=%d) AS item_bytes,(SELECT COUNT(*) FROM {$root} AND ({$bad})) AS root_policy_bad,(SELECT COUNT(*) FROM `{$p}woocommerce_order_itemmeta` m INNER JOIN `{$p}woocommerce_order_items` i ON i.order_item_id=m.order_item_id WHERE i.order_id=%d AND ({$item_bad})) AS item_policy_bad",$id,$id,$id,$id,$id,$id));
  if(!is_array($counts)||count($counts)!==1)self::fail();$size=$counts[0];if(!in_array($size['policy_budget_format']??null,[1,'1'],true))self::fail();foreach(['root_rows','root_bytes','item_rows','item_bytes','root_policy_bad','item_policy_bad']as$k)if(!isset($size[$k])||!is_numeric($size[$k])||$size[$k]<0)self::fail();if((int)$size['root_policy_bad']!==0||(int)$size['item_policy_bad']!==0||$size['root_rows']>self::MAX_META_ROWS||$size['item_rows']>self::MAX_META_ROWS||$size['root_bytes']+$size['item_bytes']>self::MAX_PHYSICAL_BYTES)self::fail();
  $queries=['items'=>$s->prepare("SELECT order_item_id,order_item_type,CASE WHEN OCTET_LENGTH(order_item_name)<=2048 THEN order_item_name ELSE NULL END AS order_item_name,order_id FROM `{$p}woocommerce_order_items` WHERE order_id=%d ORDER BY order_item_id LIMIT 601 FOR UPDATE",$id),'item_meta'=>$s->prepare("SELECT m.meta_id,m.order_item_id,m.meta_key,m.meta_value FROM `{$p}woocommerce_order_itemmeta` m INNER JOIN `{$p}woocommerce_order_items` i ON i.order_item_id=m.order_item_id WHERE i.order_id=%d ORDER BY m.order_item_id,m.meta_id LIMIT 12001 FOR UPDATE",$id)];
  if($hpos){$queries['order']=$s->prepare("SELECT id,status,currency,total_amount,tax_amount,customer_id,payment_method,payment_method_title,transaction_id FROM `{$p}wc_orders` WHERE id=%d LIMIT 2 FOR UPDATE",$id);$queries['money']=$s->prepare("SELECT order_id,order_key,shipping_total_amount,shipping_tax_amount,date_paid_gmt FROM `{$p}wc_order_operational_data` WHERE order_id=%d LIMIT 2 FOR UPDATE",$id);$queries['address']=$s->prepare("SELECT order_id,address_type,country,state,city,postcode,address_1,address_2 FROM `{$p}wc_order_addresses` WHERE order_id=%d AND address_type IN ('shipping','billing') ORDER BY address_type LIMIT 3 FOR UPDATE",$id);$queries['order_meta']=$s->prepare("SELECT id AS meta_id,meta_key,meta_value FROM `{$p}wc_orders_meta` WHERE order_id=%d ORDER BY id LIMIT 12001 FOR UPDATE",$id);}
  else{$queries['order']=$s->prepare("SELECT ID,post_type,post_status FROM `{$p}posts` WHERE ID=%d LIMIT 2 FOR UPDATE",$id);$queries['order_meta']=$s->prepare("SELECT meta_id,meta_key,meta_value FROM `{$p}postmeta` WHERE post_id=%d ORDER BY meta_id LIMIT 12001 FOR UPDATE",$id);}
  $out=[];foreach($queries as$key=>$sql){$rows=$s->get_results($sql);$cap=match($key){'items'=>601,'item_meta','order_meta'=>12001,'address'=>3,default=>2};if(!is_array($rows)||count($rows)>=$cap||in_array($key,['order','money'],true)&&count($rows)!==1||$key==='items'&&[]===$rows)self::fail();$out[$key]=[];foreach($rows as$row){$r=[];foreach($row as$k=>$v){if(!is_string($k)||null!==$v&&!is_scalar($v))self::fail();$r[$k]=null===$v?null:(string)$v;}if($key==='items'&&null===$r['order_item_name'])self::fail();$out[$key][]=$r;}}
  if(count($out['order_meta'])!==(int)$size['root_rows']||count($out['item_meta'])!==(int)$size['item_rows'])self::fail();return$out;
 }
 public static function assert_physical(array$physical,array$native,bool$hpos):void{
  QuoteNativeOrderFacts::assert_physical($physical,$native,$hpos);self::foreign($physical['order_meta']);self::foreign($physical['item_meta']);$meta=[];foreach($physical['order_meta']as$r)$meta[$r['meta_key']][]=$r['meta_value'];
  if($hpos){$r=$physical['order'][0];$money=$physical['money'][0];if($r['status']!=='wc-'.$native['status']||($r['transaction_id']??'')!==$native['transaction_id']||($money['date_paid_gmt']??null)!==$native['paid_at'])self::fail();}
  else{if($physical['order'][0]['post_status']!=='wc-'.$native['status'])self::fail();$tx=$meta['_transaction_id']??[];if($tx!==[$native['transaction_id']]&&!($native['transaction_id']===''&&$tx===[]))self::fail();$paid=$meta['_date_paid']??[];if($native['paid_at']===null){if($paid!==[]&&$paid!==['']&&$paid!==['0'])self::fail();}elseif(count($paid)!==1||!ctype_digit($paid[0])||gmdate('Y-m-d H:i:s',(int)$paid[0])!==$native['paid_at'])self::fail();}
 }
 public static function assert_rows(array$physical,array$order,array$items):void{
  $root=[];foreach($physical['order_meta']as$r)if(self::own_key($r['meta_key'])){$root[$r['meta_key']][]=$r['meta_value'];}ksort($root);$expected=[];foreach($order as$k=>$v)$expected[$k]=[$v];ksort($expected);if($root!==$expected)self::fail();$actual=[];
  foreach($physical['item_meta']as$r)if(self::own_key($r['meta_key']))$actual[(int)$r['order_item_id']][$r['meta_key']][]=$r['meta_value'];
  foreach($items as$id=>$rows){$a=$actual[$id]??[];ksort($a);$e=[];foreach($rows as$k=>$v)$e[$k]=[$v];ksort($e);if($a!==$e)self::fail();unset($actual[$id]);}if([]!==$actual)self::fail();
 }
 public static function fail():never{throw new \RuntimeException('Saved customer policy history is unavailable.');}
}
