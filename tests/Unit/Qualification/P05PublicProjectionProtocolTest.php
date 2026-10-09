<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Qualification;
use PHPUnit\Framework\TestCase;
final class P05PublicProjectionProtocolTest extends TestCase {
 public function test_public_browser_protocol_rejects_private_and_malformed_promises():void {
  $script=dirname(__DIR__,3).'/scripts/qualification/test-opening-promise-native-configuration-browser.py';
  $process=proc_open(['python3',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
  self::assertSame(0,$exit,$stdout.$stderr);
 }
}
