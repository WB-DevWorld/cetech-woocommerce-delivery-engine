#!/usr/bin/env python3
"""Pure subprocess proofs of the fixture observer, never a WordPress bootstrap."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
SECRET = 'PRIVATE_ORDER_17_nonce_SQL_source_path_message'
PHP = r'''
namespace CetechDeliveryEngine\Integrations\DeliveryQuote {
    class QuotePlacementRuntime {
        public function stage_classic($order) {
            $GLOBALS['events'][]='stage'; ++$GLOBALS['calls'];
            $GLOBALS['stage_entry_status']=$GLOBALS['cetech_n18_callback_status'];
            if ($GLOBALS['throw']) { throw $GLOBALS['original_error']; }
            return 'unchanged';
        }
    }
}
namespace {
    class WC_Order {}
    class WP_Hook { public array $callbacks=[]; }
    class ForeignHook { public array $callbacks=[]; }
    class PrivateDiagnosticError extends \RuntimeException {}
    function cetech_n18_placement_tracked_order($order):bool { return $GLOBALS['tracked']; }
    function before($order):void { $GLOBALS['events'][]='before'; }
    function after($order):void { $GLOBALS['events'][]='after'; }
    require $argv[1];
    $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    if (!($input['missing_root']??false)) { define('CETECH_DE_PATH',$input['active_root']??$argv[2]); }
    $GLOBALS['events']=[]; $GLOBALS['calls']=0; $GLOBALS['tracked']=$input['tracked']??true;
    $GLOBALS['cetech_n18_creating_order']=$input['creating']??true;
    $GLOBALS['cetech_n18_callback_observation']=null; $GLOBALS['cetech_n18_callback_status']='not_installed'; $GLOBALS['throw']=$input['throw']??false;
    $GLOBALS['original_error']=new \RuntimeException($input['secret']);
    $mode=$input['mode'];
    if ($mode==='slot') {
        $object=new \CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime();
        $hook=($input['foreign']??false)?new ForeignHook():new WP_Hook();
        $priority=PHP_INT_MAX-1;
        $hook->callbacks=[10=>['before'=>['function'=>'before','accepted_args'=>1]],$priority=>[
            'sibling_before'=>['function'=>'before','accepted_args'=>1],
            'native_exact'=>['function'=>[$object,'stage_classic'],'accepted_args'=>$input['arity']??1],
            'sibling_after'=>['function'=>'after','accepted_args'=>1]],PHP_INT_MAX=>['after'=>['function'=>'after','accepted_args'=>1]]];
        if ($input['duplicate']??false) { $hook->callbacks[20]=['duplicate'=>['function'=>[$object,'stage_classic'],'accepted_args'=>1]]; }
        if ($input['wrong_priority']??false) { $hook->callbacks[20]=$hook->callbacks[$priority]; unset($hook->callbacks[$priority]); }
        if ($input['overbound']??false) { $hook->callbacks[20]=array_fill(0,257,['function'=>'before','accepted_args'=>1]); }
        $GLOBALS['wp_filter']=['woocommerce_checkout_order_created'=>$hook]; $before=$hook->callbacks;
        $restore=null; $same=null; $refused=false; $shape=false; $installed_status=null; $calls_before_dispatch=null;
        try {
            $restore=cetech_n18_install_stage_callback_observer();
            $installed_status=$GLOBALS['cetech_n18_callback_status']; $calls_before_dispatch=$GLOBALS['calls'];
            $changed=$hook->callbacks; $changed[$priority]['native_exact']['function']=$before[$priority]['native_exact']['function'];
            $shape=$before===$changed&&array_keys($before[$priority])===array_keys($hook->callbacks[$priority]);
            foreach ($hook->callbacks as $entries) { foreach ($entries as $entry) { ($entry['function'])(new WC_Order()); } }
        } catch (\Throwable $error) { $same=$error===$GLOBALS['original_error']; $refused=null===$restore; }
        finally { if (null!==$restore) { $restore(); } }
        echo json_encode(['shape'=>$shape,'restored'=>$before===$hook->callbacks,'calls'=>$GLOBALS['calls'],'calls_before_dispatch'=>$calls_before_dispatch,'installed_status'=>$installed_status,'entry_status'=>$GLOBALS['stage_entry_status']??null,'events'=>$GLOBALS['events'],'same_throwable'=>$same,'refused'=>$refused,'status'=>$GLOBALS['cetech_n18_callback_status'],'observation'=>$GLOBALS['cetech_n18_callback_observation']],JSON_THROW_ON_ERROR);
    } elseif ($mode==='classifier_failure') {
        $wrapper=cetech_n18_stage_callback_wrapper(static function($order):never{++$GLOBALS['calls'];throw $GLOBALS['original_error'];},static function($error):never{throw new \Error('classifier private failure');});
        try { $wrapper(new WC_Order()); } catch (\Throwable $error) { $same=$error===$GLOBALS['original_error']; }
        echo json_encode(['same_throwable'=>$same,'calls'=>$GLOBALS['calls'],'status'=>$GLOBALS['cetech_n18_callback_status'],'observation'=>$GLOBALS['cetech_n18_callback_observation']],JSON_THROW_ON_ERROR);
    } elseif ($mode==='source_map') {
        $maps=cetech_n18_stage_source_maps(); $map=$maps[$input['class']];
        require $argv[2].'/vendor/autoload.php'; class_exists($input['class']);
        $file=$input['foreign_file']??$argv[2].'/'.$map['path'];
        $frame=['class'=>$input['class'],'type'=>$input['type']??'::','function'=>$input['function']??'fail','file'=>$input['frame_file']??$file,'line'=>$input['line']];
        echo json_encode(['site'=>cetech_n18_stage_guard_site($file,$input['throw_line']??$map['throw_line'],$frame)],JSON_THROW_ON_ERROR);
    } elseif ($mode==='real_guard') {
        require $argv[2].'/vendor/autoload.php';
        if (isset($input['load_root'])) { require $input['load_root'].'/src/Integrations/DeliveryQuote/FixedPromotionNativePlacementRuntime.php'; }
        $class=new \ReflectionClass(\CetechDeliveryEngine\Integrations\DeliveryQuote\FixedPromotionNativePlacementRuntime::class);
        $runtime=$class->newInstanceWithoutConstructor();
        try { $runtime->stage(new WC_Order(),'unsupported'); }
        catch (\Throwable $cause) { $error=new \RuntimeException($input['secret'],0,$cause); }
        echo json_encode(cetech_n18_stage_callback_observation($error),JSON_THROW_ON_ERROR);
    } elseif ($mode==='chain') {
        $error=new PrivateDiagnosticError($input['secret']);
        for($i=0;$i<20;++$i) { $error=new \RuntimeException($input['secret'],0,$error); }
        echo json_encode(cetech_n18_stage_callback_observation($error),JSON_THROW_ON_ERROR);
    }
}
'''


class StageCallbackObserverTests(unittest.TestCase):
    def run_probe(self, mode, **arguments):
        result = subprocess.run(['php', '-r', PHP, str(HERE / 'opening-http-promotion-placement-observer.php'), str(ROOT)], input=json.dumps({'mode': mode, 'secret': SECRET, **arguments}), text=True, capture_output=True, check=False)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotIn(SECRET, result.stdout + result.stderr)
        self.assertNotIn(str(ROOT), result.stdout + result.stderr)
        self.assertNotIn('PrivateDiagnosticError', result.stdout + result.stderr)
        return json.loads(result.stdout)

    def test_success_preserves_exact_slot_order_arity_result_and_restores(self):
        value = self.run_probe('slot')
        self.assertTrue(value['shape']); self.assertTrue(value['restored'])
        self.assertEqual(1, value['calls']); self.assertFalse(value['refused'])
        self.assertEqual(['before', 'before', 'stage', 'after', 'after'], value['events'])
        self.assertIsNone(value['observation'])
        self.assertEqual('completed', value['status'])
        self.assertEqual(0, value['calls_before_dispatch']); self.assertEqual('installed', value['installed_status'])
        self.assertEqual('entered', value['entry_status'])

    def test_original_exception_is_observed_once_rethrown_by_identity_and_restored(self):
        value = self.run_probe('slot', throw=True)
        self.assertTrue(value['same_throwable']); self.assertTrue(value['restored']); self.assertTrue(value['shape'])
        self.assertEqual(1, value['calls']); self.assertEqual(['before', 'before', 'stage'], value['events'])
        self.assertEqual('threw', value['status'])
        self.assertEqual({'format': 'cetech-n18-stage-classic-failure-v1', 'error_class': 'RuntimeException', 'cause_class': 'RuntimeException', 'guard_site': 'unknown'}, value['observation'])

    def test_observer_never_runs_or_classifies_an_untracked_or_noncreating_order(self):
        for flags in ({'tracked': False}, {'creating': False}, {'creating': 1}):
            value = self.run_probe('slot', throw=True, **flags)
            self.assertEqual(1, value['calls']); self.assertTrue(value['same_throwable'])
            self.assertIsNone(value['observation']); self.assertTrue(value['restored'])

    def test_classifier_failure_preserves_original_throwable_and_single_call(self):
        value = self.run_probe('classifier_failure')
        self.assertEqual({'same_throwable': True, 'calls': 1, 'status': 'threw', 'observation': None}, value)

    def test_invalid_or_ambiguous_registration_refuses_before_any_native_callback(self):
        for flags in ({'foreign': True}, {'duplicate': True}, {'wrong_priority': True}, {'arity': 2}, {'arity': '1'}, {'overbound': True}):
            with self.subTest(flags=flags):
                value = self.run_probe('slot', **flags)
                self.assertTrue(value['refused']); self.assertTrue(value['restored'])
                self.assertEqual(0, value['calls']); self.assertEqual([], value['events'])
                self.assertEqual('not_installed', value['status'])

    def test_actual_production_authorization_guard_retains_previous_chain_without_private_data(self):
        value = self.run_probe('real_guard')
        self.assertEqual({'format': 'cetech-n18-stage-classic-failure-v1', 'error_class': 'RuntimeException', 'cause_class': 'UnexpectedValueException', 'guard_site': 'stage_authorization'}, value)

    def test_actual_installed_copy_classifies_only_when_active_root_and_reflected_source_agree(self):
        relative = Path('src/Integrations/DeliveryQuote/FixedPromotionNativePlacementRuntime.php')
        with tempfile.TemporaryDirectory(prefix='n18-observer-pure-') as temporary:
            installed = Path(temporary); (installed / relative).parent.mkdir(parents=True)
            (installed / relative).write_bytes((ROOT / relative).read_bytes())
            value = self.run_probe('real_guard', active_root=str(installed), load_root=str(installed))
            self.assertEqual('stage_authorization', value['guard_site'])
            self.assertEqual('unknown', self.run_probe('real_guard', active_root=str(installed))['guard_site'])
            self.assertEqual('unknown', self.run_probe('real_guard', load_root=str(installed))['guard_site'])
        self.assertEqual('unknown', self.run_probe('real_guard', missing_root=True)['guard_site'])

    def test_long_previous_chain_is_bounded_and_unknown_classes_never_escape(self):
        value = self.run_probe('chain')
        self.assertEqual('RuntimeException', value['cause_class']); self.assertEqual('unknown', value['guard_site'])

    def test_exact_source_maps_pin_real_guard_call_lines_and_closed_sites(self):
        # Read the production source only; this starts no native WordPress service.
        listing = subprocess.run(['php', '-r', 'require $argv[1];echo json_encode(cetech_n18_stage_source_maps(),JSON_THROW_ON_ERROR);', str(HERE / 'opening-http-promotion-placement-observer.php')], text=True, capture_output=True, check=True)
        maps = json.loads(listing.stdout)
        for class_name, mapping in maps.items():
            source = (ROOT / mapping['path']).read_bytes()
            self.assertEqual(mapping['sha256'], hashlib.sha256(source).hexdigest())
            self.assertIn('throw new', source.decode().splitlines()[mapping['throw_line'] - 1])
            for line, site in mapping['sites'].items():
                with self.subTest(site=site, line=line):
                    self.assertIn('self::fail()', source.decode().splitlines()[int(line) - 1])
                    self.assertEqual(site, self.run_probe('source_map', **{'class': class_name, 'line': int(line)})['site'])

    def test_foreign_or_shifted_trace_sites_are_unknown(self):
        base = {'class': 'CetechDeliveryEngine\\Application\\Order\\QuoteNativeOrderStager', 'line': 191}
        for changes in ({'foreign_file': SECRET}, {'frame_file': SECRET}, {'line': 19000}, {'throw_line': 1}, {'function': SECRET}, {'type': '->'}):
            self.assertEqual('unknown', self.run_probe('source_map', **{**base, **changes})['site'])


if __name__ == '__main__':
    unittest.main()
