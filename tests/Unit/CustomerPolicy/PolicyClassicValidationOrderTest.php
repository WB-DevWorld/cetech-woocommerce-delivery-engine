<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class PolicyClassicValidationOrderTest extends TestCase {
 public function test_retained_context_refusal_does_not_freeze_before_correction_and_explicit_review():void{
  $out=$this->probe('context');self::assertTrue($out['no_original_after_invalid']);self::assertTrue($out['first_error']);self::assertSame(0,$out['first_prepare_calls']);self::assertSame(['retained'],$out['first_events']);self::assertTrue($out['corrected_review']);self::assertFalse($out['corrected_error']);self::assertTrue($out['corrected_frozen']);self::assertSame(['retained','policy'],$out['corrected_events']);self::assertTrue($out['repeat_same_original']);self::assertTrue($out['changed_retry_refused']);self::assertTrue($out['pending_original_preserved']);self::assertTrue($out['pending_clear_refused']);self::assertSame(0,$out['native_sql']);self::assertSame(PHP_INT_MAX,$out['priority']);
 }
 #[DataProvider('existing_refusals')]
 public function test_native_error_or_notice_blocks_freezing_after_all_validation_callbacks(string$mode,bool$error):void{
  $out=$this->probe($mode);self::assertSame(0,$out['first_prepare_calls']);self::assertSame($error,$out['first_error']);self::assertSame($mode==='error'?0:1,$out['first_notice']);self::assertSame(['retained'],$out['first_events']);self::assertTrue($out['no_original_after_invalid']);self::assertSame(0,$out['native_sql']);self::assertSame(PHP_INT_MAX,$out['priority']);
 }
 public static function existing_refusals():array{return[['error',true],['notice',false],['both',true]];}
 private function probe(string$mode):array{$process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-classic-validation-order-probe.php',$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr);return json_decode($stdout,true,16,JSON_THROW_ON_ERROR);}
}
