<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\CustomerPolicy;
use CetechDeliveryEngine\Application\CustomerPolicy\PolicyNativeOrderFacts;

/** Separate policy fingerprint. Capture prewarms; later comparison uses raw reflection only. */
final readonly class PolicyNativeLocalBinding {
 private function __construct(private \WC_Order$order,private string$digest){}
 public static function capture(\WC_Order$order):?self{try{$order->get_meta_data();foreach($order->get_items('line_item')as$item)$item->get_meta_data();return new self($order,self::digest($order));}catch(\Throwable){return null;}}
 public function fingerprint():string{return$this->digest;}
 public function unchanged():bool{try{return hash_equals($this->digest,self::digest($this->order));}catch(\Throwable){return false;}}
 private static function digest(\WC_Order$order):string{
  $raw=self::property($order,\WC_Abstract_Order::class,'items');if(!is_array($raw))PolicyNativeOrderFacts::fail();$lines=$raw['line_items']??[];if(!is_array($lines)||count($lines)>200)PolicyNativeOrderFacts::fail();$objects=[$order,...array_values($lines)];$rows=[];$members=[];
  foreach($lines as$key=>$item){if(!$item instanceof \WC_Order_Item_Product)PolicyNativeOrderFacts::fail();$members[$key]=spl_object_id($item);}
  foreach($objects as$object){$meta=self::property($object,\WC_Data::class,'meta_data');if(!is_array($meta)||count($meta)>12000)PolicyNativeOrderFacts::fail();$owned=[];foreach($meta as$index=>$member){if(!is_object($member)||get_class($member)!=='WC_Meta_Data')PolicyNativeOrderFacts::fail();$before=self::property($member,\WC_Meta_Data::class,'data');$current=self::property($member,\WC_Meta_Data::class,'current_data');if(!is_array($before)||!is_array($current))PolicyNativeOrderFacts::fail();$key=$current['key']??null;$original=$before['key']??null;if(!is_string($key)||!is_string($original))PolicyNativeOrderFacts::fail();if(!PolicyNativeOrderFacts::own_key($key)&&!PolicyNativeOrderFacts::own_key($original))continue;if($before!==$current||!is_string($current['value']??null))PolicyNativeOrderFacts::fail();$owned[$index]=['member'=>spl_object_id($member),'id'=>$current['id'],'key'=>$key,'value'=>['bytes'=>strlen($current['value']),'sha256'=>hash('sha256',$current['value'])]];}$rows[]=['object'=>spl_object_id($object),'id'=>self::property($object,\WC_Data::class,'id'),'policy'=>$owned];}
  return hash('sha256','cetech-customer-policy-local-v1:'.json_encode(['site'=>$GLOBALS['blog_id']??null,'order'=>self::property($order,\WC_Data::class,'id'),'members'=>$members,'rows'=>$rows],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
 }
 private static function property(object$o,string$class,string$name):mixed{$p=new \ReflectionProperty($class,$name);if(!$p->isInitialized($o)||$p->isStatic())PolicyNativeOrderFacts::fail();return method_exists($p,'getRawValue')?$p->getRawValue($o):$p->getValue($o);}
 public function __serialize():never{throw new \LogicException('Private native policy binding.');}
}
