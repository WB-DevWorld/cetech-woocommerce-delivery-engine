<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class NativeCartQuoteShippingTest extends TestCase {
	public function test_fresh_native_rate_class_autoload_restores_the_existing_packet_without_getters_or_writes():void { $out=$this->unloaded_rate_probe('matching'); self::assertTrue($out['exact_shipping']); self::assertTrue($out['rate_initially_unloaded']); self::assertTrue($out['native_class_autoloadable']); self::assertTrue($out['available']); self::assertSame(1,$out['restore_autoloads']); self::assertTrue($out['calculated']); self::assertSame(1,$out['selected_count']); $this->assert_read_only($out); }
	#[DataProvider('unloaded_rate_refusals')]
	public function test_rate_autoload_does_not_allow_unavailable_lifecycle_or_foreign_cache_objects(string $mode):void { $out=$this->unloaded_rate_probe($mode); self::assertTrue($out['rate_initially_unloaded']); self::assertFalse($out['available']); self::assertFalse($out['calculated']); self::assertSame(0,$out['selected_count']); self::assertSame(0,$out['foreign_autoloads']); $this->assert_read_only($out); }
	public static function unloaded_rate_refusals():array { return array_map(static fn(string $mode):array=>[$mode],['unavailable','wakeup','unserialize','unknown_class']); }
	public function test_fresh_request_restores_exact_existing_native_calculation_without_repricing_or_writing():void { $out=$this->probe('matching'); self::assertTrue($out['available']); self::assertTrue($out['calculated']); self::assertSame(1,$out['selected_count']); self::assertSame(1,$out['packages']); self::assertSame(1,$out['package_reads']); $this->assert_read_only($out); }
	#[DataProvider('invalid_cached_facts')]
	public function test_missing_changed_unknown_or_unbounded_cache_never_fabricates_calculated_shipping(string $mode):void { $out=$this->probe($mode); self::assertFalse($out['available']); self::assertFalse($out['calculated']); self::assertSame(0,$out['selected_count']); self::assertSame(0,$out['packages']); $this->assert_read_only($out); }
	public static function invalid_cached_facts():array { return array_map(static fn(string $v):array=>[$v],['missing_cache','changed_hash','changed_method','unknown_class','wrong_totals','changed_quantity','overflow']); }
	public function test_unsupported_native_package_callback_is_refused_before_it_is_invoked():void { $out=$this->probe('unsupported_package_callback'); self::assertFalse($out['available']); self::assertSame(0,$out['package_reads']); self::assertFalse($out['calculated']); $this->assert_read_only($out); }
	public function test_real_retained_package_presentation_registration_allows_exact_cached_rate_read():void { $out=$this->probe('package_name_retained'); self::assertTrue($out['available']); self::assertSame(1,$out['package_name_calls']); self::assertSame('Shipment',$out['package_name']); self::assertSame(1,$out['hash_calls']); self::assertTrue($out['calculated']); $this->assert_read_only($out); }
	#[DataProvider('invalid_package_name_hooks')]
	public function test_foreign_or_modified_package_name_registration_refuses_before_callback(string $mode):void { $out=$this->probe($mode); self::assertFalse($out['available']); self::assertSame(0,$out['package_name_calls']); self::assertSame(0,$out['package_reads']); self::assertSame(0,$out['hash_calls']); self::assertFalse($out['calculated']); $this->assert_read_only($out); }
	public static function invalid_package_name_hooks():array { return array_map(static fn(string $v):array=>[$v],['package_name_unknown','package_name_priority','package_name_args','package_name_method','package_name_duplicate']); }
	public function test_missing_native_shipping_version_refuses_before_hash_or_transient_publication():void { $out=$this->probe('missing_version'); self::assertFalse($out['available']); self::assertFalse($out['calculated']); self::assertSame(0,$out['hash_calls']); self::assertSame(0,$out['transient_writes']); $this->assert_read_only($out); }
	public function test_only_explicit_admitted_preparation_invokes_native_totals_and_native_session_publication():void { $out=$this->probe('admitted_prepare'); self::assertTrue($out['available']); self::assertSame(1,$out['totals_calls']); self::assertSame(1,$out['save_calls']); self::assertSame(0,$out['package_reads']); self::assertSame(0,$out['wakeup_calls']); }
	private function assert_read_only(array $out):void { self::assertSame(0,$out['transient_writes']); self::assertSame(0,$out['rate_getters']); self::assertSame(0,$out['totals_calls']); self::assertSame(0,$out['save_calls']); self::assertSame(0,$out['write_calls']); self::assertSame(0,$out['wakeup_calls']); self::assertTrue($out['session_unchanged']); }
	/** Fresh transport shims isolate native class loading; this is not WordPress qualification. */
	private function unloaded_rate_probe(string $mode):array {
		$program= <<<'PHP'
require $argv[1].'/vendor/autoload.php';
class Calls { public static int $autoloads=0,$foreign=0,$getters=0,$totals=0,$saves=0,$writes=0,$wakeups=0; }
class WP_Object_Cache { private array $cache=['options'=>['alloptions'=>['_transient_shipping-transient-version'=>'1791380000']]]; private string $blog_prefix=''; protected array $global_groups=[]; private bool $multisite=false; }
class WC_Cart { public array $cart_contents=['line_one'=>['product_id'=>10,'variation_id'=>0,'quantity'=>2]]; protected array $totals=['shipping_total'=>'7','shipping_tax'=>'0.7']; protected array $shipping_methods=[]; protected bool $has_calculated_shipping=false; public function get_shipping_packages():array{return [['contents'=>$this->cart_contents,'destination'=>['country'=>'GH']]];} public function calculate_totals():never{++Calls::$totals;throw new LogicException('Unexpected totals calculation.');} public function calculated():bool{return $this->has_calculated_shipping;} public function selected():array{return $this->shipping_methods;} }
class WC_Session_Handler { public function __construct(protected array $_data){} public function save_data():never{++Calls::$saves;throw new LogicException('Unexpected persistence.');} public function set():never{++Calls::$writes;throw new LogicException('Unexpected setter.');} public function bytes():string{return serialize($this->_data);} }
class WC_Shipping { public array $packages=[]; private function get_package_hash(array $package):string{unset($package['rates'],$package['package_name']);return 'wc_ship_'.md5(serialize($package));} }
function get_option(string $name,mixed $default=null):mixed{return $default;} function wp_using_ext_object_cache():bool{return false;} function wp_installing():bool{return false;}
$mode=$argv[2];
spl_autoload_register(static function(string $class)use($mode):void {
    if('WC_Shipping_Rate'!==$class){++Calls::$foreign;return;} ++Calls::$autoloads;if('unavailable'===$mode){return;}
    $lifecycle=match($mode){'wakeup'=>'public function __wakeup():void{++Calls::$wakeups;}','unserialize'=>'public function __unserialize(array $data):void{++Calls::$wakeups;}',default=>''};
    eval('class WC_Shipping_Rate { protected array $data=[]; protected array $meta_data=[]; public function get_id():never{++Calls::$getters;throw new LogicException("Unexpected rate getter.");} public function get_cost():never{++Calls::$getters;throw new LogicException("Unexpected rate getter.");} public function get_taxes():never{++Calls::$getters;throw new LogicException("Unexpected rate getter.");} '.$lifecycle.' }');
});
$cart=new WC_Cart();$shipping=new WC_Shipping();$hash='wc_ship_'.md5(serialize(['contents'=>$cart->cart_contents,'destination'=>['country'=>'GH']]));$key='delivery_engine_selected_offer:1:group';
$rate='O:16:"WC_Shipping_Rate":2:{'.serialize("\0*\0data").serialize(['id'=>$key,'cost'=>'7','taxes'=>[1=>'0.7']]).serialize("\0*\0meta_data").serialize([]).'}';
if('unknown_class'===$mode){$rate='O:22:"PRIVATE_Unknown_Object":0:{}';}
$stored='a:2:{'.serialize('package_hash').serialize($hash).serialize('rates').'a:1:{'.serialize($key).$rate.'}}';
$session=new WC_Session_Handler(['cart_totals'=>serialize(['shipping_total'=>'7','shipping_tax'=>'0.7']),'chosen_shipping_methods'=>serialize([$key]),'shipping_for_package_0'=>$stored]);
$GLOBALS['woocommerce']=new class($cart,$session,$shipping){public function __construct(public WC_Cart $cart,public WC_Session_Handler $session,private WC_Shipping $ship){}public function shipping():WC_Shipping{return $this->ship;}};$GLOBALS['wp_filter']=[];$GLOBALS['wp_object_cache']=new WP_Object_Cache();
$before=$session->bytes();$unloaded=!class_exists('WC_Shipping_Rate',false);$available=false;
try{CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteShipping::restore_cached_calculation();$available=true;}catch(Throwable){}
$restore_autoloads=Calls::$autoloads;$autoloadable='matching'===$mode?class_exists('WC_Shipping_Rate'):null;
echo json_encode(['exact_shipping'=>'WC_Shipping'===get_class($shipping),'rate_initially_unloaded'=>$unloaded,'native_class_autoloadable'=>$autoloadable,'restore_autoloads'=>$restore_autoloads,'foreign_autoloads'=>Calls::$foreign,'available'=>$available,'calculated'=>$cart->calculated(),'selected_count'=>count($cart->selected()),'transient_writes'=>0,'rate_getters'=>Calls::$getters,'totals_calls'=>Calls::$totals,'save_calls'=>Calls::$saves,'write_calls'=>Calls::$writes,'wakeup_calls'=>Calls::$wakeups,'session_unchanged'=>$before===$session->bytes()],JSON_THROW_ON_ERROR);
PHP;
		$process=proc_open([PHP_BINARY,'-r',$program,dirname(__DIR__,3),$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr);return json_decode($stdout,true,16,JSON_THROW_ON_ERROR);
	}
	private function probe(string $mode):array { $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/DeliveryQuote/native-cart-shipping-probe.php',$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes); self::assertIsResource($process); fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0,proc_close($process),$stderr); return json_decode($stdout,true,16,JSON_THROW_ON_ERROR); }
}
