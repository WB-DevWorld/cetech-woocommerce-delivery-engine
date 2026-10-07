<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeContextIdentity;
use PHPUnit\Framework\TestCase;
final class NativeIdentityTest extends TestCase {
 public function test_destination_is_keyed_and_site_purpose_and_rotation_separated():void {
  $a=QuoteNativeContextIdentity::from_private_key(str_repeat('a',32));$b=QuoteNativeContextIdentity::from_private_key(str_repeat('b',32));$address=['country'=>'GH','state'=>'AA','city'=>'Accra','postcode'=>'GA-001','address_1'=>'PRIVATE-OWN-ADDRESS','address_2'=>''];$digest=$a->destination_digest(1,$address);
  self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$digest);self::assertNotSame($digest,$a->destination_digest(2,$address));self::assertNotSame($digest,$b->destination_digest(1,$address));self::assertNotSame($a->key_epoch(),$b->key_epoch());self::assertNotSame($digest,$a->tax_location_digest(1,['GH','AA','GA-001','Accra']));$address['address_1']='PRIVATE-SECOND-ADDRESS';self::assertNotSame($digest,$a->destination_digest(1,$address));self::assertStringNotContainsString('PRIVATE',$digest);
 }
 public function test_unkeyed_location_identity_is_not_quote_destination_identity():void{$address=['country'=>'GH','state'=>'AA','city'=>'Accra','postcode'=>'GA-001','address_1'=>'PRIVATE-OWN-ADDRESS'];$keys=QuoteNativeContextIdentity::from_private_key(str_repeat('a',32));self::assertNotSame(\CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress::fromInput($address)->identity(),$keys->destination_digest(1,$address));}
 public function test_native_option_storage_matches_warmed_integer_boolean_and_serialized_array():void {$method=new \ReflectionMethod(\CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeWooSource::class,'option_storage');self::assertSame('2',$method->invoke(null,2));self::assertSame('2',$method->invoke(null,'2'));self::assertSame('1',$method->invoke(null,true));self::assertSame(serialize(['tax_status'=>'none']),$method->invoke(null,['tax_status'=>'none']));self::assertNotSame('3',$method->invoke(null,2));}
 public function test_identity_carrier_has_no_generic_key_or_address_export():void{$identity=QuoteNativeContextIdentity::from_private_key(str_repeat('a',32));foreach(['json','php'] as $format){try{if('json'===$format){json_encode($identity,JSON_THROW_ON_ERROR);}else{serialize($identity);}self::fail();}catch(\LogicException){self::assertTrue(true);}}}
}
