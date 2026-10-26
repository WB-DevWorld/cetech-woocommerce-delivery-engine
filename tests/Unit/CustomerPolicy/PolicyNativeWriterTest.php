<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual typed native Stage, actual receipt owner and real in-memory SQL, not Woo certification. */
final class PolicyNativeWriterTest extends TestCase {
 #[DataProvider('cases')]
 public function test_atomic_binding_missing_only_recovery_and_native_mapping(string$mode,bool$success,int$writes,?int$reads=null):void{
  $r=$this->probe('customer-policy-writer-probe.php',$mode);self::assertSame($success,$r['verified']);self::assertSame($writes,$r['native_writes']);self::assertSame(0,$r['getters_in_sql']);self::assertTrue($r['preserved']);if(null!==$reads)self::assertSame($reads,$r['source_reads']);if($success){self::assertNull($r['error']);self::assertTrue($r['no_rewrite']);self::assertTrue($r['bound_empty_refused']);self::assertGreaterThanOrEqual(2,$r['own_rows']);}else self::assertNotNull($r['error']);if(str_contains($mode,'retire')){self::assertTrue($r['retired']);self::assertTrue($r['second']);self::assertSame(3,$r['own_rows']);}if(in_array($mode,['lost-bind-ack','unsent-bind'],true))self::assertTrue($r['retried']);
 }
 public static function cases():array{return[
  'HPOS native first+repeat'=>['hpos',true,2,1], 'CPT native first+repeat'=>['cpt',true,2,1], 'lost binding ACK same original'=>['lost-bind-ack',true,2,1], 'unsent binding first-write still fresh'=>['unsent-bind',true,2,2],
  'foreign immutable order index'=>['index-collision',false,0,1], 'identical index bytes ABA'=>['index-aba',false,2,1], 'rawkey has no product fallback'=>['wrong-raw-key',false,0,0], 'cache-hidden foreign quote'=>['quote-hidden',false,0,0], 'method changed after binding'=>['method-after-bind',false,0,1],
  'HPOS complete empty permits second checkout'=>['complete-retire',true,2,1], 'CPT complete empty permits second checkout'=>['cpt-complete-retire',true,2,1],
 ];}
 #[DataProvider('pointer_cases')]
 public function test_native_resume_pointer_never_destructively_reuses_an_old_policy_order(string$mode,bool$clear):void{
  $r=$this->probe('customer-policy-writer-probe.php',$mode);self::assertTrue($r['verified']);self::assertTrue($r['retired']);self::assertTrue($r['second']);self::assertSame($clear,$r['pointer_cleared']);self::assertSame(!$clear,$r['pointer_refused']);self::assertTrue($r['preserved']);self::assertSame(0,$r['getters_in_sql']);
 }
 public static function pointer_cases():array{return[['pointer-second',true],['pointer-partial',false],['pointer-foreign',false],['pointer-off',false]];}
 #[DataProvider('store_pointer_cases')]
 public function test_store_pre_core_pointer_preserves_history_and_requires_actual_post_line_binding(string$mode,bool$refused,bool$detach,bool$read=false):void{
  $r=$this->probe('customer-policy-store-pointer-probe.php',$mode);self::assertSame($refused,$r['refused']);self::assertSame($detach?0:123,$r['pointer']);self::assertTrue($r['history_unchanged']);self::assertTrue($r['journal_unchanged']);self::assertSame(0,$r['writes']);self::assertSame(0,$r['getters_in_sql']);self::assertSame(str_contains($mode,'init')?1:0,$r['native_loads']);self::assertSame(!$read,$r['remembered']);if($read){self::assertSame(0,$r['prepared_calls']);self::assertSame(0,$r['allowed_calls']);}else self::assertSame(1,$r['prepared_calls']);
 }
 public static function store_pointer_cases():array{return[
  ['hpos-draft',false,true],['cpt-draft',false,true],['hpos-pending',false,true],['hpos-failed',false,true],
  ['hpos-foreign',true,false],['hpos-paid',true,false],['hpos-attempted',true,false],['hpos-off',true,false],['hpos-unmanaged',false,false],
  ['hpos-read-get-attempted-off',true,false,true],['hpos-read-patch-attempted-off',true,false,true],['cpt-read-get-attempted-off',true,false,true],['cpt-read-patch-attempted-off',true,false,true],['hpos-read-get-unmanaged',false,false,true],['hpos-read-patch-unmanaged',false,false,true],
  ['hpos-read-get-bound-off',true,false,true],['hpos-read-patch-bound-off',true,false,true],['cpt-read-get-bound-off',true,false,true],['cpt-read-patch-bound-off',true,false,true],['hpos-read-get-bound-init-off',true,false,true],['hpos-read-put-bound-off',true,false,true],['hpos-read-head-bound-off',true,false,true],
  ['hpos-upper-draft',false,true],['cpt-upper-draft',false,true],['hpos-read-get-bound-upper-off',true,false,true],['cpt-read-patch-bound-upper-off',true,false,true],
 ];}
 #[DataProvider('uppercase_stage_cases')]
 public function test_native_uppercase_store_post_preserves_preflight_and_final_typed_stage(string$mode):void{
  $r=$this->probe('customer-policy-store-pointer-probe.php',$mode);self::assertFalse($r['refused']);self::assertTrue($r['remembered']);self::assertSame(1,$r['prepared_calls']);self::assertSame(1,$r['legacy_calls']);self::assertTrue($r['staged']);self::assertSame(2,$r['writes']);self::assertSame(0,$r['getters_in_sql']);
 }
 public static function uppercase_stage_cases():array{return[['hpos-upper-stage'],['cpt-upper-stage']];}
 #[DataProvider('unrelated_store_route_cases')]
 public function test_route_alias_guard_keeps_whole_route_and_native_handler_identity(string$mode):void{
  $r=$this->probe('customer-policy-store-pointer-probe.php',$mode);self::assertFalse($r['refused']);self::assertSame(123,$r['pointer']);self::assertSame(0,$r['prepared_calls']);self::assertSame(0,$r['allowed_calls']);self::assertTrue($r['history_unchanged']);self::assertTrue($r['journal_unchanged']);self::assertSame(0,$r['writes']);
 }
 public static function unrelated_store_route_cases():array{return[['hpos-bad-route'],['hpos-upper-foreign-handler']];}
 #[DataProvider('payment_cases')]
 public function test_actual_c07_saved_orderpay_has_no_new_capture_or_current_product_dependency(string$mode,bool$allowed):void{
  $r=$this->probe('customer-policy-c07-probe.php',$mode);self::assertTrue($r['allowed']);self::assertTrue($r['admitted']);self::assertSame($allowed,$r['last']);foreach(['new_called','prepared_called','catalog_calls','legacy_calls','getters_in_sql','writes']as$key)self::assertSame(0,$r[$key],$key);self::assertGreaterThanOrEqual(1,$r['control_confirmations']);
 }
 public static function payment_cases():array{return[['hpos',true],['cpt',true],['hpos-mutation',false],['cpt-mutation',false],['hpos-pause',false],['cpt-pause',false],['hpos-method-save',true],['cpt-method-save',true],['hpos-untraced-method-save',false],['cpt-untraced-method-save',false]];}
 /** The fixture is the unmodified pinned Woo 11.1.2 PaymentContext source. */
 #[DataProvider('native_context_cases')]
 public function test_native_payment_context_preserves_the_actual_class_object_and_method_boundary(string$mode,bool$expected):void{
  $r=$this->probe('customer-policy-c07-probe.php',$mode);self::assertTrue($r['allowed']);self::assertTrue($r['admitted']);self::assertSame($expected,$r['context_passed']);foreach(['new_called','prepared_called','catalog_calls','legacy_calls','getters_in_sql','writes']as$key)self::assertSame(0,$r[$key],$key);
 }
 public static function native_context_cases():array{return[['hpos-native-context',true],['cpt-native-context',true],['hpos-native-context-other-object',false],['hpos-native-context-public-double',false],['hpos-native-context-wrong-method',false],['hpos-native-context-pause',false]];}
 private function probe(string$filename,string$mode):array{$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__,2).'/Support/CustomerPolicy/'.$filename).' '.escapeshellarg($mode);$lines=[];$exit=0;exec($cmd.' 2>&1',$lines,$exit);self::assertSame(0,$exit,implode("\n",$lines));return json_decode(implode("\n",$lines),true,16,JSON_THROW_ON_ERROR);}
}
