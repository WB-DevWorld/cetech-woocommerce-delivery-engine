<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Genuine installed-Woo metadata methods through actual composed legacy line hooks. */
final class PolicyUnsavedOwnershipTest extends TestCase {
 #[DataProvider('native_rows')]
 public function test_unsaved_ordinary_native_rows_do_not_claim_history_and_real_policy_markers_still_refuse(string$mode,bool$on,bool$owned):void{
  $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-unsaved-ownership-probe.php',$mode,$on?'on':'off'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr);$out=json_decode($stdout,true,16,JSON_THROW_ON_ERROR);
  self::assertSame($owned,$out['facts_attempted']);self::assertSame($owned,$out['reader_attempted']);self::assertSame($owned,$out['read_refused']);self::assertSame(!$owned,$out['read_empty']);self::assertSame($owned,$out['hook_refused']);self::assertSame($owned?0:1,$out['builder_calls']);self::assertSame(0,$out['sql_calls']);self::assertSame(0,$out['source_calls']);self::assertSame($on,$out['policy_enabled']);
 }
 public static function native_rows():array{$cases=[['empty',false,false],['ordinary',false,false],['ordinary',true,false],['item_ordinary',false,false],['item_ordinary',true,false],['own_root',false,true],['own_root',true,true],['own_item',false,true],['own_item',true,true]];foreach(['null','future','duplicate','hidden','previous','current','malformed_key','unreadable_map','foreign_meta','over_budget']as$mode)$cases[]=[$mode,false,true];return$cases;}
}
