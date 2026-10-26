#!/usr/bin/env python3
"""Counterfeit protocol tests only; no native physical acceptance is asserted."""
from copy import deepcopy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
SPEC=importlib.util.spec_from_file_location('r24_verifier',Path(__file__).with_name('verify-customer-policy-native.py'))
V=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(V)
class ReceiptProtocol(unittest.TestCase):
 def setUp(self):
  source=dict(sorted({p:'a'*64 for p in V.REQUIRED_SOURCES}.items()))
  self.auth={'source_head':'a'*40,'candidate_head':'b'*40,'source_tree':'c'*40,'installed_php_sources':source,'installed_php_sources_hash':V.digest(V.canonical(source)),'production_php_count':len(source),'producer_sha256':{p:'d'*64 for p in V.PRODUCERS},'prerequisite_receipts':{'opening-qualification-results.json':'e'*64},'package_identity':{'zip_sha256':'f'*64,'zip_bytes':123456,'report_sha256':'1'*64}}
  self.reports={}
  for backend in V.BACKENDS:
   report=V.skeleton(self.auth,backend);report['environment'].update(php='8.5.11',wordpress='7.1.2',woocommerce='11.1.2',database_version='11.4.13-MariaDB-ubu2404')
   for row in report['cases']:V.check_case(report,row['id'],dict.fromkeys(V.CASE_FIELDS[row['id']],True))
   report['tamper_variants']=dict.fromkeys(V.TAMPER_VARIANTS,True);report['history_variants']=dict.fromkeys(V.HISTORY_VARIANTS,True);report['status']='PASS';self.reports[backend]=report
  self.closeout={'format':V.CLOSEOUT_FORMAT,**deepcopy(self.auth),'status':'PASS','evidence':dict.fromkeys(('source_database_unchanged','source_files_unchanged','all_owned_databases_removed','all_scoped_users_removed','all_owned_sites_removed','all_listeners_stopped_and_waited','all_private_material_removed'),True)}
 def refuse(self,edit):
  report=deepcopy(self.reports['hpos']);edit(report)
  with self.assertRaises((ValueError,TypeError,KeyError)):V.verify_backend(report,'hpos',self.auth)
 def test_closed_complete_synthetic_protocol_accepts_both_backend_documents(self):
  for backend in V.BACKENDS:self.assertTrue(V.verify_backend(self.reports[backend],backend,self.auth))
 def test_thirty_unique_aggregate_and_twenty_six_per_actual_backend(self):
  aggregate=V.aggregate(self.auth,self.reports,self.closeout);self.assertEqual(30,len(aggregate['cases']));self.assertEqual(30,len({r['id']for r in aggregate['cases']}));self.assertEqual([26,26],[len(r['cases'])for r in self.reports.values()]);self.assertTrue(V.verify_aggregate(aggregate,self.auth,self.reports,self.closeout))
 def test_skeleton_does_not_invent_any_observation_or_success(self):
  report=V.skeleton(self.auth,'hpos');self.assertEqual('FAIL',report['status']);self.assertTrue(all(v is False for r in report['cases']for v in r['evidence'].values()))
  with self.assertRaises(ValueError):V.verify_backend(report,'hpos',self.auth)
 def test_any_missing_case_refuses(self):self.refuse(lambda r:r['cases'].pop())
 def test_duplicated_case_in_place_refuses(self):self.refuse(lambda r:r['cases'].__setitem__(1,deepcopy(r['cases'][0])))
 def test_reordered_cases_refuse(self):self.refuse(lambda r:r['cases'].reverse())
 def test_wrong_backend_matrix_case_refuses(self):self.refuse(lambda r:r['cases'][8].__setitem__('id',r['cases'][8]['id'].replace('HPOS','CPT')))
 def test_missing_evidence_refuses(self):self.refuse(lambda r:r['cases'][0]['evidence'].pop(next(iter(r['cases'][0]['evidence']))))
 def test_extra_private_evidence_refuses(self):self.refuse(lambda r:r['cases'][0]['evidence'].__setitem__('private_customer',True))
 def test_false_evidence_cannot_hide_behind_pass_status(self):self.refuse(lambda r:r['cases'][0]['evidence'].__setitem__('source_and_package_exact',False))
 def test_integer_and_string_truth_are_not_observations(self):
  for value in(1,'true',None):self.refuse(lambda r:r['cases'][0]['evidence'].__setitem__('source_and_package_exact',value))
 def test_wrong_stack_and_flag_types_refuse(self):
  for key,value in(('php','8.5.12'),('wordpress','7.1.3'),('woocommerce','11.2.0'),('database_version','11.8.8'),('hpos','no'),('sync','yes'),('schema','11'),('flags_count',23),('flags_count',True)):
   self.refuse(lambda r:r['environment'].__setitem__(key,value))
 def test_every_missing_history_variant_refuses(self):
  for name in V.HISTORY_VARIANTS:self.refuse(lambda r:r['history_variants'].__setitem__(name,False))
 def test_every_missing_tamper_variant_refuses(self):
  for name in V.TAMPER_VARIANTS:self.refuse(lambda r:r['tamper_variants'].__setitem__(name,False))
 def test_undeclared_variant_refuses(self):self.refuse(lambda r:r['tamper_variants'].__setitem__('made_up',True))
 def test_each_source_authority_field_is_exact(self):
  for key in V.HEADER:self.refuse(lambda r:r.__setitem__(key,None))
 def test_declared_bounds_limitations_cannot_be_deleted(self):self.refuse(lambda r:r['limits'].pop())
 def test_complete_backend_closure_requires_physical_cleanup(self):
  for field in self.closeout['evidence']:
   bad=deepcopy(self.closeout);bad['evidence'][field]=False
   with self.assertRaises(ValueError):V.aggregate(self.auth,self.reports,bad)
 def test_aggregate_hashes_are_derived_from_both_actual_backend_documents(self):
  report=V.aggregate(self.auth,self.reports,self.closeout);report['backends']['cpt']='0'*64
  with self.assertRaises(ValueError):V.verify_aggregate(report,self.auth,self.reports,self.closeout)
 def test_original_prerequisite_identity_is_not_substituted(self):
  report=V.aggregate(self.auth,self.reports,self.closeout);report['prerequisite_receipts']['opening-qualification-results.json']='0'*64
  with self.assertRaises(ValueError):V.verify_aggregate(report,self.auth,self.reports,self.closeout)
 def test_duplicate_decoded_json_names_are_rejected(self):
  with tempfile.TemporaryDirectory()as directory:
   path=Path(directory)/'r.json';path.write_text('{"source_head":1,"source_head":2}')
   with self.assertRaises(ValueError):V.load_json(path)
 def test_symlink_and_oversized_receipts_are_refused(self):
  with tempfile.TemporaryDirectory()as directory:
   path=Path(directory)/'r.json';path.write_text('{}');link=Path(directory)/'link';link.symlink_to(path)
   with self.assertRaises(ValueError):V.load_json(link)
   with self.assertRaises(ValueError):V.load_json(path,1)
 def test_private_state_requires_private_permissions(self):
  with tempfile.TemporaryDirectory()as directory:
   path=Path(directory)/'state.json';path.write_text('{}');path.chmod(0o644)
   with self.assertRaises(ValueError):V.regular_private(path)
   path.chmod(0o600);self.assertEqual({},V.regular_private(path))
 def test_check_case_cannot_accept_unknown_name_or_nonboolean_evidence(self):
  report=V.skeleton(self.auth,'hpos')
  with self.assertRaises(ValueError):V.check_case(report,'fabricated',{})
  with self.assertRaises(ValueError):V.check_case(report,V.CASES[0][0],dict.fromkeys(V.CASES[0][1],1))
  prefix='customer_policy_native_browser_diagnostic='
  value={'backend':'hpos','phase':'pdp_simple','error_class':'TimeoutError','code':'browser_timeout'}
  self.assertIsNotNone(V.browser_diagnostic(prefix+json.dumps(value),'hpos'))
  for code in ('parent_form_invalid','parent_variation_mismatch','parent_field_invalid','parent_submission_mismatch'):
   parent={**value,'phase':'pdp_variation','error_class':'ObservationError','code':code};self.assertIsNotNone(V.browser_diagnostic(prefix+json.dumps(parent),'hpos'))
  self.assertIsNone(V.browser_diagnostic(prefix+json.dumps(value),'cpt'))
  for key,untrusted in (('phase','private customer URL?nonce=secret'),('error_class','SecretClass'),('code','raw private message')):
   invalid=dict(value);invalid[key]=untrusted;self.assertIsNone(V.browser_diagnostic(prefix+json.dumps(invalid),'hpos'))
  self.assertIsNone(V.browser_diagnostic(prefix+json.dumps({**value,'html':'private'}),'hpos'))
  self.assertIsNone(V.browser_diagnostic(prefix+'{"backend":"hpos","backend":"hpos","phase":"pdp_simple","error_class":"TimeoutError","code":"browser_timeout"}','hpos'))
  self.assertIsNone(V.browser_diagnostic(prefix+'x'*513,'hpos'))
  prefix='customer_policy_native_checkout_diagnostic='
  alignment_default={**dict.fromkeys(V.CHECKOUT_ALIGNMENT_FLAGS,False),'metadata_delta':'unobserved'}
  value={'backend':'hpos','route':'classic','operation':'final_body','response_seen':True,'http_class':'2xx','native_result':'unobserved','body_access':'body_unavailable','body_kind':'unobserved','native_error':'unobserved','gateway_entries':'one','gateway_delegations':'zero','payment_completions':'zero','unsaved_preflight':'pass','validation':{'observed':True,'has_errors':True,'categories':['delivery'],'notice_errors':False},'checkpoints':dict.fromkeys(V.CHECKOUT_CHECKPOINTS,False),'stage_classic':{'status':'unobserved','error_class':'none','cause_class':'none','cause_point':'none','alignment':deepcopy(alignment_default)},'stage_store_post':{'status':'unobserved','error_class':'none','cause_class':'none','cause_point':'none','alignment':deepcopy(alignment_default)},'new_native_orders':'zero','new_native_lines':'zero','original_state':'frozen'}
  self.assertIsNotNone(V.checkout_diagnostic(prefix+json.dumps(value),'hpos'))
  self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(value),'cpt'))
  unseen=deepcopy(value);unseen['response_seen']=False;self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(unseen),'hpos'));unseen['http_class']='unobserved';self.assertIsNotNone(V.checkout_diagnostic(prefix+json.dumps(unseen),'hpos'))
  thrown=deepcopy(value);thrown['stage_classic']={'status':'threw','error_class':'RuntimeException','cause_class':'TypeError','cause_point':'other','alignment':deepcopy(alignment_default)};self.assertIsNotNone(V.checkout_diagnostic(prefix+json.dumps(thrown),'hpos'))
  for point in V.CHECKOUT_CAUSE_POINTS[1:]:
   thrown['stage_classic']['cause_point']=point;self.assertIsNotNone(V.checkout_diagnostic(prefix+json.dumps(thrown),'hpos'))
  thrown['stage_classic']['cause_point']='none';self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(thrown),'hpos'))
  probe=deepcopy(value);probe['stage_store_post']={'status':'threw','error_class':'RuntimeException','cause_class':'RuntimeException','cause_point':'initial_final_fence','alignment':{**dict.fromkeys(V.CHECKOUT_ALIGNMENT_FLAGS,True),'metadata_delta':'equal'}}
  for delta in V.CHECKOUT_ALIGNMENT_DELTAS[1:]:
   probe['stage_store_post']['alignment']['metadata_delta']=delta;self.assertIsNotNone(V.checkout_diagnostic(prefix+json.dumps(probe),'hpos'))
  for field,bad in [('observed',1),('store_unchanged','PRIVATE'),('metadata_projection_matches',None),('business_unchanged',{}),('metadata_delta','PRIVATE /path:123')]:
   invalid=deepcopy(probe);invalid['stage_store_post']['alignment'][field]=bad;self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(invalid),'hpos'))
  invalid=deepcopy(probe);invalid['stage_store_post']['alignment']['private']='raw';self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(invalid),'hpos'))
  invalid=deepcopy(probe);invalid['stage_store_post']['alignment']['observed']=False;self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(invalid),'hpos'))
  invalid=deepcopy(probe);invalid['stage_store_post']['alignment']['metadata_delta']='unobserved';self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(invalid),'hpos'))
  for key,untrusted in (('route','private?nonce=value'),('operation','private_nonce=value'),('response_seen',1),('http_class',200),('native_result','private body'),('body_access','private CDP error message'),('body_kind','private data'),('native_error','cetech_private_secret_code'),('gateway_entries',1),('gateway_delegations','private order'),('payment_completions',[]),('unsaved_preflight','invented'),('new_native_orders',2),('original_state','private order')):
   invalid=deepcopy(value);invalid[key]=untrusted;self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(invalid),'hpos'))
  rest=deepcopy(value);rest.update(route='blocks',body_access='captured',body_kind='rest_error',native_error='payment_processing',http_class='4xx',native_result='failure');self.assertIsNotNone(V.checkout_diagnostic(prefix+json.dumps(rest),'hpos'))
  rest['native_error']='unobserved';self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(rest),'hpos'));rest['body_kind']='payment_result';rest['native_error']='payment_processing';self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(rest),'hpos'))
  for member,field,untrusted in (('validation','categories',['private error code']),('validation','has_errors',1),('checkpoints','before_v2_line','secret'),('stage_classic','status','private method'),('stage_classic','error_class','SecretThrowable'),('stage_classic','cause_class','SecretPreviousThrowable'),('stage_classic','cause_point','/private/source/path:123 nonce=secret')):
   invalid=deepcopy(value);invalid[member][field]=untrusted;self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(invalid),'hpos'))
  invalid=deepcopy(value);invalid['validation']['categories']=['delivery','delivery'];self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(invalid),'hpos'))
  invalid=deepcopy(value);invalid['validation']['observed']=False;self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(invalid),'hpos'))
  invalid=deepcopy(value);invalid['stage_classic']={'status':'threw','error_class':'none','cause_class':'none'};self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps(invalid),'hpos'))
  self.assertIsNone(V.checkout_diagnostic(prefix+json.dumps({**value,'body':'private'}),'hpos'))
  self.assertIsNone(V.checkout_diagnostic(prefix+'{"backend":"hpos","backend":"hpos"}','hpos'))
  self.assertIsNone(V.checkout_diagnostic(prefix+'x'*2049,'hpos'))
if __name__=='__main__':unittest.main()
