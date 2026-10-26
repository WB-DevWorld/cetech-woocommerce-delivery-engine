<?php
declare(strict_types=1);
// Declared SQLite/CRUD ports execute the committed fixture functions only.
require dirname(__DIR__,3).'/vendor/autoload.php';
class_alias(CetechDeliveryEngine\Application\CustomerPolicy\PolicyNativeOrderFacts::class,'PolicyNativeOrderFacts');
function r24_require(bool$ok):void{if(!$ok)throw new RuntimeException('Owned physical history probe refused.');}
function r24_hash(array$v):string{return hash('sha256',json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
function r24_attempt_rows(int$id):array{return[];}
function wc_get_product(...$args):never{++$GLOBALS['product_reads'];throw new RuntimeException('Current catalog lookup forbidden.');}
final class OwnedHistoryCoordinatesWpdb {
 public string$prefix='owned_';public int$reads=0;public PDO$db;
 public function __construct(){ $this->db=new PDO('sqlite::memory:');$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); }
 public function prepare(string$sql,int...$values):string{foreach($values as$value){r24_require($value>0);$sql=preg_replace('/%d/',(string)$value,$sql,1);}r24_require(!str_contains($sql,'%'));return$sql;}
}
function r24_rows(string$sql):array{global$wpdb;r24_require(str_starts_with($sql,'SELECT ')&&!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP)\b/i',$sql));++$wpdb->reads;return$wpdb->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
final class OwnedHistoryCoordinateItem {
 public function get_id():int{return 71;}
 public function get_product_id():int{++$GLOBALS['coordinate_getters'];return$GLOBALS['catalog_deleted']?0:33;}
 public function get_variation_id():int{++$GLOBALS['coordinate_getters'];return$GLOBALS['catalog_deleted']?0:44;}
 public function get_quantity():int{return 1;}
 public function get_meta(string$key,bool$single):string{return'owned-'.$key;}
}
final class WC_Order {
 public function __construct(int$id){r24_require($id===456);}
 public function get_id():int{return 456;}
 public function get_items(string$type):array{r24_require($type==='line_item');return[71=>new OwnedHistoryCoordinateItem()];}
 public function get_meta(string$key,bool$single):string{return'owned-'.$key;}
 public function get_customer_id():int{return 0;}
 public function get_order_key():string{return'owned-key';}
 public function get_checkout_payment_url():string{return'http://owned.invalid/order-pay';}
 public function get_total():string{return'10.00';}
 public function get_currency():string{return'GHS';}
 public function is_paid():bool{return false;}
 public function needs_payment():bool{return true;}
 public function get_date_paid():null{return null;}
 public function get_transaction_id():string{return'';}
 public function get_status():string{return'pending';}
}
$wpdb=new OwnedHistoryCoordinatesWpdb();$db=$wpdb->db;
$db->exec('CREATE TABLE owned_woocommerce_order_items(order_item_id INTEGER PRIMARY KEY,order_id INTEGER,order_item_type TEXT)');$db->exec('CREATE TABLE owned_woocommerce_order_itemmeta(meta_id INTEGER PRIMARY KEY,order_item_id INTEGER,meta_key TEXT,meta_value TEXT)');
$db->exec("INSERT INTO owned_woocommerce_order_items VALUES(71,456,'line_item')");$db->exec("INSERT INTO owned_woocommerce_order_itemmeta VALUES(81,71,'_product_id','33'),(82,71,'_variation_id','44')");
$mode=$argv[1]??'variation';$set=static function(string$key,mixed$value)use($db):void{$q=$db->prepare('UPDATE owned_woocommerce_order_itemmeta SET meta_value=? WHERE meta_key=?');$q->execute([$value,$key]);};
switch($mode){
 case'variation':break;case'simple':$set('_variation_id','0');break;
 case'duplicate_product':$db->exec("INSERT INTO owned_woocommerce_order_itemmeta VALUES(83,71,'_product_id','33')");break;
 case'duplicate_variation':$db->exec("INSERT INTO owned_woocommerce_order_itemmeta VALUES(83,71,'_variation_id','44')");break;
 case'missing_product':$db->exec("DELETE FROM owned_woocommerce_order_itemmeta WHERE meta_key='_product_id'");break;
 case'missing_variation':$db->exec("DELETE FROM owned_woocommerce_order_itemmeta WHERE meta_key='_variation_id'");break;
 case'wrong_owner':$db->exec('UPDATE owned_woocommerce_order_items SET order_id=457');break;
 case'wrong_item_type':$db->exec("UPDATE owned_woocommerce_order_items SET order_item_type='fee'");break;
 case'zero_product':$set('_product_id','0');break;case'negative_product':$set('_product_id','-33');break;case'leading_zero':$set('_product_id','033');break;case'overflow':$set('_product_id',(string)PHP_INT_MAX.'0');break;case'fraction':$set('_product_id','33.0');break;case'empty':$set('_product_id','');break;case'nul':$set('_product_id',"33\0");break;case'null':$set('_product_id',null);break;case'negative_variation':$set('_variation_id','-1');break;default:throw new RuntimeException('Unknown declared mode.');
}
$source=file_get_contents(dirname(__DIR__,3).'/scripts/qualification/customer-policy-native.php');foreach(['r24_history_coordinate_id','r24_history_item_coordinates','r24_history']as$name){r24_require(preg_match('/^function '.preg_quote($name,'/').'\(.*$/m',$source,$match)===1);eval($match[0]);}
$snapshot=static fn():array=>[$db->query('SELECT * FROM owned_woocommerce_order_items ORDER BY order_item_id')->fetchAll(PDO::FETCH_ASSOC),$db->query('SELECT * FROM owned_woocommerce_order_itemmeta ORDER BY meta_id')->fetchAll(PDO::FETCH_ASSOC)];
$before=$snapshot();$changes=(int)$db->query('SELECT total_changes()')->fetchColumn();$GLOBALS['coordinate_getters']=$GLOBALS['product_reads']=0;$GLOBALS['catalog_deleted']=false;$accepted=false;$same=false;$coordinates=null;
try{$coordinates=r24_history_item_coordinates(456,71);$first=r24_history(456);$GLOBALS['catalog_deleted']=true;$second=r24_history(456);$same=$first===$second&&$first['legacy']['items'][0]['product_id']===$coordinates['product_id']&&$first['legacy']['items'][0]['variation_id']===$coordinates['variation_id'];$accepted=true;}catch(RuntimeException){}
echo json_encode(['physical_qualification'=>false,'accepted'=>$accepted,'coordinates'=>$coordinates,'coordinate_getters'=>$GLOBALS['coordinate_getters'],'product_reads'=>$GLOBALS['product_reads'],'writes_after_seed'=>(int)$db->query('SELECT total_changes()')->fetchColumn()-$changes,'rows_unchanged'=>$before===$snapshot(),'history_unchanged_after_catalog_delete'=>$same],JSON_THROW_ON_ERROR);
