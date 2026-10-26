<?php
declare(strict_types=1);
// Actual pinned Woo 11.1.2 WC_Data metadata methods and WC_Meta_Data source.
// An owned in-memory metadata store acknowledges writes; no service is started.
define('ABSPATH', '/owned-native-meta-regression/');
function do_action(...$arguments): void {}
function maybe_unserialize($value) { return $value; }
require dirname(__DIR__,3).'/vendor/autoload.php';
$pinned=__DIR__.'/native-woocommerce/';
$hashes=['abstract-wc-data.php'=>'7a9c9f5150506a1d86324f9d95c5f6ed5db8b73ed0d3fdd0ebb341ec7b0f2daf','class-wc-meta-data.php'=>'8aa027314ad537d2dfe5207f1e8372ae14f061aacb6e5da20dac7dd1e45f4d3a'];
foreach($hashes as$file=>$expected){if(hash_file('sha256',$pinned.$file)!==$expected)throw new RuntimeException('Pinned Woo 11.1.2 metadata source differs.');require$pinned.$file;}
final class NativeMetaMemoryStore {
 private int $next=700;
 public function get_internal_meta_keys(): array { return []; }
 public function add_meta($object,$meta): int { return ++$this->next; }
}
class WC_Order_Item_Product extends WC_Data {
 public function __construct(){ $this->id=456;$this->data=['order_id'=>123,'product_id'=>33,'variation_id'=>0,'quantity'=>1,'total'=>'10.00'];$this->meta_data=[]; }
}
class WC_Order extends WC_Data {
 protected array $items;
 public function __construct(){ $this->id=123;$this->data=['status'=>'pending','total'=>'17.00','currency'=>'GHS'];$this->meta_data=[];$this->items=['line_items'=>[456=>new WC_Order_Item_Product()],'shipping_lines'=>[],'tax_lines'=>[],'fee_lines'=>[],'coupon_lines'=>[]]; }
 public function line(): WC_Order_Item_Product { return $this->items['line_items'][456]; }
}
function raw_property(object$object,string$key): mixed { return (new ReflectionProperty($object,$key))->getValue($object); }
function set_property(object$object,string$key,mixed$value): void { (new ReflectionProperty($object,$key))->setValue($object,$value); }
function native_acknowledge(WC_Data$object,NativeMetaMemoryStore$store): void { set_property($object,'data_store',$store);$object->save_meta_data();set_property($object,'data_store',null); }
function native_reload(WC_Data$old,WC_Data$fresh): void { $rows=[];foreach(raw_property($old,'meta_data')as$meta){$data=$meta->get_data();$rows[]=(object)['meta_id'=>$data['id'],'meta_key'=>$data['key'],'meta_value'=>$data['value']];}$fresh->init_meta_data($rows); }
function mutate_meta(WC_Data$object,string$field,mixed$value,bool$original=true): void { $meta=raw_property($object,'meta_data')[0];$meta->$field=$value;if($original)$meta->apply_changes(); }
$GLOBALS['blog_id']=1;$mode=$argv[1]??'same';$a=new WC_Order();
$a->add_meta_data('_cetech_de_policy_manifest','owned-manifest');$a->add_meta_data('_cetech_de_policy_manifest_format','1');$a->add_meta_data('native_note','owned-note');
$a->line()->add_meta_data('_cetech_de_policy_packet','owned-packet');$a->line()->add_meta_data('_cetech_de_policy_line_key','raw-owned-key');$a->line()->add_meta_data('_cetech_de_policy_format','1');
$store=new NativeMetaMemoryStore();native_acknowledge($a,$store);native_acknowledge($a->line(),$store);$b=new WC_Order();native_reload($a,$b);native_reload($a->line(),$b->line());
$before=raw_property($a,'meta_data')[0]->get_data();$reloaded=raw_property($b,'meta_data')[0]->get_data();
$binding=CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyNativePaymentLocalBinding::capture($a);
switch($mode){
 case 'root_index':case 'item_index':$o=$mode==='root_index'?$b:$b->line();$m=raw_property($o,'meta_data');set_property($o,'meta_data',[1=>$m[0],2=>$m[1],3=>$m[2]]);break;
 case 'root_order':case 'item_order':$o=$mode==='root_order'?$b:$b->line();set_property($o,'meta_data',array_reverse(raw_property($o,'meta_data')));break;
 case 'duplicate':$m=raw_property($b->line(),'meta_data');$m[]=$m[0];set_property($b->line(),'meta_data',$m);break;
 case 'id':mutate_meta($b->line(),'id',999);break;
 case 'id_type':mutate_meta($b,'id',(string)$before['id']);break;
 case 'key':mutate_meta($b->line(),'key','_cetech_de_policy_future');break;
 case 'value':mutate_meta($b->line(),'value','changed-packet');break;
 case 'value_type':mutate_meta($b,'value',1);break;
 case 'current':mutate_meta($b->line(),'value','changed-current',false);break;
 case 'original':$meta=raw_property($b->line(),'meta_data')[0];$row=raw_property($meta,'data');$row['value']='changed-original';set_property($meta,'data',$row);break;
 case 'extra':mutate_meta($b,'extra','unknown');break;
 case 'missing':$meta=raw_property($b,'meta_data')[0];foreach(['data','current_data']as$p){$row=raw_property($meta,$p);unset($row['id']);set_property($meta,$p,$row);}break;
 case 'strict':$meta=raw_property($a,'meta_data')[0];foreach(['data','current_data']as$p){$row=raw_property($meta,$p);set_property($meta,$p,['id'=>$row['id'],'key'=>$row['key'],'value'=>$row['value']]);}break;
}
echo json_encode(['native_added_keys'=>array_keys($before),'native_read_keys'=>array_keys($reloaded),'same_saved_rows'=>$before==$reloaded,'same_native'=>$binding->same_native_facts($b),'strict_unchanged'=>$binding->unchanged()],JSON_THROW_ON_ERROR);
