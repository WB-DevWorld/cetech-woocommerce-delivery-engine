<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
/** Private site/purpose-separated keys. No address or key is returned by generic serialization. */
final readonly class QuoteNativeContextIdentity implements \JsonSerializable {
 private function __construct(private string $key){}
 public static function from_server():self {if(!function_exists('wp_salt')||(function_exists('has_filter')&&false!==has_filter('salt'))){throw new \RuntimeException('Native quote context unavailable.');}return self::from_private_key(wp_salt('auth'));}
 /** Explicit trusted fixture/equivalent key manager, never a posted key. */
 public static function from_private_key(string $key):self {if(strlen($key)<16||strlen($key)>4096){QuoteShape::invalid();}return new self($key);}
 public function key_epoch():string{return 'native_quote_v1:'.substr(hash_hmac('sha256','native-quote-key-epoch-v1',$this->key),0,16);}
 public function destination_digest(int $site,array $destination):string {QuoteShape::integer($site);$address=DeliveryAddress::fromInput($destination);if(!$address->isComplete()){QuoteShape::invalid();}return hash_hmac('sha256','native-quote-destination-v1:'.$site.':'.$this->key_epoch().':'.$address->identity(),$this->key);}
 public function tax_location_digest(int $site,array $location):string {QuoteShape::integer($site);if(!array_is_list($location)||count($location)!==4){QuoteShape::invalid();}foreach($location as $part){if(!is_string($part)||strlen($part)>256){QuoteShape::invalid();}}return hash_hmac('sha256','native-quote-tax-location-v1:'.$site.':'.$this->key_epoch().':'.QuoteJson::encode(['location'=>$location]),$this->key);}
 public function jsonSerialize():never{throw new \LogicException('Native context identities are private.');}
 public function __serialize():never{throw new \LogicException('Native context identities are private.');}
 public function __unserialize(array $data):never{throw new \LogicException('Native context identities cannot be reconstructed generically.');}
}
