<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use CetechDeliveryEngine\Application\CustomerPolicy\{CustomerPolicyReadGrant,CustomerPolicySessionOwner};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class CurrentPolicyGrantTest extends TestCase{
 private function frame():array{return['site_id'=>1,'actor_id'=>0,'session_subject'=>str_repeat('a',64),'product_id'=>33,'variation_id'=>0,'parent_id'=>0,'quantity'=>'2','real_offer_id'=>7,'context_digest'=>str_repeat('b',64),'cart_key'=>'raw-key','purpose'=>'cart'];}
 private function refuse(callable$f):void{$error=null;try{$f();}catch(\Throwable$e){$error=$e;}self::assertInstanceOf(\RuntimeException::class,$error);}
 public function test_grant_allows_is_pure_and_revocation_sticks_after_native_refresh():void{$native=true;$calls=0;$grant=CustomerPolicyReadGrant::captured($this->frame(),['site_id'=>1,'prefix'=>'wp_'],static function()use(&$native,&$calls):bool{++$calls;return$native;});$before=$calls;$native=false;self::assertTrue($grant->allows());self::assertSame($before,$calls);self::assertFalse($grant->refresh());$native=true;self::assertFalse($grant->refresh());self::assertFalse($grant->allows());}
 public function test_returned_frame_and_binding_are_detached():void{$g=CustomerPolicyReadGrant::captured($this->frame(),['site_id'=>1,'prefix'=>'wp_'],static fn():bool=>true);$frame=$g->frame();$frame['quantity']='999';$binding=$g->binding();$binding['site_id']=2;self::assertSame('2',$g->frame()['quantity']);self::assertSame(1,$g->binding()['site_id']);self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D',$g->key());}
 #[DataProvider('invalid_frames')]
 public function test_closed_frame_rejects_foreign_noncanonical_or_ambiguous_coordinates(string$key,mixed$value):void{$this->refuse(fn()=>CustomerPolicyReadGrant::captured(array_replace($this->frame(),[$key=>$value]),['site_id'=>1,'prefix'=>'wp_'],static fn():bool=>true));}
 public static function invalid_frames():array{return[['site_id',0],['site_id','1'],['actor_id',-1],['product_id',0],['variation_id',33],['parent_id',33],['quantity',0],['quantity','0'],['quantity','01'],['quantity','1.5'],['quantity','1e1'],['quantity','9223372036854775808'],['real_offer_id',0],['real_offer_id','7'],['session_subject',str_repeat('A',64)],['context_digest','broken'],['cart_key',''],['cart_key',str_repeat('x',257)],['cart_key',"\xff"],['purpose','order'],['purpose','product']];}
 public function test_real_derived_variation_frame_is_supported():void{$f=array_replace($this->frame(),['variation_id'=>44,'parent_id'=>33]);self::assertSame($f,CustomerPolicyReadGrant::checked_frame($f));}
 public function test_same_product_cannot_be_its_own_variation_parent():void{$this->refuse(fn()=>CustomerPolicyReadGrant::checked_frame(array_replace($this->frame(),['variation_id'=>33,'parent_id'=>33])));}
 #[DataProvider('invalid_bindings')]
 public function test_route_is_site_bound_and_closed(array$b):void{$this->refuse(fn()=>CustomerPolicyReadGrant::captured($this->frame(),$b,static fn():bool=>true));}
 public static function invalid_bindings():array{return[[['site_id'=>2,'prefix'=>'wp_']],[['site_id'=>1,'prefix'=>'']],[['site_id'=>1,'prefix'=>'bad-prefix']],[['site_id'=>1,'prefix'=>str_repeat('a',58)]],[['site_id'=>1,'prefix'=>'wp_','extra'=>true]]];}
 public function test_session_owner_does_not_depend_on_current_product_and_cannot_be_serialized():void{$calls=0;$owner=CustomerPolicySessionOwner::captured(1,0,str_repeat('a',64),['site_id'=>1,'prefix'=>'wp_'],static function()use(&$calls):bool{++$calls;return true;});$before=$calls;self::assertTrue($owner->allows());self::assertSame($before,$calls);self::assertSame(0,$owner->actor_id());foreach([$owner,CustomerPolicyReadGrant::captured($this->frame(),['site_id'=>1,'prefix'=>'wp_'],static fn():bool=>true)]as$value){try{json_encode($value,JSON_THROW_ON_ERROR);self::fail('Private grant leaked.');}catch(\LogicException){self::assertTrue(true);}try{serialize($value);self::fail('Private grant serialized.');}catch(\LogicException){self::assertTrue(true);}}}
}
