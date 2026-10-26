<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
/** Actual Woo Factory/OrderCache and native order/cache methods; declared memory ports only. */
final class PolicyHistoryOrderObjectCacheTest extends TestCase {
 #[DataProvider('backends')]
 public function test_history_reads_fresh_native_order_after_owned_raw_restore(string$backend):void {
  $p=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-history-order-object-cache-probe.php',$backend],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($p);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$error.' '.$out);$r=json_decode($out,true,16,JSON_THROW_ON_ERROR);
  self::assertFalse($r['physical_qualification']);self::assertSame($backend,$r['backend']);self::assertSame('future',$r['future']);self::assertSame($backend==='hpos',$r['factory_reuses_same_object']);self::assertSame($backend==='hpos'?'future':'original',$r['factory_after_restore']);self::assertSame('original',$r['direct_after_restore']);self::assertSame('original',$r['helper_after_restore']);self::assertSame('original',$r['physical_value']);self::assertSame(71,$r['physical_row_id']);self::assertSame(1,$r['metadata_deletes']);self::assertSame(0,$r['broad_flushes']);
 }
 public static function backends():array{return[['hpos'],['cpt']];}
}
