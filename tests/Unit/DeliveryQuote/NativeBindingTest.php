<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
/** Isolated PHP fake-native shapes prove the pure fence; actual Woo is a separate native qualification. */
final class NativeBindingTest extends TestCase {
 #[DataProvider('changes')]
 public function test_pure_binding_detects_native_reference_state_and_option_callback_changes(string $change):void {
  [$status,$stdout,$stderr]=$this->probe($change);
  self::assertSame(0,$status,self::diagnostic($stdout,$stderr));self::assertSame('PASS',$stdout);self::assertSame(0,strlen($stderr),'Isolated child wrote unexpected diagnostics.');
 }
 public function test_isolated_child_failure_reports_only_finite_phase_and_error_class():void {
  [$status,$stdout,$stderr]=$this->probe('probe_throw');
  self::assertSame(86,$status);self::assertSame(['status'=>'FAIL','phase'=>'binding','error_class'=>'RuntimeException'],json_decode($stdout,true));self::assertSame(0,strlen($stderr));self::assertStringNotContainsString('private-child-sentinel',$stdout);
 }
 /** @return array{int,string,string} Raw stderr stays private and is never included in a PHPUnit failure. */
 private function probe(string $change):array {
  $autoload=dirname(__DIR__,3).'/vendor/autoload.php';
  $code= <<<'CODE'
$phase='bootstrap';$reported=false;
register_shutdown_function(static function()use(&$phase,&$reported):void{$error=error_get_last();if(!$reported&&null!==$error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR],true)){$reported=true;echo json_encode(['status'=>'FAIL','phase'=>$phase,'error_class'=>'EngineFatalError']);}});
try{
require $argv[1];
$phase='setup';
class WC_Shipping {protected static $_instance; public array $packages=[];public static function install(object $o):void{self::$_instance=$o;}}
class WC_Product {public array $data=[];public array $changes=[];}
class WP_Hook {public array $callbacks=[];}
function has_filter(string $hook):int|false{return isset($GLOBALS['wp_filter'][$hook])?0:false;}
$shipping=new WC_Shipping();WC_Shipping::install($shipping);
$cart=(object)['cart_contents'=>[],'totals'=>['shipping_total'=>'1'],'shipping_methods'=>[]];
$wc=(object)['cart'=>$cart,'customer'=>(object)['data'=>[]],'session'=>(object)['_data'=>[]],'countries'=>(object)[]];
$GLOBALS['woocommerce']=$wc;$GLOBALS['blog_id']=1;$GLOBALS['current_user']=(object)['ID'=>0];$GLOBALS['wp_filter']=[];
$source=new CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeWooSource();
foreach(['wc'=>$wc,'objects'=>[$cart,$wc->customer,$wc->session,$shipping],'site'=>1,'user'=>0,'option_names'=>['woocommerce_currency']] as $name=>$value){(new ReflectionProperty($source,$name))->setValue($source,$value);}
$phase='hooks';
if($argv[2]==='retained_decorator'){$h=new WP_Hook();$runtime=(new ReflectionClass(CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlRuntime::class))->newInstanceWithoutConstructor();$h->callbacks=[PHP_INT_MAX=>[['function'=>[$runtime,'decorate_packages'],'accepted_args'=>1]]];$GLOBALS['wp_filter']['woocommerce_cart_shipping_packages']=$h;if(!(new ReflectionMethod($source,'hooks_supported'))->invoke(null,[])){echo 'RETAINED-DECORATOR-DENIED';exit(8);}}
if($argv[2]==='core_label_same'){$h=new WP_Hook();$h->callbacks=[10=>[['function'=>'sanitize_text_field','accepted_args'=>1]]];$GLOBALS['wp_filter']['woocommerce_shipping_rate_label']=$h;if(!(new ReflectionMethod($source,'hooks_supported'))->invoke(null,[])){echo 'CORE-LABEL-DENIED';exit(6);}}
$phase='binding';if($argv[2]==='probe_throw'){throw new RuntimeException('private-child-sentinel');}
$m=new ReflectionMethod($source,'raw_binding');$digest=$m->invoke($source);(new ReflectionProperty($source,'binding'))->setValue($source,$digest);
$phase='baseline';
if(!$source->unchanged()){echo 'BASELINE-FAIL';exit(2);}
if(in_array($argv[2],['core_label_same','retained_decorator'],true)){if(!$source->unchanged()){echo 'CORE-LABEL-FENCE-DENIED';exit(7);}echo 'PASS';exit(0);}
$phase='mutation';switch($argv[2]){
case 'cart':$wc->cart=clone $cart;break;
case 'customer':$wc->customer=clone $wc->customer;break;
case 'session':$wc->session=clone $wc->session;break;
case 'shipping':WC_Shipping::install(new WC_Shipping());break;
case 'core_label_foreign':case 'core_label_priority':case 'core_label_args':case 'core_label_args_bool':
$h=new WP_Hook();$h->callbacks=[($argv[2]==='core_label_priority'?11:10)=>[['function'=>($argv[2]==='core_label_foreign'?'foreign_label':'sanitize_text_field'),'accepted_args'=>($argv[2]==='core_label_args'?2:($argv[2]==='core_label_args_bool'?true:1))]]];$GLOBALS['wp_filter']['woocommerce_shipping_rate_label']=$h;if((new ReflectionMethod($source,'hooks_supported'))->invoke(null,[])){echo 'ALTERED-CORE-CALLBACK-MISSED';exit(5);}break;
case 'option_filter_zero':
case 'option_filter':$h=new WP_Hook();$h->callbacks=[10=>[['function'=>'foreign_money_callback']]];$GLOBALS['wp_filter']['option_woocommerce_currency']=$h;break;
case 'total':$cart->totals['shipping_total']='2';break;
case 'cycle':$a=[];$a['self']=&$a;$cart->totals=$a;break;
case 'supported_cycle':$p=new WC_Product();$p->data['self']=$p;$cart->cart_contents=['line'=>['data'=>$p]];break;
case 'large':$cart->totals=array_fill(0,5000,'x');break;
}
if($argv[2]==='option_filter_zero'){if((new ReflectionMethod($source,'hooks_supported'))->invoke(null,['woocommerce_currency'])){echo 'ZERO-PRIORITY-MISSED';exit(4);}}
$phase='assertion';if($source->unchanged()){echo 'MUTATION-MISSED';exit(3);}$phase='complete';echo 'PASS';
}catch(Throwable $error){$reported=true;$class=get_class($error);if(!in_array($class,['ArgumentCountError','TypeError','ReflectionException','RuntimeException','LogicException','Error'],true)){$class='OtherThrowable';}echo json_encode(['status'=>'FAIL','phase'=>$phase,'error_class'=>$class]);exit(86);}
CODE;
  $pipes=[];$process=proc_open([PHP_BINARY,'-d','memory_limit=32M','-d','display_errors=0','-d','log_errors=0','-r',$code,$autoload,$change],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);return [$status,$stdout,$stderr];
 }
 private static function diagnostic(string $stdout,string $stderr):string {
  $facts=json_decode($stdout,true);$phases=['bootstrap','setup','hooks','binding','baseline','mutation','assertion','complete'];$classes=['ArgumentCountError','TypeError','ReflectionException','RuntimeException','LogicException','Error','OtherThrowable','EngineFatalError'];
  if(is_array($facts)&&array_keys($facts)===['status','phase','error_class']&&$facts['status']==='FAIL'&&in_array($facts['phase'],$phases,true)&&in_array($facts['error_class'],$classes,true)){return 'Isolated native binding probe failed: '.$facts['phase'].' / '.$facts['error_class'].'.';}
  $predicate=in_array($stdout,['RETAINED-DECORATOR-DENIED','CORE-LABEL-DENIED','BASELINE-FAIL','CORE-LABEL-FENCE-DENIED','ALTERED-CORE-CALLBACK-MISSED','ZERO-PRIORITY-MISSED','MUTATION-MISSED'],true)?$stdout:'protocol_unavailable';return 'Isolated native binding probe did not finish: '.$predicate.'; stderr_present='.(strlen($stderr)>0?'true':'false').'.';
 }
 public static function changes():array{return array_map(static fn(string $kind):array=>[$kind],['cart','customer','session','shipping','option_filter','option_filter_zero','retained_decorator','core_label_same','core_label_foreign','core_label_priority','core_label_args','core_label_args_bool','total','cycle','supported_cycle','large']);}
}
