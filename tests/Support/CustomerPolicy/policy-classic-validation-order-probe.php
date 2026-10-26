<?php
declare(strict_types=1);
// Actual installed WordPress WP_Hook/WP_Error; equivalent no-service native resource ports.
$pinned=__DIR__.'/native-wordpress/';
foreach(['class-wp-hook.php'=>'b839c0e5672246bca8db1ab781ec8835f7732f253c375a237cbf6ec536e8d12e','class-wp-error.php'=>'a9e971548bf6a1d93c821f980f530efba985c25d5d0b13d9b63e63b378a81a94']as$file=>$hash){if(hash_file('sha256',$pinned.$file)!==$hash)throw new RuntimeException('Pinned WordPress class differs.');require$pinned.$file;}
$GLOBALS['wp_filter']=[];$GLOBALS['notice_errors']=0;
function _wp_filter_build_unique_id($hook,$callback,$priority){if(is_string($callback))return$callback;if(is_object($callback))return'spl_object_id:'.spl_object_id($callback);return(is_object($callback[0])?(string)spl_object_id($callback[0]):$callback[0].'::').$callback[1];}
function add_filter($hook,$callback,$priority=10,$arity=1){$GLOBALS['wp_filter'][$hook]??=new WP_Hook();$GLOBALS['wp_filter'][$hook]->add_filter($hook,$callback,$priority,$arity);return true;}function add_action($hook,$callback,$priority=10,$arity=1){return add_filter($hook,$callback,$priority,$arity);}
function do_action($hook,...$arguments){if(isset($GLOBALS['wp_filter'][$hook]))$GLOBALS['wp_filter'][$hook]->do_action($arguments);}function apply_filters($hook,$value,...$arguments){return isset($GLOBALS['wp_filter'][$hook])?$GLOBALS['wp_filter'][$hook]->apply_filters($value,[$value,...$arguments]):$value;}
function wc_notice_count($type='success'){return$type==='error'?$GLOBALS['notice_errors']:0;}
require dirname(__DIR__,2).'/bootstrap.php';require __DIR__.'/CustomerPolicyFacadeTestFixture.php';
use CetechDeliveryEngine\Tests\Support\CustomerPolicy\CustomerPolicyFacadeTestFixture as F;
use CetechDeliveryEngine\Application\CustomerPolicy\{CustomerPolicyFacade,CustomerPolicyCurrentProvider,PolicyNativeOrderStager,PolicySavedOrderReader};
use CetechDeliveryEngine\Infrastructure\Persistence\CustomerPolicyCurrentSource;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyCheckoutRuntime;
use CetechDeliveryEngine\Domain\CustomerContext\{CustomerCartContext,MatchingLocation,DeliveryAddress};
$GLOBALS['native_sql']=0;$factory=new class implements OperationConnectionFactory{public function open():OperationSession{++$GLOBALS['native_sql'];throw new RuntimeException('Native order SQL forbidden.');}};
function fresh_facade(F$f,OperationConnectionFactory$factory):CustomerPolicyFacade{return new CustomerPolicyFacade($f->authority,new CustomerPolicyCurrentProvider(new CustomerPolicyCurrentSource($f->source->resolver(),fn(array$b)=>$f->source->connection())),$f->receipts,new PolicySavedOrderReader($factory),fn():bool=>$f->enabled,fn(string$route,int$revision):bool=>$f->fresh&&$route==='classic'&&$revision===1,fn()=>$f->cart,static fn():bool=>true);}
function review(CustomerPolicyFacade$facade,F$f):void{$view=$facade->for_cart_lines($f->cart)['native-key'];if(!is_string($view['ticket'])||!$facade->accept_cart_review('native-key',$view['ticket']))throw new RuntimeException('Actual explicit policy review failed.');}
function corrected_context(F$f,string$address='Owned corrected address'):void{$location=MatchingLocation::fromInput(['country'=>'GH','state'=>'AA','city'=>'Accra','postcode'=>'00001']);$delivery=DeliveryAddress::fromInput(['country'=>'GH','state'=>'AA','city'=>'Accra','postcode'=>'00001','address_1'=>$address]);$f->cart->lines['native-key'][CustomerCartContext::CART_KEY]=CustomerCartContext::delivery(4,$location,$delivery)->toArray();}
$mode=$argv[1]??'context';$f=new F();try{
 $f->cart->lines=['native-key'=>$f->line()];$facade=$f->facade;review($facade,$f);$events=[];$prepare_calls=0;
 $runtime=new PolicyCheckoutRuntime($factory,new PolicyNativeOrderStager($factory),new PolicySavedOrderReader($factory),function(string$route)use(&$facade,&$prepare_calls,&$events){++$prepare_calls;$events[]='policy';return$facade->prepare_checkout($route,1);},static fn()=>false,static fn()=>true,static fn()=>true);$runtime->register();
 // Retained validator priority and real context predicates; native error/notice ports are explicit.
 add_action('woocommerce_after_checkout_validation',static function(array$data,WP_Error$errors)use($f,$mode,&$events):void{unset($data);$events[]='retained';if($mode==='context'){$context=CustomerCartContext::fromCartItem($f->cart->lines['native-key']);if(!$context?->hasMatchingLocation()||!$context?->hasCompleteDeliveryAddress())$errors->add('cetech_de_delivery_selection','Declared retained context refusal.');}elseif($mode==='error'||$mode==='both')$errors->add('native_error','Declared native validation error.');if($mode==='notice'||$mode==='both')$GLOBALS['notice_errors']=1;},10,2);
 $priority=null;foreach($GLOBALS['wp_filter']['woocommerce_after_checkout_validation']->callbacks as$at=>$entries)foreach($entries as$entry)if($entry['function']===[$runtime,'early_classic'])$priority=$at;
 $invalid=new WP_Error();do_action('woocommerce_after_checkout_validation',[],$invalid);$first=$f->receipts->active_original($f->authority->session_owner());$first_events=$events;$first_prepare=$prepare_calls;
 $out=['priority'=>$priority,'first_error'=>$invalid->has_errors(),'first_notice'=>$GLOBALS['notice_errors'],'first_prepare_calls'=>$first_prepare,'first_events'=>$first_events,'no_original_after_invalid'=>$first===null,'native_sql'=>0];
 if($mode==='context'){
  corrected_context($f);$facade=fresh_facade($f,$factory);$reviewed=false;try{review($facade,$f);$reviewed=true;}catch(Throwable){}if(!$reviewed){$out+=['corrected_review'=>false];echo json_encode($out,JSON_THROW_ON_ERROR);return;}$events=[];$valid=new WP_Error();do_action('woocommerce_after_checkout_validation',[],$valid);$opened=$f->receipts->active_original($f->authority->session_owner());$witness=$opened?->original_witness();
  $repeat=new WP_Error();do_action('woocommerce_after_checkout_validation',[],$repeat);$again=$f->receipts->active_original($f->authority->session_owner());
  corrected_context($f,'Later changed address');$facade=fresh_facade($f,$factory);$changed=new WP_Error();do_action('woocommerce_after_checkout_validation',[],$changed);$after=$f->receipts->active_original($f->authority->session_owner());
  $out+=['corrected_review'=>true,'corrected_error'=>$valid->has_errors(),'corrected_frozen'=>$opened?->state()==='frozen'&&$opened->binding()===null,'corrected_events'=>array_slice($events,0,2),'repeat_same_original'=>$again?->attempt_id()===$opened?->attempt_id()&&$again?->original_witness()===$witness,'changed_retry_refused'=>in_array('cetech_de_customer_policy_review',$changed->get_error_codes(),true),'pending_original_preserved'=>$after?->attempt_id()===$opened?->attempt_id()&&$after?->original_witness()===$witness,'pending_clear_refused'=>!$f->receipts->invalidate_line('native-key',$f->authority->session_owner())&&!$f->receipts->retire_completed_original($f->authority->session_owner(),static fn()=>true)];
 }
 $out['native_sql']=$GLOBALS['native_sql'];echo json_encode($out,JSON_THROW_ON_ERROR);
}finally{$f->close();}
