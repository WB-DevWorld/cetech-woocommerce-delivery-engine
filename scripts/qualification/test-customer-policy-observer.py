#!/usr/bin/env python3
"""Pure tests of the actual producer's finite observer functions.

Functions are extracted with PHP's tokenizer, rather than copied into this test.
The hook-tree fixtures are synthetic structure tests, not WordPress/native proof.
The source-pin check separately reflects the real production class without
constructing services, bootstrapping WordPress, or touching a database.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get("CETECH_R24_TEST_PHP") or shutil.which("php")
PRODUCER = ROOT / "scripts/qualification/customer-policy-native.php"
ALIGNMENT_DEFAULT = {"observed": False, "store_unchanged": False, "metadata_projection_matches": False, "business_unchanged": False, "metadata_delta": "unobserved"}

EXTRACT = r'''
$source=file_get_contents($argv[1]);
$needed=['r24_stage_callback_wrapper','r24_install_stage_observer',
 'r24_stage_source_exact','r24_diagnostic_class','r24_diagnostic_cause_class','r24_diagnostic_cause_point','r24_diagnostic_initial_point','r24_validation_categories',
 'r24_mount_http','r24_install_request_stage_observer','r24_alignment_default','r24_alignment_raw','r24_alignment_source_exact','r24_alignment_probe_begin','r24_alignment_metadata_delta','r24_alignment_probe_after'];
$tokens=token_get_all($source);$found=[];
for($i=0;$i<count($tokens);++$i){
 if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
 $j=$i+1;
 while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)++$j;
 if(!isset($tokens[$j])||!is_array($tokens[$j])||$tokens[$j][0]!==T_STRING)continue;
 $name=$tokens[$j][1];if(!in_array($name,$needed,true))continue;
 if(isset($found[$name]))throw new RuntimeException('Duplicate producer function.');
 $text='';$depth=0;$body=false;
 for($k=$i;$k<count($tokens);++$k){
  $token=$tokens[$k];$text.=is_array($token)?$token[1]:$token;
  if($token==='{'){++$depth;$body=true;}
  elseif($token==='}'&&$body&&--$depth===0)break;
 }
 if(!$body||$depth!==0)throw new RuntimeException('Incomplete producer function.');
 $found[$name]=$text;
}
if(count($found)!==count($needed))throw new RuntimeException('Missing producer function.');
foreach($needed as$name)eval($found[$name]);
'''

HOOK_FIXTURE = r'''
eval('namespace CetechDeliveryEngine\\Integrations\\CustomerPolicy; class PolicyCheckoutRuntime {
 public array $calls=[]; public mixed $result=null; public ?\\Throwable $error=null; public ?\\ArrayObject $timeline=null;
 public function stage_classic(mixed $order):mixed {$this->calls[]=[func_num_args(),$order];
  $this->timeline?->append("stage_classic");
  if($this->error!==null)throw $this->error;return $this->result;}
 public function stage_store_post(mixed $order):mixed {$this->calls[]=[func_num_args(),$order];
  $this->timeline?->append("stage_store_post");
  if($this->error!==null)throw $this->error;return $this->result;}
}');
class WP_Hook {public array $callbacks=[];}
function fixture(string$hook_name='woocommerce_checkout_order_created',string$method='stage_classic'):array{
 $runtime=new CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyCheckoutRuntime();
 $runtime->result=new stdClass();$hook=new WP_Hook();$events=new ArrayObject();$runtime->timeline=$events;
 $hook->callbacks[PHP_INT_MAX-3]=['earlier'=>['function'=>static function($x)use(&$events){$events[]='earlier';},'accepted_args'=>1]];
 $hook->callbacks[PHP_INT_MAX-2]=[
  'sibling-before'=>['function'=>static function($x)use(&$events){$events[]='before';},'accepted_args'=>1],
  'actual-slot'=>['function'=>[$runtime,$method],'accepted_args'=>1,'fixture_tag'=>'retained'],
  'sibling-after'=>['function'=>static function($x)use(&$events){$events[]='after';},'accepted_args'=>1]];
 $GLOBALS['wp_filter'][$hook_name]=$hook;
 return[$runtime,$hook,&$events];
}
function dispatch_fixture(WP_Hook$hook,array$args):void{
 foreach($hook->callbacks as$entries)foreach($entries as$entry)
  ($entry['function'])(...array_slice($args,0,$entry['accepted_args']));
}
'''

PRODUCTION_REFLECTION = r'''
function r24_plan():array{return$GLOBALS['observer_test_plan'];}
$observer_root=$argv[2];
spl_autoload_register(static function(string$class)use($observer_root):void{
 $prefix='CetechDeliveryEngine\\';if(!str_starts_with($class,$prefix))return;
 $file=$observer_root.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
 if(is_file($file))require$file;
});
$GLOBALS['observer_test_plan']=['installed_php_sources'=>[
 'src/Integrations/CustomerPolicy/PolicyCheckoutRuntime.php'=>hash_file('sha256',$observer_root.'/src/Integrations/CustomerPolicy/PolicyCheckoutRuntime.php')]];
'''


class ProducerObserverTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not PHP:
            raise RuntimeError("PHP is required for the producer observer regression")

    def php(self, body, *, hook_fixture=False, production_reflection=False):
        script = "<?php\ndeclare(strict_types=1);\n" + EXTRACT
        if hook_fixture:
            script += HOOK_FIXTURE
        if production_reflection:
            script += PRODUCTION_REFLECTION
        script += "\n" + body
        result = subprocess.run(
            [PHP, "-f", "/dev/stdin", "--", str(PRODUCER), str(ROOT)], input=script,
            text=True, capture_output=True, timeout=30, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_original_runs_once_with_exact_arguments_return_and_record_order(self):
        actual = self.php(r'''
 $input=new stdClass();$result=new stdClass();$calls=[];$records=[];
 $original=static function($first,$second)use(&$calls,$result){$calls[]=[func_num_args(),$first,$second];return$result;};
 $record=static function(...$args)use(&$records){$records[]=$args;};
 $wrapped=r24_stage_callback_wrapper($original,$record,'stage_classic');$actual=$wrapped($input,17);
 echo json_encode([count($calls),$calls[0][0],$calls[0][1]===$input,$calls[0][2],$actual===$result,$records],JSON_THROW_ON_ERROR);
''')
        self.assertEqual(actual, [1, 2, True, 17, True,
            [["stage_classic", "entered", "none", "none", "none", ALIGNMENT_DEFAULT], ["stage_classic", "returned", "none", "none", "none", ALIGNMENT_DEFAULT]]])

    def test_original_throwable_identity_and_closed_class_survive_recorder_failure(self):
        actual = self.php(r'''
 class PrivateFixtureError extends RuntimeException{}
 $out=[];foreach([new LogicException('PRIVATE'),new PrivateFixtureError('PRIVATE')]as$error){
  $calls=0;$records=[];$original=static function($arg)use(&$calls,$error){++$calls;throw$error;};
  $record=static function(...$args)use(&$records){$records[]=$args;throw new Error('RECORDER');};
  try{r24_stage_callback_wrapper($original,$record,'stage_store_post')(new stdClass());$same=false;}
  catch(Throwable$caught){$same=$caught===$error;}
  $out[]=[$calls,$same,$records];
 }echo json_encode($out,JSON_THROW_ON_ERROR);
''')
        self.assertEqual(actual, [
            [1, True, [["stage_store_post", "entered", "none", "none", "none", ALIGNMENT_DEFAULT], ["stage_store_post", "threw", "LogicException", "LogicException", "other", ALIGNMENT_DEFAULT]]],
            [1, True, [["stage_store_post", "entered", "none", "none", "none", ALIGNMENT_DEFAULT], ["stage_store_post", "threw", "OtherThrowable", "OtherThrowable", "other", ALIGNMENT_DEFAULT]]],
        ])

    def test_recorder_failure_never_changes_successful_original_result(self):
        actual = self.php(r'''
 $calls=0;$record_calls=0;$result=new stdClass();
 $original=static function()use(&$calls,$result){++$calls;return$result;};
 $record=static function()use(&$record_calls){++$record_calls;throw new RuntimeException('RECORDER');};
 $actual=r24_stage_callback_wrapper($original,$record,'stage_classic')();
 echo json_encode([$calls,$record_calls,$actual===$result],JSON_THROW_ON_ERROR);
''')
        self.assertEqual(actual, [1, 2, True])

    def test_exact_slot_retains_siblings_key_priority_arity_and_restores_once(self):
        actual = self.php(r'''
 [$runtime,$hook,$events]=fixture();$before=$hook->callbacks;$records=[];
 $record=static function($slot,$status,$class,$cause,$point,$alignment)use(&$records,$events){$records[]=[$slot,$status,$class,$cause,$point,$alignment];$events->append($status);};
 $restore=r24_install_stage_observer($runtime,'woocommerce_checkout_order_created','stage_classic',$record);
 $order=new stdClass();dispatch_fixture($hook,[$order,'EXTRA']);
 $slot=$hook->callbacks[PHP_INT_MAX-2]['actual-slot'];
 $stable_keys=array_keys($hook->callbacks)===array_keys($before)&&array_keys($hook->callbacks[PHP_INT_MAX-2])===array_keys($before[PHP_INT_MAX-2]);
 $siblings=$hook->callbacks[PHP_INT_MAX-3]===$before[PHP_INT_MAX-3]&&$hook->callbacks[PHP_INT_MAX-2]['sibling-before']===$before[PHP_INT_MAX-2]['sibling-before']&&$hook->callbacks[PHP_INT_MAX-2]['sibling-after']===$before[PHP_INT_MAX-2]['sibling-after'];
 $restore();$restored=$hook->callbacks===$before;$restore();
 echo json_encode([$stable_keys,$siblings,$slot['accepted_args'],$slot['fixture_tag'],count($runtime->calls),$runtime->calls[0][0],$runtime->calls[0][1]===$order,$restored,$hook->callbacks===$before,$records,$events->getArrayCopy()],JSON_THROW_ON_ERROR);
''', hook_fixture=True)
        self.assertEqual(actual, [True, True, 1, "retained", 1, 1, True, True, True,
            [["stage_classic", "entered", "none", "none", "none", ALIGNMENT_DEFAULT], ["stage_classic", "returned", "none", "none", "none", ALIGNMENT_DEFAULT]],
            ["earlier", "before", "entered", "stage_classic", "returned", "after"]])

    def test_restore_never_overwrites_a_later_entry_or_different_hook_slot(self):
        actual = self.php(r'''
 $out=[];foreach(['function','arity','metadata']as$change){
  [$runtime,$hook]=fixture();$restore=r24_install_stage_observer($runtime,'woocommerce_checkout_order_created','stage_classic',static function(){});
  if($change==='function')$hook->callbacks[PHP_INT_MAX-2]['actual-slot']['function']=static function(){};
  elseif($change==='arity')$hook->callbacks[PHP_INT_MAX-2]['actual-slot']['accepted_args']=2;
  else$hook->callbacks[PHP_INT_MAX-2]['actual-slot']['fixture_tag']='changed';
  $changed=$hook->callbacks;$restore();$out[]=$changed===$hook->callbacks;
 }echo json_encode($out,JSON_THROW_ON_ERROR);
''', hook_fixture=True)
        self.assertEqual(actual, [True, True, True])

    def test_wrong_ambiguous_or_over_bound_hook_shape_is_refused_without_mutation(self):
        actual = self.php(r'''
 $out=[];foreach(['duplicate','priority','arity','instance','runtime','hook','method','entry_cap','priority_cap','malformed']as$bad){
  [$runtime,$hook]=fixture();$method='stage_classic';$name='woocommerce_checkout_order_created';
  if($bad==='duplicate')$hook->callbacks[PHP_INT_MAX-2]['duplicate']=$hook->callbacks[PHP_INT_MAX-2]['actual-slot'];
  elseif($bad==='priority'){$hook->callbacks[123]=$hook->callbacks[PHP_INT_MAX-2];unset($hook->callbacks[PHP_INT_MAX-2]);}
  elseif($bad==='arity')$hook->callbacks[PHP_INT_MAX-2]['actual-slot']['accepted_args']=2;
  elseif($bad==='instance')$hook->callbacks[PHP_INT_MAX-2]['actual-slot']['function'][0]=new CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyCheckoutRuntime();
  elseif($bad==='runtime')$runtime=new stdClass();
  elseif($bad==='hook')$name='unknown_hook';
  elseif($bad==='method')$method='stage_store_post';
  elseif($bad==='entry_cap')for($i=0;$i<257;++$i)$hook->callbacks[0]['extra'.$i]=['function'=>static function(){},'accepted_args'=>1];
  elseif($bad==='priority_cap')for($i=0;$i<257;++$i)$hook->callbacks[$i]=[];
  elseif($bad==='malformed')$hook->callbacks[0]='not-entries';
  $before=$hook->callbacks;$refused=false;
  try{r24_install_stage_observer($runtime,$name,$method,static function(){});}catch(RuntimeException){$refused=true;}
  $out[$bad]=$refused&&$hook->callbacks===$before;
 }echo json_encode($out,JSON_THROW_ON_ERROR);
''', hook_fixture=True)
        self.assertEqual(set(actual), {"duplicate", "priority", "arity", "instance", "runtime", "hook", "method", "entry_cap", "priority_cap", "malformed"})
        self.assertTrue(all(actual.values()), actual)

    def test_store_slot_and_exact_exception_are_preserved(self):
        actual = self.php(r'''
 [$runtime,$hook]=fixture('woocommerce_store_api_checkout_order_processed','stage_store_post');
 $error=new UnexpectedValueException('PRIVATE');$runtime->error=$error;$records=[];$before=$hook->callbacks;
 $restore=r24_install_stage_observer($runtime,'woocommerce_store_api_checkout_order_processed','stage_store_post',static function(...$args)use(&$records){$records[]=$args;});
 try{dispatch_fixture($hook,[new stdClass()]);$same=false;}catch(Throwable$caught){$same=$caught===$error;}
 $restore();echo json_encode([$same,count($runtime->calls),$hook->callbacks===$before,$records],JSON_THROW_ON_ERROR);
''', hook_fixture=True)
        self.assertEqual(actual, [True, 1, True,
            [["stage_store_post", "entered", "none", "none", "none", ALIGNMENT_DEFAULT], ["stage_store_post", "threw", "UnexpectedValueException", "UnexpectedValueException", "other", ALIGNMENT_DEFAULT]]])

    def test_previous_cause_is_closed_bounded_and_does_not_replace_outer_throwable(self):
        actual = self.php(r'''
 class PrivateCauseError extends RuntimeException{}
 $leaf=new ValueError('PRIVATE');$eight=$leaf;
 for($i=0;$i<7;++$i)$eight=new RuntimeException('PRIVATE',0,$eight);
 $nine=new RuntimeException('PRIVATE',0,$eight);
 $unknown=new RuntimeException('PRIVATE',0,new PrivateCauseError('PRIVATE'));
 $cycle=new RuntimeException('PRIVATE');(new ReflectionProperty(Exception::class,'previous'))->setValue($cycle,$cycle);
 $records=[];$outer=new RuntimeException('PRIVATE',0,new ValueError('PRIVATE'));
 $wrapped=r24_stage_callback_wrapper(static function()use($outer){throw$outer;},static function(...$args)use(&$records){$records[]=$args;},'stage_classic');
 try{$wrapped();$same=false;}catch(Throwable$caught){$same=$caught===$outer;}
 echo json_encode([r24_diagnostic_cause_class($eight),r24_diagnostic_cause_class($nine),r24_diagnostic_cause_class($unknown),r24_diagnostic_cause_class($cycle),$same,$records],JSON_THROW_ON_ERROR);
''')
        self.assertEqual(actual, ["ValueError", "OtherThrowable", "OtherThrowable", "OtherThrowable", True,
            [["stage_classic", "entered", "none", "none", "none", ALIGNMENT_DEFAULT], ["stage_classic", "threw", "RuntimeException", "ValueError", "other", ALIGNMENT_DEFAULT]]])

    def test_validation_categories_are_closed_and_contain_no_private_unknown_code(self):
        actual = self.php(r'''
 $codes=['PRIVATE-CUSTOMER-ADDRESS','billing_first_name_required','shipping_postcode_validation','cetech_de_delivery_selection','cetech_de_customer_policy_review','cetech_de_checkout_control','shipping','payment','terms','payment','billing_DYNAMIC_PRIVATE_required'];
 echo json_encode([r24_validation_categories($codes),r24_validation_categories(array_fill(0,257,'terms')),r24_validation_categories([])],JSON_THROW_ON_ERROR);
''')
        self.assertEqual(actual, [["delivery", "policy", "control", "shipping", "payment", "terms", "field_required", "field_invalid", "other"], ["other"], []])

    def test_deepest_known_fence_preserves_throwable_and_never_evaluates_private_arguments(self):
        actual = self.php(r'''
 $private=new class{public int$reads=0;public function __debugInfo():array{++$this->reads;throw new Error('PRIVATE');}public function __serialize():array{++$this->reads;throw new Error('PRIVATE');}};
 eval('namespace CetechDeliveryEngine\\Application\\CustomerPolicy; class PolicyNativeOrderAttempt {public static function acknowledged_rows(mixed$private):never{throw new \\RuntimeException("PRIVATE");}}');
 eval('namespace CetechDeliveryEngine\\Integrations\\CustomerPolicy; class PolicyCheckoutRuntime {public static function stage(\\Throwable$previous):never{throw new \\RuntimeException("PRIVATE",0,$previous);}}');
 try{CetechDeliveryEngine\Application\CustomerPolicy\PolicyNativeOrderAttempt::acknowledged_rows($private);}catch(Throwable$leaf){}
 try{CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyCheckoutRuntime::stage($leaf);}catch(Throwable$outer){}
 $records=[];$wrapped=r24_stage_callback_wrapper(static function()use($outer){throw$outer;},static function(...$args)use(&$records){$records[]=$args;},'stage_store_post');
 try{$wrapped();$same=false;}catch(Throwable$caught){$same=$caught===$outer;}
 echo json_encode([r24_diagnostic_cause_point($outer),$same,$private->reads,$records],JSON_THROW_ON_ERROR);
''')
        self.assertEqual(actual, ["metadata_acknowledgement", True, 0,
            [["stage_store_post", "entered", "none", "none", "none", ALIGNMENT_DEFAULT],
             ["stage_store_post", "threw", "RuntimeException", "RuntimeException", "metadata_acknowledgement", ALIGNMENT_DEFAULT]]])

    def test_cause_point_enforces_eight_causes_and_thirty_two_total_frames(self):
        actual = self.php(r'''
 $unknown=['class'=>'PRIVATE\\PolicyNativeOrderAttempt','function'=>'align_initial_metadata','args'=>[new stdClass()],'file'=>'PRIVATE','line'=>999];
 $known=['class'=>'CetechDeliveryEngine\\Application\\CustomerPolicy\\PolicyNativeOrderAttempt','function'=>'align_initial_metadata','args'=>[new stdClass()],'file'=>'PRIVATE','line'=>999];
 $trace=new ReflectionProperty(Exception::class,'trace');
 $make=static function(array$frames)use($trace):Throwable{$e=new RuntimeException('PRIVATE');$trace->setValue($e,$frames);return$e;};
 $at32=$make([...array_fill(0,31,$unknown),$known]);$at33=$make([...array_fill(0,32,$unknown),$known]);
 $leaf=$make([$known]);$eight=$leaf;for($i=0;$i<7;++$i)$eight=new RuntimeException('PRIVATE',0,$eight);$nine=new RuntimeException('PRIVATE',0,$eight);
 $cycle=new RuntimeException('PRIVATE');(new ReflectionProperty(Exception::class,'previous'))->setValue($cycle,$cycle);
 $outer=new RuntimeException('PRIVATE',0,$make(array_fill(0,32,$unknown)));$trace->setValue($outer,[$known]);
 echo json_encode([r24_diagnostic_cause_point($at32),r24_diagnostic_cause_point($at33),r24_diagnostic_cause_point($eight),r24_diagnostic_cause_point($nine),r24_diagnostic_cause_point($cycle),r24_diagnostic_cause_point($outer),r24_diagnostic_cause_point($make([$unknown]))],JSON_THROW_ON_ERROR);
''')
        self.assertEqual(actual, ["initial_alignment", "bounded", "initial_alignment", "bounded", "bounded", "bounded", "other"])

    def test_initial_subfences_require_real_installed_source_pin_and_exact_executed_line(self):
        actual = self.php(r'''
 define('CETECH_DE_PATH',$observer_root);
 $class='CetechDeliveryEngine\\Application\\CustomerPolicy\\PolicyNativeOrderAttempt';class_exists($class);
 $relative='src/Application/CustomerPolicy/PolicyNativeOrderAttempt.php';$file=$observer_root.'/'.$relative;
 $GLOBALS['observer_test_plan']['installed_php_sources'][$relative]=hash_file('sha256',$file);
 $needles=['initial_authority'=>"if(!\$this->issued_for(\$order,\$p)",'initial_reader'=>"\$read=new \\ReflectionMethod",'initial_store'=>"if(!is_object(\$store)",'initial_row_projection'=>"foreach(\$before as\$index=>\$row)",'initial_index_pair'=>"if(count(\$indices)!==2",'initial_post_read_authority'=>"if(!PolicyNativeOrderFacts::hpos()",'initial_final_fence'=>"if(self::raw(\$order,'data_store'"];
 $lines=file($file,FILE_IGNORE_NEW_LINES);$out=[];$frame=null;
 foreach($needles as$point=>$needle){$matches=[];foreach($lines as$index=>$line)if(str_starts_with(trim($line),$needle))$matches[]=$index+1;if(count($matches)!==1)throw new RuntimeException('Pinned subfence ambiguous.');$frame=['class'=>$class,'function'=>'fail','file'=>$file,'line'=>$matches[0],'args'=>['PRIVATE']];$out[$point]=r24_diagnostic_initial_point($frame);}
 $mutations=[];foreach([['line'=>1],['line'=>'40'],['file'=>__FILE__],['function'=>'PRIVATE'],['class'=>'PRIVATE']]as$changes)$mutations[]=r24_diagnostic_initial_point(array_replace($frame,$changes));
 $GLOBALS['observer_test_plan']['installed_php_sources'][$relative]=str_repeat('0',64);$mutations[]=r24_diagnostic_initial_point($frame);
 echo json_encode([$out,$mutations],JSON_THROW_ON_ERROR);
''', production_reflection=True)
        labels = ["initial_authority", "initial_reader", "initial_store", "initial_row_projection", "initial_index_pair", "initial_post_read_authority", "initial_final_fence"]
        self.assertEqual(actual, [dict(zip(labels, labels)), [None] * 6])

    def test_genuine_native_probe_exceptions_reach_the_exact_pinned_initial_subfence(self):
        probe = ROOT / "tests/Support/CustomerPolicy/policy-pinned-native-alignment-probe.php"
        prepend = "<?php\ndeclare(strict_types=1);\n" + EXTRACT.replace("$argv[1]", json.dumps(str(PRODUCER)))
        prepend += "\ndefine('CETECH_DE_PATH'," + json.dumps(str(ROOT)) + ");\n"
        prepend += r'''
function r24_plan():array{$relative='src/Application/CustomerPolicy/PolicyNativeOrderAttempt.php';return['installed_php_sources'=>[$relative=>hash_file('sha256',CETECH_DE_PATH.'/'.$relative)]];}
register_shutdown_function(static function():void{$error=$GLOBALS['e']??null;echo "\n".json_encode(['observed_cause_point'=>$error instanceof Throwable?r24_diagnostic_cause_point($error):'none'],JSON_THROW_ON_ERROR)."\n";});
'''
        modes = {"revoked":"initial_authority", "wrong_store":"initial_store", "missing_index":"initial_index_pair", "pending_root":"metadata_acknowledgement", "root_money":"initial_final_fence"}
        with tempfile.TemporaryDirectory(prefix="r24-observer-cause-") as directory:
            file = Path(directory) / "prepend.php"
            file.write_text(prepend)
            file.chmod(0o600)
            for mode, expected in modes.items():
                with self.subTest(mode=mode):
                    result = subprocess.run([PHP, "-d", "auto_prepend_file=" + str(file), str(probe), mode], text=True, capture_output=True, timeout=30, check=False)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    lines = result.stdout.splitlines()
                    self.assertEqual(len(lines), 2)
                    native = json.loads(lines[0])
                    self.assertFalse(native["accepted"])
                    self.assertFalse(native["physical_qualification"])
                    self.assertEqual(json.loads(lines[1]), {"observed_cause_point": expected})

    def test_metadata_delta_is_closed_and_distinguishes_membership_type_value_and_order(self):
        actual = self.php(r'''
 $rows=[['id'=>1,'key'=>'ordinary-a','value'=>'one'],['id'=>2,'key'=>'ordinary-b','value'=>'two']];$out=[];
 $out[]=r24_alignment_metadata_delta($rows,$rows);
 foreach(['shape','membership','type','value','order','mixed','gap']as$mode){$changed=$rows;switch($mode){case'shape':unset($changed[0]['id']);break;case'membership':$changed[0]['id']=3;break;case'type':$changed[0]['value']=1;break;case'value':$changed[0]['value']='changed';break;case'order':$changed=array_reverse($changed);break;case'mixed':$changed[0]['value']=1;$changed=array_reverse($changed);break;case'gap':$changed=[1=>$changed[0],2=>$changed[1]];break;}$out[]=r24_alignment_metadata_delta($rows,$changed);}
 echo json_encode($out,JSON_THROW_ON_ERROR);
''')
        self.assertEqual(actual, ["equal", "shape", "membership", "type", "value", "order", "mixed", "order"])

    def test_passive_alignment_probe_retains_revoked_exact_attempt_across_genuine_native_read(self):
        probe = ROOT / "tests/Support/CustomerPolicy/policy-pinned-native-alignment-probe.php"
        native_source = probe.read_text().removeprefix("<?php\ndeclare(strict_types=1);")
        native_source = native_source.replace("__DIR__", json.dumps(str(probe.parent)))
        marker = "$accepted=false;$error=null;try{"
        self.assertEqual(native_source.count(marker), 1)
        capture = r'''
$observerClass='CetechDeliveryEngine\\Integrations\\CustomerPolicy\\PolicyCheckoutRuntime';
$observerRuntime=(new ReflectionClass($observerClass))->newInstanceWithoutConstructor();
(new ReflectionProperty($observerClass,'stager'))->setValue($observerRuntime,$stager);
(new ReflectionProperty($observerClass,'prepared'))->setValue($observerRuntime,$p);
$observerBefore=r24_alignment_probe_begin([$observerRuntime,'stage_classic'],[$order]);
'''
        native_source = native_source.replace(marker, capture + marker)
        prefix = "<?php\ndeclare(strict_types=1);\n" + EXTRACT.replace("$argv[1]", json.dumps(str(PRODUCER)))
        prefix += "\ndefine('CETECH_DE_PATH'," + json.dumps(str(ROOT)) + ");\n"
        prefix += r'''
function r24_plan():array{$files=['src/Integrations/CustomerPolicy/PolicyCheckoutRuntime.php','src/Application/CustomerPolicy/PolicyNativeOrderStager.php','src/Application/CustomerPolicy/PolicyNativeOrderAttempt.php','src/Application/CustomerPolicy/CustomerPolicyPreparedCheckout.php'];$pins=[];foreach($files as$file)$pins[$file]=hash_file('sha256',CETECH_DE_PATH.'/'.$file);return['installed_php_sources'=>$pins];}
'''
        suffix = r'''
$stager->finish_attempt($order);
if(($argv[2]??'')==='store')alignment_raw($order,'data_store',new stdClass(),true);
$observerAfter=r24_alignment_probe_after($observerBefore);
$revoked=$observerBefore!==null&&alignment_raw($observerBefore['attempt'],'active')===false;
echo "\n".json_encode(['alignment'=>$observerAfter,'retained_revoked'=>$revoked,'reads'=>$reader->reads,'sql'=>$GLOBALS['alignment_sql'],'saves'=>$GLOBALS['alignment_saves']],JSON_THROW_ON_ERROR)."\n";
'''
        cases = [("same", "", True, True, "equal"), ("id", "", True, True, "membership"), ("type", "", True, True, "type"), ("value", "", True, True, "value"), ("other_order", "", True, True, "order"), ("gap", "", True, True, "order"), ("root_money", "", True, False, "equal"), ("wrong_site", "", True, False, "equal"), ("same", "store", False, True, "equal")]
        with tempfile.TemporaryDirectory(prefix="r24-observer-alignment-") as directory:
            file = Path(directory) / "native-probe.php"
            file.write_text(prefix + native_source + suffix)
            file.chmod(0o600)
            for mode, mutation, store_same, business_same, delta in cases:
                with self.subTest(mode=mode, mutation=mutation):
                    result = subprocess.run([PHP, str(file), mode, mutation], text=True, capture_output=True, timeout=30, check=False)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    lines = result.stdout.splitlines()
                    self.assertEqual(len(lines), 2)
                    self.assertFalse(json.loads(lines[0])["physical_qualification"])
                    observed = json.loads(lines[1])
                    self.assertEqual(observed, {"alignment":{"observed":True,"store_unchanged":store_same,"metadata_projection_matches":delta == "equal","business_unchanged":business_same,"metadata_delta":delta},"retained_revoked":True,"reads":1,"sql":0,"saves":0})

    def test_real_production_reflection_pin_needs_exact_root_and_file_bytes(self):
        cli = self.php(r'''
 $GLOBALS['cli_observer_probes']=['authorization'=>0,'autoload'=>0,'plugin'=>0,'container'=>0,'hooks'=>0];
 function r24_authorized():bool{++$GLOBALS['cli_observer_probes']['authorization'];return true;}
 function add_action(...$args):void{++$GLOBALS['cli_observer_probes']['hooks'];throw new RuntimeException('HOOK ACCESS');}
 class Plugin{
  private static ?self $instance=null;
  public function __construct(){++$GLOBALS['cli_observer_probes']['plugin'];throw new RuntimeException('PLUGIN CONSTRUCTION');}
  public static function instance():self{++$GLOBALS['cli_observer_probes']['plugin'];throw new RuntimeException('PLUGIN ACCESS');}
  public function container():never{++$GLOBALS['cli_observer_probes']['container'];throw new RuntimeException('CONTAINER ACCESS');}
 }
 class_alias(Plugin::class,'CetechDeliveryEngine\\Bootstrap\\Plugin');
 eval('namespace CetechDeliveryEngine\\Integrations\\CustomerPolicy; class PolicyCheckoutRuntime {}');
 $existing=(new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
 (new ReflectionProperty(Plugin::class,'instance'))->setValue(null,$existing);
 spl_autoload_register(static function($class):never{++$GLOBALS['cli_observer_probes']['autoload'];throw new RuntimeException('AUTOLOAD ACCESS');});
 $before=get_included_files();
 r24_mount_http();
 r24_install_request_stage_observer('woocommerce_checkout_order_created','stage_classic');
 r24_install_request_stage_observer('woocommerce_store_api_checkout_order_processed','stage_store_post');
 echo json_encode([PHP_SAPI,$GLOBALS['cli_observer_probes'],$before===get_included_files()],JSON_THROW_ON_ERROR);
''')
        self.assertEqual(cli, ["cli", {"authorization": 0, "autoload": 0,
            "plugin": 0, "container": 0, "hooks": 0}, True])
        actual = self.php(r'''
 define('CETECH_DE_PATH',$argv[2].'/');
 $runtime=(new ReflectionClass(CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyCheckoutRuntime::class))->newInstanceWithoutConstructor();
 $out=[r24_stage_source_exact($runtime),r24_stage_source_exact(new stdClass())];
 foreach([[],['installed_php_sources'=>[]],['installed_php_sources'=>['src/Integrations/CustomerPolicy/PolicyCheckoutRuntime.php'=>str_repeat('0',64)]],['installed_php_sources'=>['src/Integrations/CustomerPolicy/PolicyCheckoutRuntime.php'=>str_repeat('A',64)]],['installed_php_sources'=>['src/Integrations/CustomerPolicy/PolicyCheckoutRuntime.php'=>true]]]as$invalid){$GLOBALS['observer_test_plan']=$invalid;$out[]=r24_stage_source_exact($runtime);}
 $out[]=count(array_filter(get_included_files(),static fn(string$file):bool=>str_contains($file,'/vendor/')))===0;
 echo json_encode($out,JSON_THROW_ON_ERROR);
''', production_reflection=True)
        self.assertEqual(actual, [True, False, False, False, False, False, False, True])
        wrong_root = self.php(r'''
 define('CETECH_DE_PATH',sys_get_temp_dir().'/');
 $runtime=(new ReflectionClass(CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyCheckoutRuntime::class))->newInstanceWithoutConstructor();
 echo json_encode(r24_stage_source_exact($runtime),JSON_THROW_ON_ERROR);
''', production_reflection=True)
        self.assertFalse(wrong_root)


if __name__ == "__main__":
    unittest.main()
