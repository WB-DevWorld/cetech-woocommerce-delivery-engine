<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
/** Native calls occur only here, before the final source/control SQL fence. */
final class QuoteNativeReceiptCapture {
 public function __construct(private ?QuoteNativeCaptureSource $source=null){}
 public function capture(QuoteOwner $owner,array $requests):QuoteNativeReceipt {
  $source=$this->source??new QuoteNativeWooSource();
  if(!$owner->equals($source->current_owner())||!array_is_list($requests)||[]===$requests||count($requests)>200){throw new \RuntimeException('Native quote context unavailable.');}
  $wanted=[];$components=[];foreach($requests as $request){if(!$request instanceof QuoteNativeGroupRequest||isset($wanted[$request->legacy_group_id])||isset($components[$request->component_key])){QuoteShape::invalid();}$wanted[$request->legacy_group_id]=$request;$components[$request->component_key]=true;}
  $state=$source->capture();$s=$state->facts();
  if($s['coupons']!==0||$s['unsupported_effects']||$s['key_epoch']!==$owner->key_epoch()||count($s['packages'])!==count($requests)){throw new \RuntimeException('Native quote context unavailable.');}
  $context_digest=self::tax_context_digest($state);
  $money_digest=hash('sha256','native-quote-money-v1:'.QuoteJson::encode(['currency'=>$s['currency'],'shipping_total'=>$s['cart_shipping_total'],'shipping_tax'=>$s['cart_shipping_tax'],'packages'=>$s['packages']]));
  $groups=[];$members=[];$aggregate_final=self::money('0',$s['currency'],6);$aggregate_tax=self::money('0',$s['currency'],6);
  foreach($s['packages'] as $package){$request=$wanted[$package['group_id']]??null;if(null===$request||$request->expected_ex_tax->currency()!==$s['currency']){throw new \RuntimeException('Native quote context unavailable.');}
   $precision=max($s['display_precision'],$s['internal_precision'],$request->expected_ex_tax->precision(),self::scale($package['cost']));foreach($package['taxes'] as $tax){$precision=max($precision,self::scale($tax['amount']));}if($precision>6){QuoteShape::invalid();}
   $final=self::money($package['cost'],$s['currency'],$precision);$expected=self::money($request->expected_ex_tax->amount(),$s['currency'],$precision);if(!$final->equals($expected)){throw new \RuntimeException('Native quote amount unavailable.');}
   $tax=self::money('0',$s['currency'],$precision);$rates=[];$native=[];$fresh=[];
   foreach($package['taxes'] as $rate){$amount=self::money($rate['amount'],$s['currency'],$precision);$tax=$tax->add($amount);$rates[]=['rate_id'=>$rate['rate_id'],'amount'=>$amount->facts()];$native[$rate['rate_id']]=$amount->facts();}
   foreach($package['fresh_taxes'] as $rate){$fresh[$rate['rate_id']]=self::money($rate['amount'],$s['currency'],$precision)->facts();}ksort($fresh,SORT_NUMERIC);ksort($native,SORT_NUMERIC);if($fresh!==$native||((!$s['tax_enabled']||$s['exempt']||'none'===$package['tax_status'])&&([]!==$rates||!$tax->zero()))){throw new \RuntimeException('Native quote tax unavailable.');}
   usort($rates,static fn(array $a,array $b):int=>$a['rate_id']<=>$b['rate_id']);$total=$final->add($tax);$rounded=self::money($package['rounded_tax'],$s['currency'],$s['display_precision']);$display=self::money($package['display_total'],$s['currency'],$s['display_precision']);
   $groups[$request->component_key]=new QuoteNativeGroupReceipt(['component_key'=>$request->component_key,'customer_label'=>$package['label'],'provider'=>['code'=>'legacy_fixed_base_v1','version'=>1],'policy_digest'=>$context_digest,'list'=>$final->facts(),'final'=>$final->facts(),'tax'=>$tax->facts(),'total'=>$total->facts(),'promotion'=>['state'=>'none','amount'=>self::money('0',$s['currency'],$precision)->facts(),'provider'=>['code'=>'native_no_delivery_promotion_v1','version'=>1]],'cost'=>['state'=>'unavailable','reason'=>'cost_provider_unavailable'],'route'=>['state'=>'not_recorded'],'native_tax_receipt'=>['state'=>'recorded','source'=>'woocommerce','context_digest'=>$context_digest,'exempt'=>$s['exempt'],'tax_status'=>$package['tax_status'],'tax_class'=>$s['tax_class'],'location_digest'=>$s['location_digest'],'rounding'=>$s['rounding'],'rates'=>$rates,'rounded_tax'=>$rounded->facts(),'display_precision'=>$s['display_precision']],'native_money_receipt'=>['state'=>'recorded','source'=>'woocommerce','evidence_digest'=>$money_digest,'native_total'=>$total->facts(),'display_total'=>$display->facts(),'display_precision'=>$s['display_precision']]]);
   $members[$request->component_key]=$package['members'];$aggregate_final=$aggregate_final->add(self::money($package['cost'],$s['currency'],6));$aggregate_tax=$aggregate_tax->add(self::money('subtotal'===$s['rounding']?$tax->amount():$package['rounded_tax'],$s['currency'],6));
  }
  if(!$aggregate_final->equals(self::money($s['cart_shipping_total'],$s['currency'],6))||!$aggregate_tax->equals(self::money($s['cart_shipping_tax'],$s['currency'],6))||!$owner->equals($source->current_owner())||!$source->unchanged()){throw new \RuntimeException('Native quote context unavailable.');}
  return new QuoteNativeReceipt($owner,$state,$groups,$members,$source,$context_digest,$money_digest);
 }
 /** Native pointers and cached serialization are fenced physically, separate from the accepted tax material. */
 public static function tax_context_digest(QuoteNativeState $state):string {return QuoteNativeTaxSource::from_native_state($state)->digest();}
 private static function scale(string $value):int{$pos=strpos($value,'.');return false===$pos?0:strlen($value)-$pos-1;}
 private static function money(string $amount,string $currency,int $precision):QuoteMoney{if(self::scale($amount)>$precision){$trim=rtrim(rtrim($amount,'0'),'.');$amount=''===$trim?'0':$trim;}return QuoteMoney::from_array(['amount'=>$amount,'currency'=>$currency,'precision'=>$precision]);}
}
