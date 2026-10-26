<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Issuer-held permissions use genuine originals; no physical Woo/SQL qualification claim. */
final class PolicyNativeAttemptAuthorityTest extends TestCase {
 public static function backends():array{return[['hpos'],['cpt']];}
 #[DataProvider('backends')]
 public function test_permission_requires_exact_issuer_order_prepared_and_raw_key_latch(string$backend):void{$r=$this->probe('authority',$backend);foreach(['initial','same_object','foreign_issuer_refused','copied_order_refused','reissued_handle_refused','wrong_raw_key_refused','marker_unchanged']as$key)self::assertTrue($r[$key],$key);}
 #[DataProvider('bad_markers')]
 public function test_marker_or_partial_rows_never_grant_initial_write_permission(string$mode):void{foreach(['hpos','cpt']as$backend){$r=$this->probe($mode,$backend);foreach(['initial','refused','still_owned','stage_refused','revoked']as$key)self::assertTrue($r[$key],$backend.':'.$key);}}
 public static function bad_markers():array{return array_map(static fn(string$m):array=>[$m],['duplicate','null','future','foreign','unknown_root','item']);}
 #[DataProvider('prebind_failures')]
 public function test_every_prebind_failure_revokes_request_permission_and_keeps_permanent_marker(string$mode):void{foreach(['hpos','cpt']as$backend){$r=$this->probe('revoke-'.$mode,$backend);foreach(['initial','stage_refused','revoked','permanent_marker_unchanged']as$key)self::assertTrue($r[$key],$backend.':'.$key);}}
 public static function prebind_failures():array{return array_map(static fn(string$m):array=>[$m],['authorize','native','legacy','binding','physical','original','runtime-legacy']);}
 public function test_existing_foreign_marker_is_never_overwritten_or_adopted():void{$r=$this->probe('preexisting','hpos');self::assertTrue($r['rejected']);self::assertFalse($r['permission']);self::assertSame([$r['expected_existing']],$r['marker_values']);}
 public function test_private_permission_cannot_be_cloned_or_serialized_and_debug_is_redacted():void{$r=$this->probe('privacy','hpos');foreach(['initial','clone_refused','json_refused','serialize_refused','unserialize_refused','debug_private','original_permission_unchanged']as$key)self::assertTrue($r[$key],$key);}
 private function probe(string$mode,string$backend):array{$process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-native-attempt-authority-probe.php',$mode,$backend],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr);$r=json_decode($stdout,true,16,JSON_THROW_ON_ERROR);self::assertFalse($r['physical_native_claim']);if(isset($r['getters_in_owned_sql']))self::assertSame(0,$r['getters_in_owned_sql']);return$r;}
}
