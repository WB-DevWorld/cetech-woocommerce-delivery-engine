<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class NativeShopperDefaultProductTest extends TestCase{
 private function probe(string$mode):array{$p=dirname(__DIR__,2).'/Support/CustomerPolicy/native-shopper-product-probe.php';$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($p).' '.escapeshellarg($mode);$out=[];$code=0;exec($cmd,$out,$code);self::assertSame(0,$code);return json_decode(implode("\n",$out),true,8,JSON_THROW_ON_ERROR);}
 public function test_actual_native_product_loader_derives_variation_parent_from_native_post():void{self::assertSame(['granted'=>true,'parent'=>33],$this->probe('valid'));}
 #[DataProvider('mismatches')]
 public function test_mutable_native_object_cannot_hide_actual_post_parent_type_or_access(string$mode):void{self::assertSame(['granted'=>false,'parent'=>null],$this->probe($mode));}
 public static function mismatches():array{return[['parent_mismatch'],['type_mismatch'],['parent_type'],['password'],['post_private']];}
 public function test_actual_post_reparenting_revokes_a_previously_captured_native_grant():void{self::assertSame(['granted'=>true,'parent'=>33,'refreshed'=>false],$this->probe('late_parent'));}
}
