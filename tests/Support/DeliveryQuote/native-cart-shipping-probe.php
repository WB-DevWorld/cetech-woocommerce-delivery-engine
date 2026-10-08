<?php
declare(strict_types=1);
// Native-shape cache protocol, not an actual WordPress/WooCommerce qualification.
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
use CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteShipping;
class Calls { public static int $totals=0; public static int $save=0; public static int $writes=0; public static int $wakeup=0; public static int $package=0; public static int $getters=0; public static int $hashes=0; public static int $transient_writes=0; public static int $package_names=0; }
class Trap { public function __wakeup():void { ++Calls::$wakeup; } }
class WP_Hook { public array $callbacks=[]; }
class WP_Object_Cache { private array $cache=['options'=>['alloptions'=>['_transient_shipping-transient-version'=>'1791380000']]]; private string $blog_prefix=''; protected array $global_groups=[]; private bool $multisite=false; public function clear_version():void { unset($this->cache['options']['alloptions']['_transient_shipping-transient-version']); } public function version():mixed { return $this->cache['options']['alloptions']['_transient_shipping-transient-version']??false; } }
class WC_Shipping_Rate {
	protected array $data=['id'=>'delivery_engine_selected_offer:1:group','cost'=>'7','taxes'=>[1=>'0.7']]; protected array $meta_data=[];
	public function get_id():never { ++Calls::$getters; throw new LogicException('Read called filtered rate getter.'); } public function get_cost():never { ++Calls::$getters; throw new LogicException('Read called filtered rate getter.'); } public function get_taxes():never { ++Calls::$getters; throw new LogicException('Read called filtered rate getter.'); }
}
class WC_Cart {
	public array $cart_contents=['line_one'=>['product_id'=>10,'variation_id'=>0,'quantity'=>2]]; protected array $totals=['shipping_total'=>'7','shipping_tax'=>'0.7']; protected array $shipping_methods=[]; protected bool $has_calculated_shipping=false;
	public function get_shipping_packages():array { $hook=$GLOBALS['wp_filter']['woocommerce_cart_shipping_packages']??null; if(null!==$hook){foreach($hook->callbacks as $items){foreach($items as $item){$item['function']();}}} ++Calls::$package; $package=['contents'=>$this->cart_contents,'destination'=>['country'=>'GH']]; $name='Shipment'; $hook=$GLOBALS['wp_filter']['woocommerce_shipping_package_name']??null; if(null!==$hook){foreach($hook->callbacks as $items){foreach($items as $item){++Calls::$package_names;$name=call_user_func_array($item['function'],array_slice([$name,0,$package,1],0,$item['accepted_args']));}}} $package['package_name']=$name; return [$package]; }
	public function calculate_totals():void { ++Calls::$totals; } public function calculated():bool { return $this->has_calculated_shipping; } public function selected():array { return $this->shipping_methods; }
	public function change_total():void { $this->totals['shipping_tax']='0.8'; }
}
class WC_Session_Handler {
	protected array $_data=[]; public function __construct(array $data) { $this->_data=$data; } public function save_data():void { ++Calls::$save; } public function set():never { ++Calls::$writes; throw new LogicException('Read wrote a session.'); } public function bytes():string { return serialize($this->_data); }
}
class WC_Shipping {
	public array $packages=[]; private function get_package_hash(array $package):string { ++Calls::$hashes; if(false===$GLOBALS['wp_object_cache']->version()){++Calls::$transient_writes;} unset($package['rates'],$package['package_name']); return 'wc_ship_'.md5(serialize($package)); }
}
function add_filter(string $name,mixed $callback,int $priority=10,int $accepted_args=1):void { $GLOBALS['wp_filter'][$name]??=new WP_Hook();$GLOBALS['wp_filter'][$name]->callbacks[$priority][]=['function'=>$callback,'accepted_args'=>$accepted_args]; }
function add_action(string $name,mixed $callback,int $priority=10,int $accepted_args=1):void { add_filter($name,$callback,$priority,$accepted_args); }
function get_option(string $name,mixed $default=null):mixed { return $default; } function wp_using_ext_object_cache():bool { return false; } function wp_installing():bool { return false; }
$cart=new WC_Cart(); $shipping=new WC_Shipping(); $hash='wc_ship_'.md5(serialize(['contents'=>$cart->cart_contents,'destination'=>['country'=>'GH']])); $rate=new WC_Shipping_Rate();
$data=['cart_totals'=>serialize(['shipping_total'=>'7','shipping_tax'=>'0.7']),'chosen_shipping_methods'=>serialize(['delivery_engine_selected_offer:1:group']),'shipping_for_package_0'=>serialize(['package_hash'=>$hash,'rates'=>['delivery_engine_selected_offer:1:group'=>$rate]])];
$mode=$argv[1]??'matching';
switch($mode) {
	case 'missing_cache': unset($data['shipping_for_package_0']); break;
	case 'changed_hash': $data['shipping_for_package_0']=serialize(['package_hash'=>'wc_ship_CHANGED','rates'=>['delivery_engine_selected_offer:1:group'=>$rate]]); break;
	case 'changed_method': $data['chosen_shipping_methods']=serialize(['another-method']); break;
	case 'unknown_class': $data['shipping_for_package_0']=serialize(['package_hash'=>$hash,'rates'=>['unknown'=>new Trap()]]); break;
	case 'wrong_totals': $cart->change_total(); break;
	case 'changed_quantity': $cart->cart_contents['line_one']['quantity']=3; break;
	case 'overflow': $data['shipping_for_package_0']=str_repeat('A',65537); break;
}
$session=new WC_Session_Handler($data); $GLOBALS['woocommerce']=new class($cart,$session,$shipping) { public function __construct(public WC_Cart $cart,public WC_Session_Handler $session,private WC_Shipping $ship){} public function shipping():WC_Shipping { return $this->ship; } }; $GLOBALS['wp_filter']=[]; $GLOBALS['wp_object_cache']=new WP_Object_Cache(); if('missing_version'===$mode){$GLOBALS['wp_object_cache']->clear_version();} if('unsupported_package_callback'===$mode){$hook=new WP_Hook();$hook->callbacks=[10=>[['function'=>static function():void{++Calls::$getters;},'accepted_args'=>1]]];$GLOBALS['wp_filter']['woocommerce_cart_shipping_packages']=$hook;}
if(str_starts_with($mode,'package_name_')) {
	$presentation=new \CetechDeliveryEngine\Presentation\Frontend\CartFulfilmentPackagePresentation();$presentation->register();
	$hook=$GLOBALS['wp_filter']['woocommerce_shipping_package_name'];
	if('package_name_unknown'===$mode){$hook->callbacks=[20=>[['function'=>static function():never{++Calls::$getters;throw new LogicException('Unknown package-name callback invoked.');},'accepted_args'=>4]]];}
	if('package_name_priority'===$mode){$hook->callbacks[21]=$hook->callbacks[20];unset($hook->callbacks[20]);}
	if('package_name_args'===$mode){$hook->callbacks[20][0]['accepted_args']=3;}
	if('package_name_method'===$mode){$hook->callbacks[20][0]['function']=[$presentation,'filter_formatted_destination'];}
	if('package_name_duplicate'===$mode){add_filter('woocommerce_shipping_package_name',[new \CetechDeliveryEngine\Presentation\Frontend\CartFulfilmentPackagePresentation(),'filter_package_name'],20,4);}
}
$before=$session->bytes(); $available=false;
try { if('admitted_prepare'===$mode){ NativeCartQuoteShipping::prepare_after_admission(); }else{ NativeCartQuoteShipping::restore_cached_calculation(); } $available=true; } catch(Throwable) {}
echo json_encode(['available'=>$available,'calculated'=>$cart->calculated(),'selected_count'=>count($cart->selected()),'packages'=>count($shipping->packages),'totals_calls'=>Calls::$totals,'save_calls'=>Calls::$save,'write_calls'=>Calls::$writes,'wakeup_calls'=>Calls::$wakeup,'package_reads'=>Calls::$package,'rate_getters'=>Calls::$getters,'hash_calls'=>Calls::$hashes,'transient_writes'=>Calls::$transient_writes,'package_name_calls'=>Calls::$package_names,'package_name'=>$shipping->packages[0]['package_name']??null,'session_unchanged'=>$before===$session->bytes()],JSON_THROW_ON_ERROR);
