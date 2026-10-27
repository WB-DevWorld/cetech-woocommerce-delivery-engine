<?php
declare(strict_types=1);
// Actual WP_Hook and Woo Legacy registration with declared in-memory order/SQL ports.
$mode=$argv[1]??'off';
$argv[1]=str_starts_with($mode,'classic')?'hpos':(str_contains($mode,'cpt')?'store-cpt':'store-hpos');
define('R24_WRITER_PROBE_LIBRARY',true);
require __DIR__.'/customer-policy-writer-probe.php';

use CetechDeliveryEngine\Application\CustomerPolicy\{CustomerPolicyFinalGuard,PolicyNativeOrderStager,PolicySavedOrderReader};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\Order\QuoteNativeOrderHistory;
use CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyCheckoutRuntime;
use CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime;

$pins=[
 __DIR__.'/native-wordpress/class-wp-hook.php'=>'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e',
 __DIR__.'/native-woocommerce/Legacy.php'=>'1891092f81d39a8dfd6550471f8bcb81192b186b8be578f3b8cb72f65f50eb56',
 __DIR__.'/native-woocommerce/PaymentContext.php'=>'ec4f5994a299bd17bf00e9943a90c35dd6c53b303ab77b4eb72e72b48cc44c09',
];
foreach($pins as$file=>$hash){if(hash_file('sha256',$file)!==$hash)throw new RuntimeException('Pinned native registration class differs.');require$file;}

// Equivalent WordPress function ports; the actual WP_Hook owns callback identity.
$GLOBALS['wp_filter']=[];
function _wp_filter_build_unique_id($hook,$callback,$priority){if(is_string($callback))return$callback;if(is_object($callback))return'spl_object_id:'.spl_object_id($callback);return(is_object($callback[0])?(string)spl_object_id($callback[0]):$callback[0].'::').$callback[1];}
function add_filter($hook,$callback,$priority=10,$arity=1){$GLOBALS['wp_filter'][$hook]??=new WP_Hook();$GLOBALS['wp_filter'][$hook]->add_filter($hook,$callback,$priority,$arity);return true;}
function add_action($hook,$callback,$priority=10,$arity=1){return add_filter($hook,$callback,$priority,$arity);}
class WP_REST_Request {public function get_method(){return'POST';}public function get_route(){return'/wc/store/v1/checkout';}}

// Quote's registration and ordinary completion do not access its uninitialized
// quote writer ports. Its real strict Store API callback census stays unchanged.
$quote=(new ReflectionClass(QuotePlacementRuntime::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($quote,'active'))->setValue($quote,static fn()=>false);
$quote->register();
$native=new Automattic\WooCommerce\StoreApi\Legacy();$native->init();
$stager=new PolicyNativeOrderStager(new Factory());
$runtime=new PolicyCheckoutRuntime(new Factory(),$stager,new PolicySavedOrderReader(new Factory()),static fn()=>$p,static fn()=>false,static fn()=>true,static fn()=>true,null,null,static fn()=>true);
$runtime->register();if($mode==='register-repeat')$runtime->register();
$hookName='woocommerce_rest_checkout_process_payment_with_context';
$policyCount=static function()use($runtime,$hookName):int{$count=0;foreach($GLOBALS['wp_filter'][$hookName]->callbacks as$priority=>$callbacks)foreach($callbacks as$callback)if(($callback['function']??null)===[$runtime,'guard_store_payment_context']){if($priority!==-PHP_INT_MAX||($callback['accepted_args']??null)!==2)throw new RuntimeException('Policy payment callback boundary differs.');++$count;}return$count;};
$supported=new ReflectionMethod($quote,'store_payment_hook_supported');
$beforeCount=$policyCount();$beforeSupported=$supported->invoke($quote);

if($mode==='bad-native-priority'){
 $GLOBALS['wp_filter'][$hookName]->remove_filter($hookName,[$native,'process_legacy_payment'],999);
 add_action($hookName,[$native,'process_legacy_payment'],998,2);
}elseif($mode==='bad-native-arity'){
 add_action($hookName,[$native,'process_legacy_payment'],999,1);
}elseif($mode==='unknown-callback'){
 add_action($hookName,static function():void{},20,2);
}elseif($mode==='duplicate-native'){
 (new Automattic\WooCommerce\StoreApi\Legacy())->init();
}
$alteredSupported=$supported->invoke($quote);
$completed=null;$again=null;$ownAgain=null;$admitted=null;$contextPassed=null;$beforePaymentCount=null;
$doComplete=!in_array($mode,['off','register-repeat'],true);
if($doComplete){
 $route=str_starts_with($mode,'classic')?'classic':'store_api';
 (new ReflectionMethod($runtime,'prepare_route'))->invoke($runtime,$route);
 [$order,$item]=writer_initial_attempt($p,$stager);
 if($route==='store_api'){$runtime->remember_store_post($order,new WP_REST_Request());$runtime->stage_store_post($order);}else$runtime->stage_classic($order);
 $guard=new CustomerPolicyFinalGuard($quote,$quote,$runtime,$runtime,[$runtime,'owns_order'],[QuoteNativeOrderHistory::class,'attempted'],[$runtime,'requires_live_legacy_validation']);
 $local=EmergencyCheckoutLocalBinding::capture($order);if(!$local instanceof EmergencyCheckoutLocalBinding)throw new RuntimeException('Native local binding unavailable.');
 if($mode==='store-mutated')$order->mutation();
 $completed=$guard->complete($order,$mode==='store-wrong-route'?'classic':$route,$mode==='store-invalid-revision'?0:1,$local);
 $beforePaymentCount=$policyCount();
 if($completed)$admitted=$guard->admitted($order,$route,1,$local);
 if($mode==='store-repeat'){
  // Actual C07 final_order() checks admitted() before considering completion again.
  $again=$guard->admitted($order,$route,1,$local)||$guard->complete($order,$route,1,$local);
  $ownAgain=$runtime->complete($order,$route,1,$local);
 }
 if($completed&&$route==='store_api'){
  $context=new Automattic\WooCommerce\StoreApi\Payments\PaymentContext();$context->set_order($order);$context->set_payment_method('owned_local');
  try{$runtime->guard_store_payment_context($context,null);$contextPassed=true;}catch(Throwable){$contextPassed=false;}
 }
}
echo json_encode(['physical_qualification'=>false,'before_policy_callbacks'=>$beforeCount,'before_quote_supported'=>$beforeSupported,'altered_quote_supported'=>$alteredSupported,'completed'=>$completed,'completed_again'=>$again,'own_completed_again'=>$ownAgain,'admitted'=>$admitted,'before_payment_policy_callbacks'=>$beforePaymentCount,'after_policy_callbacks'=>$policyCount(),'context_passed'=>$contextPassed,'getters_in_sql'=>Probe::$getters],JSON_THROW_ON_ERROR);
