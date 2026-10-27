<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Installed Woo order/metadata methods with declared in-memory CRUD/item ports. */
final class PolicyPinnedNativeStatusTest extends TestCase {
 public static function backends():array{return[['hpos'],['cpt']];}
 #[DataProvider('backends')]
 public function test_exact_new_classic_claim_materializes_native_pending_before_first_save(string$backend):void{
  $r=$this->probe('initial',$backend);self::assertSame('',$r['initial_raw']);self::assertSame('pending',$r['initial_view']);self::assertTrue($r['accepted']);self::assertTrue($r['permission']);self::assertSame('pending',$r['after_initialization']);self::assertSame(0,$r['crud_before_save']);self::assertSame($r['expected_attempt'],$r['marker']);self::assertSame(1,$r['metadata_after']);self::assertTrue($r['same_native_object']);self::assertSame(123,$r['native_order_id']);self::assertSame('pending',$r['after_save']);self::assertTrue($r['permission_after_save']);self::assertSame([['id'=>0,'raw'=>'pending','row'=>'wc-pending']],$r['crud_observed']);self::assertSame(1,$r['crud_calls']);self::assertSame(0,$r['status_actions']);self::assertFalse($r['transition']);
 }
 #[DataProvider('backends')]
 public function test_native_save_and_database_projection_do_not_materialize_empty_raw_status(string$backend):void{
  $r=$this->probe('without_claim',$backend);self::assertSame('',$r['initial_raw']);self::assertSame('pending',$r['initial_view']);self::assertSame('',$r['after_save']);self::assertSame(123,$r['native_order_id']);self::assertSame([['id'=>0,'raw'=>'','row'=>'wc-pending']],$r['crud_observed']);self::assertFalse($r['permission_after_save']);self::assertSame('',$r['marker']);
 }
 #[DataProvider('refused_candidates')]
 public function test_empty_status_exception_never_authorizes_other_native_or_foreign_states(string$mode):void{
  $r=$this->probe($mode,'hpos');self::assertFalse($r['accepted']);self::assertFalse($r['permission']);self::assertFalse($r['permission_after_save']);self::assertTrue($r['status_unchanged_on_refusal']);self::assertNotSame($r['expected_attempt'],$r['marker']);self::assertSame(0,$r['crud_calls']);self::assertSame(0,$r['status_actions']);
 }
 public static function refused_candidates():array{return array_map(static fn(string$m):array=>[$m],['saved_empty','store_empty','null','future','paid','foreign_root','foreign_item','wrong_latch','custom_default','view_mutates_id','view_mutates_marker']);}
 public function test_store_native_checkout_draft_stays_draft():void{$r=$this->probe('store_draft','hpos');self::assertTrue($r['accepted']);self::assertTrue($r['permission']);self::assertSame('checkout-draft',$r['after_initialization']);self::assertSame($r['expected_attempt'],$r['marker']);self::assertSame(0,$r['crud_calls']);}
 #[DataProvider('setter_races')]
 public function test_native_setter_callbacks_cannot_issue_permission_for_a_changed_object(string$mode):void{$r=$this->probe($mode,'hpos');self::assertFalse($r['accepted']);self::assertFalse($r['permission']);self::assertFalse($r['permission_after_save']);self::assertNotSame($r['expected_attempt'],$r['marker']);self::assertSame(0,$r['crud_calls']);}
 public static function setter_races():array{return[['setter_mutates_id'],['setter_mutates_marker']];}
 public function test_native_setter_must_confirm_the_same_pending_default():void{$r=$this->probe('setter_changes_default','hpos');self::assertFalse($r['accepted']);self::assertFalse($r['permission']);self::assertFalse($r['permission_after_save']);self::assertSame('',$r['marker']);self::assertSame(0,$r['crud_calls']);self::assertSame(['from'=>'failed','to'=>'pending','note'=>'','manual'=>false],$r['transition']);self::assertSame('RuntimeException',$r['error']);}
 public function test_callback_cannot_turn_the_initial_birth_into_a_saved_pending_order():void{$r=$this->probe('view_mutates_pending_id','hpos');self::assertFalse($r['accepted']);self::assertFalse($r['permission']);self::assertFalse($r['permission_after_save']);self::assertSame(123,$r['native_order_id']);self::assertSame('pending',$r['after_initialization']);self::assertSame('',$r['marker']);self::assertSame(0,$r['crud_calls']);}
 private function probe(string$mode,string$backend):array{
  $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-pinned-native-status-probe.php',$mode,$backend],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr);$r=json_decode($stdout,true,16,JSON_THROW_ON_ERROR);self::assertFalse($r['physical_qualification']);return$r;
 }
}
