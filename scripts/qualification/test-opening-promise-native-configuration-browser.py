#!/usr/bin/env python3
"""Adversarial pure public-schema checks, not browser parity evidence."""
import subprocess
import importlib.util
import copy
import ast
import datetime
import hashlib
from html import escape
import os
from pathlib import Path
from types import SimpleNamespace
from urllib.parse import urlencode
import unittest

class PromisePublicProtocolTest(unittest.TestCase):
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
