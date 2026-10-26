<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual pinned WC_Data/WC_Meta_Data methods, isolated from the WordPress test stubs. */
final class PolicyPinnedNativeMetaReloadTest extends TestCase {
 public function test_native_add_save_and_reload_keep_the_same_acknowledged_rows():void{
  $out=$this->probe('same');self::assertSame(['key','value','id'],$out['native_added_keys']);self::assertSame(['id','key','value'],$out['native_read_keys']);self::assertTrue($out['same_saved_rows']);self::assertTrue($out['strict_unchanged']);self::assertTrue($out['same_native']);
 }
 #[DataProvider('changed_rows')]
 public function test_reload_does_not_relax_outer_indexes_membership_ids_types_or_values(string$mode):void{
  $out=$this->probe($mode);self::assertTrue($out['strict_unchanged']);self::assertFalse($out['same_native'],$mode);
 }
 public static function changed_rows():array{return array_map(static fn(string$mode):array=>[$mode],['root_index','item_index','root_order','item_order','duplicate','id','id_type','key','value','value_type','current','original','extra','missing']);}
 public function test_strict_original_object_binding_still_detects_raw_field_reordering():void{
  $out=$this->probe('strict');self::assertFalse($out['strict_unchanged']);self::assertTrue($out['same_native']);
 }
 private function probe(string$mode):array{
  $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-native-meta-reload-probe.php',$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr);return json_decode($stdout,true,16,JSON_THROW_ON_ERROR);
 }
}
