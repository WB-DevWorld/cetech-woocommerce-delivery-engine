<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\TestCase;

/** Actual qualification helper transformations; native database/browser acceptance stays separate. */
final class DomainHistoryHelperTest extends TestCase {
    public function test_actual_private_copy_config_meta_restoration_and_child_process_guards():void {
        $root=dirname(__DIR__,3);$argv=[PHP_BINARY,$root.'/tests/Support/CustomerPolicy/customer-policy-history-helper-probe.php',$root.'/scripts/qualification/customer-policy-history.php'];$pipes=[];$process=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$error);$facts=json_decode($out,true,8,JSON_THROW_ON_ERROR);self::assertSame('PASS',$facts['status']);self::assertSame(90,$facts['checks']);self::assertFalse($facts['physical_sql_or_wordpress']);
    }
}
