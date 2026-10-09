<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\ServicePromise\Shipment;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Captured native paid timestamp; the material owner rechecks the exact HPOS/CPT persisted fact. */
final readonly class ShipmentPaidEvidence {
 public function __construct(public int $order_id,public RuleTime $paid_at,public bool $hpos){if($order_id<1||$paid_at->epoch_microseconds()<=0){throw new \InvalidArgumentException('Paid evidence unavailable.');}}
 public function private_facts():array{return ['order_id'=>$this->order_id,'paid_at'=>$this->paid_at->sql(),'hpos'=>$this->hpos];}
 public function event_key(string $group):string{return hash('sha256','cetech-shipment-native-payment-v1:'.$this->order_id.':'.$group.':'.$this->paid_at->sql());}
 public function tables(OperationSession $session):array{$p=$session->table_prefix();return $this->hpos?[$p.'wc_orders',$p.'wc_order_operational_data']:[$p.'posts',$p.'postmeta'];}
 public function verify(OperationSession $session):bool {
  try{$p=$session->table_prefix();if($this->hpos){$orders=$session->get_results($session->prepare("SELECT id,status FROM `{$p}wc_orders` WHERE id=%d LIMIT 2 FOR UPDATE",$this->order_id));$rows=$session->get_results($session->prepare("SELECT order_id,date_paid_gmt FROM `{$p}wc_order_operational_data` WHERE order_id=%d LIMIT 2 FOR UPDATE",$this->order_id));if(1!==count($orders)||1!==count($rows)||!in_array($orders[0]['status'],['wc-processing','wc-completed'],true)||!is_string($rows[0]['date_paid_gmt']??null)){return false;}$date=$rows[0]['date_paid_gmt'];return $date===substr($this->paid_at->sql(),0,19);}
   $orders=$session->get_results($session->prepare("SELECT ID,post_type,post_status FROM `{$p}posts` WHERE ID=%d LIMIT 2 FOR UPDATE",$this->order_id));$rows=$session->get_results($session->prepare("SELECT meta_value FROM `{$p}postmeta` WHERE post_id=%d AND meta_key='_date_paid' LIMIT 2 FOR UPDATE",$this->order_id));return 1===count($orders)&&1===count($rows)&&'shop_order'===$orders[0]['post_type']&&in_array($orders[0]['post_status'],['wc-processing','wc-completed'],true)&&(string)intdiv($this->paid_at->epoch_microseconds(),1000000)===(string)$rows[0]['meta_value'];
  }catch(\Throwable){return false;}
 }
}
