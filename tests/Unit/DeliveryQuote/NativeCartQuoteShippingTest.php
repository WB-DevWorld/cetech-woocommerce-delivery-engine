<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class NativeCartQuoteShippingTest extends TestCase {
	public function test_fresh_request_restores_exact_existing_native_calculation_without_repricing_or_writing():void { $out=$this->probe('matching'); self::assertTrue($out['available']); self::assertTrue($out['calculated']); self::assertSame(1,$out['selected_count']); self::assertSame(1,$out['packages']); self::assertSame(1,$out['package_reads']); $this->assert_read_only($out); }
	#[DataProvider('invalid_cached_facts')]
	public function test_missing_changed_unknown_or_unbounded_cache_never_fabricates_calculated_shipping(string $mode):void { $out=$this->probe($mode); self::assertFalse($out['available']); self::assertFalse($out['calculated']); self::assertSame(0,$out['selected_count']); self::assertSame(0,$out['packages']); $this->assert_read_only($out); }
	public static function invalid_cached_facts():array { return array_map(static fn(string $v):array=>[$v],['missing_cache','changed_hash','changed_method','unknown_class','wrong_totals','changed_quantity','overflow']); }
	public function test_unsupported_native_package_callback_is_refused_before_it_is_invoked():void { $out=$this->probe('unsupported_package_callback'); self::assertFalse($out['available']); self::assertSame(0,$out['package_reads']); self::assertFalse($out['calculated']); $this->assert_read_only($out); }
	public function test_missing_native_shipping_version_refuses_before_hash_or_transient_publication():void { $out=$this->probe('missing_version'); self::assertFalse($out['available']); self::assertFalse($out['calculated']); self::assertSame(0,$out['hash_calls']); self::assertSame(0,$out['transient_writes']); $this->assert_read_only($out); }
	public function test_only_explicit_admitted_preparation_invokes_native_totals_and_native_session_publication():void { $out=$this->probe('admitted_prepare'); self::assertTrue($out['available']); self::assertSame(1,$out['totals_calls']); self::assertSame(1,$out['save_calls']); self::assertSame(0,$out['package_reads']); self::assertSame(0,$out['wakeup_calls']); }
	private function assert_read_only(array $out):void { self::assertSame(0,$out['transient_writes']); self::assertSame(0,$out['rate_getters']); self::assertSame(0,$out['totals_calls']); self::assertSame(0,$out['save_calls']); self::assertSame(0,$out['write_calls']); self::assertSame(0,$out['wakeup_calls']); self::assertTrue($out['session_unchanged']); }
	private function probe(string $mode):array { $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/DeliveryQuote/native-cart-shipping-probe.php',$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes); self::assertIsResource($process); fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0,proc_close($process),$stderr); return json_decode($stdout,true,16,JSON_THROW_ON_ERROR); }
}
