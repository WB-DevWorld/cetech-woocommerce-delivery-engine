<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\CustomerPolicy;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Closed census of every original native product line and its complete packet. */
final readonly class PolicyOrderManifest implements \JsonSerializable {
    public const FORMAT=1;
    public const MAX_BYTES=65536;
    public const MAX_LINES=200;
    private function __construct(private string$bytes){}
    public static function from_lines(int$site,int$order,string$owner_uuid,array$packets,RuleTime$captured_at):self {
        PolicyJson::integer($site);PolicyJson::integer($order);PolicyJson::uuid($owner_uuid);PolicyJson::list($packets,self::MAX_LINES,1);$entries=$seen=$line_keys=[];$owner=null;$sum=0;
        foreach($packets as$packet){if(!$packet instanceof PolicyLinePacket){self::fail();}$facts=$packet->lineage()->facts();if($facts['site_id']!==$site||$facts['order_id']!==$order||$facts['owner_uuid']!==$owner_uuid||$packet->captured_at()->sql()!==$captured_at->sql()||isset($seen[$facts['item_id']])||isset($line_keys[$facts['line_uuid']])||null!==$owner&&$owner!==$facts['owner_digest']){self::fail();}$sum+=strlen($packet->private_json());if($sum>PolicyLinePacket::MAX_AGGREGATE_BYTES){self::fail();}$owner=$facts['owner_digest'];$seen[$facts['item_id']]=true;$line_keys[$facts['line_uuid']]=true;$entries[]=['item_id'=>$facts['item_id'],'line_uuid'=>$facts['line_uuid'],'packet_digest'=>$packet->digest()];}
        usort($entries,static fn(array$a,array$b):int=>$a['item_id']<=>$b['item_id']);return self::from_private_json(PolicyJson::encode(['format'=>1,'site_id'=>$site,'order_id'=>$order,'owner_uuid'=>$owner_uuid,'owner_digest'=>$owner,'captured_at'=>$captured_at->sql(),'entries'=>$entries],self::MAX_BYTES));
    }
    public static function from_private_json(string$json):self { try { return self::parse_private($json); } catch(\Throwable) { self::fail(); } }
    private static function parse_private(string $json):self {
        $d=PolicyJson::fields(PolicyJson::decode($json,self::MAX_BYTES),['format','site_id','order_id','owner_uuid','owner_digest','captured_at','entries']);if(1!==$d['format']||!is_string($d['captured_at'])){self::fail();}PolicyJson::integer($d['site_id']);PolicyJson::integer($d['order_id']);PolicyJson::uuid($d['owner_uuid']);PolicyJson::digest($d['owner_digest']);RuleTime::parse($d['captured_at']);$last=0;$uuids=[];
        foreach(PolicyJson::list($d['entries'],self::MAX_LINES,1)as$entry){$e=PolicyJson::fields($entry,['item_id','line_uuid','packet_digest']);$id=PolicyJson::integer($e['item_id']);$uuid=PolicyJson::uuid($e['line_uuid']);PolicyJson::digest($e['packet_digest']);if($id<=$last||isset($uuids[$uuid])){self::fail();}$last=$id;$uuids[$uuid]=true;}
        if(PolicyJson::encode($d,self::MAX_BYTES)!==$json){self::fail();}return new self($json);
    }
    public function private_json():string{return$this->bytes;}
    public function digest():string{return hash('sha256','cetech-customer-policy-order-manifest-v1:'.$this->bytes);}
    public function facts():array{return PolicyJson::fields(PolicyJson::decode($this->bytes,self::MAX_BYTES),['format','site_id','order_id','owner_uuid','owner_digest','captured_at','entries']);}
    public function entries():array{return array_map(static fn($e):array=>PolicyJson::fields($e,['item_id','line_uuid','packet_digest']),$this->facts()['entries']);}
    public function captured_at():RuleTime{return RuleTime::parse($this->facts()['captured_at']);}
    public function jsonSerialize():never{self::private_only();}public function __serialize():never{self::private_only();}public function __unserialize(array$data):never{self::private_only();}public function __debugInfo():array{return['customer_policy_order_manifest'=>'redacted'];}
    private static function private_only():never{throw new \LogicException('Customer policy manifest requires an explicit private projection.');}
    private static function fail():never{throw new \InvalidArgumentException('Invalid original customer policy manifest.');}
}
