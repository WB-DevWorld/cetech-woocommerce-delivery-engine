<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Typed Stage/logger receipts; explicitly declared native trace and SQLite ports. */
final class PolicyLoggingOriginalFenceTest extends TestCase{
 #[DataProvider('cases')]
 public function test_first_logger_adoption_retains_both_original_and_reloaded_objects(string$mode,string$backend,bool$accepted,bool$originalIntact):void{
  $r=$this->probe($mode,$backend);self::assertTrue($r['typed_stage']);self::assertTrue($r['typed_logger_receipt']);self::assertSame(4,$r['logger_phase']);self::assertSame($originalIntact,$r['original_unchanged_before_logger']);self::assertSame($mode==='same-object',$r['same_object']);self::assertSame($accepted,$r['accepted']);
  if($accepted){self::assertTrue($r['next_unchanged']);self::assertTrue($r['exact_logger_target']);self::assertNull($r['error']);}else{self::assertNull($r['next_unchanged']);self::assertNull($r['exact_logger_target']);self::assertSame('RuntimeException',$r['error']);}
 }
 public static function cases():array{$rows=[];foreach(['hpos','cpt']as$backend)foreach([['untouched',true,true],['same-object',true,true],['a-money',false,false],['a-policy',false,false],['b-money',false,true],['b-policy',false,true]]as[$mode,$accepted,$original])$rows[$backend.' '.$mode]=[$mode,$backend,$accepted,$original];return$rows;}
 private function probe(string$mode,string$backend):array{
  $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-logging-original-fence-probe.php',$mode,$backend],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr.' '.$stdout);$r=json_decode($stdout,true,16,JSON_THROW_ON_ERROR);self::assertFalse($r['physical_qualification']);self::assertFalse($r['native_logger_source']);self::assertSame(0,$r['getters_in_sql']);return$r;
 }
}
