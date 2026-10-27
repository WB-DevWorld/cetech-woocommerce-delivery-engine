<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\CustomerPolicy;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Both policy families and original coordinates are frozen together, never rebuilt. */
final readonly class PolicyLinePacket implements \JsonSerializable {
    public const FORMAT=1;
    public const MAX_BYTES=327680;
    public const MAX_AGGREGATE_BYTES=2097152;
    private function __construct(private string$bytes){}
    public static function freeze(PolicyLineage$lineage,PolicyFamilyRecord$returns,PolicyFamilyRecord$refunds,RuleTime$captured_at):self {
        return self::from_private_json(PolicyJson::encode(self::document($lineage->facts(),$returns,$refunds,$captured_at->sql()),self::MAX_BYTES));
    }
    public static function from_private_json(string$json):self { try { return self::parse_private($json); } catch(\Throwable) { self::fail(); } }
    private static function parse_private(string $json):self {
        $d=PolicyJson::fields(PolicyJson::decode($json,self::MAX_BYTES),['format','lineage','captured_at','families']);if(1!==$d['format']||!is_string($d['captured_at'])){self::fail();}$at=RuleTime::parse($d['captured_at']);$lineage=PolicyLineage::from_array(PolicyJson::fields($d['lineage'],array_keys(self::maximum_lineage())));$facts=$lineage->facts();
        $families=PolicyJson::fields($d['families'],PolicyFamilyRecord::FAMILIES);
        foreach(PolicyFamilyRecord::FAMILIES as$family){$record=PolicyFamilyRecord::from_private_json(PolicyJson::encode($families[$family],PolicyFamilyRecord::MAX_BYTES));if($record->family()!==$family){self::fail();}$source=$record->facts()['source'];$c=$source->coordinate;if($source->evaluated_at!==$at->sql()||$source->real_offer_id!==$facts['real_offer_id']||$c->site_id!==$facts['site_id']||($facts['variation_id']>0?($c->scope_type!=='variation'||$c->scope_id!==$facts['variation_id']||$c->parent_id!==$facts['product_id']):($c->scope_type!=='product'||$c->scope_id!==$facts['product_id']||$c->parent_id!==0))){self::fail();}}
        if(PolicyJson::encode($d,self::MAX_BYTES)!==$json){self::fail();}return new self($json);
    }
    /** Reserve maximum-width native identities before order CRUD, using actual authored bodies once. */
    public static function reserved_bytes(PolicyFamilyRecord$returns,PolicyFamilyRecord$refunds):int{return strlen(PolicyJson::encode(self::document(self::maximum_lineage(),$returns,$refunds,'9999-12-31 23:59:59.999999'),self::MAX_BYTES));}
    public function private_json():string{return$this->bytes;}
    public function digest():string{return hash('sha256','cetech-customer-policy-line-packet-v1:'.$this->bytes);}
    public function lineage():PolicyLineage{return PolicyLineage::from_array(get_object_vars(PolicyJson::decode($this->bytes,self::MAX_BYTES)->lineage));}
    /** @return array<string,PolicyFamilyRecord> */
    public function families():array{$d=PolicyJson::decode($this->bytes,self::MAX_BYTES);$out=[];foreach(PolicyFamilyRecord::FAMILIES as$family){$out[$family]=PolicyFamilyRecord::from_private_json(PolicyJson::encode($d->families->{$family},PolicyFamilyRecord::MAX_BYTES));}return$out;}
    public function captured_at():RuleTime{return RuleTime::parse(PolicyJson::decode($this->bytes,self::MAX_BYTES)->captured_at);}
    public function facts():array{return PolicyJson::fields(PolicyJson::decode($this->bytes,self::MAX_BYTES),['format','lineage','captured_at','families']);}
    public function jsonSerialize():never{self::private_only();}public function __serialize():never{self::private_only();}public function __unserialize(array$data):never{self::private_only();}public function __debugInfo():array{return['customer_policy_line_packet'=>'redacted'];}
    private static function document(array$l,PolicyFamilyRecord$r,PolicyFamilyRecord$f,string$at):array{if('return_policy_v1'!==$r->family()||'refund_policy_v1'!==$f->family()){self::fail();}return['format'=>1,'lineage'=>(object)$l,'captured_at'=>$at,'families'=>(object)['return_policy_v1'=>PolicyJson::decode($r->private_json(),PolicyFamilyRecord::MAX_BYTES),'refund_policy_v1'=>PolicyJson::decode($f->private_json(),PolicyFamilyRecord::MAX_BYTES)]];}
    private static function maximum_lineage():array{return['format'=>1,'site_id'=>PHP_INT_MAX,'owner_uuid'=>'ffffffff-ffff-4fff-bfff-ffffffffffff','owner_digest'=>str_repeat('f',64),'line_uuid'=>'ffffffff-ffff-4fff-bfff-ffffffffffff','order_id'=>PHP_INT_MAX,'item_id'=>PHP_INT_MAX,'product_id'=>PHP_INT_MAX,'variation_id'=>PHP_INT_MAX-1,'parent_id'=>PHP_INT_MAX,'quantity'=>(string)PHP_INT_MAX,'real_offer_id'=>PHP_INT_MAX,'cart_line_digest'=>str_repeat('f',64),'customer_context_digest'=>str_repeat('f',64),'raw_package_digest'=>str_repeat('f',64),'raw_line_digest'=>str_repeat('f',64),'presentation_material_digest'=>str_repeat('f',64)];}
    private static function private_only():never{throw new \LogicException('Customer policy packets require an explicit private projection.');}
    private static function fail():never{throw new \InvalidArgumentException('Invalid original customer policy packet.');}
}
