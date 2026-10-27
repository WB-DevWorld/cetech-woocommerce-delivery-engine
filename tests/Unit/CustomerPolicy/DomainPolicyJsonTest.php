<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use CetechDeliveryEngine\Domain\CustomerPolicy\PolicyJson as J;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class DomainPolicyJsonTest extends TestCase {
    public function test_plain_objects_are_canonical_and_lists_and_exact_unicode_bytes_are_preserved():void{$data=['z'=>[null,true,4],'a'=>(object)['b'=>"café\r\n\t<em>&",'a'=>'𐐷']];$json=J::encode($data);self::assertSame('{"a":{"a":"𐐷","b":"café\\r\\n\\t<em>&"},"z":[null,true,4]}',$json);self::assertSame($json,J::encode(J::decode($json)));self::assertSame('𐐷',J::decode($json)->a->a);}
    #[DataProvider('invalid_json')]
    public function test_duplicate_keys_floats_hostile_primitives_and_invalid_utf8_refuse(string$json):void{$this->expectException(\InvalidArgumentException::class);J::decode($json);}
    public static function invalid_json():array{return array_map(static fn($x)=>[$x],['{"a":1,"a":2}','{"a":1,"\\u0061":2}','{"a":{"x":1,"x":2}}','{"value":1.0}','{"value":1e0}','{"value":9223372036854775808}','{"value":"\xff"}','true','null','12','"string"','{"a":NaN}','{"a":INF}','{}{}','{"a":','']);}
    public function test_exact_byte_bound_and_plus_one_have_distinct_outcomes():void{foreach([64,131072,327680,65536,3145728]as$cap){$json=J::encode(['x'=>str_repeat('a',$cap-8)],$cap);self::assertSame($cap,strlen($json));self::assertSame($json,J::encode(J::decode($json,$cap),$cap));try{J::encode(['x'=>str_repeat('a',$cap-7)],$cap);self::fail('Plus one accepted.');}catch(\InvalidArgumentException){self::assertTrue(true);}}}
    public function test_depth_and_node_ceiling_are_enforced_without_invoking_object_callbacks():void{$v='x';for($i=0;$i<24;++$i){$v=[$v];}self::assertNotEmpty(J::encode($v));$v=[$v];try{J::encode($v);self::fail('Depth25 accepted.');}catch(\InvalidArgumentException){self::assertTrue(true);}try{J::encode(array_fill(0,J::MAX_NODES,0));self::fail('Node overflow accepted.');}catch(\InvalidArgumentException){self::assertTrue(true);}$hostile=new class implements \JsonSerializable{public function jsonSerialize():mixed{throw new \LogicException('Callback invoked');}};try{J::encode(['x'=>$hostile]);self::fail('Hostile object accepted.');}catch(\InvalidArgumentException){self::assertTrue(true);}}
    #[DataProvider('invalid_integer')]
    public function test_integer_identity_requires_actual_positive_native_integer(mixed$v):void{$this->expectException(\InvalidArgumentException::class);J::integer($v);}
    public static function invalid_integer():array{return[[0],[-1],['1'],[1.0],[false],[null],[new \stdClass()]];}
    #[DataProvider('invalid_quantity')]
    public function test_quantity_is_canonical_positive_and_bounded(mixed$v):void{$this->expectException(\InvalidArgumentException::class);J::quantity($v);}
    public static function invalid_quantity():array{return[[0],[1],[1.0],['0'],['01'],['1.5'],['+1'],['1e1'],[' 1'],['9223372036854775808'],[null]];}
    public function test_object_subclass_and_associative_integer_keys_are_not_plain_objects():void{$bad=new class extends \stdClass{};foreach([['x'=>$bad],[2=>'a',3=>'b'],['x'=>fopen('php://memory','r')]]as$data){try{J::encode($data);self::fail('Nonplain JSON accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}}}
    public function test_closed_shape_and_scalar_codecs_reject_substitution():void{self::assertSame(['x'=>1],J::fields((object)['x'=>1],['x']));self::assertSame([1],J::list([1],1,1));foreach([static fn()=>J::fields((object)['x'=>1,'y'=>2],['x']),static fn()=>J::fields((object)[],['x']),static fn()=>J::list((object)['0'=>1],1),static fn()=>J::list([],1,1),static fn()=>J::digest(str_repeat('A',64)),static fn()=>J::uuid('00000000-0000-1000-8000-000000000001'),static fn()=>J::encode([],1)]as$make){try{$make();self::fail('Unsupported shape accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}}}
}
