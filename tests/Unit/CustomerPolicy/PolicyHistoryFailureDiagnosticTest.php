<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
/** Failure diagnostics execute the actual wrapper; no physical history acceptance is implied. */
final class PolicyHistoryFailureDiagnosticTest extends TestCase{
 #[DataProvider('classes')]
 public function test_failure_retains_the_identical_throwable_and_only_closed_labels(string$case,string$class):void{
  $r=$this->probe($case);self::assertTrue($r['caught_same']);self::assertTrue($r['refused']);self::assertSame(1,$r['work_calls']);self::assertSame(1,$r['write_calls']);self::assertSame([['r24_history_failure'=>['mode'=>'lifecycle','step'=>'shipment_cards','class'=>$class]]],$r['writes']);
 }
 public static function classes():array{return[['runtime','runtime'],['invalid_argument','invalid_argument'],['type_error','type_error'],['error','error'],['exception','exception']];}
 public function test_success_output_is_unchanged_and_not_wrapped_as_a_failure():void{$r=$this->probe('success');self::assertFalse($r['refused']);self::assertSame([['saved_packet_bytes_unchanged'=>true]],$r['writes']);self::assertSame(1,$r['write_calls']);}
 public function test_private_writer_failure_cannot_replace_the_original_refusal():void{$r=$this->probe('writer_failure');self::assertTrue($r['caught_same']);self::assertTrue($r['refused']);self::assertSame([],$r['writes']);self::assertSame(1,$r['write_calls']);}
 public function test_unknown_step_is_closed_again_immediately_before_serializing():void{$r=$this->probe('unknown_label');self::assertTrue($r['caught_same']);self::assertSame('unobserved',$r['writes'][0]['r24_history_failure']['step']);}
 public function test_unknown_mode_never_executes_the_work_and_is_closed():void{$r=$this->probe('invalid_mode');self::assertTrue($r['refused']);self::assertSame(0,$r['work_calls']);self::assertSame('unobserved',$r['writes'][0]['r24_history_failure']['mode']);}
 public function test_no_current_uses_its_distinct_closed_mode():void{$r=$this->probe('no_current');self::assertTrue($r['caught_same']);self::assertSame(['mode'=>'no_current','step'=>'source_probe','class'=>'runtime'],$r['writes'][0]['r24_history_failure']);}
 public function test_successful_cleanup_does_not_hide_the_original_failed_operation():void{$r=$this->probe('cleanup_preserves');self::assertTrue($r['caught_same']);self::assertSame('shipment_cards',$r['writes'][0]['r24_history_failure']['step']);}
 public function test_cleanup_failure_reports_the_actual_replacement_exception_and_operation():void{$r=$this->probe('cleanup_replaces');self::assertTrue($r['caught_same']);self::assertSame(['mode'=>'lifecycle','step'=>'source_restore','class'=>'runtime'],$r['writes'][0]['r24_history_failure']);}
 private function probe(string$case):array{
  $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-history-failure-diagnostic-probe.php',$case],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr.' '.$stdout);self::assertStringNotContainsString('PRIVATE-',$stdout);$r=json_decode($stdout,true,16,JSON_THROW_ON_ERROR);self::assertFalse($r['physical_qualification']);self::assertTrue($r['context_restored']);return$r;
 }
}
