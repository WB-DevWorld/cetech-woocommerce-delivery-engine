<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
/** Genuine WC_Data metadata-cache behavior with declared memory ports; no database service. */
final class PolicyHistoryMetadataCacheTest extends TestCase{
 #[DataProvider('coordinates')]
 public function test_raw_seed_and_restore_require_only_the_exact_native_owner_cache_eviction(string$kind):void{
  $r=$this->probe($kind);self::assertSame('original',$r['before']);self::assertSame('original',$r['stale_seed']);self::assertSame('future',$r['fresh_future']);self::assertSame('future',$r['stale_restore']);self::assertSame('original',$r['fresh_restored']);self::assertSame(71,$r['physical_row_id']);self::assertSame('original',$r['physical_value']);self::assertSame(3,$r['store_reads']);self::assertSame([[$r['expected_key'],$r['expected_group']],[$r['expected_key'],$r['expected_group']]],$r['deletes']);
 }
 public static function coordinates():array{return[['item'],['cpt'],['hpos']];}
 #[DataProvider('invalid_coordinates')]
 public function test_foreign_or_malformed_owner_coordinates_do_not_evict_any_cache(string$invalid):void{
  $r=$this->probe('item',$invalid);self::assertTrue($r['refused']);self::assertSame(0,$r['deletes']);self::assertSame(1,$r['store_reads']);self::assertSame('original',$r['cached_value']);self::assertSame(71,$r['physical_row_id']);self::assertSame('future',$r['physical_value']);
 }
 public static function invalid_coordinates():array{return array_map(static fn(string$mode):array=>[$mode],['table','pk','fk','zero','leading-zero','overflow','object']);}
 private function probe(string$kind,string$invalid=''):array{
  $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-history-metadata-cache-probe.php',$kind,$invalid],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr.' '.$stdout);$r=json_decode($stdout,true,16,JSON_THROW_ON_ERROR);self::assertFalse($r['physical_qualification']);return$r;
 }
}
