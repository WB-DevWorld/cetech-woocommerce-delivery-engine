<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual production reader over owned native-shaped objects and real in-memory SQL. */
final class PolicySavedNativeTest extends TestCase {
 #[DataProvider('cases')]
 public function test_saved_packet_native_integrity_and_refusals(string$mode,bool$accepted,bool$before_sql=false):void{
  $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__,2).'/Support/CustomerPolicy/customer-policy-native-probe.php').' '.escapeshellarg($mode);$lines=[];$code=0;exec($command.' 2>&1',$lines,$code);self::assertSame(0,$code,implode("\n",$lines));$r=json_decode(implode("\n",$lines),true,16,JSON_THROW_ON_ERROR);self::assertSame($accepted,$r['accepted']);self::assertSame($accepted?1:0,$r['count']);self::assertSame(0,$r['getters_in_sql']);self::assertSame(0,$r['writes']);self::assertTrue($r['preserved']);if($before_sql)self::assertSame(0,$r['owners']);if($accepted){self::assertSame(1,$r['owners']);self::assertSame(1,$r['rollback']);self::assertSame(1,$r['retire']);}
 }
 public static function cases():array{return[
  'HPOS genuine complete'=>['hpos',true], 'CPT genuine complete'=>['cpt',true], 'raw cache-hidden still read'=>['filter-hidden',true], 'paid HPOS read only'=>['paid',true], 'paid CPT read only'=>['cpt-paid',true],
  'unauthorized no SQL'=>['unauthorized',false,true], 'missing packet'=>['missing-packet',false,true], 'duplicate format'=>['duplicate-format',false,true], 'null manifest'=>['null-manifest',false,true], 'future packet'=>['future-packet',false,true], 'future namespace'=>['unknown-prefix',false], 'case-folded namespace'=>['casefold-prefix',false], 'legacy exact bytes changed'=>['legacy-changed',false,true], 'foreign quote owner'=>['foreign-quote',false,true], 'wrong raw cart key'=>['swapped-key',false,true], 'unsupported fee'=>['fee',false,true], 'physical money changed'=>['physical-money-changed',false], 'rollback unacknowledged'=>['rollback-failed',false], 'retirement unacknowledged'=>['retire-failed',false], 'retirement native mutation'=>['retire-mutated',false], 'participant refused'=>['tables-refused',false],
 ];}
}
