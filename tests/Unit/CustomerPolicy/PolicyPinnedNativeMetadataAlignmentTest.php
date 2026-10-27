<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Genuine installed Woo methods, declared in-memory metadata/item ports; no physical qualification. */
final class PolicyPinnedNativeMetadataAlignmentTest extends TestCase{
 public function test_native_hpos_read_order_is_acknowledged_before_the_strict_initial_capture():void{
  $r=$this->probe('same');self::assertFalse($r['old_native_same']);self::assertTrue($r['accepted']);self::assertTrue($r['new_native_same']);self::assertTrue($r['strict_after']);self::assertFalse($r['strict_before_still_unchanged']);self::assertSame(['ordinary_before','_cetech_de_policy_attempt','_billing_address_index','_shipping_address_index','ordinary_after'],$r['before_keys']);self::assertSame(['_billing_address_index','_shipping_address_index','ordinary_before','_cetech_de_policy_attempt','ordinary_after'],$r['after_keys']);self::assertSame(1,$r['reads']);self::assertSame(array_fill_keys(['id','type','value','duplicate','missing','other_order','other_value','gap'],false),$r['post_alignment_mutations']);
 }
 public function test_real_unique_logger_replacement_gap_is_projected_only_before_initial_capture():void{
  $r=$this->probe('logger_gap');self::assertSame(['first_indices'=>[0,1,2,3,4,5],'second_indices'=>[0,1,2,3,4,6],'adds'=>2,'deleted_ids'=>[2001]],$r['logger']);self::assertFalse($r['old_native_same']);self::assertTrue($r['accepted']);self::assertSame([0,1,2,3,4,5],$r['after_indices']);self::assertTrue($r['native_projection_exact']);self::assertTrue($r['new_native_same']);self::assertTrue($r['strict_after']);self::assertFalse($r['strict_before_still_unchanged']);self::assertSame(1,$r['reads']);self::assertSame(array_fill_keys(['id','type','value','duplicate','missing','other_order','other_value','gap'],false),$r['post_alignment_mutations']);
 }
 #[DataProvider('inter_key_orders')]
 public function test_native_sql_inter_key_permutations_preserve_exact_rows_and_repeated_key_meaning(string$mode):void{
  $r=$this->probe($mode);self::assertFalse($r['old_native_same']);self::assertTrue($r['accepted']);self::assertTrue($r['new_native_same']);self::assertTrue($r['native_projection_exact']);self::assertTrue($r['strict_after']);self::assertSame(range(0,8),$r['after_indices']);self::assertSame('first',$r['repeated_first_before']);self::assertSame('first',$r['repeated_first_after']);self::assertSame(['first','second'],$r['repeated_values_after']);self::assertSame(['numeric-one','numeric-leading-zero'],$r['numeric_key_values_after']);self::assertSame(1,$r['reads']);self::assertSame(array_fill_keys(['id','type','value','duplicate','missing','other_order','other_value','gap'],false),$r['post_alignment_mutations']);
 }
 public static function inter_key_orders():array{return[['inter_key_order'],['index_key_order']];}
 public function test_reference_bearing_metadata_is_detached_before_the_native_read():void{$r=$this->probe('reference_unchanged');self::assertTrue($r['accepted']);self::assertTrue($r['native_projection_exact']);self::assertTrue($r['strict_after']);self::assertSame(1,$r['reads']);}
 #[DataProvider('preflight_refusals')]
 public function test_unacknowledged_unowned_or_ambiguous_candidates_never_trigger_a_reload(string$mode):void{$r=$this->probe($mode);self::assertFalse($r['accepted']);self::assertSame(0,$r['reads']);}
 public static function preflight_refusals():array{return array_map(static fn(string$m):array=>[$m],['missing_index','duplicate_index','pending_root','zero_id','revoked','wrong_prepared','wrong_store']);}
 #[DataProvider('read_refusals')]
 public function test_real_native_reload_cannot_rebase_other_rows_or_business_facts(string$mode):void{$r=$this->probe($mode);self::assertFalse($r['accepted']);self::assertSame(1,$r['reads']);}
 public static function read_refusals():array{return array_map(static fn(string$m):array=>[$m],['id','type','value','duplicate','missing','same_key_order','same_key_id','same_key_type','same_key_value','same_key_missing','same_key_added','other_value','reference_value','prefix','root_money','item_quantity','marker','wrong_site','gap','date','date_replacement','authority_money']);}
 public function test_same_initial_capability_cannot_reload_twice():void{$r=$this->probe('repeat');self::assertFalse($r['accepted']);self::assertSame(1,$r['reads']);}
 private function probe(string$mode):array{$process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-pinned-native-alignment-probe.php',$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr);$r=json_decode($stdout,true,16,JSON_THROW_ON_ERROR);self::assertFalse($r['physical_qualification']);self::assertTrue($r['same_native_object']);self::assertFalse($r['new_index_or_binding']);self::assertSame(0,$r['sql']);self::assertSame(0,$r['saves']);return$r;}
}
