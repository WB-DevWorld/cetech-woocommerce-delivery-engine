<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\CustomerPolicy;

/** Exact original native identities; no quote line-key or product/quantity fallback. */
final readonly class PolicyLineage implements \JsonSerializable {
    public const FORMAT=1;
    public const MAX_CART_KEY_BYTES=256;
    private const FIELDS=['format','site_id','owner_uuid','owner_digest','line_uuid','order_id','item_id','product_id','variation_id','parent_id','quantity','real_offer_id','cart_line_digest','customer_context_digest','raw_package_digest','raw_line_digest','presentation_material_digest'];
    private function __construct(private string$bytes){}
    public static function from_array(array$data):self {
        PolicyJson::fields($data,self::FIELDS);if(1!==$data['format']){self::fail();}
        foreach(['site_id','order_id','item_id','product_id','real_offer_id']as$key){PolicyJson::integer($data[$key]);}
        foreach(['variation_id','parent_id']as$key){PolicyJson::integer($data[$key],0);}
        if((0===$data['variation_id']&&0!==$data['parent_id'])||($data['variation_id']>0&&($data['parent_id']!==$data['product_id']||$data['variation_id']===$data['product_id']))){self::fail();}
        foreach(['owner_uuid','line_uuid']as$key){PolicyJson::uuid($data[$key]);}
        foreach(['owner_digest','cart_line_digest','customer_context_digest','raw_package_digest','raw_line_digest','presentation_material_digest']as$key){PolicyJson::digest($data[$key]);}
        PolicyJson::quantity($data['quantity']);return new self(PolicyJson::encode($data,4096));
    }
    public static function cart_line_digest(int$site,string$key):string{if($site<1||''===$key||strlen($key)>self::MAX_CART_KEY_BYTES||1!==preg_match('//u',$key)){self::fail();}return hash('sha256','cetech-customer-policy-cart-line-v1:'.$site.':'.$key);}
    public function facts():array{return PolicyJson::fields(PolicyJson::decode($this->bytes,4096),self::FIELDS);}
    public function digest():string{return hash('sha256','cetech-customer-policy-lineage-v1:'.$this->bytes);}
    public function jsonSerialize():never{self::private_only();}public function __serialize():never{self::private_only();}public function __unserialize(array$data):never{self::private_only();}public function __debugInfo():array{return['customer_policy_lineage'=>'redacted'];}
    private static function private_only():never{throw new \LogicException('Customer policy lineage requires an explicit private projection.');}
    private static function fail():never{throw new \InvalidArgumentException('Invalid original customer policy lineage.');}
}
