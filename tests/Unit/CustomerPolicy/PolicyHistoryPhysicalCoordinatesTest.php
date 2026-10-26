<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
/** Actual qualification helpers over declared SQLite item rows; no site boot. */
final class PolicyHistoryPhysicalCoordinatesTest extends TestCase {
 #[DataProvider('modes')]
 public function test_retained_history_coordinates_are_physical_and_closed(string$mode,bool$accepted):void {
  $p=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-history-physical-coordinates-probe.php',$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($p);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$error.' '.$out);$r=json_decode($out,true,16,JSON_THROW_ON_ERROR);
  self::assertFalse($r['physical_qualification']);self::assertSame($accepted,$r['accepted']);self::assertSame(0,$r['coordinate_getters']);self::assertSame(0,$r['product_reads']);self::assertSame(0,$r['writes_after_seed']);self::assertTrue($r['rows_unchanged']);
  if($accepted){self::assertSame(['product_id'=>33,'variation_id'=>$mode==='simple'?0:44],$r['coordinates']);self::assertTrue($r['history_unchanged_after_catalog_delete']);}
 }
 public static function modes():array{return[['variation',true],['simple',true],['duplicate_product',false],['duplicate_variation',false],['missing_product',false],['missing_variation',false],['wrong_owner',false],['wrong_item_type',false],['zero_product',false],['negative_product',false],['leading_zero',false],['overflow',false],['fraction',false],['empty',false],['nul',false],['null',false],['negative_variation',false]];}
}
