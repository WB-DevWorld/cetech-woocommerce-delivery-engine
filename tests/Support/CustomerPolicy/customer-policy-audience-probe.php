<?php
declare(strict_types=1);
class WC_Order {
 public function __construct(public int$id=51,public int$customer=7,public string$key='wc_order_SYNTHETIC51',public string$billing='shopper@example.invalid'){}
 public function get_id(){return$this->id;}public function get_customer_id($context=''){return$this->customer;}public function get_order_key($context=''){return$this->key;}public function get_billing_email($context=''){return$this->billing;}
}
class WC_Email {public function __construct(public mixed$object,public mixed$recipient='shopper@example.invalid',public bool$customer=true){}public function is_customer_email(){return$this->customer;}public function get_recipient(){return$this->recipient;}}
$GLOBALS['actor']=7;$GLOBALS['query']=[];$GLOBALS['lookups']=0;$GLOBALS['saved']=new WC_Order();
function get_current_user_id(){return$GLOBALS['actor'];}function wc_get_order($id){++$GLOBALS['lookups'];return$GLOBALS['saved'];}function wp_unslash($v){return$v;}function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}function get_query_var($key,$default=''){return$GLOBALS['query'][$key]??$default;}function is_order_received_page(){return isset($GLOBALS['query']['order-received']);}function is_wc_endpoint_url($key){return isset($GLOBALS['query'][$key]);}function is_view_order_page(){return isset($GLOBALS['query']['view-order']);}
require dirname(__DIR__,3).'/src/Integrations/CustomerPolicy/PolicySavedAudience.php';
$a=new CetechDeliveryEngine\Integrations\CustomerPolicy\PolicySavedAudience();$order=clone$GLOBALS['saved'];$email=new WC_Email(clone$order);$surface='customer_order';
switch($argv[1]??''){
 case'owner':break;case'foreign-customer':$GLOBALS['actor']=8;break;case'guest-no-key':$order->customer=0;$GLOBALS['saved']->customer=0;$GLOBALS['actor']=0;break;
 case'guest-received':case'guest-pay':case'guest-view':case'guest-wrong-key':case'guest-wrong-id':case'guest-ambiguous':case'guest-overflow':case'guest-array-key':
 $order->customer=0;$GLOBALS['saved']->customer=0;$GLOBALS['actor']=0;$route=match($argv[1]){'guest-pay'=>'order-pay','guest-view'=>'view-order',default=>'order-received'};$GLOBALS['query'][$route]='51';$_GET['key']=$order->key;if('guest-wrong-key'===$argv[1])$_GET['key']='foreign';if('guest-wrong-id'===$argv[1])$GLOBALS['query'][$route]='52';if('guest-ambiguous'===$argv[1])$GLOBALS['query']['order-pay']='51';if('guest-overflow'===$argv[1])$GLOBALS['query'][$route]='9223372036854775808';if('guest-array-key'===$argv[1])$_GET['key']=[$order->key];break;
 case'unknown-surface':$surface='admin';break;case'native-missing':$GLOBALS['saved']=false;break;case'copied-id':$order->id=52;break;case'copied-key':$order->key='changed';break;case'copied-owner':$order->customer=8;break;
 case'email':case'email-no-browser':case'email-admin':case'email-foreign-id':case'email-foreign-key':case'email-foreign-owner':case'email-wrong-recipient':case'email-list':case'email-display-name':case'email-control':case'email-invalid':case'email-not-order':case'email-case':
 $surface='customer_email';if('email-no-browser'===$argv[1])$GLOBALS['actor']=0;if('email-admin'===$argv[1])$email->customer=false;if('email-foreign-id'===$argv[1])$email->object->id=52;if('email-foreign-key'===$argv[1])$email->object->key='changed';if('email-foreign-owner'===$argv[1])$email->object->customer=8;if('email-wrong-recipient'===$argv[1])$email->recipient='foreign@example.invalid';if('email-list'===$argv[1])$email->recipient='shopper@example.invalid,foreign@example.invalid';if('email-display-name'===$argv[1])$email->recipient='Shopper <shopper@example.invalid>';if('email-control'===$argv[1])$email->recipient="shopper@example.invalid\nBcc:foreign@example.invalid";if('email-invalid'===$argv[1])$email->recipient='no-mail';if('email-not-order'===$argv[1])$email->object=new stdClass();if('email-case'===$argv[1])$email->recipient='SHOPPER@example.invalid';break;
 default:throw new RuntimeException('Unknown finite probe');
}
$result=$a($order,$surface,'customer_email'===$surface?$email:null);echo json_encode(['allowed'=>$result,'lookups'=>$GLOBALS['lookups']],JSON_THROW_ON_ERROR);
