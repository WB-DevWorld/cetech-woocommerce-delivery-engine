<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class PolicySavedAudienceTest extends TestCase {
 public static function cases():array{$positive=['owner','guest-received','guest-pay','guest-view','email','email-no-browser','email-case'];$negative=['foreign-customer','guest-no-key','guest-wrong-key','guest-wrong-id','guest-ambiguous','guest-overflow','guest-array-key','unknown-surface','native-missing','copied-id','copied-key','copied-owner','email-admin','email-foreign-id','email-foreign-key','email-foreign-owner','email-wrong-recipient','email-list','email-display-name','email-control','email-invalid','email-not-order'];$out=[];foreach($positive as$key)$out[$key]=[$key,true];foreach($negative as$key)$out[$key]=[$key,false];return$out;}
 #[DataProvider('cases')]
 public function test_actual_native_audience_context(string$case,bool$expected):void{$path=dirname(__DIR__,2).'/Support/CustomerPolicy/customer-policy-audience-probe.php';$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' '.escapeshellarg($case);exec($cmd,$lines,$status);self::assertSame(0,$status,implode("\n",$lines));$result=json_decode(implode("\n",$lines),true,16,JSON_THROW_ON_ERROR);self::assertSame($expected,$result['allowed']);self::assertLessThanOrEqual(1,$result['lookups']);}
}
