<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use CetechDeliveryEngine\Integrations\CustomerPolicy\NativeShopperPolicyAuthority;
use CetechDeliveryEngine\Domain\CustomerContext\{CustomerCartContext,MatchingLocation};
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class NativeShopperPolicyAuthorityTest extends TestCase{
 private array$owner,$products,$lines;private bool$available=true;
 private bool$had_wc;private mixed$previous_wc;
 protected function setUp():void{
  // These trusted-port fixtures own their geography environment too: the
  // production MatchingLocation codec consults WC()->countries while reparsing.
  // An unrelated suite's native Woo stub must not supply that hidden input.
  $this->had_wc=array_key_exists('cetech_de_test_wc',$GLOBALS);$this->previous_wc=$GLOBALS['cetech_de_test_wc']??null;$GLOBALS['cetech_de_test_wc']=null;
  $this->owner=['site_id'=>1,'actor_id'=>0,'session_id'=>'guest-native-A','cookie'=>false,'wc'=>new \stdClass(),'session'=>new \stdClass(),'cart'=>new \stdClass(),'database'=>new \stdClass(),'auth_session'=>''];$this->products=[33=>['id'=>33,'parent_id'=>0,'is_variation'=>false,'is_variable'=>true,'visible'=>true,'viewable'=>true],44=>['id'=>44,'parent_id'=>33,'is_variation'=>true,'is_variable'=>false,'visible'=>true,'viewable'=>true]];$this->lines=['actual-key'=>$this->line()];
 }
 protected function tearDown():void{if($this->had_wc){$GLOBALS['cetech_de_test_wc']=$this->previous_wc;}else{unset($GLOBALS['cetech_de_test_wc']);}}
 private function line():array{return['product_id'=>33,'variation_id'=>0,'quantity'=>2,CustomerCartContext::CART_KEY=>$this->context()->toArray(),CartDeliverySelectionCapture::CART_SELECTION_KEY=>['contract_version'=>'1','product_id'=>33,'variation_id'=>null,'target_type'=>'product','target_id'=>33,'display_key'=>'in_warehouse:delivery:7','fulfilment_availability'=>'in_warehouse','fulfilment_choice'=>'delivery','delivery_offer_id'=>7,'rule_id'=>1,'issued_at'=>'2026-10-26T00:00:00Z']];}
 private function context():CustomerCartContext{return CustomerCartContext::delivery(7,MatchingLocation::fromInput(['country'=>'GH','state'=>'AA','city'=>'Accra','postcode'=>'00001']));}
 private function authority(?callable$available=null):NativeShopperPolicyAuthority{return new NativeShopperPolicyAuthority($available??fn():bool=>$this->available,fn(int$id)=>$this->products[$id]??null,fn():array=>$this->owner,fn(string$key)=>$this->lines[$key]??null,static fn():array=>['site_id'=>1,'prefix'=>'wp_']);}
 private function refuse(callable$f):void{$error=null;try{$f();}catch(\Throwable$e){$error=$e;}self::assertInstanceOf(\RuntimeException::class,$error);}
 public function test_real_product_and_current_native_cart_bind_the_same_session_and_context():void{$a=$this->authority();$p=$a->product(33,0,'2',7,$this->context(),'in_warehouse:delivery:7');$c=$a->cart('actual-key');self::assertSame($p->session_subject(),$c->session_subject());self::assertSame('product',$p->frame()['purpose']);self::assertSame('cart',$c->frame()['purpose']);self::assertSame('actual-key',$c->frame()['cart_key']);self::assertSame(NativeShopperPolicyAuthority::context_digest($this->context()),$c->frame()['context_digest']);}
 public function test_variation_uses_actual_parent_and_reparenting_revokes_original():void{$g=$this->authority()->product(33,44,'2',7,$this->context(),'in_warehouse:delivery:7');self::assertSame(33,$g->frame()['parent_id']);$this->products[44]['parent_id']=99;self::assertFalse($g->refresh());}
 public function test_guest_subjects_and_same_user_browser_tokens_are_distinct():void{$a=$this->authority();$first=$a->session_owner()->session_subject();$this->owner['session_id']='guest-native-B';self::assertNotSame($first,$a->session_owner()->session_subject());$this->owner['actor_id']=9;$this->owner['session_id']='9';$this->owner['auth_session']='native-auth-A';$first=$a->session_owner()->session_subject();$this->owner['auth_session']='native-auth-B';self::assertNotSame($first,$a->session_owner()->session_subject());}
 public function test_existing_original_owner_never_loads_a_deleted_product():void{$a=$this->authority();$owner=$a->session_owner();$this->products=[];self::assertTrue($owner->refresh());$this->refuse(fn()=>$a->product(33,0,'2',7,$this->context(),'in_warehouse:delivery:7'));}
 #[DataProvider('hidden_resources')]
 public function test_missing_hidden_or_wrong_parent_resources_never_gain_a_grant(int$id,string$field,mixed$value):void{$this->products[$id][$field]=$value;$this->refuse(fn()=>$this->authority()->product(33,44,'2',7,$this->context(),'in_warehouse:delivery:7'));}
 public static function hidden_resources():array{return[[33,'visible',false],[33,'viewable',false],[33,'is_variable',false],[33,'is_variation',true],[44,'visible',false],[44,'viewable',false],[44,'is_variation',false],[44,'parent_id',99]];}
 #[DataProvider('bad_owners')]
 public function test_guest_ids_do_not_substitute_for_authenticated_native_sessions(string$key,mixed$value):void{$this->owner[$key]=$value;$this->refuse(fn()=>$this->authority()->session_owner());}
 public static function bad_owners():array{return[['site_id',0],['actor_id',-1],['session_id',''],['session_id','0'],['session_id',str_repeat('x',257)],['session_id',"\xff"],['cookie',true],['auth_session',null],['cart',null],['database',null]];}
 public function test_positive_actor_without_auth_token_or_matching_woo_id_is_refused():void{$this->owner['actor_id']=9;$this->owner['session_id']='9';$this->refuse(fn()=>$this->authority()->session_owner());$this->owner['auth_session']='token';$this->owner['session_id']='10';$this->refuse(fn()=>$this->authority()->session_owner());}
 public function test_option_callback_cannot_revoke_native_owner_and_still_publish():void{$a=$this->authority(function():bool{$this->owner['session_id']='foreign';return true;});$this->refuse(fn()=>$a->product(33,0,'2',7,$this->context(),'in_warehouse:delivery:7'));}
 public function test_changed_actual_quantity_or_removed_raw_key_revokes_grant():void{$a=$this->authority();$g=$a->cart('actual-key');$this->lines['actual-key']['quantity']=3;self::assertFalse($g->refresh());$g=$a->cart('actual-key');unset($this->lines['actual-key']);self::assertFalse($g->refresh());$this->refuse(fn()=>$a->cart('posted-product-33'));}
 public function test_changed_native_geography_normalization_refuses_the_original_raw_cart_context():void{
  $a=$this->authority();$g=$a->cart('actual-key');
  $GLOBALS['cetech_de_test_wc']=(object)['countries'=>new class{public function get_states(string$country):array{return'GH'===$country?['Z9'=>'AA']:[];}}];
  self::assertFalse($g->refresh());$this->refuse(fn()=>$a->cart('actual-key'));
  $GLOBALS['cetech_de_test_wc']=null;self::assertFalse($g->refresh());
 }
 #[DataProvider('bad_quantities')]
 public function test_fractional_noncanonical_and_nonpositive_cart_quantity_refuse(mixed$q):void{$this->lines['actual-key']['quantity']=$q;$this->refuse(fn()=>$this->authority()->cart('actual-key'));}
 public static function bad_quantities():array{return[[0],[-1],[1.5],['01'],['1.5'],['1e1'],[true],[null],['9223372036854775808']];}
 public function test_pickup_offer_zero_and_mismatching_native_intent_refuse():void{$a=$this->authority();$this->refuse(fn()=>$a->product(33,0,'2',0,$this->context(),'in_warehouse:delivery:7'));$this->refuse(fn()=>$a->product(33,0,'2',7,CustomerCartContext::pickup(9),'in_warehouse:delivery:7'));$this->lines['actual-key'][CartDeliverySelectionCapture::CART_SELECTION_KEY]['delivery_offer_id']=8;$this->refuse(fn()=>$a->cart('actual-key'));}
 public function test_extra_context_input_is_not_silently_normalized_into_authority():void{$this->lines['actual-key'][CustomerCartContext::CART_KEY]['injected']='private';$this->refuse(fn()=>$this->authority()->cart('actual-key'));}
 public function test_server_available_option_refusal_and_late_session_change_are_sticky():void{$a=$this->authority();$this->available=false;$this->refuse(fn()=>$a->product(33,0,'2',7,$this->context(),'in_warehouse:delivery:7'));$this->available=true;$g=$a->cart('actual-key');$this->owner['session_id']='changed';self::assertFalse($g->refresh());$this->owner['session_id']='guest-native-A';self::assertFalse($g->refresh());}
}
