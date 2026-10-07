<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
/** Exact sealed native inputs. Amounts remain strict decimals; no arbitrary extensions. */
final readonly class QuoteNativeState implements \JsonSerializable {
 private function __construct(private array $facts) {}
 public static function from_array(array $facts): self {
  $facts=QuoteJson::detach($facts);
  QuoteShape::fields($facts,['currency','display_precision','internal_precision','exempt','tax_class','location_digest','destination_digest','key_epoch','rounding','tax_enabled','coupons','unsupported_effects','cart_shipping_total','cart_shipping_tax','packages','source_facts']);
  QuoteShape::currency($facts['currency']); QuoteShape::integer($facts['display_precision'],0,6); QuoteShape::integer($facts['internal_precision'],0,6);
  foreach(['exempt','tax_enabled','unsupported_effects'] as $key) { if(!is_bool($facts[$key])) {QuoteShape::invalid();} }
  if(!is_string($facts['tax_class']) || (''!==$facts['tax_class'] && preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D',$facts['tax_class'])!==1)) {QuoteShape::invalid();}
  QuoteShape::digest($facts['location_digest']); QuoteShape::digest($facts['destination_digest']); QuoteShape::machine($facts['key_epoch']); QuoteShape::choice($facts['rounding'],['per_line','subtotal']); QuoteShape::integer($facts['coupons'],0,200);
  self::decimal($facts['cart_shipping_total']); self::decimal($facts['cart_shipping_tax']);
  $seen=[];
  foreach(QuoteShape::list($facts['packages'],200,1) as $package) {
   QuoteShape::fields($package,['group_id','rate_id','method_id','instance_id','tax_status','label','cost','taxes','fresh_taxes','rounded_tax','display_total','members']);
   if(!is_string($package['group_id']) || ''===$package['group_id'] || strlen($package['group_id'])>255 || isset($seen[$package['group_id']])) {QuoteShape::invalid();} $seen[$package['group_id']]=true;
   if(!is_string($package['rate_id']) || strlen($package['rate_id'])>255) {QuoteShape::invalid();} QuoteShape::choice($package['method_id'],['delivery_engine_selected_offer']); QuoteShape::integer($package['instance_id'],0);
   QuoteShape::choice($package['tax_status'],['taxable','none']);
   if(!is_string($package['label']) || ''===$package['label'] || strlen($package['label'])>120 || preg_match('/[\x00-\x1f\x7f<>]/',$package['label'])) {QuoteShape::invalid();}
   self::decimal($package['cost']);self::decimal($package['rounded_tax']);self::decimal($package['display_total']);
   foreach(['taxes','fresh_taxes'] as $field) { $ids=[];foreach(QuoteShape::list($package[$field],200) as $rate) {QuoteShape::fields($rate,['rate_id','amount']); $id=QuoteShape::integer($rate['rate_id']); if(isset($ids[$id])){QuoteShape::invalid();}$ids[$id]=true;self::decimal($rate['amount']);} }
   $keys=[];foreach(QuoteShape::list($package['members'],200,1) as $member) {QuoteShape::fields($member,['line_key','product_id','variation_id','quantity']); $key=QuoteShape::machine($member['line_key'],128); if(isset($keys[$key])){QuoteShape::invalid();}$keys[$key]=true;QuoteShape::integer($member['product_id']);if(null!==$member['variation_id']){QuoteShape::integer($member['variation_id']);}self::decimal($member['quantity']);if(strspn(str_replace('.','',$member['quantity']),'0')===strlen(str_replace('.','',$member['quantity']))){QuoteShape::invalid();}}
  }
  $source=$facts['source_facts']; QuoteShape::fields($source,['option_rows','tax_rows','tax_class_rows','tax_location_rows','method_rows','session_row','customer_rows','selectors']);
  $selectors=$source['selectors'];QuoteShape::fields($selectors,['option_names','tax_class','method_instance_ids','session_key','customer_id','site_id','table_prefix']);QuoteShape::list($selectors['option_names'],64,1);foreach($selectors['option_names'] as $name){QuoteShape::machine($name,191);}if(!is_string($selectors['tax_class'])||strlen($selectors['tax_class'])>64||!is_string($selectors['session_key'])||''===$selectors['session_key']||strlen($selectors['session_key'])>128){QuoteShape::invalid();}QuoteShape::integer($selectors['customer_id'],0);QuoteShape::integer($selectors['site_id']);QuoteShape::machine($selectors['table_prefix'],30);foreach(QuoteShape::list($selectors['method_instance_ids'],200,1) as $id){QuoteShape::integer($id,0);}
  unset($source['selectors']);foreach($source as $key=>$rows) {if(!is_array($rows) || !array_is_list($rows) || count($rows)>1000){QuoteShape::invalid();}foreach($rows as $row){if(!is_array($row)||array_is_list($row)){QuoteShape::invalid();}if(count($row)!==count(QuoteNativeReceiptGuard::COLUMNS[$key])||array_diff(QuoteNativeReceiptGuard::COLUMNS[$key],array_keys($row))!==[]){QuoteShape::invalid();}foreach($row as $name=>$value){if(!is_string($name)||!is_string($value)||strlen($value)>16384){QuoteShape::invalid();}}}}
  return new self($facts);
 }
 public static function decimal(mixed $value): string { if(!is_string($value)||preg_match('/\A(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D',$value)!==1){QuoteShape::invalid();}return $value; }
 public function facts(): array{return $this->facts;}
 public function jsonSerialize(): never {throw new \LogicException('An authorized native quote projection is required.');}
 public function __serialize(): never {throw new \LogicException('Native quote facts cannot be serialized generically.');}
 public function __unserialize(array $data): never {throw new \LogicException('Native quote facts cannot be reconstructed generically.');}
}
