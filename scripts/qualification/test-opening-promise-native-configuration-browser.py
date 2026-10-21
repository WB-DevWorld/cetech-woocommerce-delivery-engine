#!/usr/bin/env python3
"""Adversarial pure public-schema checks, not browser parity evidence."""
import subprocess
import importlib.util
import copy
import ast
import datetime
import hashlib
import json
from html import escape
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
from urllib.parse import urlencode
import unittest

class PromisePublicProtocolTest(unittest.TestCase):
    def test_failure_diagnostics_are_closed_private_safe_scalars(self):
        script = Path(__file__).with_name('opening-http-promise-native-configuration.py')
        spec = importlib.util.spec_from_file_location('p05_failure_schema_controls', script)
        driver = importlib.util.module_from_spec(spec); spec.loader.exec_module(driver)
        diagnostic = driver.FailureDiagnostic()
        clean = diagnostic.error(RuntimeError('PRIVATE message https://private.invalid/?token=PRIVATE'))
        self.assertEqual(dict(cls='RuntimeError', phase='none', code='unclassified', http_status=None, public_status='none'), dict(cls=clean.pop('class'), **clean))
        diagnostic.begin('classic_refresh_post')
        diagnostic.observe_http(True); diagnostic.observe_public({'token':'PRIVATE'})
        diagnostic.mark('refused_public_state')
        exported = diagnostic.error(RuntimeError('PRIVATE raw response'))
        self.assertTrue(driver.failure_error_valid(exported))
        self.assertIsNone(exported['http_status']); self.assertEqual('unknown', exported['public_status'])
        self.assertNotIn('PRIVATE', json.dumps(exported))
        for field, value in (('phase','PRIVATE'), ('code','PRIVATE'), ('public_status','PRIVATE'), ('class','PRIVATE'), ('http_status',True), ('http_status',99), ('http_status',600), ('http_status','200')):
            changed = dict(exported); changed[field] = value
            with self.subTest(field=field,value=value): self.assertFalse(driver.failure_error_valid(changed))
        for key in ('raw','message','url','token','response','headers'):
            with self.subTest(key=key): self.assertFalse(driver.failure_error_valid(dict(exported, **{key:'PRIVATE'})))
        for value in ('PRIVATE', [], None):
            with self.subTest(value=value), self.assertRaises(ValueError): diagnostic.begin(value)
            with self.subTest(value=value), self.assertRaises(ValueError): diagnostic.mark(value)
        diagnostic.clear()
        self.assertEqual({'class':'OtherError','phase':'none','code':'unclassified','http_status':None,'public_status':'none'}, diagnostic.error(OSError('PRIVATE transport URL')))

    def test_actual_seed_classic_interval_classifies_failures_and_clears_after_case_nine(self):
        """Execute the real nested helpers and interval against controlled responses."""
        script = Path(__file__).with_name('opening-http-promise-native-configuration.py')
        spec = importlib.util.spec_from_file_location('p05_failure_interval_controls', script)
        driver = importlib.util.module_from_spec(spec); spec.loader.exec_module(driver)
        run = next(node for node in ast.parse(script.read_text()).body if isinstance(node,ast.FunctionDef) and node.name == 'run')
        body = next(node for node in run.body if isinstance(node,ast.Try)).body
        helpers = [node for node in body if isinstance(node,ast.FunctionDef) and node.name in ('inspect','fixture')]
        first = next(i for i,node in enumerate(body) if isinstance(node,ast.Expr) and isinstance(node.value,ast.Call) and isinstance(node.value.func,ast.Attribute) and isinstance(node.value.func.value,ast.Name) and node.value.func.value.id == 'diagnostic' and node.value.func.attr == 'begin' and node.value.args[0].value == 'shopper_inspect_before_seed')
        last = next(i for i,node in enumerate(body[first:],first) if isinstance(node,ast.Expr) and isinstance(node.value,ast.Call) and isinstance(node.value.func,ast.Attribute) and isinstance(node.value.func.value,ast.Name) and node.value.func.value.id == 'diagnostic' and node.value.func.attr == 'clear')
        program = compile(ast.Module(body=[*helpers,*body[first:last + 1]],type_ignores=[]),str(script),'exec')
        identity = dict.fromkeys(driver.IDENTITY_KEYS,'owned-source')
        observer = {'success':True,'data':{'source_identity':identity,'nonce':'PRIVATE-nonce','review_nonce':'PRIVATE-review','facts':{'generation':1}}}
        promise = {'groups':[{'customer_text':'Expected public wording'}]}

        def response(value, status=200):
            return SimpleNamespace(status=status, body=value if isinstance(value,bytes) else json.dumps(value).encode())

        def exercise(index=None, replacement=None, require_all_requests=False):
            queue = [response(copy.deepcopy(observer)),response({'success':True}),response(copy.deepcopy(observer)),response({'data':{'status':'review_required','generation':2}}),response(copy.deepcopy(observer)),response({'data':{'status':'confirmed','quote':{'promise':promise}}}),response(b'<span data-cetech-de-original-promise="1">Expected public wording</span>')]
            if index is not None: queue[index] = replacement
            class Client:
                def request(inner,*args):
                    value=queue.pop(0)
                    if isinstance(value,Exception): raise value
                    return value
                get = request
            class Recorder:
                def check(inner,case_id,condition,evidence):
                    if not condition or not all(value is True for value in evidence.values()): raise RuntimeError('PRIVATE case details')
            diagnostic = driver.FailureDiagnostic()
            namespace = dict(diagnostic=diagnostic,client=Client(),headers={},state={'identity':identity,'classic_url':'PRIVATE-classic','classic_page_url':'PRIVATE-page'},IDENTITY_KEYS=driver.IDENTITY_KEYS,uuid=driver.uuid,safe_promise=lambda value:value == promise,recorder=Recorder())
            try: exec(program,namespace)
            except Exception as error:
                if require_all_requests: self.assertEqual([],queue)
                return diagnostic.error(error)
            return diagnostic.error(RuntimeError('PRIVATE later Store API failure'))

        bad_identity = copy.deepcopy(observer); bad_identity['data']['source_identity']['source_tree'] = 'PRIVATE-wrong-source'
        missing_nonce = copy.deepcopy(observer); missing_nonce['data'].pop('review_nonce')
        scenarios = [
            (0,response(observer,403),'shopper_inspect_before_seed','observer_http_status',403,'none'),
            (0,response({'success':False}),'shopper_inspect_before_seed','observer_success_false',200,'none'),
            (0,response(bad_identity),'shopper_inspect_before_seed','observer_source_identity_mismatch',200,'none'),
            (0,response(b'PRIVATE invalid JSON'),'shopper_inspect_before_seed','json_decode_or_shape',200,'none'),
            (0,response([]),'shopper_inspect_before_seed','json_decode_or_shape',200,'none'),
            (1,response(b'PRIVATE non-JSON refusal',503),'shopper_seed_post','stimulus_http_status',503,'none'),
            (1,response({'success':False}),'shopper_seed_post','stimulus_success_false',200,'none'),
            (2,response(observer,403),'shopper_inspect_after_seed','observer_http_status',403,'none'),
            (3,response({'data':{'status':'review_required'}},503),'classic_refresh_post','refresh_http_status',503,'review_required'),
            (3,response({'data':{'status':'unavailable'}}),'classic_refresh_post','refused_public_state',200,'unavailable'),
            (3,response({'data':{'status':'PRIVATE-hidden-state'}}),'classic_refresh_post','refused_public_state',200,'unknown'),
            (4,response(missing_nonce),'shopper_inspect_before_confirm','json_decode_or_shape',200,'none'),
            (5,response({'data':[]}),'classic_confirm_post','json_decode_or_shape',200,'none'),
            (5,response(b'PRIVATE bad JSON'),'classic_confirm_post','json_decode_or_shape',200,'none'),
            (5,response({'data':{'status':'expired','quote':{'promise':promise}}}),'classic_confirm_post','refused_public_state',200,'expired'),
            (5,response({'data':{'status':'confirmed','quote':{'promise':promise}}},403),'classic_confirm_post','refused_public_state',403,'confirmed'),
        ]
        for index,phase in ((0,'shopper_inspect_before_seed'),(1,'shopper_seed_post'),(2,'shopper_inspect_after_seed'),(3,'classic_refresh_post'),(4,'shopper_inspect_before_confirm'),(5,'classic_confirm_post'),(6,'classic_page_get')):
            scenarios.append((index,OSError('PRIVATE transport https://private.invalid/?token=PRIVATE'),phase,'transport_or_unclassified',None,'confirmed' if index == 6 else 'none'))
        for index,replacement,phase,code,status,public_status in scenarios:
            with self.subTest(index=index,phase=phase,code=code):
                actual = exercise(index,replacement)
                self.assertTrue(driver.failure_error_valid(actual))
                self.assertEqual((phase,code,status,public_status),tuple(actual[key] for key in ('phase','code','http_status','public_status')))
                self.assertNotIn('PRIVATE',json.dumps(actual))
        confirm_refused = exercise(5,response({'data':{'status':'unavailable','quote':{'promise':promise}}},403),require_all_requests=True)
        self.assertEqual(('classic_confirm_post',403,'unavailable'),tuple(confirm_refused[key] for key in ('phase','http_status','public_status')))
        self.assertEqual({'class':'RuntimeError','phase':'none','code':'unclassified','http_status':None,'public_status':'none'},exercise())

    def test_guide_forms_are_native_unique_nonce_bearing_posts(self):
        script = Path(__file__).with_name('opening-http-promise-native-configuration.py')
        spec = importlib.util.spec_from_file_location('p05_guide_form_controls', script)
        driver = importlib.util.module_from_spec(spec); spec.loader.exec_module(driver)
        common = driver.module('p05_guide_html_controls', script.with_name('opening-http-driver.py'))
        html = '<form method="post" action="" class="cetech-de-guide-form"><input name="cetech_de_action" value="cetech_de_promise_guide"><input name="cetech_de_nonce" value="native-guide"></form>'
        selected = lambda form: 'cetech-de-guide-form' in form.attrs.get('class', '').split() and form.fields.get('cetech_de_action') == 'cetech_de_promise_guide'
        response = lambda body: common.Response(200, body.encode(), {}, 'http://127.0.0.1:18000/wp-admin/admin.php?page=cetech-de-promises')
        self.assertEqual('native-guide', driver.guide_form(response('<form method="post"><input name="cetech_de_action" value="advanced"></form>' + html), selected)['cetech_de_nonce'])
        for malformed in (html + html, html.replace('native-guide', ''), html.replace('method="post"', 'method="get"'), html.replace('action=""', 'action="https://external.invalid/"'), html.replace('cetech_de_promise_guide', 'other_action')):
            with self.subTest(malformed=malformed):
                with self.assertRaises(RuntimeError): driver.guide_form(response(malformed), selected)

    def test_each_owned_cleanup_is_attempted_after_an_independent_refusal(self):
        script = Path(__file__).with_name('opening-http-promise-native-configuration.py')
        spec = importlib.util.spec_from_file_location('p05_cleanup_controls', script)
        driver = importlib.util.module_from_spec(spec); spec.loader.exec_module(driver)
        attempts = []; refusal = RuntimeError('owned refusal')
        class Bridge:
            def __init__(inner, name): inner.name = name
            def call(inner, action):
                attempts.append((inner.name, action))
                if inner.name == 'configuration': raise refusal
                return {'restored':True}
        results, error = driver.cleanup_http_bridges({name:Bridge(name) for name in ('shipment','placement','configuration','promise','cart')})
        self.assertIs(refusal, error)
        self.assertEqual(['shipment','placement','configuration','promise','cart'], [name for name, _ in attempts])
        self.assertEqual({'cleanup_failed':False}, results['configuration'])
        self.assertTrue(results['cart']['restored'])

    def test_guide_register_preserves_the_actual_native_query_fence_outside_owned_admin_posts(self):
        """Execute the real register method and production fence with native-hook shims."""
        script = Path(__file__).with_name('opening-http-promise-staff-guide-support.php')
        source = script.parents[1].parent / 'src'
        code = r'''
final class WP_Hook { public array $callbacks=[]; }
class wpdb { public function remove_placeholder_escape(string $sql):string{return $sql;} }
function add_filter(string $hook,callable $callback,int $priority=10,int $args=1):void {
 $GLOBALS['wp_filter'][$hook]??=new WP_Hook();$GLOBALS['wp_filter'][$hook]->callbacks[$priority][]=['function'=>$callback,'accepted_args'=>$args];
}
function is_admin():bool{return $GLOBALS['control']['admin'];}
function get_current_user_id():int{return $GLOBALS['control']['user'];}
function get_current_blog_id():int{return $GLOBALS['control']['site'];}
function get_option(string $name):mixed{return ['home'=>'http://127.0.0.1:8085','siteurl'=>'http://127.0.0.1:8085'][$name]??null;}
function cetech_q05_state():array{++$GLOBALS['control']['state_reads'];return $GLOBALS['control']['state'];}
final class CetechQuotePlacementHttpFixture {
 public static function guard(array $state):void{++$GLOBALS['control']['guards'];if(($state['environment']??null)!==true)throw new RuntimeException('Unowned fixture');}
}
define('DISABLE_WP_CRON',true);
require $argv[1];
foreach(['Domain/DeliveryQuote/QuoteShape.php','Domain/DeliveryQuote/QuoteJson.php','Application/DeliveryQuote/LegacyQuoteSourcePlan.php','Application/DeliveryQuote/LegacyQuoteSourceLocalBinding.php']as$file)require $argv[2].'/'.$file;
function check(bool $okay):void{if(!$okay)throw new RuntimeException('Guide registration control diverged');}
function reset_control(array $change=[]):void{
 $_SERVER=['REQUEST_METHOD'=>'POST'];$_GET=['page'=>'cetech-de-promises'];$_POST=['cetech_de_action'=>'cetech_de_promise_guide'];
 $GLOBALS['control']=['admin'=>true,'user'=>17,'site'=>1,'state_reads'=>0,'guards'=>0,'state'=>['environment'=>true,'site_id'=>1,'p05'=>['active'=>true,'admin'=>['id'=>17]],'p05_guide'=>['active'=>true]]];
 foreach($change as$key=>$value){if($key==='server')$_SERVER=$value;elseif($key==='get')$_GET=$value;elseif($key==='post')$_POST=$value;else$GLOBALS['control'][$key]=$value;}
 $GLOBALS['wpdb']=new wpdb();$GLOBALS['wp_filter']=[];add_filter('query',[$GLOBALS['wpdb'],'remove_placeholder_escape'],0,1);
}
function fence_accepted():bool{try{return CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceLocalBinding::capture()->unchanged();}catch(RuntimeException){return false;}}
$active=['environment'=>true,'site_id'=>1,'p05'=>['active'=>true,'admin'=>['id'=>17]],'p05_guide'=>['active'=>true]];
$cases=[
 ['state'=>array_diff_key($active,['p05_guide'=>true])],
 ['state'=>array_replace_recursive($active,['p05_guide'=>['active'=>false]])],
 ['state'=>array_replace_recursive($active,['p05'=>['active'=>false]])],
 ['state'=>array_replace_recursive($active,['p05'=>['admin'=>['id'=>'17']]])],
 ['state'=>array_replace_recursive($active,['site_id'=>2])],
 ['user'=>0],['user'=>18],['site'=>2],['admin'=>false],
 ['server'=>['REQUEST_METHOD'=>'GET']],['server'=>[]],
 ['get'=>['page'=>'other-page']],['get'=>['page'=>['cetech-de-promises']]],['get'=>[]],
 ['post'=>['cetech_de_action'=>'cetech_de_promise_preview']],['post'=>['cetech_de_action'=>['cetech_de_promise_guide']]],['post'=>[]],
];
foreach($cases as$change){reset_control($change);check(fence_accepted());$before=$GLOBALS['wp_filter']['query']->callbacks;CetechPromiseStaffGuideHttpFixture::register();check($before===$GLOBALS['wp_filter']['query']->callbacks);check(fence_accepted());check($GLOBALS['control']['guards']===0);}
reset_control(['state'=>array_replace($active,['environment'=>false])]);$refused=false;try{CetechPromiseStaffGuideHttpFixture::register();}catch(RuntimeException){$refused=true;}check($refused&&fence_accepted());
reset_control();check(fence_accepted());CetechPromiseStaffGuideHttpFixture::register();check($GLOBALS['control']['state_reads']===1&&$GLOBALS['control']['guards']===1);check(count($GLOBALS['wp_filter']['query']->callbacks[PHP_INT_MAX]??[])===1);
$observer=$GLOBALS['wp_filter']['query']->callbacks[PHP_INT_MAX][0];check($observer['accepted_args']===1&&$observer['function']('SELECT 1')==='SELECT 1');check(!fence_accepted());
fwrite(STDOUT,'guide_registration_native_fence_controls=PASS');
'''
        result = subprocess.run([os.environ.get('CETECH_DE_TEST_PHP', 'php'), '-r', code, str(script), str(source)], capture_output=True, timeout=20)
        self.assertEqual(0, result.returncode, (result.stdout + result.stderr).decode(errors='replace')[:2000])
        self.assertEqual(b'guide_registration_native_fence_controls=PASS', result.stdout)

    def test_guide_cli_cold_load_owns_its_common_dependency_and_preserves_argument_suppression(self):
        """Cold CLI dispatch uses real common state/output helpers and no preload."""
        source = Path(__file__).with_name('opening-http-promise-staff-guide-support.php')
        with tempfile.TemporaryDirectory(prefix='guide-cli-control-') as temporary:
            work = Path(temporary); support = work / 'support'; support.mkdir()
            private = work / 'private'; private.mkdir(mode=0o700)
            site = work / 'site'; site.mkdir()
            helper = support / source.name; helper.write_bytes(source.read_bytes())
            common = source.with_name('opening-http-fixture-common.php')
            (support / common.name).write_bytes(common.read_bytes())
            (support / 'opening-http-promise-native-configuration-support.php').write_text('''<?php
if (isset($args)) { throw new RuntimeException('Nested dispatcher received guide arguments.'); }
final class CetechQuotePlacementHttpFixture {
 public static int $guards=0;
 public static function guard(array $state):void { ++self::$guards; if (($state['fixture_control']??null)!==true) { throw new RuntimeException('Cold CLI guard refused state.'); } }
}
''')
            state = private / 'state.json'; output = work / 'output.json'
            state.write_text(json.dumps({'site_path':str(site.resolve()), 'database_name':'cetech_wp_opening_qualification_control', 'fixture_control':True})); state.chmod(0o600)
            code = r'''
class WC_Product {} final class GuidePluginControl {} class_alias('GuidePluginControl','CetechDeliveryEngine\Bootstrap\Plugin');
function get_option(string $name):mixed{return ['cetech_opening_qualification_disposable'=>'1','home'=>'http://127.0.0.1:8085','siteurl'=>'http://127.0.0.1:8085'][$name]??null;}
function add_filter(...$args):void{$GLOBALS['cold_common_filters'][]=$args;}
define('WP_CLI',true);define('WP_ADMIN',true);define('DISABLE_WP_CRON',true);define('ABSPATH',$argv[4].'/');define('DB_NAME','cetech_wp_opening_qualification_control');define('DB_HOST','127.0.0.1');
putenv('CETECH_DE_NATIVE_OPENING_QUALIFICATION=1');putenv('CETECH_DE_HTTP_OPENING_QUALIFICATION=1');putenv('CETECH_DE_WP_DB_HOST=127.0.0.1');putenv('CETECH_DE_HTTP_FIXTURE_SITE='.$argv[4]);
putenv('CETECH_DE_HTTP_PRIVATE_DIR='.$argv[5]);putenv('CETECH_DE_HTTP_WORK_PATH='.$argv[6]);
if(function_exists('opening_http_read_state'))throw new RuntimeException('Control did not start cold.');
$args=['cleanupguide',$argv[2],$argv[3]];$original=$args;require $argv[1];
if($args!==$original||CetechQuotePlacementHttpFixture::$guards!==1||($GLOBALS['cold_common_filters']??[])!==[['action_scheduler_allow_async_request_runner','__return_false',PHP_INT_MAX]])throw new RuntimeException('CLI arguments or guards changed.');
fwrite(STDOUT,'guide_cli_cold_common_dependency_controls=PASS');
'''
            result = subprocess.run([os.environ.get('CETECH_DE_TEST_PHP', 'php'), '-r', code, str(helper), str(state), str(output), str(site), str(private), str(work)], capture_output=True, timeout=20)
            self.assertEqual(0, result.returncode, (result.stdout + result.stderr).decode(errors='replace')[:2000])
            self.assertEqual(b'guide_cli_cold_common_dependency_controls=PASS', result.stdout)
            self.assertEqual(dict.fromkeys(('exact_owned_sources_removed','exact_owned_operation_namespaces_removed','exact_private_index_restored','exact_pending_journal_restored','protected_history_unchanged','adoption_stays_off'),True), json.loads(output.read_text()))

    def test_large_assembled_intent_sql_preserves_bytes_and_refuses_template_drift(self):
        """Source-authored plans and SQL cover large literals without a native bootstrap."""
        helper = Path(__file__).with_name('opening-http-promise-staff-guide-support.php')
        source = helper.parents[1].parent / 'src'
        code = r'''
function __($text){return $text;} function wp_load_translations_early(){} function _doing_it_wrong(...$args){}
function has_filter(...$args){return true;} function add_filter(...$args){} function wp_cache_delete(...$args){}
spl_autoload_register(static function(string $class)use($argv):void{$prefix='CetechDeliveryEngine\\';if(str_starts_with($class,$prefix)){require $argv[2].'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';}});
require $argv[1];
$native=null;if(($argv[3]??'')!==''){require $argv[3];$native=(new ReflectionClass('wpdb'))->newInstanceWithoutConstructor();}
final class LargeGuideSqlCapture {
 public string $options='wp_options';public string $last_error='';public array $queries=[];private array $args=[];private ?string $value=null;
 public function __construct(private mixed $native){}
 public function prepare(string $sql,mixed ...$args):string{
  $this->args=$args;if($this->native!==null)return $this->native->remove_placeholder_escape($this->native->prepare($sql,...$args));
  foreach($args as$value){$escaped=strtr((string)$value,["\\"=>"\\\\","\0"=>"\\0","\n"=>"\\n","\r"=>"\\r","\x1a"=>"\\Z","'"=>"\\'",'"'=>'\\"']);$offset=strpos($sql,'%s');if($offset===false)throw new RuntimeException('Unused SQL argument');$sql=substr_replace($sql,"'".$escaped."'",$offset,2);}return $sql;
 }
 public function query(string $sql):int{$this->queries[]=$sql;$this->value=str_starts_with($sql,'INSERT ')?$this->args[1]:(str_starts_with($sql,'UPDATE ')?$this->args[0]:null);return 1;}
 public function get_var(string $sql):?string{return $this->value;}
}
function check(bool $okay):void{if(!$okay)throw new RuntimeException('Large source-intent SQL control diverged');}
function refused(callable $call):void{try{$call();}catch(RuntimeException){return;}throw new RuntimeException('Malformed large SQL was accepted');}
$fields=['offer_code'=>'q04_offer_0123456789ab','scope'=>'product','product_id'=>'99','variation_id'=>'','target_id'=>'99','estimate_required'=>'no','processing_min'=>'1','processing_max'=>'2','delivery_min'=>'1','delivery_max'=>'2','unit'=>'business_days','timezone'=>'Africa/Accra','cutoff'=>'','cutoff_miss'=>'next_opening','guide_step'=>'draft','review_id'=>'','review_stamp'=>'','replace_draft'=>''];
foreach(['mon','tue','wed','thu','fri','sat','sun']as$day){$fields[$day.'_open']='09:00';$fields[$day.'_close']='17:00';}
$date=new DateTimeImmutable('2027-01-01',new DateTimeZone('UTC'));$dates=[];for($i=0;$i<64;++$i)$dates[]=$date->modify('+'.$i.' days')->format('Y-m-d');$fields['closures']=implode("\n",$dates);
$offer=['internal_code'=>$fields['offer_code'],'public_label'=>"O'Hara · café 😀 \\route \"quoted\" 100%",'route'=>'local_delivery'];
$assemble=static function(array $fields,array $offer):array{
$draft=CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseStaffDraft::assemble($fields,$offer);check($draft['missing']===[]);
$class=CetechDeliveryEngine\Presentation\Admin\PromiseStaffGuide::class;$guide=(new ReflectionClass($class))->newInstanceWithoutConstructor();(new ReflectionProperty($class,'values'))->setValue($guide,$fields);
$payload=new ReflectionMethod($class,'payload');$step=new ReflectionMethod($class,'step');$row=new ReflectionMethod($class,'index_row');
$calendar=$draft['calendar'];$policy=$draft['policy'];$scope=['kind'=>'product','target_id'=>99];$pre=['object_revision'=>0,'version_revision'=>0,'published_version_id'=>0];
$steps=[
 $step->invoke($guide,'promise.version.create',$payload->invoke($guide,'calendar',$calendar->private_facts(),$calendar->to_private_json(),$calendar->digest(),['kind'=>'global','target_id'=>0],$pre)),
 $step->invoke($guide,'promise.version.create',$payload->invoke($guide,'policy',$policy->private_facts(),$policy->to_private_json(),$policy->digest(),$scope,$pre)),
];
$binding=CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding::bind(1,'store');
foreach($steps as$command){$target=CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseVersionCommand::target_key($binding,$command['payload']['kind'],$command['payload']['logical_id']);$identity=new CetechDeliveryEngine\Domain\Contracts\OperationIdentity(1,'service_promise.configuration','user:17',$command['operation'],1,$target,$command['token']);CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseVersionCommand::from_array($identity,$binding,$command['payload']+['author_user_id'=>17]);}
$plan=['format_version'=>1,'owner'=>17,'site_id'=>1,'site_key'=>'store','scope'=>$scope,'intent_id'=>CetechDeliveryEngine\Domain\Contracts\RequestContext::create()->request_id,'state'=>'ready','cursor'=>0,'steps'=>$steps,'row'=>$row->invoke($guide,$policy->private_facts(),$policy->digest(),$calendar,null,$offer['public_label']),'prior'=>null,'fields'=>$fields];
return $plan;};
$plan=$assemble($fields,$offer);
$plain=$assemble(array_replace($fields,['closures'=>'']),array_replace($offer,['public_label'=>'Q04 native delivery']));
$name='cetech_de_staff_intent_'.str_repeat('a',64);$bad='cetech_de_staff_intent_'.str_repeat('b',64);
$encoded=static fn(array $value):string=>CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseStaffIntent::encode($value);
check(strlen($encoded($plain))>=6000&&strlen($encoded($plain))<8192);check(strlen($encoded($plan))>=8192);
// Synthetic opaque UI padding tests the journal's byte bound; native command bodies remain unchanged.
$near=$plan;$near['fields']['parser_control_padding']='';$near['fields']['parser_control_padding']=str_repeat('x',196500-strlen($encoded($near)));check(strlen($encoded($near))===196500);
foreach([$plain,$plan,$near]as$original){
 $before=$encoded($original);$claimed=$original;$claimed['state']='inflight';$claimed['had_uncertainty']=false;$claimed['claim']=CetechDeliveryEngine\Domain\Contracts\RequestContext::create()->request_id;$after=$encoded($claimed);
 check(strlen($after)<=196608);check(CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseStaffIntent::decode($before)['state']==='ready');check(CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseStaffIntent::decode($after)['state']==='inflight');
 $GLOBALS['wpdb']=$db=new LargeGuideSqlCapture($native);$store=new CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseStaffIntent();
 check($store->insert($name,$before));check(CetechPromiseStaffGuideHttpFixture::journal_sql($db->queries[0],$db->options,$name)===[$before]);
 check($store->replace($name,$before,$after));check(CetechPromiseStaffGuideHttpFixture::journal_sql($db->queries[1],$db->options,$name)===[$after,$before]);
 check($store->replace($name,$after,null));check(CetechPromiseStaffGuideHttpFixture::journal_sql($db->queries[2],$db->options,$name)===[$after]);
 foreach($db->queries as$sql){
  refused(fn()=>CetechPromiseStaffGuideHttpFixture::journal_sql(str_replace($name,$bad,$sql),$db->options,$name));
  refused(fn()=>CetechPromiseStaffGuideHttpFixture::journal_sql(str_replace('`wp_options`','`wp_other_options`',$sql),$db->options,$name));
  refused(fn()=>CetechPromiseStaffGuideHttpFixture::journal_sql(substr($sql,0,-1),$db->options,$name));
  foreach([' OR 1=1',';',' -- comment',' /* comment */']as$tail)refused(fn()=>CetechPromiseStaffGuideHttpFixture::journal_sql($sql.$tail,$db->options,$name));
  refused(fn()=>CetechPromiseStaffGuideHttpFixture::journal_sql(str_replace('option_value','autoload',$sql),$db->options,$name));
 }
 $update=$db->queries[1];refused(fn()=>CetechPromiseStaffGuideHttpFixture::journal_sql(str_replace("AND BINARY option_name = '$name'","AND BINARY option_name = '$bad'",$update),$db->options,$name));
}
fwrite(STDOUT,$native===null?'guide_large_source_intent_sql_controls=PASS':'guide_large_native_wpdb_intent_sql_controls=PASS');
'''
        result = subprocess.run([os.environ.get('CETECH_DE_TEST_PHP', 'php'), '-r', code, str(helper), str(source), os.environ.get('CETECH_DE_GUIDE_WPDB_SOURCE', '')], capture_output=True, timeout=20)
        self.assertEqual(0, result.returncode, (result.stdout + result.stderr).decode(errors='replace')[:2000])
        self.assertEqual(b'guide_large_native_wpdb_intent_sql_controls=PASS' if os.environ.get('CETECH_DE_GUIDE_WPDB_SOURCE') else b'guide_large_source_intent_sql_controls=PASS', result.stdout)

    def test_journal_observer_decodes_actual_intent_sql_and_refuses_name_or_shape_drift(self):
        """Execute source insert/replace methods; never mirror their SQL templates."""
        script = Path(__file__).with_name('opening-http-promise-staff-guide-support.php')
        intent = script.parents[1].parent / 'src/Application/ServicePromise/Configuration/PromiseStaffIntent.php'
        code = r'''
function has_filter($tag,$callback){return true;} function add_filter(...$args){}
function wp_load_translations_early(){} function _doing_it_wrong(...$args){} function __($text){return $text;}
require $argv[1]; require $argv[2];
$native=null;
if(isset($argv[3])&&$argv[3]!==''){require $argv[3];$native=(new ReflectionClass('wpdb'))->newInstanceWithoutConstructor();}
final class GuideSqlCapture {
 public string $options='wp_options'; public string $last_error=''; public array $queries=[]; public ?string $value=null; private array $args=[];
 public function __construct(private mixed $native){}
 public function prepare(string $sql,mixed ...$args):string {
  $this->args=$args;
  if($this->native!==null)return $this->native->remove_placeholder_escape($this->native->prepare($sql,...$args));
  foreach($args as$value){$escaped=strtr((string)$value,["\\"=>"\\\\","\0"=>"\\0","\n"=>"\\n","\r"=>"\\r","\x1a"=>"\\Z","'"=>"\\'",'"'=>'\\"']);$position=strpos($sql,'%s');if($position===false)throw new RuntimeException('Unused fixture placeholder');$sql=substr_replace($sql,"'".$escaped."'",$position,2);}return $sql;
 }
 public function query(string $sql):int {$this->queries[]=$sql;$this->value=str_starts_with($sql,'INSERT ')?$this->args[1]:(str_starts_with($sql,'UPDATE ')?$this->args[0]:null);return 1;}
 public function get_var(string $sql):?string{return $this->value;}
}
function check(bool $okay):void{if(!$okay)throw new RuntimeException('Guide SQL control diverged');}
function refused(callable $work):void{try{$work();}catch(RuntimeException){return;}throw new RuntimeException('Guide SQL mutation was accepted');}
$name='cetech_de_staff_intent_'.str_repeat('a',64);$bad='cetech_de_staff_intent_'.str_repeat('b',64);
$fixtures=[json_encode(['reason'=>"O'Hara · café 😀 \\route \"quoted\"\nline 100%",'state'=>'ready'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),"raw\nline\rreturn\0null\x1aend\\'\"%"];
foreach($fixtures as$before){
 $GLOBALS['wpdb']=$db=new GuideSqlCapture($native);$store=new CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseStaffIntent();$after=$before.' next';
 check($store->insert($name,$before));check(CetechPromiseStaffGuideHttpFixture::journal_sql($db->queries[0],$db->options,$name)===[$before]);
 check($store->replace($name,$before,$after));$update=$db->queries[1];check(CetechPromiseStaffGuideHttpFixture::journal_sql($update,$db->options,$name)===[$after,$before]);
 check($store->replace($name,$after,null));$delete=$db->queries[2];check(CetechPromiseStaffGuideHttpFixture::journal_sql($delete,$db->options,$name)===[$after]);
 foreach([$update,$delete]as$sql){
  check(str_contains($sql,"WHERE option_name = '$name' AND BINARY option_name = '$name' AND BINARY option_value = "));
  refused(fn()=>CetechPromiseStaffGuideHttpFixture::journal_sql(str_replace("AND BINARY option_name = '$name'","AND BINARY option_name = '$bad'",$sql),$db->options,$name));
  refused(fn()=>CetechPromiseStaffGuideHttpFixture::journal_sql(str_replace("WHERE option_name = '$name'","WHERE option_name = '$bad'",$sql),$db->options,$name));
  refused(fn()=>CetechPromiseStaffGuideHttpFixture::journal_sql($sql.' OR 1=1',$db->options,$name));
  refused(fn()=>CetechPromiseStaffGuideHttpFixture::journal_sql(str_replace("WHERE option_name = '$name' AND ",'WHERE ',$sql),$db->options,$name));
 }
 refused(fn()=>CetechPromiseStaffGuideHttpFixture::journal_sql($db->queries[0],$db->options,$bad));
}
refused(fn()=>CetechPromiseStaffGuideHttpFixture::sql_literal('\\q'));
fwrite(STDOUT,$native===null?'guide_journal_source_sql_controls=PASS':'guide_journal_native_wpdb_prepare_controls=PASS');
'''
        native = os.environ.get('CETECH_DE_GUIDE_WPDB_SOURCE', '')
        result = subprocess.run([os.environ.get('CETECH_DE_TEST_PHP', 'php'), '-r', code, str(intent), str(script), native], capture_output=True, timeout=20)
        self.assertEqual(0, result.returncode, (result.stdout + result.stderr).decode(errors='replace')[:2000])
        self.assertEqual(b'guide_journal_native_wpdb_prepare_controls=PASS' if native else b'guide_journal_source_sql_controls=PASS', result.stdout)

    def test_shipment_requests_use_the_protected_editor_route_and_its_native_forms(self):
        """Pure driver control: the guide page deliberately has no shipment form."""
        script = Path(__file__).with_name('opening-http-promise-native-configuration.py')
        tree = ast.parse(script.read_text())
        run = next(node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == 'run')
        body = next(node for node in run.body if isinstance(node, ast.Try)).body
        action_form = next(node for node in body if isinstance(node, ast.FunctionDef) and node.name == 'action_form')
        first = next(i for i, node in enumerate(body) if isinstance(node, ast.Assign) and any(isinstance(target, ast.Name) and target.id == 'shipment' for target in node.targets))
        last = next(i for i, node in enumerate(body) if isinstance(node, ast.Expr) and isinstance(node.value, ast.Call) and node.value.args and isinstance(node.value.args[0], ast.Constant) and node.value.args[0].value == 'HTTP-W2P05-OUTSIDER-SHIPMENT-HISTORY-REFUSED')
        common_spec = importlib.util.spec_from_file_location('p05_shipment_route_controls', script.with_name('opening-http-driver.py'))
        common = importlib.util.module_from_spec(common_spec)
        import sys
        sys.modules[common_spec.name] = common
        common_spec.loader.exec_module(common)
        admin_url = 'http://127.0.0.1:18000/wp-admin/admin.php?page=cetech-de-promises'
        shipment_url = admin_url + '&shipment_id=17'
        order_url = 'http://127.0.0.1:18000/my-account/view-order/23/'
        original = 'Original delivery estimate'
        current = 'Separate current estimate'
        reason = 'Tracked P05 internal shipment scheduling reason'
        baseline = dict(shipment_id=17, revision=2, material_hash='unchanged', original_text=original)
        after = dict(baseline, revision=3, material_hash='updated', current_text=current, view_order_url=order_url, reason_sha256=hashlib.sha256(reason.encode()).hexdigest(), snapshot_preserved=True, original_preserved=True)
        command = {}
        requests = []

        def response(html='', status=200):
            return common.Response(status, html.encode(), {}, shipment_url)

        def form(verb, fields=None):
            fields = dict(cetech_de_action='cetech_de_promise_shipment_' + verb, cetech_de_nonce='native-' + verb) | (fields or {})
            return '<form method="post" action="">' + ''.join('<input name="' + escape(key) + '" value="' + escape(value) + '">' for key, value in fields.items()) + '</form>'

        class Bridge:
            saved = False

            def call(inner, action):
                if action == 'preparehttpafterbrowser': return dict(baseline)
                if action == 'trackhttpstaff:tracked-request': return {}
                if action == 'inspecthttpshipment': return dict(after if inner.saved else baseline)
                raise AssertionError('Unexpected fixture action: ' + action)

        bridge = Bridge()

        class Admin:
            def get(inner, url):
                self.assertEqual(shipment_url, url)
                requests.append(('GET', url))
                return response(form('read', dict(shipment_id='17')))

            def post(inner, url, fields):
                self.assertEqual(shipment_url, url)
                requests.append(('POST', url))
                verb = fields['cetech_de_action'].removeprefix('cetech_de_promise_shipment_')
                self.assertEqual('native-' + verb, fields['cetech_de_nonce'])
                self.assertEqual('17', fields['shipment_id'])
                if verb == 'read':
                    return response(original + form('update', dict(shipment_id='17', expected_revision='2', request_token='tracked-request', original_envelope='Signed-fixture-packet')))
                self.assertEqual('update', verb)
                if fields['original_envelope'] != 'Signed-fixture-packet' or (command and fields != command):
                    return response('<div class="notice notice-error"><p>The request could not be confirmed</p></div>')
                command.update(fields)
                bridge.saved = True
                return response('<div class="notice notice-success"><p>The separate current estimate was saved</p></div>' + form('update', command))

        class Customer:
            def get(inner, url):
                self.assertEqual(order_url, url)
                return response('<div data-cetech-de-shipment-original="1">' + original + '</div><div data-cetech-de-shipment-current="1">' + current + '</div>')

        class Denied:
            cookies = [SimpleNamespace(name='wordpress_logged_in_fixture')]

            def get(inner, url):
                self.assertEqual(order_url, url)
                return response(status=403)

            def post(inner, url, fields):
                self.assertEqual(shipment_url, url)
                self.assertEqual('native-read', fields['cetech_de_nonce'])
                requests.append(('DENIED POST', url))
                return response(status=403)

        checks = []

        class Recorder:
            def check(inner, case_id, condition, evidence):
                self.assertTrue(condition, case_id)
                self.assertTrue(all(value is True for value in evidence.values()), case_id)
                checks.append(case_id)

        namespace = dict(admin_url=admin_url, published=response('<p>Guide only</p>'), rendered=response('<p>Guide only</p>'), bridges={'shipment':bridge}, admin=Admin(), client=Customer(), denied=Denied(), recorder=Recorder(), datetime=datetime, hashlib=hashlib, urlencode=urlencode)
        exec(compile(ast.Module(body=[action_form, *body[first:last + 1]], type_ignores=[]), str(script), 'exec'), namespace)
        self.assertEqual(4, len(checks))
        self.assertEqual(['GET', 'POST', 'POST', 'POST', 'POST', 'POST', 'DENIED POST'], [method for method, _ in requests])
        self.assertEqual('tracked-request', command['request_token'])

    def test_actual_http_projection_has_the_same_closed_scalar_bounds(self):
        script = Path(__file__).with_name('opening-http-promise-native-configuration.py')
        spec=importlib.util.spec_from_file_location('p05_actual_http_projection_controls',script)
        driver=importlib.util.module_from_spec(spec);spec.loader.exec_module(driver)
        relative=dict(format_version=1,service_label='Standard',state='relative_window',display_timezone='Africa/Accra',reason_codes=[],relative_explanation='after_payment_confirmation',min=60,max=120,unit='elapsed_minutes',known_zero=False)
        wrap=lambda view:dict(contract_version=1,original=True,groups=[dict(views=[view],customer_text='Standard: delivery window')])
        self.assertTrue(driver.safe_promise(wrap(relative)))
        for key,value in [('format_version',True),('service_label','<private>'),('display_timezone','Unknown/Timezone'),('reason_codes',['private_provider_message']),('min',True),('max',9007199254740992),('known_zero',True)]:
            packet=wrap(copy.deepcopy(relative));packet['groups'][0]['views'][0][key]=value
            with self.subTest(key=key):self.assertFalse(driver.safe_promise(packet))
        for change in ['version','private','text','groups']:
            packet=wrap(copy.deepcopy(relative))
            if change=='version':packet['contract_version']=True
            if change=='private':packet['groups'][0]['views'][0]['component_key']='PRIVATE'
            if change=='text':packet['groups'][0]['customer_text']='<script>private</script>'
            if change=='groups':packet['groups']=[]
            with self.subTest(change=change):self.assertFalse(driver.safe_promise(packet))
        absolute=dict(format_version=1,service_label='Standard',state='absolute_window',display_timezone='Africa/Accra',reason_codes=[],**{'from':'2026-10-09 16:00:00.000000','until':'2026-10-09 18:00:00.000000'})
        self.assertTrue(driver.safe_promise(wrap(absolute)))
        for instant in ['2026-02-31 16:00:00.000000','2026-13-09 16:00:00.000000','2026-10-09 24:00:00.000000']:
            view=copy.deepcopy(absolute);view['from']=instant
            with self.subTest(instant=instant):self.assertFalse(driver.safe_promise(wrap(view)))

    def test_closed_public_projection_and_private_injection_refusals(self):
        script = Path(__file__).with_name('promise-handoff-browser.cjs')
        code = r'''
const fs=require('fs'); const source=fs.readFileSync(process.argv[1],'utf8');
const start=source.indexOf('function safeMoney('),end=source.indexOf("const countKeys=",start);if(start<0||end<start)throw Error('Closed producer schema missing');eval(source.slice(start,end));
const relative={format_version:1,service_label:'Standard',state:'relative_window',display_timezone:'Africa/Accra',reason_codes:[],relative_explanation:'after_payment_confirmation',min:60,max:120,unit:'elapsed_minutes',known_zero:false};
const absolute={format_version:1,service_label:'Standard',state:'absolute_window',display_timezone:'Africa/Accra',reason_codes:[],from:'2026-10-09 16:00:00.000000',until:'2026-10-09 18:00:00.000000'};
const unavailable={format_version:1,service_label:'Standard',state:'unavailable',display_timezone:'Africa/Accra',reason_codes:['missing_destination']};
const wrap=view=>({contract_version:1,original:true,groups:[{views:[view],customer_text:'Standard: delivery window'}]});
for(const view of [relative,absolute,unavailable])if(!safePromise(wrap(view)))throw Error('Valid closed safe view refused');
const changes=[p=>p.groups[0].component_key='PRIVATE-origin',p=>p.groups[0].views[0].source_id='PRIVATE-source',p=>p.groups[0].views[0].policy_id='PRIVATE-policy',p=>p.groups[0].views[0].min='60',p=>p.groups[0].views[0].known_zero=true,p=>p.groups[0].views[0].relative_explanation='from_checkout',p=>p.original=false,p=>p.admission_handle='PRIVATE',p=>p.groups[0].customer_text='<script>private</script>',p=>p.groups[0].views[0].reason_codes=['private_provider_message'],p=>p.groups[0].views[0].display_timezone='Unknown/Timezone',p=>p.groups=[]];
for(const change of changes){const p=wrap(structuredClone(relative));change(p);if(safePromise(p))throw Error('Private, malformed or weaker projection accepted');}
const a=wrap(structuredClone(absolute));a.groups[0].views[0].until='2026-10-09 15:00:00.000000';if(safePromise(a))throw Error('Reversed absolute promise accepted');
for(const instant of ['2026-02-31 16:00:00.000000','2026-13-09 16:00:00.000000','2026-10-09 24:00:00.000000']){const malformed=wrap(structuredClone(absolute));malformed.groups[0].views[0].from=instant;if(safePromise(malformed))throw Error('Normalized invalid native date accepted');}
const u=wrap(structuredClone(unavailable));u.groups[0].views[0].reason_codes=[];if(safePromise(u))throw Error('Unavailable silently winning');
process.stdout.write('p05_public_projection_protocol=PASS');
'''
        result = subprocess.run(['node','-e',code,str(script)],capture_output=True,timeout=20)
        self.assertEqual(0,result.returncode,(result.stdout+result.stderr).decode(errors='replace')[:1000])
        self.assertEqual(b'p05_public_projection_protocol=PASS',result.stdout)

if __name__=='__main__': unittest.main()
