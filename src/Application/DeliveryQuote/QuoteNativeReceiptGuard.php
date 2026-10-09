<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
/** Fixed native selectors; all final reads use the coordinator-owned current transaction. */
final readonly class QuoteNativeReceiptGuard implements QuoteNativeTaxEvidenceGuard {
 public const CUSTOMER_META=['session_tokens','billing_country','billing_state','billing_city','billing_postcode','shipping_country','shipping_state','shipping_city','shipping_postcode','shipping_address_1','shipping_address_2','is_vat_exempt'];
 public const COLUMNS=[
  'option_rows'=>['option_id','option_name','option_value','autoload'],
  'tax_rows'=>['tax_rate_id','tax_rate_country','tax_rate_state','tax_rate','tax_rate_name','tax_rate_priority','tax_rate_compound','tax_rate_shipping','tax_rate_order','tax_rate_class'],
  'tax_class_rows'=>['tax_rate_class_id','name','slug'],
  'tax_location_rows'=>['location_id','location_code','tax_rate_id','location_type'],
  'method_rows'=>['zone_id','instance_id','method_id','method_order','is_enabled'],
  'session_row'=>['session_id','session_key','session_value','session_expiry'],
  'customer_rows'=>['umeta_id','user_id','meta_key','meta_value'],
 ];
 public function __construct(private QuoteNativeReceipt $receipt){}
 public function tax_source():QuoteNativeTaxSource{return $this->receipt->tax_source();}
 public function tables(OperationSession $session):array{$s=$this->receipt->source_facts()['selectors'];if($s['site_id']!==$session->site_id()||$s['table_prefix']!==$session->table_prefix()){throw new \RuntimeException('Native quote source unavailable.');}$p=$session->table_prefix();return [$p.'options',$p.'woocommerce_tax_rates',$p.'wc_tax_rate_classes',$p.'woocommerce_tax_rate_locations',$p.'woocommerce_shipping_zone_methods',$p.'woocommerce_sessions',$p.'usermeta'];}
 public function verify(OperationSession $session,QuoteOwner $owner,QuoteContext $context):bool {
  try{$expected=$this->receipt->source_facts();$s=$expected['selectors'];if($owner->site_id()!==$session->site_id()||$context->digest()!==$this->receipt->bind_context($context)->digest()||!$this->receipt->matches_owner($owner)||!$this->receipt->unchanged()){return false;}$this->tables($session);$current=self::read($s,$session);return hash_equals(QuoteJson::encode($expected),QuoteJson::encode($current))&&$this->receipt->unchanged();}catch(\Throwable){return false;}
 }
 /** Called only outside an owned final unit; native route refuses unknown DB adapters. */
 public static function read_physical(array $selectors):array {
  $db=$GLOBALS['wpdb']??null;if(!is_object($db)||!isset($db->dbh)||!$db->dbh instanceof \mysqli||!isset($db->prefix)||!is_string($db->prefix)||(defined('WP_CONTENT_DIR')&&is_file(WP_CONTENT_DIR.'/db.php'))){throw new \RuntimeException('Native quote source unavailable.');}
  if(($db->usermeta??null)!==$db->prefix.'usermeta'){throw new \RuntimeException('Native quote source unavailable.');}
  $selectors['site_id']=get_current_blog_id();$selectors['table_prefix']=$db->prefix;return self::read($selectors,$db);
 }
 /** Fixed saved-order selectors on the current owned unit; no native call is made here. */
 public static function read_current(array $selectors,OperationSession $session):array {if($session->is_retired()||!$session->in_transaction()||($selectors['site_id']??null)!==$session->site_id()||($selectors['table_prefix']??null)!==$session->table_prefix()){throw new \RuntimeException('Native quote source unavailable.');}return self::read($selectors,$session);}
 private static function read(array $s,object $db):array {
  $owned=$db instanceof OperationSession;$p=$s['table_prefix'];if(!is_string($p)||!preg_match('/\A[a-zA-Z0-9_]+\z/D',$p)||strlen($p)>30){throw new \RuntimeException('Native quote source unavailable.');}
  foreach($s['option_names'] as $name){if(!in_array($name,QuoteNativeWooSource::OPTIONS,true)&&preg_match('/\Awoocommerce_delivery_engine_selected_offer(?:_[0-9]+)?_settings\z/D',$name)!==1){throw new \RuntimeException('Native quote source unavailable.');}}
  $quote=static function(string $value)use($db,$owned):string{return $owned?$db->prepare('%s',[$value]):$db->prepare('%s',$value);};
  $rows=static function(string $sql,string $kind)use($db,$owned):array{$raw=$owned?$db->get_results($sql):$db->get_results($sql,ARRAY_A);if(!is_array($raw)||count($raw)>200){throw new \RuntimeException('Native quote source unavailable.');}$out=[];foreach($raw as $row){if(!is_array($row)||array_keys($row)!==self::COLUMNS[$kind]){throw new \RuntimeException('Native quote source unavailable.');}$copy=[];foreach($row as $name=>$value){if(!is_string($value)&&!is_int($value)){throw new \RuntimeException('Native quote source unavailable.');}$value=(string)$value;if(strlen($value)>16384){throw new \RuntimeException('Native quote source unavailable.');}$copy[$name]=$value;}$out[]=$copy;}return $out;};
  $suffix=$owned?' FOR UPDATE':'';$names=implode(',',array_map($quote,$s['option_names']));$ids=implode(',',array_map(static fn(int $id):string=>(string)$id,$s['method_instance_ids']));$class=$quote($s['tax_class']);$key=$quote($s['session_key']);$user=(int)$s['customer_id'];
  $out=[];$out['option_rows']=$rows("SELECT option_id,option_name,LEFT(option_value,16385) AS option_value,autoload FROM `{$p}options` WHERE option_name IN ({$names}) ORDER BY option_name LIMIT 201{$suffix}",'option_rows');
  $out['tax_rows']=$rows("SELECT tax_rate_id,tax_rate_country,tax_rate_state,tax_rate,tax_rate_name,tax_rate_priority,tax_rate_compound,tax_rate_shipping,tax_rate_order,tax_rate_class FROM `{$p}woocommerce_tax_rates` WHERE tax_rate_class = {$class} ORDER BY tax_rate_id LIMIT 201{$suffix}",'tax_rows');
  $taxids=implode(',',array_map(static fn(array $row):string=>(string)(int)$row['tax_rate_id'],$out['tax_rows']));if(''===$taxids){$taxids='0';}
  $out['tax_class_rows']=$rows("SELECT tax_rate_class_id,name,slug FROM `{$p}wc_tax_rate_classes` ORDER BY tax_rate_class_id LIMIT 201{$suffix}",'tax_class_rows');
  $out['tax_location_rows']=$rows("SELECT location_id,location_code,tax_rate_id,location_type FROM `{$p}woocommerce_tax_rate_locations` WHERE tax_rate_id IN ({$taxids}) ORDER BY location_id LIMIT 201{$suffix}",'tax_location_rows');
  $out['method_rows']=$rows("SELECT zone_id,instance_id,method_id,method_order,is_enabled FROM `{$p}woocommerce_shipping_zone_methods` WHERE instance_id IN ({$ids}) ORDER BY instance_id LIMIT 201{$suffix}",'method_rows');
  $out['session_row']=$rows("SELECT session_id,session_key,LEFT(session_value,16385) AS session_value,session_expiry FROM `{$p}woocommerce_sessions` WHERE session_key = {$key} ORDER BY session_id LIMIT 2{$suffix}",'session_row');
  $metas=implode(',',array_map($quote,self::CUSTOMER_META));$out['customer_rows']=$rows("SELECT umeta_id,user_id,meta_key,LEFT(meta_value,16385) AS meta_value FROM `{$p}usermeta` WHERE user_id = {$user} AND meta_key IN ({$metas}) ORDER BY umeta_id LIMIT 201{$suffix}",'customer_rows');$out['selectors']=$s;
  QuoteJson::encode($out);return $out;
 }
}
