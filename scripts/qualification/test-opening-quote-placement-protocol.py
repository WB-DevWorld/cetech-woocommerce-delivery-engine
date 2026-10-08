#!/usr/bin/env python3
"""Closed Q06 receipt and actual native browser route correlation protocol.

These adversarial checks do not claim disposable WordPress route qualification.
"""
import copy
import http.cookiejar
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock
from urllib.parse import quote

ROOT = Path(__file__).parent
SPEC = importlib.util.spec_from_file_location('q06_http', ROOT / 'opening-http-quote-placement.py')
DRIVER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DRIVER)
IDENTITY = {'source_head': '1' * 40, 'candidate_head': '2' * 40, 'source_tree': '3' * 40, 'installed_php_sources_hash': '4' * 64}
COOKIE_NOW = 1700000000


def cookie_client():
    state = dict(base_url='http://127.0.0.1:8085', username='q06_owned', password='UNUSED-SYNTHETIC-PASSWORD', user_id=20, identity=copy.deepcopy(IDENTITY))
    client = SimpleNamespace(base_url=state['base_url'], cookies=http.cookiejar.CookieJar())
    suffix = 'a' * 32
    def add(name, value, path='/', expires=None, rest=None):
        client.cookies.set_cookie(http.cookiejar.Cookie(0, name, value, None, False, '127.0.0.1', False, False, path, True, False, expires, expires is None, None, None, rest or {}, False))
    facts = 'q06_owned|' + str(COOKIE_NOW + 86400) + '|OriginalSyntheticSessionToken|'
    for path in ('/wp-admin', '/wp-content/plugins'):
        add('wordpress_' + suffix, quote(facts + 'AUTH-HMAC', safe=''), path, rest={'HttpOnly': None})
    add('wordpress_logged_in_' + suffix, quote(facts + 'LOGGED-HMAC', safe=''), rest={'HttpOnly': None})
    add('wp_woocommerce_session_' + suffix, quote('20|' + str(COOKIE_NOW + 172800) + '|' + str(COOKIE_NOW + 86400) + '|WOO-HMAC', safe=''), expires=COOKIE_NOW + 172800, rest={'HttpOnly': None, 'SameSite': 'Strict'})
    add('wordpress_test_cookie', 'WP Cookie check')
    add('woocommerce_cart_hash', 'NATIVE-CART-HASH', expires=COOKIE_NOW + 172800)
    add('woocommerce_items_in_cart', '1', expires=COOKIE_NOW + 172800)
    return client, state


def cookie_boundary(packet, state, observed=None):
    source = (ROOT / 'quote-placement-browser.cjs').read_text()
    functions = source[source.index('function nativeRouteMatches('):source.index('write();\n(async')]
    script = "const crypto=require('node:crypto'),base=new URL('http://127.0.0.1:8085');const input=JSON.parse(require('node:fs').readFileSync(0,'utf8'));const state=input.state;function own(value){try{const u=new URL(value,base);return u.origin===base.origin&&!u.username&&!u.password&&!u.hash;}catch(_){return false;}};\n" + functions + "\nconst converted=nativeCookieHandoff(input.packet,state,input.now);const observed=input.observed===null&&converted?converted.map(cookie=>({...cookie,sameSite:cookie.sameSite||'Lax'})):input.observed;process.stdout.write(JSON.stringify({accepted:converted!==null,cookies:converted,readback:nativeCookieReadback(input.packet,observed,state,input.now)}));"
    result = subprocess.run(['node', '-e', script], input=json.dumps(dict(packet=packet, state=state, observed=observed, now=COOKIE_NOW)).encode(), capture_output=True, timeout=20, check=False)
    if result.returncode != 0:
        raise AssertionError('Private native cookie boundary failed')
    return json.loads(result.stdout)


def evidence(case_id):
    booleans = DRIVER.DIRECT_BOOLS.get(case_id, DRIVER.BROWSER_BOOLS + (('one_free_completion',) if case_id == DRIVER.BROWSER_IDS[1] else ('one_gateway_call',)))
    return {'before': dict.fromkeys(DRIVER.COUNTS, 0), 'after': dict.fromkeys(DRIVER.COUNTS, 1), 'observations': dict.fromkeys(booleans, True), 'http_status': 200}


def browser_report():
    return dict(format='cetech-w2q06-placement-browser-v1', runtime={'playwright': '1.58.2', 'chromium': '145.0.7632.6'}, status='PASS', cases=[{'id': case, 'status': 'PASS', 'evidence': evidence(case)} for case in DRIVER.BROWSER_IDS], **IDENTITY)


def helpers(expression):
    source = (ROOT / 'quote-placement-browser.cjs').read_text()
    functions = source[source.index('function nativeRouteMatches('):source.index('write();\n(async')]
    script = "const base=new URL('http://127.0.0.1:8085');const state={store_extensions_url:'/?rest_route=/wc/store/v1/cart/extensions',checkout_url:'/?rest_route=/wc/store/v1/checkout'};const own=value=>{try{const u=new URL(value,base);return u.origin===base.origin&&!u.username&&!u.password&&!u.hash;}catch(_){return false;}};\n" + functions + '\nconsole.log(JSON.stringify(' + expression + '));'
    result = subprocess.run(['node', '-e', script], capture_output=True, timeout=20, check=False)
    if result.returncode != 0:
        raise AssertionError('Pure native placement browser correlation failed')
    return json.loads(result.stdout)


def operational_plan(rows):
    code = "require $argv[1]; require $argv[2]; $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR); echo json_encode(CetechQuotePlacementOperationalCleanup::plan($input['before'],$input['orders'],$input['rows']),JSON_THROW_ON_ERROR);"
    fixture = {'before': {name: [] for name in ('shipments', 'shipment_items', 'shipment_events', 'audit_log')}, 'orders': {'10': {'groups': ['group'], 'items': [101]}}, 'rows': rows}
    result = subprocess.run([os.environ.get('CETECH_DE_QUALIFICATION_PHP', 'php'), '-r', code, str(ROOT.parent.parent / 'vendor/autoload.php'), str(ROOT / 'opening-quote-placement-support.php')], input=json.dumps(fixture).encode(), capture_output=True, timeout=20, check=False)
    if result.returncode != 0:
        raise AssertionError('Owned native operational cleanup policy failed')
    return json.loads(result.stdout)


def seal_decorator_probe():
    code = r'''
require $argv[1];
$source=file_get_contents($argv[2]);
$start=strpos($source,'final class CetechQuotePlacementHttpFixture {');
$end=strpos($source,"\nif (!class_exists('CetechOpeningQuotePlacementGateway'",$start);
if(false===$start||false===$end){throw new RuntimeException('Fixture class boundary unavailable.');}
eval(substr($source,$start,$end-$start));
function expect(bool $condition):void{if(!$condition){throw new RuntimeException('Native seal decorator regression.');}}
function native_runtime(?Closure $prior):object{
    $runtime=(new ReflectionClass(CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($runtime,'decorate_guard'))->setValue($runtime,$prior);return $runtime;
}
function native_guard():object{
    return new class implements CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementSavedEvidenceGuard {
        public function tables(CetechDeliveryEngine\Domain\Operation\OperationSession $session):array{return [];}
        public function verify(CetechDeliveryEngine\Domain\Operation\OperationSession $session,CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding $binding):bool{return true;}
    };
}
$input=native_guard();$produced=native_guard();$inside_sql=false;$sequence=[];$calls=0;
$state=['q06'=>['active'=>true,'barrier'=>'pause','barrier_triggered'=>false]];
$prior=static function($guard)use(&$sequence,&$inside_sql,$input,$produced){expect(!$inside_sql&&$guard===$input);$sequence[]='prior';return $produced;};
$selected=static function()use(&$state,&$sequence,&$inside_sql){expect(!$inside_sql);$sequence[]='selected';return CetechQuotePlacementHttpFixture::seal_barrier_selected($state,true,false);};
$barrier=static function()use(&$state,&$sequence,&$inside_sql,&$calls){expect(!$inside_sql&&array_slice($sequence,-2)===['prior','selected']);++$calls;$sequence[]='barrier';$state['q06']['barrier_triggered']=true;};
$runtime=native_runtime($prior);CetechQuotePlacementHttpFixture::install_seal_barrier($runtime,$selected,$barrier);
$decorator=(new ReflectionProperty($runtime,'decorate_guard'))->getValue($runtime);
expect($decorator instanceof Closure&&$decorator!==$prior&&(new ReflectionFunction($decorator))->getStaticVariables()['prior']===$prior);
expect($decorator($input)===$produced&&$calls===1&&$sequence===['prior','selected','barrier']);
expect($decorator($input)===$produced&&$calls===1&&$sequence===['prior','selected','barrier','prior','selected']);
// Pure owned-SQL guard use must not run a fixture/native stimulus callback.
$inside_sql=true;$session=(new ReflectionClass(CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection::class))->newInstanceWithoutConstructor();
$binding=(new ReflectionClass(CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding::class))->newInstanceWithoutConstructor();
expect([]===$produced->tables($session)&&$produced->verify($session,$binding)&&$calls===1&&count($sequence)===5);$inside_sql=false;
foreach(['expiry','pause']as$mode){$s=['q06'=>['active'=>true,'barrier'=>$mode,'barrier_triggered'=>false]];expect(CetechQuotePlacementHttpFixture::seal_barrier_selected($s,true,false));}
foreach(['seal_ack','monetary','protected','snapshot',null,'unknown']as$mode){$state['q06']['barrier']=$mode;$state['q06']['barrier_triggered']=false;$before=$calls;expect($decorator($input)===$produced&&$calls===$before);}
foreach([[false,true,false],[true,false,false],[true,true,true]]as[$active,$principal,$cli]){$s=['q06'=>['active'=>$active,'barrier'=>'pause','barrier_triggered'=>false]];expect(!CetechQuotePlacementHttpFixture::seal_barrier_selected($s,$principal,$cli));}
foreach([null,static function(){return new stdClass();},static function(){throw new RuntimeException('Prior refused.');}]as$invalid){
    $r=native_runtime($invalid);$effects=0;$observed=false;
    try{CetechQuotePlacementHttpFixture::install_seal_barrier($r,static fn()=>true,static function()use(&$effects){++$effects;});$d=(new ReflectionProperty($r,'decorate_guard'))->getValue($r);$d($input);}catch(RuntimeException){$observed=true;}
    expect($observed&&0===$effects);
}
echo json_encode(['prior_retained'=>true,'prior_called_first'=>true,'exact_produced_guard_retained'=>true,'barrier_once'=>true,'other_modes_inert'=>true,'inactive_foreign_cli_inert'=>true,'missing_invalid_throwing_prior_refused'=>true,'no_stimulus_inside_owned_sql'=>true],JSON_THROW_ON_ERROR);
'''
    result = subprocess.run([os.environ.get('CETECH_DE_QUALIFICATION_PHP', 'php'), '-r', code, str(ROOT.parent.parent / 'vendor/autoload.php'), str(ROOT / 'opening-http-quote-placement-support.php')], capture_output=True, timeout=20, check=False)
    if result.returncode != 0:
        raise AssertionError('Native production seal decorator identity/timing refused: ' + result.stderr.decode(errors='replace')[:1000])
    return json.loads(result.stdout)


def checkout_target_probe():
    code = r'''
require $argv[1];
$source=file_get_contents($argv[2]);$start=strpos($source,'final class CetechQuotePlacementHttpFixture {');$end=strpos($source,"\nif (!class_exists('CetechOpeningQuotePlacementGateway'",$start);
if(false===$start||false===$end){throw new RuntimeException('Fixture class boundary unavailable.');}eval(substr($source,$start,$end-$start));
$state=['base_url'=>'http://127.0.0.1:8085','classic_page_id'=>11,'blocks_page_id'=>12,'classic_page_url'=>'http://127.0.0.1:8085/?page_id=11','blocks_page_url'=>'http://127.0.0.1:8085/?page_id=12'];
$expected=['classic'=>CetechQuotePlacementHttpFixture::checkout_target($state,11,$state['classic_page_url'])===$state['classic_page_url'],'blocks'=>CetechQuotePlacementHttpFixture::checkout_target($state,'12',$state['blocks_page_url'])===$state['blocks_page_url']];
foreach([[13,$state['blocks_page_url']],[11,$state['blocks_page_url']],['011',$state['classic_page_url']],[true,$state['classic_page_url']],[12,'http://127.0.0.1:8085/?page_id=12&extra=PRIVATE'],[12,'http://foreign.invalid/?page_id=12'],[12,'http://127.0.0.1:8085/?page_id=12#fragment']]as[$page,$url]){
    $refused=false;try{CetechQuotePlacementHttpFixture::checkout_target($state,$page,$url);}catch(RuntimeException){$refused=true;}if(!$refused){throw new RuntimeException('Unowned checkout target accepted.');}
}
foreach(['http://127.0.0.1:8086/?page_id=12','https://127.0.0.1:8085/?page_id=12','http://actor:PRIVATE@127.0.0.1:8085/?page_id=12']as$url){$changed=$state;$changed['blocks_page_url']=$url;$refused=false;try{CetechQuotePlacementHttpFixture::checkout_target($changed,12,$url);}catch(RuntimeException){$refused=true;}if(!$refused){throw new RuntimeException('Wrong checkout origin accepted.');}}
echo json_encode($expected+['foreign_selection_refused'=>true,'wrong_selected_page_refused'=>true,'unowned_url_refused'=>true,'wrong_origin_credentials_fragment_refused'=>true],JSON_THROW_ON_ERROR);
'''
    result = subprocess.run([os.environ.get('CETECH_DE_QUALIFICATION_PHP', 'php'), '-r', code, str(ROOT.parent.parent / 'vendor/autoload.php'), str(ROOT / 'opening-http-quote-placement-support.php')], capture_output=True, timeout=20, check=False)
    if result.returncode != 0:
        raise AssertionError('Owned native checkout target refused: ' + result.stderr.decode(errors='replace')[:1000])
    return json.loads(result.stdout)


def foreign_restoration_probe():
    code = r'''
require $argv[1]; require $argv[3];
$source=file_get_contents($argv[2]);$start=strpos($source,'final class CetechQuotePlacementHttpFixture {');$end=strpos($source,"\nif (!class_exists('CetechOpeningQuotePlacementGateway'",$start);
if(false===$start||false===$end){throw new RuntimeException('Fixture class boundary unavailable.');}eval(substr($source,$start,$end-$start));
$quote=CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures::quote(state:'accepted');
$row=CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures::binding($quote,order:10)->row();
$binding=CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding::from_row(array_replace($row,['state'=>'sealed','revision'=>3,'snapshot_digest'=>hash('sha256','snapshot'),'context_digest'=>hash('sha256','context'),'verified_at'=>$row['created_at'],'sealed_at'=>$row['created_at']]),$quote);
$foreign=['id'=>21,'login'=>'q06_foreign_aaaaaaaaaaaa'];
$authority=['order_id'=>10,'original_customer_id'=>20,'foreign_customer'=>$foreign,'snapshot_hash'=>hash('sha256','protected'),'quote'=>$quote->row(),'binding'=>$binding->row()];
$state=['site_id'=>1,'user_id'=>20,'owners'=>[$quote->header()->owner()->digest()=>true],'q06'=>['active'=>true,'orders'=>[10],'foreign_order_id'=>10,'foreign_user'=>$foreign,'foreign_restoration'=>$authority]];
$current=['order_id'=>10,'customer_id'=>21,'needs_payment'=>true,'paid'=>false,'history_supported'=>true,'snapshot_hash'=>$authority['snapshot_hash'],'quote'=>$authority['quote'],'binding'=>$authority['binding']];
function match_restore(array $state,array $authority,array $current,bool $restored=false):bool{return CetechQuotePlacementHttpFixture::foreign_restoration_matches($state,$authority,$current,$restored);}
if(!match_restore($state,$authority,$current)){throw new RuntimeException('Exact temporary foreign transfer refused.');}
$restored=$current;$restored['customer_id']=20;
if(!match_restore($state,$authority,$restored,true)||match_restore($state,$authority,$restored)||match_restore($state,$authority,$current,true)){throw new RuntimeException('Restoration phase owner mismatch.');}
$variants=[];
foreach(['order_id'=>11,'customer_id'=>22,'needs_payment'=>false,'paid'=>true,'history_supported'=>false,'snapshot_hash'=>hash('sha256','changed')]as$key=>$value){$changed=$current;$changed[$key]=$value;$variants[]=[$state,$authority,$changed];}
$changed=$current;$changed['order_id']='10';$variants[]=[$state,$authority,$changed];
$changed=$current;$changed['quote']['revision']++;$variants[]=[$state,$authority,$changed];
$changed=$current;$changed['binding']['revision']=2;$variants[]=[$state,$authority,$changed];
$changed=$current;$changed['binding']['snapshot_digest']=hash('sha256','changed');$variants[]=[$state,$authority,$changed];
$changed=$state;$changed['q06']['orders']=[11];$variants[]=[$changed,$authority,$current];
$changed=$state;$changed['q06']['foreign_order_id']=11;$variants[]=[$changed,$authority,$current];
$changed=$state;$changed['q06']['foreign_user']['login']='q06_foreign_bbbbbbbbbbbb';$variants[]=[$changed,$authority,$current];
$changed=$state;$changed['user_id']=22;$variants[]=[$changed,$authority,$current];
$changed=$state;$changed['site_id']=2;$variants[]=[$changed,$authority,$current];
$changed=$state;$changed['owners']=[];$variants[]=[$changed,$authority,$current];
$changed=$authority;$changed['original_customer_id']=21;$variants[]=[$state,$changed,$current];
$changed=$authority;$changed['unexpected']='untrusted';$variants[]=[$state,$changed,$current];
$changed=$current;$changed['unexpected']='untrusted';$variants[]=[$state,$authority,$changed];
foreach($variants as[$s,$a,$c]){if(match_restore($s,$a,$c)){throw new RuntimeException('Changed foreign restoration authority accepted.');}}
echo json_encode(['exact_foreign_owner'=>true,'exact_restored_owner'=>true,'wrong_phase_owner_refused'=>true,'untracked_foreign_paid_history_changes_refused'=>true,'typed_membership_and_closed_authority_refused'=>true],JSON_THROW_ON_ERROR);
'''
    result = subprocess.run([os.environ.get('CETECH_DE_QUALIFICATION_PHP', 'php'), '-r', code, str(ROOT.parent.parent / 'vendor/autoload.php'), str(ROOT / 'opening-http-quote-placement-support.php'), str(ROOT.parent.parent / 'tests/Support/DeliveryQuote/QuoteStorageFixtures.php')], capture_output=True, timeout=20, check=False)
    if result.returncode != 0:
        raise AssertionError('Exact foreign fixture restoration guard refused: ' + result.stderr.decode(errors='replace')[:1000])
    return json.loads(result.stdout)


class QuotePlacementProtocol(unittest.TestCase):
    def test_original_python_cookiejar_roundtrips_through_node_without_another_login(self):
        client, state = cookie_client(); packet = DRIVER.browser_cookie_packet(client, state, COOKIE_NOW)
        observed = cookie_boundary(packet, state)
        self.assertTrue(observed['accepted']); self.assertTrue(observed['readback'])
        native = {(cookie.name, cookie.domain, cookie.path): cookie for cookie in client.cookies}
        self.assertEqual(len(native), len(observed['cookies']))
        for cookie in observed['cookies']:
            original = native[(cookie['name'], cookie['domain'], cookie['path'])]
            self.assertEqual(original.value, cookie['value'])
            self.assertEqual(-1 if original.expires is None else original.expires, cookie['expires'])
            self.assertEqual(original.secure, cookie['secure'])
            self.assertEqual(original.has_nonstandard_attr('HttpOnly'), cookie['httpOnly'])
            self.assertEqual(original.get_nonstandard_attr('SameSite'), cookie.get('sameSite'))
        self.assertEqual({'/wp-admin', '/wp-content/plugins'}, {cookie['path'] for cookie in observed['cookies'] if cookie['name'].startswith('wordpress_') and not cookie['name'].startswith('wordpress_logged_in_') and cookie['name'] != 'wordpress_test_cookie'})

    def test_python_cookie_export_refuses_foreign_expired_aliases_and_principal_loss(self):
        for variant in ('host', 'dot_host', 'explicit_domain', 'path', 'expired', 'auth_expired', 'duplicate', 'unknown', 'oversize', 'secure', 'unknown_rest', 'lost_auth_path', 'lost_logged_in', 'lost_woo', 'woo_customer', 'other_token', 'other_username'):
            with self.subTest(variant=variant):
                client, state = cookie_client(); client.cookies = list(client.cookies)
                logged = next(cookie for cookie in client.cookies if cookie.name.startswith('wordpress_logged_in_'))
                woo = next(cookie for cookie in client.cookies if cookie.name.startswith('wp_woocommerce_session_'))
                if variant == 'host': logged.domain = 'foreign.invalid'
                elif variant == 'dot_host': logged.domain = '.127.0.0.1'; logged.domain_initial_dot = True
                elif variant == 'explicit_domain': logged.domain_specified = True
                elif variant == 'path': logged.path = '/foreign'
                elif variant == 'expired': woo.expires = COOKIE_NOW
                elif variant == 'auth_expired': logged.value = logged.value.replace(str(COOKIE_NOW + 86400), str(COOKIE_NOW))
                elif variant == 'duplicate': client.cookies.append(copy.deepcopy(logged))
                elif variant == 'unknown': logged.name = 'foreign_authority_cookie'
                elif variant == 'oversize': logged.value = 'x' * 4097
                elif variant == 'secure': logged.secure = True
                elif variant == 'unknown_rest': logged._rest['Partitioned'] = None
                elif variant == 'lost_auth_path': client.cookies = [cookie for cookie in client.cookies if cookie.path != '/wp-admin']
                elif variant == 'lost_logged_in': client.cookies.remove(logged)
                elif variant == 'lost_woo': client.cookies.remove(woo)
                elif variant == 'woo_customer': woo.value = woo.value.replace('20%7C', '21%7C')
                elif variant == 'other_token': logged.value = logged.value.replace('OriginalSyntheticSessionToken', 'OtherSyntheticSessionToken')
                elif variant == 'other_username': logged.value = logged.value.replace('q06_owned', 'q06_foreign')
                with self.assertRaises(RuntimeError): DRIVER.browser_cookie_packet(client, state, COOKIE_NOW)

    def test_node_cookie_import_refuses_scope_identity_session_and_closed_shape_changes(self):
        client, state = cookie_client(); original = DRIVER.browser_cookie_packet(client, state, COOKIE_NOW)
        for variant in ('origin', 'identity', 'stale', 'future', 'customer', 'session_proof', 'host', 'path', 'expired', 'duplicate', 'lost_auth_path', 'lost_logged_in', 'lost_woo', 'extra_packet', 'extra_cookie', 'wrong_type', 'flags', 'samesite', 'woo_customer', 'token'):
            with self.subTest(variant=variant):
                packet = copy.deepcopy(original); logged = next(cookie for cookie in packet['cookies'] if cookie['name'].startswith('wordpress_logged_in_'))
                woo = next(cookie for cookie in packet['cookies'] if cookie['name'].startswith('wp_woocommerce_session_'))
                if variant == 'origin': packet['origin'] = 'http://127.0.0.1:8086'
                elif variant == 'identity': packet['identity']['candidate_head'] = '9' * 40
                elif variant == 'stale': packet['created_at'] -= 61
                elif variant == 'future': packet['created_at'] += 6
                elif variant == 'customer': packet['customer_id'] = 21
                elif variant == 'session_proof': packet['logged_in_sha256'] = '9' * 64
                elif variant == 'host': logged['domain'] = 'foreign.invalid'
                elif variant == 'path': logged['path'] = '/foreign'
                elif variant == 'expired': woo['expires'] = COOKIE_NOW
                elif variant == 'duplicate': packet['cookies'].append(copy.deepcopy(logged))
                elif variant == 'lost_auth_path': packet['cookies'] = [cookie for cookie in packet['cookies'] if cookie['path'] != '/wp-admin']
                elif variant == 'lost_logged_in': packet['cookies'].remove(logged)
                elif variant == 'lost_woo': packet['cookies'].remove(woo)
                elif variant == 'extra_packet': packet['password'] = 'UNTRUSTED'
                elif variant == 'extra_cookie': logged['partitionKey'] = 'UNTRUSTED'
                elif variant == 'wrong_type': logged['expires'] = True
                elif variant == 'flags': logged['httpOnly'] = False
                elif variant == 'samesite': logged['sameSite'] = 'None'
                elif variant == 'woo_customer': woo['value'] = woo['value'].replace('20%7C', '21%7C')
                elif variant == 'token': logged['value'] = logged['value'].replace('OriginalSyntheticSessionToken', 'OtherSyntheticSessionToken')
                self.assertFalse(cookie_boundary(packet, state)['accepted'])

    def test_native_cookie_readback_refuses_loss_aliases_and_changed_values_or_flags(self):
        client, state = cookie_client(); packet = DRIVER.browser_cookie_packet(client, state, COOKIE_NOW)
        converted = cookie_boundary(packet, state)['cookies']
        original = [dict(cookie, sameSite=cookie.get('sameSite', 'Lax')) for cookie in converted]
        for variant in ('loss', 'duplicate', 'value', 'expiry', 'path', 'httpOnly', 'secure', 'sameSite', 'extra'):
            with self.subTest(variant=variant):
                observed = copy.deepcopy(original)
                if variant == 'loss': observed.pop()
                elif variant == 'duplicate': observed[1] = copy.deepcopy(observed[0])
                elif variant == 'value': observed[0]['value'] += 'changed'
                elif variant == 'expiry': observed[0]['expires'] += 1
                elif variant == 'path': observed[0]['path'] = '/foreign'
                elif variant == 'httpOnly': observed[0]['httpOnly'] = not observed[0]['httpOnly']
                elif variant == 'secure': observed[0]['secure'] = True
                elif variant == 'sameSite': observed[0]['sameSite'] = 'Strict'
                elif variant == 'extra': observed[0]['partitionKey'] = 'foreign'
                self.assertFalse(cookie_boundary(packet, state, observed)['readback'])

    def test_cookie_handoff_updates_only_the_owned_private_browser_file(self):
        client, state = cookie_client()
        with tempfile.TemporaryDirectory() as directory:
            main = Path(directory) / 'fixture.json'; main.write_text('main-private-authority'); os.chmod(main, 0o600)
            path = Path(str(main) + '.browser.json'); state.update(state_path=str(main), browser_state_path=str(path))
            private = dict(state, setup_marker='unchanged'); path.write_text(json.dumps(private)); os.chmod(path, 0o600)
            with mock.patch.object(DRIVER.time, 'time', return_value=COOKIE_NOW): DRIVER.write_browser_cookie_handoff(client, state, path)
            restored = json.loads(path.read_text()); self.assertEqual('main-private-authority', main.read_text()); self.assertEqual('unchanged', restored['setup_marker'])
            self.assertNotIn('username', restored); self.assertNotIn('password', restored); self.assertEqual(0o600, path.stat().st_mode & 0o777)
            self.assertTrue(cookie_boundary(restored['cookie_handoff'], state)['readback']); self.assertFalse(any(path.parent.glob(path.name + '.handoff-*')))
            foreign = path.with_name('foreign.browser.json'); foreign.write_text(json.dumps(private)); os.chmod(foreign, 0o600)
            with self.assertRaises(RuntimeError): DRIVER.write_browser_cookie_handoff(client, state, foreign)
            for variant in ('permission', 'identity', 'origin', 'malformed'):
                with self.subTest(variant=variant):
                    altered = copy.deepcopy(private)
                    if variant == 'identity': altered['identity']['source_tree'] = '9' * 40
                    elif variant == 'origin': altered['base_url'] = 'http://127.0.0.1:8086'
                    encoded = 'malformed-private-cookie-payload' if variant == 'malformed' else json.dumps(altered)
                    path.write_text(encoded); os.chmod(path, 0o644 if variant == 'permission' else 0o600)
                    with mock.patch.object(DRIVER.time, 'time', return_value=COOKIE_NOW):
                        with self.assertRaises(RuntimeError): DRIVER.write_browser_cookie_handoff(client, state, path)
                    self.assertEqual(encoded, path.read_text()); self.assertEqual('main-private-authority', main.read_text())
            path.unlink(); path.symlink_to(main)
            with self.assertRaises(RuntimeError): DRIVER.write_browser_cookie_handoff(client, state, path)
            self.assertEqual('main-private-authority', main.read_text())

    def test_foreign_customer_restore_requires_exact_tracked_unpaid_history(self):
        observed = foreign_restoration_probe()
        self.assertEqual({'exact_foreign_owner', 'exact_restored_owner', 'wrong_phase_owner_refused', 'untracked_foreign_paid_history_changes_refused', 'typed_membership_and_closed_authority_refused'}, set(observed))
        self.assertTrue(all(value is True for value in observed.values()))

    def test_orderpay_redirect_uses_the_single_owned_native_checkout_target(self):
        observed = checkout_target_probe()
        self.assertEqual({'classic', 'blocks', 'foreign_selection_refused', 'wrong_selected_page_refused', 'unowned_url_refused', 'wrong_origin_credentials_fragment_refused'}, set(observed))
        self.assertTrue(all(value is True for value in observed.values()))

    def test_late_stimulus_retains_the_exact_production_guard_decorator(self):
        observed = seal_decorator_probe()
        self.assertEqual({'prior_retained', 'prior_called_first', 'exact_produced_guard_retained', 'barrier_once', 'other_modes_inert', 'inactive_foreign_cli_inert', 'missing_invalid_throwing_prior_refused', 'no_stimulus_inside_owned_sql'}, set(observed))
        self.assertTrue(all(value is True for value in observed.values()))

    def test_preparation_failure_discloses_no_private_cause(self):
        class Recorder:
            def check(self, case_id, condition, facts):
                self.case = {'id': case_id, 'status': 'PASS' if condition else 'FAIL', 'evidence': facts}
        class PrivateSqlCredentialError(Exception):
            pass
        for error, expected in ((ValueError('PRIVATE SQL credential payload'), 'ValueError'), (PrivateSqlCredentialError('PRIVATE raw session token'), 'OtherError')):
            recorder = Recorder(); DRIVER.record_preparation_failure(recorder, error)
            self.assertEqual(DRIVER.DIRECT_IDS[0], recorder.case['id'])
            self.assertEqual('FAIL', recorder.case['status'])
            self.assertEqual({'stage': 'ownership', 'error_class': expected, 'required_case_incomplete': True}, recorder.case['evidence'])
            self.assertTrue(DRIVER.case_valid(recorder.case))
            self.assertNotIn('PRIVATE', json.dumps(recorder.case))
            self.assertNotIn('PrivateSqlCredentialError', json.dumps(recorder.case))

    def test_late_unrelated_operational_rows_never_gain_cleanup_authority(self):
        import hashlib
        rows = {
            'shipments': [{'id': '1', 'order_id': '10', 'delivery_group_id': 'group', 'idempotency_key': '10|group', 'shipment_number': 'DE-10-' + hashlib.sha1(b'group').hexdigest()[:8]}],
            'shipment_items': [{'id': '2', 'shipment_id': '1', 'order_id': '10', 'order_item_id': '101'}],
            'shipment_events': [{'id': '3', 'shipment_id': '1', 'event_type': 'created', 'source': 'system', 'actor_user_id': None}],
            'audit_log': [{'id': '4', 'entity_type': 'order', 'entity_id': '10', 'action': 'shipment_creation_succeeded', 'actor_user_id': None, 'previous_value': None, 'site_context': None, 'new_value': json.dumps({'outcome': 'created', 'source': 'system', 'shipment_ids': [1]})}],
        }
        plan = operational_plan(rows)
        self.assertTrue(plan['ownership_complete'])
        self.assertEqual({'shipments': [1], 'shipment_items': [2], 'shipment_events': [3], 'audit_log': [4]}, plan['authorized'])
        # These arrive after the baseline snapshot, including an audit with the
        # same numeric entity ID but a different entity type or receipt owner.
        for suffix, mutation in (
            ('shipments', {'id': '91', 'order_id': '999'}),
            ('shipment_items', {'id': '92', 'shipment_id': '91', 'order_id': '999'}),
            ('shipment_events', {'id': '93', 'shipment_id': '91'}),
            ('audit_log', {'id': '94', 'entity_type': 'product'}),
            ('audit_log', {'id': '95', 'new_value': json.dumps({'outcome': 'created', 'source': 'system', 'shipment_ids': [91]})}),
            ('audit_log', {'id': '96', 'action': 'configuration_changed'}),
        ):
            late = copy.deepcopy(rows); unrelated = dict(late[suffix][0], **mutation); late[suffix].append(unrelated)
            actual = operational_plan(late)
            self.assertFalse(actual['ownership_complete'])
            self.assertNotIn(int(unrelated['id']), actual['authorized'][suffix])
            remaining = [row for row in late[suffix] if int(row['id']) not in actual['authorized'][suffix]]
            self.assertIn(unrelated, remaining)

    def test_inventory_is_unique_and_exact(self):
        self.assertEqual(28, len(DRIVER.DIRECT_IDS))
        self.assertEqual(2, len(DRIVER.BROWSER_IDS))
        self.assertEqual(31, len(DRIVER.REQUIRED_IDS))
        self.assertEqual(31, len(set(DRIVER.REQUIRED_IDS)))
        self.assertEqual(set(DRIVER.DIRECT_IDS), set(DRIVER.DIRECT_BOOLS))
        self.assertTrue(all(set(value).issubset(DRIVER.BOOLS) for value in DRIVER.DIRECT_BOOLS.values()))

    def test_every_case_requires_its_exact_closed_observations(self):
        for case_id in DRIVER.DIRECT_IDS + DRIVER.BROWSER_IDS:
            case = {'id': case_id, 'status': 'PASS', 'evidence': evidence(case_id)}
            self.assertTrue(DRIVER.case_valid(case), case_id)
            for key in list(case['evidence']['observations']):
                missing = copy.deepcopy(case); del missing['evidence']['observations'][key]
                self.assertFalse(DRIVER.case_valid(missing), case_id + key)
                false = copy.deepcopy(case); false['evidence']['observations'][key] = False
                self.assertFalse(DRIVER.case_valid(false), case_id + key)
                wrong = copy.deepcopy(case); wrong['evidence']['observations'][key] = 1
                self.assertFalse(DRIVER.case_valid(wrong), case_id + key)
            private = copy.deepcopy(case); private['evidence']['observations']['private_quote_row'] = 'PRIVATE'
            self.assertFalse(DRIVER.case_valid(private))

    def test_counters_are_exact_bounded_integers(self):
        passed = evidence(DRIVER.DIRECT_IDS[0])
        for bad in (True, -1, 1000001, 'PRIVATE-NATIVE-ID', None, 1.0):
            changed = copy.deepcopy(passed); changed['before']['orders'] = bad
            self.assertFalse(DRIVER.evidence_valid(changed))
        for key in ('private_order_key', 'nonce', 'session'):
            changed = copy.deepcopy(passed); changed['after'][key] = 'PRIVATE'
            self.assertFalse(DRIVER.evidence_valid(changed))
        for status in (True, 99, 600, '200'):
            changed = copy.deepcopy(passed); changed['http_status'] = status
            self.assertFalse(DRIVER.evidence_valid(changed))

    def test_browser_requires_exact_identity_runtime_order_and_completion(self):
        report = browser_report(); self.assertTrue(DRIVER.browser_report_valid(report, IDENTITY))
        for mutation in ('source', 'version', 'chromium', 'duplicate', 'reverse', 'missing', 'extra_case', 'private_top', 'private_case', 'false_predicate', 'wrong_id', 'not_pass'):
            bad = copy.deepcopy(report)
            if mutation == 'source': bad['installed_php_sources_hash'] = '9' * 64
            elif mutation == 'version': bad['runtime']['playwright'] = '1.59.0'
            elif mutation == 'chromium': bad['runtime']['chromium'] = '145.0.0.0'
            elif mutation == 'duplicate': bad['cases'][1] = copy.deepcopy(bad['cases'][0])
            elif mutation == 'reverse': bad['cases'].reverse()
            elif mutation == 'missing': bad['cases'].pop()
            elif mutation == 'extra_case': bad['cases'].append(copy.deepcopy(bad['cases'][0]))
            elif mutation == 'private_top': bad['password'] = 'PRIVATE'
            elif mutation == 'private_case': bad['cases'][0]['evidence']['quote_body'] = 'PRIVATE'
            elif mutation == 'false_predicate': bad['cases'][0]['evidence']['observations']['known_sealed_receipt'] = False
            elif mutation == 'wrong_id': bad['cases'][0]['id'] = DRIVER.DIRECT_IDS[0]
            elif mutation == 'not_pass': bad['status'] = 'RUNNING'
            self.assertFalse(DRIVER.browser_report_valid(bad, IDENTITY), mutation)

    def test_failure_and_cleanup_never_publish_private_strings(self):
        failed = {'stage': 'submit', 'error_class': 'TimeoutError', 'required_case_incomplete': True}
        self.assertTrue(DRIVER.evidence_valid(failed, failure=True))
        for key, value in (('stage', 'PRIVATE-URI'), ('error_class', 'PRIVATE-ERROR'), ('required_case_incomplete', 1), ('message', 'PRIVATE')):
            bad = dict(failed); bad[key] = value
            self.assertFalse(DRIVER.evidence_valid(bad, failure=True))
        cleaned = dict.fromkeys(DRIVER.CLEANUP_KEYS, True)
        self.assertTrue(DRIVER.cleanup_valid(cleaned))
        self.assertTrue(DRIVER.case_valid({'id': DRIVER.CLEANUP_ID, 'status': 'PASS', 'evidence': cleaned}))
        self.assertFalse(DRIVER.case_valid({'id': DRIVER.CLEANUP_ID, 'status': 'PASS', 'evidence': dict(cleaned, cleanup_restored=False)}))
        for key in DRIVER.CLEANUP_KEYS:
            bad = dict(cleaned); del bad[key]
            self.assertFalse(DRIVER.cleanup_valid(bad))

    def test_effective_native_method_distinguishes_final_posts_from_updates(self):
        checkout = '/?rest_route=/wc/store/v1/checkout'
        args = ",'POST','{}',state.store_extensions_url,state.checkout_url,"
        self.assertEqual(1, helpers('nativeCheckoutPosts(' + json.dumps(checkout) + args + '{})'))
        self.assertEqual(0, helpers('nativeCheckoutPosts(' + json.dumps(checkout) + args + "{'X-HTTP-Method-Override':'PUT'})"))
        self.assertEqual(0, helpers('nativeCheckoutPosts(' + json.dumps(checkout) + args + "{'x-http-method-override':'PATCH'})"))
        self.assertEqual(1, helpers('nativeCheckoutPosts(' + json.dumps(checkout + '&_method=POST') + args + "{'x-http-method-override':'PUT'})"))
        for url, headers in ((checkout + '&_method=PUT&_method=POST', {}), (checkout, {'X-HTTP-Method-Override': 'POST'}), (checkout, {'x-http-method-override': 'PUT,POST'}), (checkout + '&_method[]=POST', {}), ('http://127.0.0.1:8086/?rest_route=/wc/store/v1/checkout', {})):
            self.assertIsNone(helpers('checkoutSelection(' + json.dumps(url) + ",'POST'," + json.dumps(headers) + ",'{}')"))

    def test_actual_final_batch_response_is_correlated_to_same_index(self):
        batch = {'requests': [{'path': '/wc/store/v1/cart', 'method': 'GET', 'body': {}}, {'path': '/wc/store/v1/checkout', 'method': 'POST', 'body': {}}]}
        selection = helpers("checkoutSelection('/?rest_route=/wc/store/v1/batch','POST',{}," + json.dumps(json.dumps(batch)) + ')')
        self.assertEqual({'transport': 'batch', 'index': 1, 'count': 2}, selection)
        del batch['requests'][0]['body']
        self.assertEqual(selection, helpers("checkoutSelection('/?rest_route=/wc/store/v1/batch','POST',{}," + json.dumps(json.dumps(batch)) + ')'))
        returned = {'payment_result': {'payment_status': 'success'}}
        payload = {'responses': [{'status': 200, 'headers': {}, 'body': {'private_sibling': 'UNOBSERVED'}}, {'status': 200, 'headers': {}, 'body': returned}]}
        self.assertEqual(returned, helpers('checkoutResponse(' + json.dumps(selection) + ',' + json.dumps(payload) + ',207)'))
        self.assertIsNone(helpers('checkoutResponse(' + json.dumps(selection) + ',' + json.dumps(payload) + ',200)'))
        payload['responses'][1]['status'] = 500
        self.assertIsNone(helpers('checkoutResponse(' + json.dumps(selection) + ',' + json.dumps(payload) + ',207)'))
        batch['requests'].append(copy.deepcopy(batch['requests'][1]))
        self.assertIsNone(helpers("checkoutSelection('/?rest_route=/wc/store/v1/batch','POST',{}," + json.dumps(json.dumps(batch)) + ')'))

    def test_actual_route_correlation_refuses_foreign_ambiguous_and_unbounded_requests(self):
        checkout = '/?rest_route=/wc/store/v1/checkout'
        for observed in ('http://foreign.invalid/?rest_route=/wc/store/v1/checkout', checkout + '&rest_route=/other', checkout + '#hidden', 'http://user@127.0.0.1:8085' + checkout):
            self.assertIsNone(helpers('checkoutSelection(' + json.dumps(observed) + ",'POST',{},'{}')"))
        for requests in ([], [None], [{'path': 'https://foreign.invalid/wc/store/v1/checkout', 'method': 'POST', 'body': {}}], [{'path': '/wc/store/v1/batch', 'method': 'POST', 'body': {}}], [{'path': '/wc/store/v1/cart', 'method': 'GET', 'body': {}}] * 26):
            self.assertIsNone(helpers("checkoutSelection('/?rest_route=/wc/store/v1/batch','POST',{}," + json.dumps(json.dumps({'requests': requests})) + ')'))
        self.assertIsNone(helpers("checkoutSelection('/?rest_route=/wc/store/v1/batch','POST',{},JSON.stringify({requests:[{path:'/wc/store/v1/checkout',method:'POST',body:{padding:'x'.repeat(262145)}}]}))"))


if __name__ == '__main__':
    unittest.main()
