<?php
declare(strict_types=1);
// Genuine typed Stage and logger receipts over declared native-shaped order,
// checkout/logger trace and in-memory SQLite ports. No WordPress certification.
$requested=$argv[1]??'untouched';$backend=$argv[2]??'cpt';$argv[1]=$backend;
define('R24_WRITER_PROBE_LIBRARY',true);require __DIR__.'/customer-policy-writer-probe.php';
use CetechDeliveryEngine\Application\CustomerPolicy\PolicyNativeOrderStager;
use CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyNativeCheckoutLoggingEvidence;

$stager=new PolicyNativeOrderStager(new Factory());[$order,$item]=writer_initial_attempt($p,$stager);
$result=$stager->stage($order,$p,static fn()=>true);$stage=$result['stage'];$a=$order;
// This is only a declared native reload port, never a Stage/receipt clone.
$b=$requested==='same-object'?$a:unserialize(serialize($a));
if($requested==='a-money')$a->mutation();
elseif($requested==='a-policy')$a->get_items('line_item')[456]->replace('_cetech_de_policy_packet','late-unsaved-original-packet');
elseif($requested==='b-money')$b->mutation();
elseif($requested==='b-policy')$b->get_items('line_item')[456]->replace('_cetech_de_policy_packet','changed-reloaded-packet');
elseif(!in_array($requested,['untouched','same-object'],true))throw new RuntimeException('Unknown logger original fence mode.');
$originalUnchanged=$stage->unchanged();
$directory=sys_get_temp_dir().'/r24-policy-logger-'.bin2hex(random_bytes(8));
if(!mkdir($directory.'/includes',0700,true))throw new RuntimeException('Owned logger trace allocation failed.');
define('WC_ABSPATH',$directory.'/');
$logger=<<<'PHP'
<?php
function wc_log_order_step(string$message,?array$context=null,bool$final=false,bool$first=false):void{
 static$logging_active=true,$order,$order_uid='12345678-1234-4234-9234-123456789abc',$order_uid_short='12345678',$steps=[];
 if(($context['order_object']??null)instanceof WC_Order){$order=$context['order_object'];$order->add_meta_data('_debug_log_source','place-order-debug-'.$order_uid_short,true);$order->save();}
 $steps[]=$message;
}
PHP;
$checkout=<<<'PHP'
<?php
class WC_Checkout{
 public function process_checkout(WC_Order$order):void{
  wc_log_order_step('[Shortcode #1] Place Order flow initiated');
  wc_log_order_step('[Shortcode #2] Session updated with checkout data and totals calculated');
  wc_log_order_step('[Shortcode #3] Checkout posted data validated');
  $GLOBALS['r24_logger_episode']=CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyNativeCheckoutLoggingEvidence::begin_classic();
  wc_log_order_step('[Shortcode #4] Validated/Created customer and created order object',['order_object'=>$order]);
 }
}
PHP;
$loggerPath=$directory.'/includes/wc-order-step-logger-functions.php';$checkoutPath=$directory.'/includes/class-wc-checkout.php';
file_put_contents($loggerPath,$logger);file_put_contents($checkoutPath,$checkout);chmod($loggerPath,0600);chmod($checkoutPath,0600);
$evidence=null;$accepted=false;$error=null;$nextUnchanged=null;$sameTarget=null;
try{
 require$loggerPath;require$checkoutPath;
 $GLOBALS['native_method_observer']=static function(WC_Order$saved)use(&$evidence):void{
  $episode=$GLOBALS['r24_logger_episode']??null;
  if(!$episode instanceof PolicyNativeCheckoutLoggingEvidence)throw new RuntimeException('Genuine logger episode was not issued.');
  $evidence=$episode->observe_saved($saved);
 };
 (new WC_Checkout())->process_checkout($b);
 unset($GLOBALS['native_method_observer']);
 if(!$evidence instanceof PolicyNativeCheckoutLoggingEvidence||!$evidence->is_current())throw new RuntimeException('Genuine logger save evidence was not issued.');
 try{$next=$stage->with_logging($b,$evidence);$accepted=true;$nextUnchanged=$next->unchanged();$sameTarget=$next->order()===$b;}catch(Throwable$caught){$error=get_class($caught);}
 echo json_encode(['physical_qualification'=>false,'native_logger_source'=>false,'typed_stage'=>true,'typed_logger_receipt'=>true,'logger_phase'=>$evidence->phase(),'same_object'=>$a===$b,'original_unchanged_before_logger'=>$originalUnchanged,'accepted'=>$accepted,'next_unchanged'=>$nextUnchanged,'exact_logger_target'=>$sameTarget,'error'=>$error,'getters_in_sql'=>Probe::$getters],JSON_THROW_ON_ERROR);
}finally{
 unset($GLOBALS['native_method_observer']);unlink($loggerPath);unlink($checkoutPath);rmdir($directory.'/includes');rmdir($directory);
}
