<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\Persistence\{CustomerPolicyReceiptStore,DataLifecycleOptionsStore};

/** Complete saved history has no current product/source/session/original-journal dependency. */
final readonly class PolicySavedOrderReader {
 public function __construct(private OperationConnectionFactory$factory){}
 public function read(\WC_Order$order,callable$authorize):array{if(true!==$authorize())PolicyNativeOrderFacts::fail();if(!$this->attempted($order))return[];return PolicyNativeOrderStage::read_saved_history($this->factory,$order,$authorize);}
 public function proof(\WC_Order$order,callable$authorize):PolicyNativeOrderStage{return PolicyNativeOrderStage::capture_saved($this->factory,$order,$authorize);}
 /** Index-only, null, duplicate, future or filtered-away markers claim ownership. */
 public function attempted(\WC_Order$order):bool{
  if(PolicyNativeOrderFacts::attempted($order))return true;if(!class_exists('WC_Data',false)||!$order instanceof \WC_Data)return false;$id=$order->get_id();if(!is_int($id)||$id<1)return false;$hpos=PolicyNativeOrderFacts::hpos();$s=null;$active=false;$retired=false;
  try{$s=$this->factory->open();$p=$s->table_prefix();$site=$s->site_id();if($site!==($GLOBALS['blog_id']??null)||1!==preg_match('/\A[A-Za-z0-9_]{1,30}\z/D',$p)||$s->is_retired()||$s->in_transaction()||!$s->begin())PolicyNativeOrderFacts::fail();$active=true;if(!$s->validate_tables([$p.'options',$p.'woocommerce_order_items',$p.'woocommerce_order_itemmeta',$p.($hpos?'wc_orders_meta':'postmeta')]))PolicyNativeOrderFacts::fail();(new DataLifecycleOptionsStore())->assert_ready($s,$site);
   $index=$s->get_results($s->prepare("SELECT option_id,option_name FROM `{$p}options` FORCE INDEX (`option_name`) WHERE option_name=%s LIMIT 2 FOR UPDATE",CustomerPolicyReceiptStore::order_index_name($site,$id)));$meta=$hpos?"SELECT id AS meta_id FROM `{$p}wc_orders_meta` WHERE order_id=%d":"SELECT meta_id FROM `{$p}postmeta` WHERE post_id=%d";$root=$s->get_results($s->prepare($meta." AND LEFT(meta_key,18)='_cetech_de_policy_' LIMIT 1 FOR UPDATE",$id));$items=$s->get_results($s->prepare("SELECT m.meta_id FROM `{$p}woocommerce_order_itemmeta` m INNER JOIN `{$p}woocommerce_order_items` i ON i.order_item_id=m.order_item_id WHERE i.order_id=%d AND LEFT(m.meta_key,18)='_cetech_de_policy_' LIMIT 1 FOR UPDATE",$id));if(!is_array($index)||!is_array($root)||!is_array($items)||!$s->rollback())PolicyNativeOrderFacts::fail();$active=false;if($s->in_transaction()||!$s->retire()||!$s->is_retired())PolicyNativeOrderFacts::fail();$retired=true;if($s->site_id()!==$site||$s->table_prefix()!==$p||PolicyNativeOrderFacts::hpos()!==$hpos)PolicyNativeOrderFacts::fail();return$index!==[]||$root!==[]||$items!==[];
  }catch(\Throwable){return true;}finally{if(null!==$s){if($active)try{$s->rollback();}catch(\Throwable){}if(!$retired)try{$s->retire();}catch(\Throwable){}}}
 }
}
