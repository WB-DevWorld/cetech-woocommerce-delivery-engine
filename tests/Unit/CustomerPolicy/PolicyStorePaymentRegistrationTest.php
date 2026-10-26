<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual runtime/WP_Hook/Woo Legacy callbacks; declared in-memory native and SQL ports. */
final class PolicyStorePaymentRegistrationTest extends TestCase {
 #[DataProvider('idle_cases')]
 public function test_idle_policy_registration_preserves_the_exact_native_quote_hook_census(string$mode):void{
  $r=$this->probe($mode);self::assertSame(0,$r['before_policy_callbacks']);self::assertTrue($r['before_quote_supported']);self::assertTrue($r['altered_quote_supported']);self::assertSame(0,$r['after_policy_callbacks']);self::assertNull($r['completed']);
 }
 public static function idle_cases():array{return[['off'],['register-repeat']];}
 #[DataProvider('successful_cases')]
 public function test_only_successful_store_admission_installs_one_guard_before_payment(string$mode,bool$store):void{
  $r=$this->probe($mode);self::assertSame(0,$r['before_policy_callbacks']);self::assertTrue($r['before_quote_supported']);self::assertTrue($r['completed']);self::assertTrue($r['admitted']);self::assertSame($store?1:0,$r['before_payment_policy_callbacks']);self::assertSame($store?1:0,$r['after_policy_callbacks']);self::assertSame($store?true:null,$r['context_passed']);if($mode==='store-repeat'){self::assertTrue($r['completed_again']);self::assertTrue($r['own_completed_again']);}
 }
 public static function successful_cases():array{return[['store-hpos',true],['store-cpt',true],['store-repeat',true],['classic',false]];}
 #[DataProvider('refused_cases')]
 public function test_failed_admission_never_installs_policy_payment_authority(string$mode,bool$nativeHooks):void{
  $r=$this->probe($mode);self::assertSame(0,$r['before_policy_callbacks']);self::assertTrue($r['before_quote_supported']);self::assertSame($nativeHooks,$r['altered_quote_supported']);self::assertFalse($r['completed']);self::assertSame(0,$r['before_payment_policy_callbacks']);self::assertSame(0,$r['after_policy_callbacks']);self::assertNull($r['context_passed']);
 }
 public static function refused_cases():array{return[['store-invalid-revision',true],['store-mutated',true],['store-wrong-route',true],['bad-native-priority',false],['bad-native-arity',false],['unknown-callback',false],['duplicate-native',false]];}
 private function probe(string$mode):array{
  $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-store-payment-registration-probe.php',$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr);$r=json_decode($stdout,true,16,JSON_THROW_ON_ERROR);self::assertFalse($r['physical_qualification']);self::assertSame(0,$r['getters_in_sql']);return$r;
 }
}
