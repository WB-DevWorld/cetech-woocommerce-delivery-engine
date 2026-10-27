<?php
declare(strict_types=1);
// Actual pinned WC_Data cache/read methods; raw metadata store and WordPress
// cache function ports are in memory. The qualification helper itself is invoked.
define('ABSPATH','/owned-history-cache-regression/');require dirname(__DIR__,3).'/vendor/autoload.php';
$pins=['abstract-wc-data.php'=>'7a9c9f5150506a1d86324f9d95c5f6ed5db8b73ed0d3fdd0ebb341ec7b0f2daf','class-wc-meta-data.php'=>'8aa027314ad537d2dfe5207f1e8372ae14f061aacb6e5da20dac7dd1e45f4d3a'];
foreach($pins as$file=>$hash){$path=__DIR__.'/native-woocommerce/'.$file;if(hash_file('sha256',$path)!==$hash)throw new RuntimeException('Pinned metadata cache source differs.');require$path;}
require dirname(__DIR__,3).'/scripts/qualification/customer-policy-history.php';
function r24_require($ok):void{if(!$ok)throw new RuntimeException('Owned history cache observation refused.');}
function maybe_unserialize($value){return$value;}
$GLOBALS['owned_meta_cache']=[];$GLOBALS['cache_deletes']=[];
function wp_cache_get($key,$group){return$GLOBALS['owned_meta_cache'][$group][$key]??false;}
function wp_cache_set($key,$value,$group){$GLOBALS['owned_meta_cache'][$group][$key]=$value;return true;}
function wp_cache_delete($key,$group){$GLOBALS['cache_deletes'][]=[$key,$group];unset($GLOBALS['owned_meta_cache'][$group][$key]);return true;}
class WC_Cache_Helper{public static function get_cache_prefix($group){return'owned-'.$group.':';}}
final class HistoryMetadataStore{public int$reads=0;public array$rows;public function __construct(){$this->rows=[(object)['meta_id'=>71,'meta_key'=>'_cetech_de_policy_packet','meta_value'=>'original']];}public function read_meta($object){++$this->reads;return unserialize(serialize($this->rows));}public function filter_raw_meta_data($object,$rows){return$rows;}}
final class HistoryMetadataObject extends WC_Data{public function __construct($store,string$group){$this->id=456;$this->cache_group=$group;$this->data_store=$store;$this->meta_data=null;}}
$GLOBALS['wpdb']=(object)['prefix'=>'owned_','postmeta'=>'owned_postmeta'];$kind=$argv[1]??'item';$invalid=$argv[2]??'';
[$table,$pk,$fk,$group]=match($kind){'item'=>['owned_woocommerce_order_itemmeta','meta_id','order_item_id','order-items'],'cpt'=>['owned_postmeta','meta_id','post_id','orders'],'hpos'=>['owned_wc_orders_meta','id','order_id','orders'],default=>throw new RuntimeException('Unknown metadata cache coordinate.')};
$target=['table'=>$table,'pk'=>$pk,'fk'=>$fk,'row'=>['row_id'=>'71','owner_id'=>'456','meta_key'=>'_cetech_de_policy_packet','meta_value'=>'original']];
$store=new HistoryMetadataStore();$value=static function()use($store,$group):string{return(new HistoryMetadataObject($store,$group))->get_meta_data()[0]->get_data()['value'];};
$before=$value();$store->rows[0]->meta_value='future';$staleSeed=$value();
if($invalid!==''){
 if($invalid==='table')$target['table']='foreign_order_itemmeta';elseif($invalid==='pk')$target['pk']='foreign';elseif($invalid==='fk')$target['fk']='foreign';elseif($invalid==='zero')$target['row']['owner_id']='0';elseif($invalid==='leading-zero')$target['row']['owner_id']='0456';elseif($invalid==='overflow')$target['row']['owner_id']=(string)PHP_INT_MAX.'1';elseif($invalid==='object')$target['row']['owner_id']=new stdClass();else throw new RuntimeException('Unknown invalid cache coordinate.');
 $refused=false;try{r24_hist_invalidate_meta_cache($target);}catch(Throwable){$refused=true;}
 echo json_encode(['physical_qualification'=>false,'refused'=>$refused,'deletes'=>count($GLOBALS['cache_deletes']),'store_reads'=>$store->reads,'cached_value'=>$value(),'physical_row_id'=>$store->rows[0]->meta_id,'physical_value'=>$store->rows[0]->meta_value],JSON_THROW_ON_ERROR);return;
}
r24_hist_invalidate_meta_cache($target);$future=$value();$store->rows[0]->meta_value='original';$staleRestore=$value();r24_hist_invalidate_meta_cache($target);$restored=$value();
echo json_encode(['physical_qualification'=>false,'before'=>$before,'stale_seed'=>$staleSeed,'fresh_future'=>$future,'stale_restore'=>$staleRestore,'fresh_restored'=>$restored,'physical_row_id'=>$store->rows[0]->meta_id,'physical_value'=>$store->rows[0]->meta_value,'store_reads'=>$store->reads,'deletes'=>$GLOBALS['cache_deletes'],'expected_key'=>WC_Data::generate_meta_cache_key(456,$group),'expected_group'=>$group],JSON_THROW_ON_ERROR);
