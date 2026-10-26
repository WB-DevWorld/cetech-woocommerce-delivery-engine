<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class PolicyNativeAttemptRecoveryTest extends TestCase {
 #[DataProvider('recoveries')]
 public function test_marker_only_retry_detaches_only_pointer_and_creates_new_order_under_same_original(string$mode):void{$out=$this->probe($mode);self::assertFalse($out['refused']);foreach(['detached','old_unchanged','same_attempt','fresh_id','old_owned','old_unpayable']as$key)self::assertTrue($out[$key],$key);self::assertSame(0,$out['getters_in_sql']);}
 public static function recoveries():array{return[['hpos'],['cpt'],['store-hpos'],['store-cpt']];}
 #[DataProvider('refusals')]
 public function test_foreign_duplicate_raced_or_unknown_marker_observation_cannot_clear_pointer(string$mode):void{$out=$this->probe($mode);self::assertTrue($out['refused']);self::assertFalse($out['detached']);self::assertTrue($out['old_unchanged']);self::assertSame(0,$out['getters_in_sql']);}
 public static function refusals():array{return array_map(static fn(string$mode):array=>[$mode],['foreign-marker','duplicate-marker','foreign-index','marker-aba','original-aba','paid','rollback','retire']);}
 private function probe(string$mode):array{$process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-native-attempt-recovery-probe.php',$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr);return json_decode($stdout,true,16,JSON_THROW_ON_ERROR);}
}
