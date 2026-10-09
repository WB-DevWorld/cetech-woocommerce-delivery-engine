<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\ServicePromise\Shipment;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseJson,PromiseShape};

/** Closed separate current prediction. It never replaces the original accepted promise. */
final readonly class ShipmentCurrentPromise implements \JsonSerializable {
 private function __construct(private array $facts) {}
 public static function from_array(array $facts): self {
  $facts=PromiseJson::detach($facts,65536); PromiseShape::fields($facts,['format','source','state','original_packet_digest','event_key','event_at','reason','runtime','views']);
  PromiseShape::integer($facts['format'],1,1); PromiseShape::choice($facts['source'],['payment_confirmed','staff_revision']); PromiseShape::choice($facts['state'],['absolute_window','unavailable']); PromiseShape::digest($facts['original_packet_digest']); PromiseShape::digest($facts['event_key']); $facts['event_at']=PromiseShape::instant($facts['event_at'])->sql(); PromiseShape::text($facts['reason'],1000);
  $runtime=PromiseShape::object($facts['runtime']); PromiseShape::fields($runtime,['timezone_data_version','runtime_id','digest']); PromiseShape::text($runtime['timezone_data_version'],128); PromiseShape::id($runtime['runtime_id']); PromiseShape::digest($runtime['digest']);
  $views=PromiseShape::list($facts['views'],'absolute_window'===$facts['state']?1:0,16); if('unavailable'===$facts['state'] && []!==$views){PromiseShape::invalid();}
  foreach($views as $view){$view=PromiseShape::object($view);PromiseShape::fields($view,['service_label','display_timezone','from','until']);PromiseShape::text($view['service_label'],160);PromiseShape::timezone($view['display_timezone']);$from=PromiseShape::instant($view['from']);$until=PromiseShape::instant($view['until']);if($from->compare($until)>0){PromiseShape::invalid();}}
  return new self($facts);
 }
 public static function from_json(string $json):self{$value=self::from_array(PromiseJson::decode($json,65536));if($value->to_private_json()!==$json){PromiseShape::invalid();}return $value;}
 public function private_facts():array{return PromiseJson::detach($this->facts,65536);}
 public function public_facts():array{return ['state'=>$this->facts['state'],'views'=>$this->facts['views'],'source'=>$this->facts['source']];}
 public function to_private_json():string{return PromiseJson::encode($this->facts,65536);}
 public function digest():string{return hash('sha256','cetech-shipment-current-promise-v1:'.$this->to_private_json());}
 public function jsonSerialize():never{throw new \LogicException('Authorized shipment projection required.');}
 public function __unserialize(array $data):never{throw new \LogicException('Private shipment prediction requires strict decoding.');}
 public function __serialize():never{throw new \LogicException('Private shipment prediction cannot be serialized.');}
}
