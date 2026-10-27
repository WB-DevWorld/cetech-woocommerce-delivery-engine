<?php
declare(strict_types=1);
require dirname(__DIR__,3).'/vendor/autoload.php';
class WP_Post{public function __construct(public int$ID,public string$post_type,public int$post_parent=0,public string$post_status='publish',public string$post_password='',public string$post_modified_gmt='2026-10-26 00:00:00'){}}
class WC_Product{public function __construct(public int$id,public int$parent=0,public bool$variable=false){}public function get_id():int{return$this->id;}public function get_status(string$context='view'):string{return'publish';}public function get_parent_id(string$context='view'):int{return$this->parent;}public function is_type(string$type):bool{return $this->variable?'variable'===$type:'simple'===$type;}public function is_visible():bool{return true;}public function get_catalog_visibility(string$context='view'):string{return'visible';}}
class WC_Product_Variation extends WC_Product{public function variation_is_visible():bool{return true;}}
function wc_get_product(int$id):mixed{return$GLOBALS['products'][$id]??null;}function get_post(int$id):mixed{return$GLOBALS['posts'][$id]??null;}function sanitize_key(string$s):string{return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
$GLOBALS['products']=[33=>new WC_Product(33,0,true),44=>new WC_Product_Variation(44,33)];
$GLOBALS['posts']=[33=>new WP_Post(33,'product'),44=>new WP_Post(44,'product_variation',33)];
$mode=$argv[1]??'valid';$before=null;
if('parent_mismatch'===$mode)$GLOBALS['posts'][44]->post_parent=99;
elseif('type_mismatch'===$mode)$GLOBALS['posts'][44]->post_type='product';
elseif('parent_type'===$mode)$GLOBALS['posts'][33]->post_type='page';
elseif('password'===$mode)$GLOBALS['posts'][33]->post_password='private';
elseif('post_private'===$mode)$GLOBALS['posts'][44]->post_status='private';
$owner=['site_id'=>1,'actor_id'=>0,'session_id'=>'native-guest','cookie'=>false,'wc'=>new stdClass(),'session'=>new stdClass(),'cart'=>new stdClass(),'database'=>new stdClass(),'auth_session'=>''];
$authority=new CetechDeliveryEngine\Integrations\CustomerPolicy\NativeShopperPolicyAuthority(static fn():bool=>true,null,static fn():array=>$owner,null,static fn():array=>['site_id'=>1,'prefix'=>'wp_']);
try{$grant=$authority->product(33,44,'1',7,CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext::delivery(7),'in_warehouse:delivery:7');$result=['granted'=>true,'parent'=>$grant->frame()['parent_id']];if('late_parent'===$mode){$GLOBALS['posts'][44]->post_parent=99;$result['refreshed']=$grant->refresh();}}
catch(Throwable){$result=['granted'=>false,'parent'=>null];}
echo json_encode($result,JSON_THROW_ON_ERROR);
