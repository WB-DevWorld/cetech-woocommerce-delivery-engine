<?php
declare(strict_types=1);
// Genuine typed Stage and logger receipts over declared native-shaped order,
// checkout/logger trace and in-memory SQLite ports. No WordPress certification.
$requested=$argv[1]??'untouched';$backend=$argv[2]??'cpt';$argv[1]=$backend;
define('R24_WRITER_PROBE_LIBRARY',true);require __DIR__.'/customer-policy-writer-probe.php';
use CetechDeliveryEngine\Application\CustomerPolicy\PolicyNativeOrderStager;
use CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyNativeCheckoutLoggingEvidence;

$stager=new PolicyNativeOrderStager(new Factory());[$order,$item]=writer_initial_attempt($p,$stager);
$order->add_meta_data('repeat','first');$order->add_meta_data('repeat','second');$order->add_meta_data('1','numeric-one');$order->add_meta_data('01','numeric-leading-zero');$order->save();
$result=$stager->stage($order,$p,static fn()=>true);$stage=$result['stage'];$a=$order;
// This is only a declared native reload port, never a Stage/receipt clone.
$b=$requested==='same-object'?$a:unserialize(serialize($a));
$meta_property=new ReflectionProperty(WC_Data::class,'meta_data');
$change_root=static function(WC_Order$target,string$mode)use($meta_property):void{
 $members=$meta_property->getValue($target);$positions=[];foreach($members as$index=>$member){$row=$member->raw();$positions[$row['key']][]=$index;}
 if(in_array($mode,['b-root-gap','a-root-gap'],true)){$out=[];foreach($members as$key=>$m)$out[$key+1]=$m;$members=$out;}
 elseif($mode==='b-root-missing'){unset($members[$positions['repeat'][0]]);}
 elseif($mode==='b-root-added'){$target->add_meta_data('additional-native-key','added');return;}
 elseif($mode==='b-inter-key'||$mode==='a-root-order'||$mode==='same-root-order'){[$x,$y]=[$positions['1'][0],$positions['01'][0]];[$members[$x],$members[$y]]=[$members[$y],$members[$x]];}
 elseif($mode==='b-same-key-order'){[$x,$y]=$positions['repeat'];[$members[$x],$members[$y]]=[$members[$y],$members[$x]];}
 else{$m=$members[$positions['repeat'][0]];$data=new ReflectionProperty(WC_Meta_Data::class,'data');$current=new ReflectionProperty(WC_Meta_Data::class,'current_data');$row=$m->raw();switch($mode){case'b-root-value':$row['value']='changed';break;case'b-root-type':$row['value']=1;break;case'b-root-id':$row['id']=99999;break;case'b-root-id-type':$row['id']=(string)$row['id'];break;case'b-root-zero-id':$row['id']=0;break;case'b-root-duplicate-id':$row['id']=$members[$positions['repeat'][1]]->raw()['id'];break;case'b-root-current':$row['value']='pending';$current->setValue($m,$row);return;default:throw new RuntimeException('Unknown root read mutation.');}$data->setValue($m,$row);$current->setValue($m,$row);}
 $meta_property->setValue($target,$members);
};
if(in_array($requested,['b-inter-key','b-same-key-order','b-root-value','b-root-type','b-root-id','b-root-id-type','b-root-zero-id','b-root-duplicate-id','b-root-current','b-root-gap','b-root-missing','b-root-added'],true))$change_root($b,$requested);
elseif(in_array($requested,['a-root-order','a-root-gap'],true))$change_root($a,$requested);
elseif($requested==='same-root-order'){$b=$a;$change_root($a,$requested);}
elseif($requested==='b-item-order'){$line=$b->get_items('line_item')[456];$meta_property->setValue($line,array_reverse($meta_property->getValue($line)));}
if($requested==='a-money')$a->mutation();
elseif($requested==='a-policy')$a->get_items('line_item')[456]->replace('_cetech_de_policy_packet','late-unsaved-original-packet');
elseif($requested==='b-money')$b->mutation();
elseif($requested==='b-policy')$b->get_items('line_item')[456]->replace('_cetech_de_policy_packet','changed-reloaded-packet');
elseif(!in_array($requested,['untouched','same-object','b-inter-key','b-same-key-order','b-root-value','b-root-type','b-root-id','b-root-id-type','b-root-zero-id','b-root-duplicate-id','b-root-current','a-root-order','same-root-order','b-item-order','b-root-gap','a-root-gap','b-root-missing','b-root-added','phase5'],true))throw new RuntimeException('Unknown logger original fence mode.');
$originalUnchanged=$stage->unchanged();
$directory=sys_get_temp_dir().'/r24-policy-logger-'.bin2hex(random_bytes(8));
if(!mkdir($directory.'/includes',0700,true))throw new RuntimeException('Owned logger trace allocation failed.');
define('WC_ABSPATH',$directory.'/');
$logger=<<<'PHP'
<?php
function wc_log_order_step(string$message,?array$context=null,bool$final=false,bool$first=false):void{
 static$logging_active=true,$order,$order_uid='12345678-1234-4234-9234-123456789abc',$order_uid_short='12345678',$steps=[];
 if(($context['order_object']??null)instanceof WC_Order){$order=$context['order_object'];if($GLOBALS['r24_logger_phase5']??false){$rp=new ReflectionProperty(WC_Data::class,'meta_data');$members=$rp->getValue($order);foreach($members as$key=>$meta)if($meta->raw()['key']==='_debug_log_source')unset($members[$key]);$rp->setValue($order,$members);$table=Probe::$hpos?'owned_wc_orders_meta':'owned_postmeta';$parent=Probe::$hpos?'order_id':'post_id';$native_id=$order->get_id();Probe::$db->exec("DELETE FROM $table WHERE $parent=$native_id AND meta_key='_debug_log_source'");$order->add_meta_data('_debug_log_source','place-order-debug-'.$order_uid_short,false);}else$order->add_meta_data('_debug_log_source','place-order-debug-'.$order_uid_short,true);$order->save();}
 $steps[]=$message;
}
PHP;
$checkout=<<<'PHP'
<?php
class WC_Checkout{
 public function process_checkout(WC_Order$order):void{
  if($GLOBALS['r24_logger_phase5']??false){wc_log_order_step('[Shortcode #5] woocommerce_checkout_order_processed hook ran successfully',['order_object'=>$order]);return;}
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
$evidence=null;$phase5Accepted=null;$phase5Strict=null;$accepted=false;$error=null;$nextUnchanged=null;$sameTarget=null;
try{
 require$loggerPath;require$checkoutPath;
 $GLOBALS['native_method_observer']=static function(WC_Order$saved)use(&$evidence):void{
  $episode=$GLOBALS['r24_logger_episode']??null;
  if(!$episode instanceof PolicyNativeCheckoutLoggingEvidence)throw new RuntimeException('Genuine logger episode was not issued.');
  $evidence=$episode->observe_saved($saved);
 };
 (new WC_Checkout())->process_checkout($b);
 if(!$evidence instanceof PolicyNativeCheckoutLoggingEvidence||!$evidence->is_current())throw new RuntimeException('Genuine logger save evidence was not issued.');
 try{$next=$stage->with_logging($b,$evidence);$accepted=true;$nextUnchanged=$next->unchanged();$sameTarget=$next->order()===$b;}catch(Throwable$caught){$error=get_class($caught);}
 if($requested==='phase5'&&$accepted){$GLOBALS['r24_logger_phase5']=true;(new WC_Checkout())->process_checkout($b);$phase5Strict=$next->unchanged();$successor=$next->with_logging($b,$evidence);$phase5Accepted=$successor->unchanged();}
 echo json_encode(['physical_qualification'=>false,'native_logger_source'=>false,'typed_stage'=>true,'phase5_accepted'=>$phase5Accepted,'phase5_prior_strict'=>$phase5Strict,'typed_logger_receipt'=>true,'logger_phase'=>$evidence->phase(),'same_object'=>$a===$b,'original_unchanged_before_logger'=>$originalUnchanged,'accepted'=>$accepted,'next_unchanged'=>$nextUnchanged,'exact_logger_target'=>$sameTarget,'error'=>$error,'getters_in_sql'=>Probe::$getters],JSON_THROW_ON_ERROR);
}finally{
 unset($GLOBALS['native_method_observer']);unlink($loggerPath);unlink($checkoutPath);rmdir($directory.'/includes');rmdir($directory);
}
