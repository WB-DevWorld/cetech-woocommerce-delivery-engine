<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteSavedOrderNativeTaxProjectionTest extends TestCase {
	#[DataProvider('accepted_native_maps')]
	public function test_prewarm_and_saved_admission_use_one_native_matcher_and_preserve_the_actual_tax_map(string $mode):void { $out=$this->probe($mode); self::assertTrue($out['accepted'],$mode); self::assertSame(1,$out['finders']); self::assertTrue($out['actual_map_unchanged']); self::assertSame(0,$out['callbacks']); }
	public static function accepted_native_maps():array { return [['prewarm'],['zero'],['empty'],['paid']]; }
	#[DataProvider('changed_native_facts')]
	public function test_foreign_zero_ids_subsets_tiny_unrounded_amounts_and_source_or_native_changes_refuse(string $mode):void { $out=$this->probe($mode); self::assertFalse($out['accepted'],$mode); self::assertLessThanOrEqual(1,$out['finders']); self::assertTrue($out['actual_map_unchanged']); self::assertSame(0,$out['callbacks']); }
	public static function changed_native_facts():array { return array_map(static fn(string $mode):array=>[$mode],['foreign','subset','tiny','money','source_before','source_during','hook_before','hook_during','cached_option','location','customer','instance','fee','exempt','precision']); }
	public function test_saved_current_guard_repeats_exact_sources_and_session_with_no_native_calls_under_owned_sql():void { $out=$this->probe('guard'); self::assertTrue($out['accepted']); self::assertTrue($out['guard_accepted']); self::assertSame(7,$out['guard_reads']); self::assertSame(0,$out['owned_native_calls']); self::assertSame(1,$out['finders']); }
	#[DataProvider('current_guard_changes')]
	public function test_current_saved_guard_refuses_changed_source_hooks_session_or_physical_saved_facts(string $mode):void { $out=$this->probe($mode); self::assertTrue($out['accepted']); self::assertFalse($out['guard_accepted'],$mode); self::assertSame(0,$out['owned_native_calls']); self::assertSame(0,$out['callbacks']); }
	public static function current_guard_changes():array { return [['guard_source_changed'],['guard_hook_changed'],['guard_session_changed'],['guard_saved_changed']]; }
	public function test_exact_native_order_tax_callback_is_accepted_and_its_identity_is_held_through_pure_saved_verification():void { $out=$this->probe('guard_native_hook'); self::assertTrue($out['accepted']); self::assertTrue($out['guard_accepted']); self::assertTrue($out['fresh_hooks_supported']); self::assertSame(1,$out['native_tax_callbacks']); self::assertSame(1,$out['finders']); self::assertSame(7,$out['guard_reads']); self::assertSame(0,$out['owned_native_calls']); self::assertSame(0,$out['callbacks']); }
	#[DataProvider('native_hook_denials')]
	public function test_unknown_or_altered_native_order_tax_callback_or_view_method_filter_is_refused_before_invocation(string $mode):void { $out=$this->probe($mode); self::assertFalse($out['accepted'],$mode); self::assertSame(0,$out['finders']); self::assertSame(0,$out['native_tax_callbacks']); self::assertSame(0,$out['callbacks']); }
	public static function native_hook_denials():array { return array_map(static fn(string $mode):array=>[$mode],['native_hook_unknown','native_hook_priority','native_hook_args','native_hook_foreign','native_hook_registration','native_hook_subclass','native_hook_method_filter']); }
	public function test_replacing_all_native_controller_hooks_with_another_supported_instance_during_native_matcher_refuses():void { $out=$this->probe('native_hook_during_replace'); self::assertFalse($out['accepted']); self::assertTrue($out['fresh_hooks_supported']); self::assertSame(1,$out['finders']); self::assertSame(1,$out['native_tax_callbacks']); self::assertSame(0,$out['callbacks']); }
	#[DataProvider('held_hook_changes')]
	public function test_saved_guard_refuses_supported_controller_replacement_before_or_after_owned_sql_without_native_calls(string $mode):void { $out=$this->probe($mode); self::assertTrue($out['accepted']); self::assertFalse($out['guard_accepted'],$mode); self::assertTrue($out['fresh_hooks_supported']); self::assertSame('guard_native_hook_replaced_after_sql'===$mode?7:0,$out['guard_reads']); self::assertSame(0,$out['owned_native_calls']); self::assertSame(0,$out['callbacks']); }
	public static function held_hook_changes():array { return [['guard_native_hook_replaced'],['guard_native_hook_replaced_after_sql']]; }
	public function test_saved_guard_refuses_late_shipping_method_view_filter_with_no_invocation():void { $out=$this->probe('guard_native_hook_method_filter'); self::assertTrue($out['accepted']); self::assertFalse($out['guard_accepted']); self::assertSame(0,$out['owned_native_calls']); self::assertSame(0,$out['callbacks']); }
	#[DataProvider('dynamic_option_boundaries')]
	public function test_original_method_option_callback_before_or_during_native_prewarm_refuses_without_invocation(string $mode):void { $out=$this->probe($mode); self::assertFalse($out['accepted']); self::assertSame('dynamic_option_during'===$mode?1:0,$out['finders']); self::assertSame(0,$out['callbacks']); }
	public static function dynamic_option_boundaries():array { return [['dynamic_option_before'],['dynamic_option_during']]; }
	#[DataProvider('dynamic_option_guard_boundaries')]
	public function test_original_method_option_registration_remains_fenced_before_and_after_owned_sql(string $mode):void { $out=$this->probe($mode); self::assertTrue($out['accepted']); self::assertFalse($out['guard_accepted']); self::assertSame('guard_dynamic_option_after_sql'===$mode?7:0,$out['guard_reads']); self::assertSame(0,$out['owned_native_calls']); self::assertSame(0,$out['callbacks']); }
	public static function dynamic_option_guard_boundaries():array { return [['guard_dynamic_option'],['guard_dynamic_option_after_sql']]; }
	public function test_saved_native_mapping_uses_the_preloaded_line_census_and_never_calls_a_fresh_item_factory():void { $out=$this->probe('zero'); self::assertTrue($out['accepted']); self::assertSame(0,$out['factory_calls']); self::assertSame(1,$out['line_census_reads']); self::assertTrue($out['actual_map_unchanged']); }
	#[DataProvider('cached_line_census_changes')]
	public function test_missing_extra_foreign_key_id_parent_or_class_in_cached_line_census_refuses_without_factory_fallback(string $mode):void { $out=$this->probe($mode); self::assertFalse($out['accepted'],$mode); self::assertSame(0,$out['factory_calls']); self::assertSame(1,$out['line_census_reads']); self::assertSame(0,$out['callbacks']); }
	public static function cached_line_census_changes():array { return array_map(static fn(string $mode):array=>[$mode],['cached_missing','cached_extra','cached_key','cached_id','cached_parent','cached_class','cached_subclass','cached_foreign']); }
	private function probe(string $mode):array { $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/DeliveryQuote/native-saved-tax-projection-probe.php',$mode], [['pipe','r'],['pipe','w'],['pipe','w']],$pipes); self::assertIsResource($process); fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0,proc_close($process),$stderr); return json_decode($stdout,true,16,JSON_THROW_ON_ERROR); }
}
