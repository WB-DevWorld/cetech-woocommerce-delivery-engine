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

define('DAY_IN_SECONDS',86400);define('HOUR_IN_SECONDS',3600);define('MONTH_IN_SECONDS',2592000);
$cachePins=['includes/class-wc-order-factory.php'=>'6093fd78d83a886cd823c70f2550013a504453b425d46821177f2a16f93a744f','src/Caching/CacheEngine.php'=>'79eab4d1c8ad02abd1436a6f8b4b891c713a7b06fd19d7165ca2968122cb5cfb','src/Caching/ObjectCache.php'=>'7e6fca1d48c4fd66ac55f73fc43a0e4394bb78e73d1a4d4021143d654551091a','src/Caches/OrderCache.php'=>'9fb0f7e63f63a60988b70d6362b8c15ee6c3e681e6223ad47cacc7a0bfa20ff0','src/Caches/OrderCacheController.php'=>'7c6e41095d18fab771873268623bae7a6617152092e4afc90263a3228e03a35c'];
foreach($cachePins as$file=>$hash){$path=WC_ABSPATH.$file;if(hash_file('sha256',$path)!==$hash)throw new RuntimeException('Pinned order cache source differs.');require_once$path;}
$backend=$argv[1]??'hpos';if(!in_array($backend,['hpos','cpt'],true))throw new RuntimeException('Unknown cache backend.');
$GLOBALS['object_hpos']=$backend==='hpos';$GLOBALS['meta_cache']=[];$GLOBALS['meta_deletes']=[];
function apply_filters($hook,$value,...$args){return$value;}
function do_action(...$args):void{}
function maybe_unserialize($value){return$value;}
function wp_list_pluck($rows,$key){return array_map(static fn($r)=>$r->$key,$rows);}
function get_option($key,$default=false){return$default;}
function absint($v){return abs((int)$v);}
function get_user_by(...$args){return false;}
function wc_get_is_paid_statuses(){return['processing','completed'];}
function wp_parse_args($a,$b=[]){return array_merge($b,$a);}
function wp_cache_get($k,$g){return$GLOBALS['meta_cache'][$g][$k]??false;}
function wp_cache_set($k,$v,$g){$GLOBALS['meta_cache'][$g][$k]=$v;return true;}
function wp_cache_delete($k,$g){$GLOBALS['meta_deletes'][]=[$k,$g];unset($GLOBALS['meta_cache'][$g][$k]);return true;}
function wc_get_order_type($type){return['class_name'=>'WC_Order'];}
function wc_get_order($id){return WC_Order_Factory::get_order($id);}
function wc_get_endpoint_url($endpoint,$value,$url){return$url.'/'.$endpoint.'/'.$value;}
function wc_get_checkout_url(){return'http://owned.invalid/checkout';}
function add_query_arg($args,$url){return$url.'?'.http_build_query($args);}
function wc_get_order_statuses(){return['wc-pending'=>'Pending'];}
function r24_attempt_rows($id){return[];}
function r24_hash($v){return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR));}
function r24_require($ok){if(!$ok)throw new RuntimeException('Owned cache probe refused.');}
final class NativeHistoryObjectEngine implements Automattic\WooCommerce\Caching\CacheEngine{
 public array$rows=[];public function get_cached_object(string$key,string$group=''){return$this->rows[$group][$key]??null;}
 public function cache_object(string$key,$object,int$expiration,string$group=''):bool{$this->rows[$group][$key]=$object;return true;}
 public function delete_cached_object(string$key,string$group=''):bool{unset($this->rows[$group][$key]);return true;}
 public function is_cached(string$key,string$group=''):bool{return isset($this->rows[$group][$key]);}
 public function delete_cache_group(string$group=''):bool{throw new RuntimeException('Broad cache flush forbidden.');}
}
final class NativeHistoryCrud{
 public string$value='future';public int$reads=0;
 public function read($o):void{$o->set_object_read(true);$o->set_order_key('owned-key');$o->apply_changes();}
 public function read_meta($o){++$this->reads;return[(object)['meta_id'=>71,'meta_key'=>'_cetech_de_policy_manifest','meta_value'=>$this->value]];}
 public function filter_raw_meta_data($o,$r){return$r;}
 public function get_internal_meta_keys(){return[];}
 public function has_callable($name){return false;}
 public function get_order_type($id){return'shop_order';}
 public function read_items($o,$type){return[];}
}
class WC_Data_Store{public static NativeHistoryCrud$store;public static function load($name){return self::$store;}}
class WC_Cache_Helper{public static function get_cache_prefix($g){return'owned-'.$g.':';}}
eval('namespace Automattic\\WooCommerce\\Internal\\DataStores\\Orders; final class CustomOrdersTableController {public const HPOS_DATASTORE_CACHING_ENABLED_OPTION="owned_hpos_datastore_cache";}');
$engine=new NativeHistoryObjectEngine();$cache=new Automattic\WooCommerce\Caches\OrderCache();$controller=new Automattic\WooCommerce\Caches\OrderCacheController();$controller->init($cache);
function wc_get_container(){return new class{public function get($id){global$cache,$controller,$engine;if($id===Automattic\WooCommerce\Caches\OrderCache::class)return$cache;if($id===Automattic\WooCommerce\Caches\OrderCacheController::class)return$controller;if($id===Automattic\WooCommerce\Caching\WPCacheEngine::class)return$engine;return new class{public function custom_orders_table_usage_is_enabled():bool{return$GLOBALS['object_hpos'];}public function feature_is_enabled():bool{return false;}public function is_enabled(...$args):bool{return false;}};}};}
WC_Data_Store::$store=new NativeHistoryCrud();$GLOBALS['wpdb']=(object)['prefix'=>'owned_','postmeta'=>'owned_postmeta'];
require dirname(__DIR__,3).'/scripts/qualification/customer-policy-history.php';
class_alias(CetechDeliveryEngine\Application\CustomerPolicy\PolicyNativeOrderFacts::class,'PolicyNativeOrderFacts');
$source=file_get_contents(dirname(__DIR__,3).'/scripts/qualification/customer-policy-native.php');preg_match('/^function r24_history\(int\$id\):array\{.*$/m',$source,$match);if(count($match)!==1)throw new RuntimeException('Actual history helper unavailable.');eval($match[0]);
$first=wc_get_order(456);$future=$first->get_meta('_cetech_de_policy_manifest',true);$cachedAgain=wc_get_order(456);
WC_Data_Store::$store->value='original';$target=['table'=>$backend==='hpos'?'owned_wc_orders_meta':'owned_postmeta','pk'=>$backend==='hpos'?'id':'meta_id','fk'=>$backend==='hpos'?'order_id':'post_id','row'=>['owner_id'=>'456']];r24_hist_invalidate_meta_cache($target);
$factory=wc_get_order(456);$stale=$factory->get_meta('_cetech_de_policy_manifest',true);$direct=new WC_Order(456);$fresh=$direct->get_meta('_cetech_de_policy_manifest',true);$observed=r24_history(456);
echo json_encode(['physical_qualification'=>false,'backend'=>$backend,'future'=>$future,'factory_reuses_same_object'=>$first===$cachedAgain,'factory_after_restore'=>$stale,'direct_after_restore'=>$fresh,'helper_after_restore'=>$observed['policy']['manifest'],'physical_value'=>WC_Data_Store::$store->value,'physical_row_id'=>71,'metadata_deletes'=>count($GLOBALS['meta_deletes']),'broad_flushes'=>0],JSON_THROW_ON_ERROR);
