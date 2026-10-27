<?php
declare(strict_types=1);
// Actual installed Woo 11.1.2 WC_Data/WC_Meta_Data methods; no native services.
define('ABSPATH','/owned-unsaved-ownership-regression/');
require dirname(__DIR__,3).'/vendor/autoload.php';
use CetechDeliveryEngine\Application\CustomerPolicy\{PolicyNativeOrderFacts,PolicySavedOrderReader};
use CetechDeliveryEngine\Application\Order\{OrderDeliverySnapshotGate,OrderDeliverySnapshotBuilder,OrderDeliverySnapshotPersister};
use CetechDeliveryEngine\Bootstrap\{CustomerPolicyComposition,ServiceContainer,FeatureFlags};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Domain\Configuration\{ScopedConfigurationRepositoryInterface,ScopedConfiguration,ConfigurationScope,ConfigurationVersionInfo};
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Application\Configuration\{EffectiveConfigurationResolver,EffectiveConfigurationValidator};
$pinned=__DIR__.'/native-woocommerce/';
foreach(['abstract-wc-data.php'=>'7a9c9f5150506a1d86324f9d95c5f6ed5db8b73ed0d3fdd0ebb341ec7b0f2daf','class-wc-meta-data.php'=>'8aa027314ad537d2dfe5207f1e8372ae14f061aacb6e5da20dac7dd1e45f4d3a']as$file=>$hash){if(hash_file('sha256',$pinned.$file)!==$hash)throw new RuntimeException('Pinned Woo metadata source differs.');require$pinned.$file;}
$GLOBALS['hooks']=[];$GLOBALS['options']=[];$GLOBALS['blog_id']=1;$GLOBALS['wpdb']=(object)['prefix'=>'owned_'];$GLOBALS['sql_calls']=0;$GLOBALS['source_calls']=0;$GLOBALS['builder_calls']=0;$GLOBALS['hidden']=false;
function maybe_unserialize($value){return$value;}function wp_list_pluck($rows,$key){return array_map(static fn($row)=>$row->$key,$rows);}
function apply_filters($hook,$value,...$arguments){return$GLOBALS['hidden']&&str_ends_with($hook,'meta_data')?[]:$value;}
function add_action($hook,$callback,$priority=10,$arity=1){$GLOBALS['hooks'][$hook][$priority][]=[$callback,$arity];}function add_filter($hook,$callback,$priority=10,$arity=1){add_action($hook,$callback,$priority,$arity);}
function do_action($hook,...$arguments){$groups=$GLOBALS['hooks'][$hook]??[];ksort($groups,SORT_NUMERIC);foreach($groups as$entries)foreach($entries as[$callback,$arity])$callback(...array_slice($arguments,0,$arity));}
function get_option($name,$default=null){return$GLOBALS['options'][$name]??$default;}function get_current_blog_id(){return 1;}function get_current_user_id(){return 0;}function WC(){return(object)[];}
class WooCommerce{}
class WC_Order_Item_Product extends WC_Data {public function __construct(){$this->id=0;$this->meta_data=[];}}
class WC_Order extends WC_Data {private array$lines=[];public function __construct(){$this->id=0;$this->meta_data=[];}public function get_items($type='line_item'){return$type==='line_item'?$this->lines:[];}public function attach(WC_Order_Item_Product$item):void{$this->lines[]=$item;}}
class ForeignOwnershipMeta extends WC_Meta_Data{}
final class OwnershipNoSql implements OperationConnectionFactory {public function open():OperationSession{++$GLOBALS['sql_calls'];throw new RuntimeException('No SQL permitted.');}}
final class OwnershipNoScope implements ScopedConfigurationRepositoryInterface {
 private function deny():never{++$GLOBALS['source_calls'];throw new RuntimeException('No current source permitted.');}
 public function getGlobalConfiguration():?ScopedConfiguration{$this->deny();}public function ensureGlobalScope():ScopedConfiguration{$this->deny();}public function findByScope(ConfigurationScopeType$type,int$id):array{$this->deny();}public function findByScopeAndSlice(ConfigurationScopeType$type,int$id,string$slice):?ScopedConfiguration{$this->deny();}public function findByLegacyRuleId(int$id):?ScopedConfiguration{$this->deny();}public function findByParentProductId(int$id):array{$this->deny();}public function saveScopedConfiguration(ScopedConfiguration$c,bool$publish_revision=true):ScopedConfiguration{$this->deny();}public function completeLocalUnit(callable$work):mixed{$this->deny();}public function publishAcceptedRevision(ScopedConfiguration$c):bool{$this->deny();}public function lockScopeIdentity(ConfigurationScopeType$type,int$id,string$slice):?array{$this->deny();}public function deleteScope(ConfigurationScopeType$type,int$id,string$slice=ConfigurationScope::DEFAULT_SLICE_KEY):bool{$this->deny();}public function getVersionInfo(ConfigurationScopeType$type,int$id,string$slice=ConfigurationScope::DEFAULT_SLICE_KEY):?ConfigurationVersionInfo{$this->deny();}public function getGlobalVersion():int{$this->deny();}
}
function raw_meta(WC_Data$object):mixed{return(new ReflectionProperty(WC_Data::class,'meta_data'))->getValue($object);}function set_raw(WC_Data$object,mixed$rows):void{(new ReflectionProperty(WC_Data::class,'meta_data'))->setValue($object,$rows);}
$mode=$argv[1]??'ordinary';$on=($argv[2]??'off')==='on';$order=new WC_Order();$item=new WC_Order_Item_Product();$order->attach($item);
if($mode!=='empty')$order->add_meta_data('is_vat_exempt','no',true);
switch($mode){
 case'item_ordinary':$item->add_meta_data('ordinary_item_note','note');break;
 case'own_root':$order->add_meta_data('_cetech_de_policy_manifest','partial');break;
 case'own_item':$item->add_meta_data('_cetech_de_policy_packet','partial');break;
 case'null':$order->add_meta_data('_cetech_de_policy_manifest',null);break;
 case'future':$order->add_meta_data('_CETECH_DE_POLICY_FUTURE','unknown');break;
 case'duplicate':$item->add_meta_data('_cetech_de_policy_format','1');$item->add_meta_data('_cetech_de_policy_format','1');break;
 case'hidden':$item->add_meta_data('_cetech_de_policy_packet','partial');$GLOBALS['hidden']=true;break;
 case'previous':$order->add_meta_data('_cetech_de_policy_manifest','partial');$rows=raw_meta($order);$rows[1]->key='ordinary_replacement';break;
 case'current':$rows=raw_meta($order);$rows[0]->key='_cetech_de_policy_future';break;
 case'malformed_key':set_raw($item,[new WC_Meta_Data(['key'=>null,'value'=>'unknown'])]);break;
 case'unreadable_map':$rows=raw_meta($order);(new ReflectionProperty(WC_Meta_Data::class,'current_data'))->setValue($rows[0],null);break;
 case'foreign_meta':set_raw($item,[new ForeignOwnershipMeta(['key'=>'ordinary','value'=>'unknown'])]);break;
 case'over_budget':set_raw($item,array_fill(0,12001,new WC_Meta_Data(['key'=>'ordinary','value'=>'x'])));break;
}
$facts=PolicyNativeOrderFacts::attempted($order);$reader=new PolicySavedOrderReader(new OwnershipNoSql());$attempted=$reader->attempted($order);$read_refused=false;$read_empty=false;try{$read_empty=$reader->read($order,static fn():bool=>true)===[];}catch(Throwable){$read_refused=true;}
foreach(['enable_product_delivery_selector','enable_cart_delivery_selection_capture','enable_checkout_delivery_selection_validation','enable_woocommerce_shipping_rate_calculation','enable_order_delivery_snapshot_persistence']as$flag)$GLOBALS['options']['cetech_de_'.$flag]=1;
if($on)$GLOBALS['options']['cetech_de_enable_customer_policy_runtime']=1;
$c=new ServiceContainer();$flags=new FeatureFlags();$requirements=new CetechDeliveryEngine\Core\Requirements();$c->singleton(FeatureFlags::class,static fn()=>$flags);$c->singleton(CetechDeliveryEngine\Core\Requirements::class,static fn()=>$requirements);$c->singleton(OperationConnectionFactory::class,static fn()=>new OwnershipNoSql());$c->singleton(EffectiveConfigurationResolver::class,static fn()=>new EffectiveConfigurationResolver(new OwnershipNoScope(),new EffectiveConfigurationValidator()));
$builder=new class extends OrderDeliverySnapshotBuilder {public function __construct(){}public function build_line_snapshot(string$key,array$values,WC_Order$order):?CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot{++$GLOBALS['builder_calls'];return null;}};
$persister=new OrderDeliverySnapshotPersister(new OrderDeliverySnapshotGate($flags,$requirements,new CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate($flags,$requirements)),$builder,new CetechDeliveryEngine\Support\Logger());$c->singleton(OrderDeliverySnapshotPersister::class,static fn()=>$persister);
CustomerPolicyComposition::register($c);CustomerPolicyComposition::mount($c);$persister->register();$hook_refused=false;try{do_action('woocommerce_checkout_create_order_line_item',$item,'actual-raw-cart-key',[],$order);}catch(Throwable){$hook_refused=true;}
echo json_encode(['facts_attempted'=>$facts,'reader_attempted'=>$attempted,'read_refused'=>$read_refused,'read_empty'=>$read_empty,'hook_refused'=>$hook_refused,'builder_calls'=>$GLOBALS['builder_calls'],'sql_calls'=>$GLOBALS['sql_calls'],'source_calls'=>$GLOBALS['source_calls'],'policy_enabled'=>$flags->is_enabled('enable_customer_policy_runtime')],JSON_THROW_ON_ERROR);
