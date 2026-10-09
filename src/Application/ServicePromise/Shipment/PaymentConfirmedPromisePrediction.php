<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\ServicePromise\Shipment;
use CetechDeliveryEngine\Application\ServicePromise\Calculation\{PromiseCalculationBudget,PromiseCalculationRuntime,PromiseCalendarArithmetic};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion,PromiseJson,PromiseLimits};
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Domain\ServicePromise\Shipment\ShipmentCurrentPromise;

/** Version 1 payment-relative prediction from the exact frozen result and calendar references. */
final class PaymentConfirmedPromisePrediction {
 public function calculate(PromiseHistoricalPacket $packet,RuleTime $paid_at,array $runtime,array $calendars,string $event_key): ShipmentCurrentPromise {
  $input=$packet->input_facts(); $result=$packet->result_facts(); $views=[]; $reason='payment_confirmed'; $state='unavailable';
  try {
   if('payment_confirmed'!==$input['anchor']['kind'] || 'relative_window'!==$result['state'] || self::predates_capture($paid_at,RuleTime::parse($input['evaluated_at'])) || !(new PromiseCalculationRuntime($runtime))->matches($input['runtime'])){throw new \RuntimeException('frozen_source_unavailable');}
   $expected=[];foreach($input['calendar_refs'] as $ref){$expected[$ref['calendar_id']]=$ref;} $map=[];
   if(!array_is_list($calendars)||count($calendars)!==count($expected)){throw new \RuntimeException('frozen_source_unavailable');}
   foreach($calendars as $calendar){if(!$calendar instanceof BusinessCalendarVersion){throw new \RuntimeException('frozen_source_unavailable');}$ref=$calendar->reference()->private_facts();$id=$ref['calendar_id'];if(isset($map[$id])||($expected[$id]??null)!==$ref||$calendar->tzdata_version()!==$runtime['timezone_data_version']){throw new \RuntimeException('frozen_source_unavailable');}$map[$id]=$calendar;}
   $terminal_ids=array_column($result['body']['terminal_windows'],'component_id');$expected_terminals=$input['policy']['graph']['terminal_component_ids'];sort($terminal_ids);sort($expected_terminals);if($terminal_ids!==$expected_terminals||count($terminal_ids)!==count(array_unique($terminal_ids))){throw new \RuntimeException('frozen_source_unavailable');}$math=new PromiseCalendarArithmetic();$budget=new PromiseCalculationBudget();
   foreach($result['body']['terminal_windows'] as $window){if(!in_array($window['component_id'],$input['policy']['endpoint_terminal_component_ids'],true)){continue;}$ref=$window['calendar_refs'][0]??null;$calendar=null===$ref?null:($map[$ref['calendar_id']]??null);if(null!==$ref&&null===$calendar){throw new \RuntimeException('frozen_source_unavailable');}$from=$math->add($paid_at,$window['min'],$window['unit'],$calendar,$budget);$until=$math->add($paid_at,$window['max'],$window['unit'],$calendar,$budget);$origin=new \DateTimeImmutable($paid_at->sql(),new \DateTimeZone('UTC'));$end=new \DateTimeImmutable($until->sql(),new \DateTimeZone('UTC'));$tz=new \DateTimeZone($input['policy']['promise_timezone']);$a=new \DateTimeImmutable($origin->setTimezone($tz)->format('Y-m-d'),new \DateTimeZone('UTC'));$b=new \DateTimeImmutable($end->setTimezone($tz)->format('Y-m-d'),new \DateTimeZone('UTC'));if((int)$a->diff($b)->format('%r%a')>PromiseLimits::LOOKAHEAD_DAYS){throw new \RuntimeException('frozen_source_unavailable');}$views[]=['service_label'=>$input['policy']['service']['customer_label'],'display_timezone'=>$input['policy']['promise_timezone'],'from'=>$from->sql(),'until'=>$until->sql()];}
   if([]===$views){throw new \RuntimeException('frozen_source_unavailable');}$state='absolute_window';
  }catch(\Throwable){$views=[];$reason='Original payment-relative estimate retained; current prediction unavailable.';}
  return ShipmentCurrentPromise::from_array(['format'=>1,'source'=>'payment_confirmed','state'=>$state,'original_packet_digest'=>$packet->digest(),'event_key'=>$event_key,'event_at'=>$paid_at->sql(),'reason'=>$reason,'runtime'=>$input['runtime'],'views'=>$views]);
 }
 /** Woo persists date_paid at whole-second precision. Preserve that exact anchor; same-second capture is not proof of an earlier payment. */
 private static function predates_capture(RuleTime $paid,RuleTime $captured):bool{$epoch=$paid->epoch_microseconds();return 0===$epoch%1000000?intdiv($epoch,1000000)<intdiv($captured->epoch_microseconds(),1000000):$paid->compare($captured)<0;}
}
