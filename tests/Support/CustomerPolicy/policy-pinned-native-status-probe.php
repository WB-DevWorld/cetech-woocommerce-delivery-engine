<?php
declare(strict_types=1);
// Actual installed Woo 11.1.2 order/metadata methods; declared in-memory CRUD
// acknowledgement and item ports. No WordPress or database service is started.
define('ABSPATH','/owned-native-status-regression/');
define('WC_ABSPATH',__DIR__.'/native-woocommerce/');
require dirname(__DIR__,3).'/vendor/autoload.php';
use CetechDeliveryEngine\Application\CustomerPolicy\{PolicyNativeOrderFacts as Facts,PolicyNativeOrderStager};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Tests\Support\CustomerPolicy\{CustomerPolicyReceiptTestFixture,CustomerPolicyDomainFixture};
$hashes=['abstract-wc-data.php'=>'7a9c9f5150506a1d86324f9d95c5f6ed5db8b73ed0d3fdd0ebb341ec7b0f2daf','class-wc-meta-data.php'=>'8aa027314ad537d2dfe5207f1e8372ae14f061aacb6e5da20dac7dd1e45f4d3a','includes/legacy/abstract-wc-legacy-order.php'=>'9cc68dd6b4b95d830ee2d0f65537cddd1485bf70872b8f48271aa65af8137c6c','includes/traits/trait-wc-item-totals.php'=>'c281c1d689a023354f241b58f9c367bedb5143774f74fc9ea83e65e01f90a819','src/Internal/CostOfGoodsSold/CogsAwareTrait.php'=>'02ab77033de8eaa5888095946d0921ddda5f55a3ad0e9d7d161831454fee834c','src/Enums/OrderStatus.php'=>'c450145dc48bf0620456ca3a89fa7b9eb75ee7bf5126ebee96e064c85deaa830','src/Utilities/OrderUtil.php'=>'cd0f59582c6962cd5a8f5a0e7e97fba487590f1cb05df999cc4000dcd4875ea7','includes/abstracts/abstract-wc-order.php'=>'2b3384b5e8a49ad8d6e948eaa40cd5492c7e8f0354617df6c6db9774b02fbe64','includes/class-wc-order.php'=>'bd7989763fe03479a5ac8968abbfa2b87c345a394a21dc1bdf7d9460fe7149d6'];
foreach($hashes as$file=>$hash){if(hash_file('sha256',WC_ABSPATH.$file)!==$hash)throw new RuntimeException('Pinned installed Woo source differs.');require_once WC_ABSPATH.$file;}
$mode=$argv[1]??'initial';$backend=$argv[2]??'hpos';if(!in_array($backend,['hpos','cpt'],true))throw new RuntimeException('Unknown owned backend.');
$GLOBALS['blog_id']=1;$GLOBALS['native_default']='pending';$GLOBALS['default_calls']=0;$GLOBALS['status_actions']=0;$GLOBALS['crud_calls']=0;
function apply_filters($hook,$value,...$args){if($hook==='woocommerce_default_order_status'){++$GLOBALS['default_calls'];if(($GLOBALS['filter_mutation']??null) instanceof Closure)($GLOBALS['filter_mutation'])();return$GLOBALS['native_default'];}return$value;}
function do_action($hook,...$args):void{if(str_starts_with($hook,'woocommerce_order_status_'))++$GLOBALS['status_actions'];}
function wc_get_container(){return new class{public function get($id){return new class{public function feature_is_enabled():bool{return false;}public function orders_cache_usage_is_enabled():bool{return false;}public function is_enabled(...$args):bool{return false;}};}};}
function wc_get_is_paid_statuses():array{return['processing','completed'];}
function wc_get_order_statuses():array{return array_fill_keys(['wc-pending','wc-failed','wc-checkout-draft','wc-processing','wc-completed'],'Owned status');}
function wp_parse_args($args,$defaults=[]):array{return array_merge($defaults,$args);}
function get_user_by(...$args){return false;}
function get_current_user_id():int{return 0;}
function maybe_unserialize($value){return$value;}
function wp_list_pluck($rows,$key){return array_map(static fn($row)=>$row->$key,$rows);}
function wp_cache_delete(...$args):bool{return true;}
function wp_cache_get(...$args){return 0;}
function get_option($key,$default=false){return$default;}
function absint($value):int{return abs((int)$value);}
final class NativeStatusCrudPort {
 public array$observed=[];
 public function get_internal_meta_keys():array{return[];}
 public function read_meta($object):array{return[];}
 public function add_meta($object,$meta):int{return 700+count($this->observed);}
 public function create($order):void{++$GLOBALS['crud_calls'];$this->observed[]=['id'=>$order->get_id(),'raw'=>$order->get_status('edit'),'row'=>'wc-'.$order->get_status('view')];$order->set_id(123);$order->save_meta_data();$order->apply_changes();}
 public function update($order):void{throw new RuntimeException('Existing native order must not be updated.');}
 public function read_items($order,$type):array{return[];}
}
class WC_Data_Store {public static NativeStatusCrudPort$store;public static function load($name){return self::$store;}}
class WC_Cache_Helper {public static function get_cache_prefix($group):string{return'owned-native-cache-';}}
class WC_Order_Item_Product extends WC_Data {
 public function __construct(){parent::__construct();$this->data=['order_id'=>0,'product_id'=>33,'variation_id'=>0,'quantity'=>1];$this->meta_data=[];}
 public function get_order_id($context='view'){return$this->get_prop('order_id',$context);}
 public function get_product_id($context='view'){return$this->get_prop('product_id',$context);}
 public function get_variation_id($context='view'){return$this->get_prop('variation_id',$context);}
 public function get_quantity($context='view'){return$this->get_prop('quantity',$context);}
 public function set_order_id($id):void{$this->data['order_id']=$id;}
 public function save(){return$this->get_id();}
}
final class NativeStatusNoSql implements OperationConnectionFactory {public function open():OperationSession{throw new RuntimeException('No native SQL is permitted.');}}
function raw_native(object$o,string$key,mixed$value):void{(new ReflectionProperty($o,$key))->setValue($o,$value);}
WC_Data_Store::$store=new NativeStatusCrudPort();$order=new WC_Order();$item=new WC_Order_Item_Product();raw_native($order,'items',['line_items'=>[0=>$item]]);raw_native($order,'meta_data',[]);
$fixture=new CustomerPolicyReceiptTestFixture();[$grant,$observation]=$fixture->accepted();$route=$mode==='store_empty'||$mode==='store_draft'?'store_api':'classic';$p=$fixture->service->freeze_original($route,1,['native-key'=>$grant],['native-key'=>$observation]);$stager=new PolicyNativeOrderStager(new NativeStatusNoSql());$stager->bind_line('native-key',$item);
$initial=$order->get_status('edit');$initial_view=$order->get_status('view');
switch($mode){
 case'saved_empty':$order->set_id(123);$item->set_order_id(123);break;
 case'null':case'future':case'paid':case'store_draft':$data=(new ReflectionProperty($order,'data'))->getValue($order);$data['status']=match($mode){'null'=>null,'future'=>'future-status-v99','paid'=>'processing',default=>'checkout-draft'};raw_native($order,'data',$data);break;
 case'foreign_root':$order->add_meta_data(Facts::META_ATTEMPT,CustomerPolicyDomainFixture::uuid(999));break;
 case'foreign_item':$item->add_meta_data('_cetech_de_policy_future','future');break;
 case'wrong_latch':$stager=new PolicyNativeOrderStager(new NativeStatusNoSql());$stager->bind_line('foreign-key',$item);break;
 case'custom_default':$GLOBALS['native_default']='on-hold';break;
 case'view_mutates_id':$GLOBALS['filter_mutation']=static function()use($order,$item):void{$order->set_id(123);$item->set_order_id(123);};break;
 case'view_mutates_marker':$GLOBALS['filter_mutation']=static function()use($order):void{$order->add_meta_data(Facts::META_ATTEMPT,CustomerPolicyDomainFixture::uuid(999));};break;
 case'setter_mutates_id':$GLOBALS['filter_mutation']=static function()use($order,$item):void{if($GLOBALS['default_calls']===5){$order->set_id(123);$item->set_order_id(123);}};break;
 case'setter_mutates_marker':$GLOBALS['filter_mutation']=static function()use($order):void{if($GLOBALS['default_calls']===5)$order->add_meta_data(Facts::META_ATTEMPT,CustomerPolicyDomainFixture::uuid(999));};break;
 case'setter_changes_default':$GLOBALS['filter_mutation']=static function():void{if($GLOBALS['default_calls']===5)$GLOBALS['native_default']='failed';};break;
 case'view_mutates_pending_id':$GLOBALS['filter_mutation']=static function()use($order,$item):void{$order->set_id(123);$item->set_order_id(123);$data=(new ReflectionProperty($order,'data'))->getValue($order);$data['status']='pending';raw_native($order,'data',$data);};break;
}
$before_status=$order->get_status('edit');$before_meta=count($order->get_meta_data());$accepted=false;$error=null;
try{if($mode!=='without_claim'){$stager->begin_attempt($order,$p);$accepted=true;}}catch(Throwable$e){$error=get_class($e);}
$permission=$stager->allows_initial_legacy_write($order,$p);$after_init=$order->get_status('edit');$before_save_calls=$GLOBALS['crud_calls'];$same=$order;
if($accepted&&$mode==='initial'||$mode==='without_claim'){$order->save();}
echo json_encode(['initial_raw'=>$initial,'initial_view'=>$initial_view,'accepted'=>$accepted,'permission'=>$permission,'permission_after_save'=>$stager->allows_initial_legacy_write($order,$p),'after_initialization'=>$after_init,'after_save'=>$order->get_status('edit'),'same_native_object'=>$same===$order,'native_order_id'=>$order->get_id(),'status_unchanged_on_refusal'=>$before_status===$order->get_status('edit'),'metadata_before'=>$before_meta,'metadata_after'=>count($order->get_meta_data()),'marker'=>$order->get_meta(Facts::META_ATTEMPT,true),'expected_attempt'=>$p->attempt_id(),'crud_before_save'=>$before_save_calls,'crud_calls'=>$GLOBALS['crud_calls'],'crud_observed'=>WC_Data_Store::$store->observed,'status_actions'=>$GLOBALS['status_actions'],'transition'=>(new ReflectionProperty($order,'status_transition'))->getValue($order),'default_calls'=>$GLOBALS['default_calls'],'error'=>$error,'physical_qualification'=>false,'backend'=>$backend],JSON_THROW_ON_ERROR);
