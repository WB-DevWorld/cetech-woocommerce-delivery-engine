#!/usr/bin/env python3
"""Closed R24 receipt protocol. Synthetic test documents are never physical proof."""
from __future__ import annotations
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import zipfile
ROOT=Path(__file__).resolve().parents[2]
FORMAT='cetech-r24-customer-policy-native-v1'
BACKEND_FORMAT='cetech-r24-customer-policy-backend-v1'
CLOSEOUT_FORMAT='cetech-r24-customer-policy-closeout-v1'
BACKENDS=('hpos','cpt')
MAX_RECEIPT_BYTES=2097152
DIAGNOSTIC_PHASES=('initialize','shopper_guest','shopper_customer','pdp_simple','pdp_variation','native_add','cart_editor','cart_review','cart_quantity','checkout_classic','checkout_blocks','history','order_pay','public_read','source_race','complete')
DIAGNOSTIC_CLASSES=('ObservationError','TimeoutError','TypeError','SyntaxError','Error','UnknownError')
DIAGNOSTIC_CODES=('observation_required','case_refused','native_bridge_failed','browser_timeout','harness_type','invalid_json','private_file_missing','browser_error','unknown_error','checkout_http_refused','checkout_native_refused_no_original','checkout_native_refused_frozen','checkout_native_refused_bound','checkout_native_refused_complete','checkout_native_refused_unknown','checkout_result_unrecognized','checkout_gateway_missing','checkout_gateway_count','checkout_completion_count','checkout_cart_read_failed','checkout_cart_not_empty')
def browser_diagnostic(line,backend):
 prefix='customer_policy_native_browser_diagnostic='
 if not isinstance(line,str)or len(line)>512 or not line.startswith(prefix):return None
 try:
  value=json.loads(line[len(prefix):],object_pairs_hook=pairs)
  if type(value)is not dict or set(value)!=set(('backend','phase','error_class','code'))or value['backend']!=backend or value['phase']not in DIAGNOSTIC_PHASES or value['error_class']not in DIAGNOSTIC_CLASSES or value['code']not in DIAGNOSTIC_CODES:return None
  return prefix+json.dumps(value,sort_keys=True,separators=(',',':'))
 except(ValueError,TypeError):return None
CHECKOUT_ERROR_CLASSES=('none','Exception','RuntimeException','LogicException','InvalidArgumentException','UnexpectedValueException','Error','TypeError','ParseError','ValueError','JsonException','mysqli_sql_exception','OtherThrowable')
CHECKOUT_VALIDATION_CATEGORIES=('delivery','policy','control','shipping','payment','terms','field_required','field_invalid','other')
CHECKOUT_CHECKPOINTS=('before_v2_line','after_v2_line','before_policy_line','after_policy_line')
CHECKOUT_OPERATIONS=('unobserved','click','final_response','final_body','classification','inspect_after','native_cart','headers')
CHECKOUT_BODY_ACCESS=('unobserved','pending','ready','captured','enable_failed','request_invalid','request_ambiguous','response_mismatch','request_failed','body_unavailable','body_overflow','body_invalid','body_timeout')
def checkout_diagnostic(line,backend):
 """Closed console-only observation, independent of all four receipt schemas."""
 prefix='customer_policy_native_checkout_diagnostic='
 if not isinstance(line,str)or len(line)>2048 or not line.startswith(prefix):return None
 try:
  value=json.loads(line[len(prefix):],object_pairs_hook=pairs)
  if type(value)is not dict or set(value)!=set(('backend','route','operation','response_seen','http_class','native_result','body_access','gateway_entries','gateway_delegations','payment_completions','unsaved_preflight','validation','checkpoints','stage_classic','stage_store_post','new_native_orders','new_native_lines','original_state'))or value['backend']!=backend or value['route']not in('classic','blocks')or value['unsaved_preflight']not in('pass','unobserved'):return None
  if value['body_access']not in CHECKOUT_BODY_ACCESS:return None
  if value['operation']not in CHECKOUT_OPERATIONS or type(value['response_seen'])is not bool or value['http_class']not in('unobserved','2xx','3xx','4xx','5xx','other')or value['native_result']not in('unobserved','success','failure','other'):return None
  if not value['response_seen']and(value['http_class']!='unobserved'or value['native_result']!='unobserved'):return None
  if any(value[k]not in('zero','one','multiple','unknown')for k in('gateway_entries','gateway_delegations','payment_completions')):return None
  validation=value['validation']
  if type(validation)is not dict or set(validation)!=set(('observed','has_errors','categories','notice_errors'))or any(type(validation[k])is not bool for k in('observed','has_errors','notice_errors')):return None
  categories=validation['categories']
  if type(categories)is not list or len(categories)>len(CHECKOUT_VALIDATION_CATEGORIES)or any(type(x)is not str or x not in CHECKOUT_VALIDATION_CATEGORIES for x in categories)or categories!=[x for x in CHECKOUT_VALIDATION_CATEGORIES if x in categories]:return None
  if not validation['observed']and(validation['has_errors']or categories or validation['notice_errors']):return None
  checkpoints=value['checkpoints']
  if type(checkpoints)is not dict or set(checkpoints)!=set(CHECKOUT_CHECKPOINTS)or any(type(x)is not bool for x in checkpoints.values()):return None
  for key in('stage_classic','stage_store_post'):
   stage=value[key]
   if type(stage)is not dict or set(stage)!=set(('status','error_class','cause_class'))or stage['status']not in('unobserved','entered','returned','threw')or stage['error_class']not in CHECKOUT_ERROR_CLASSES or stage['cause_class']not in CHECKOUT_ERROR_CLASSES:return None
   if(stage['status']=='threw')!=(stage['error_class']!='none')or(stage['status']=='threw')!=(stage['cause_class']!='none'):return None
  if any(value[k]not in('zero','positive','unknown')for k in('new_native_orders','new_native_lines'))or value['original_state']not in('absent','frozen','bound','complete','multiple','unknown'):return None
  return prefix+json.dumps(value,sort_keys=True,separators=(',',':'))
 except(ValueError,TypeError):return None
PRODUCERS=('scripts/ci-customer-policy-native.sh','scripts/qualification/customer-policy-native.php','scripts/qualification/customer-policy-history.php','scripts/qualification/customer-policy-native-driver.py','scripts/qualification/customer-policy-browser.cjs','scripts/qualification/customer-policy-browser-history.cjs','scripts/qualification/verify-customer-policy-native.py')
REQUIRED_SOURCES=('src/Domain/CustomerPolicy/PolicyJson.php','src/Domain/CustomerPolicy/PolicyFamilyRecord.php','src/Domain/CustomerPolicy/PolicyLineage.php','src/Domain/CustomerPolicy/PolicyLinePacket.php','src/Domain/CustomerPolicy/PolicyOrderManifest.php','src/Application/CustomerPolicy/CustomerPolicyReadGrant.php','src/Application/CustomerPolicy/CustomerPolicyCurrentObservation.php','src/Application/CustomerPolicy/CustomerPolicyCurrentProvider.php','src/Application/CustomerPolicy/CartPolicyReceiptService.php','src/Application/CustomerPolicy/CustomerPolicyPreparedCheckout.php','src/Application/CustomerPolicy/PolicyNativeOrderFacts.php','src/Application/CustomerPolicy/PolicyNativeOrderAttempt.php','src/Application/CustomerPolicy/PolicyNativeOrderStage.php','src/Application/CustomerPolicy/PolicyNativeOrderStager.php','src/Application/CustomerPolicy/PolicySavedOrderReader.php','src/Application/CustomerPolicy/CustomerPolicyFacade.php','src/Integrations/CustomerPolicy/PolicyCheckoutRuntime.php','src/Integrations/CustomerPolicy/PolicyNativeLocalBinding.php','src/Integrations/CustomerPolicy/CustomerPolicyRuntimeGate.php')
LIMITS=[
 'Owned disposable Basic positive-real-offer policy runtime only; no merchant terms, rollout, live site, SSH or protected checkpoint acceptance.',
 'Actual Classic and Blocks browser submissions are separate for HPOS/CPT and guest/customer principals; local non-settling gateway only.',
 'Current display and immutable saved policy history grant no monetary refund, manager or catalog-listing authority.',
 'Native stage interruption is observed; unsent/lost SQL acknowledgement and atomic owner recovery have separate mandatory real-database proofs.',
 'Exhaustive codec bounds and physical current-source/receipt/native-read limits are separately enforced by unit and real-SQL jobs; this browser receipt claims only its declared native subset.',
 'Retained V3/V4/C05 behavior is the exact independently verified original-native prerequisite; this lane additionally proves its own owner exclusivity and unchanged Basic V2 bytes.',
 'Owned derivatives are completely disposed; source database/files remain exact. All listeners, scoped users, private exports and derivatives require exact cleanup; no merge, release or deployment is certified.'
]
CASES=(
 ('R24-OWNED-SOURCE-LISTENER',('source_and_package_exact','listener_possession_before_credentials','actual_stack_and_backend_recorded')),
 ('R24-OWNED-FIXTURE-BASELINE',('all_targets_declared_before_effects','baseline_complete','adoptions_off')),
 ('R24-PUBLIC-WORDING-AND-PRIVACY',('all_four_fields_exact_and_escaped','public_allowlist_closed','private_names_references_and_authors_absent')),
 ('R24-OFFER-VARIATION-AND-SAMEKEY-REDRAW',('real_offer_and_native_parent_bound','both_families_independent','changed_material_redraws','old_candidate_not_silently_accepted')),
 ('R24-STALE-ASSIGNMENT-BEFORE-NATIVE-CRUD',('real_native_submission_refused','no_new_or_destructive_order_write','no_gateway_or_completion','explicit_fresh_display_required')),
 ('R24-STALE-PUBLICATION-BEFORE-NATIVE-CRUD',('original_material_not_substituted','real_native_submission_refused','no_gateway_or_completion','saved_history_untouched')),
 ('R24-TAMPERED-AND-FOREIGN-CANDIDATE',('every_closed_tamper_variant_refused','two_guests_and_two_customers_isolated','nonce_alone_not_authority','no_native_history_or_payment_effect')),
 ('R24-EXACT-RAW-LINE-TO-NATIVE-ITEM',('unique_original_cart_key_item_bijection','native_product_variation_quantity_context_equal','positive_offer_equal','no_product_or_quantity_fallback')),
 *((f'R24-{backend}-{route}-{principal}-ORIGINAL-CAPTURE',('actual_display_and_native_final_submit','both_policy_families_captured_completely','original_item_and_order_coordinates_verified','physical_authoritative_metadata_exact','gateway_once_after_complete_binding','money_and_delivery_bytes_unchanged')) for backend in ('HPOS','CPT') for route in ('CLASSIC','BLOCKS') for principal in ('GUEST','CUSTOMER')),
 ('R24-SAVED-HTML-AND-CUSTOMER-EMAIL',('saved_only_four_fields_exact','html_literal_markup_escaped','plain_text_complete','shipment_cards_do_not_hide_policy','foreign_order_projection_denied')),
 ('R24-SAVED-AFTER-SOURCE-RETIREMENT-DELETION',('saved_packet_bytes_unchanged','saved_wording_unchanged','saved_reads_succeed_with_current_catalog_tables_unavailable','missing_legacy_policy_not_backfilled')),
 ('R24-PARTIAL-DUPLICATE-NULL-HISTORY',('presence_census_detects_every_variant','incomplete_history_not_rendered_or_repaired','original_bytes_preserved','no_gateway_or_paid_rewrite')),
 ('R24-FUTURE-FORMAT-HISTORY',('unknown_not_absence','unsupported_customer_projection_closed','raw_bytes_preserved','no_live_fallback_or_reinstall_rewrite')),
 ('R24-FOREIGN-PAID-AND-ORDERPAY',('native_order_authorization_required','paid_original_read_only','existing_orderpay_never_recaptures','money_and_packet_bytes_preserved')),
 ('R24-EXACT-RETRY-AND-INTERRUPTION',('completed_retry_no_duplicate_or_rewrite','partial_owner_not_taken_over','original_candidate_and_item_binding_retained','stage_interruption_not_final_completion','no_gateway_before_confirmed_completion')),
 ('R24-SAME-PACKAGE-REINSTALL',('package_source_exact','saved_packet_and_delivery_bytes_exact','saved_html_and_email_same','adoptions_remain_off')),
 ('R24-EXPORT-RESTORE-FRESH-PROCESS',('new_owned_destination_and_exact_manifest','fresh_saved_reader_and_renderers_equal','raw_packet_and_delivery_digest_equal','private_database_credentials_not_reused')),
 ('R24-DISPOSITIONS-AND-BOUNDS',('both_family_dispositions_distinct','unresolved_assigned_never_silent_inherit','no_fabricated_terms_or_pickup_offer','frozen_bounds_declared_and_native_scope_exact','unsupported_fee_and_coupon_refused_before_crud')),
 ('R24-RETAINED-DELIVERY-AND-ADOPTION',('v2_line_and_package_bytes_unchanged','retained_v3_v4_c05_prerequisite_exact_and_own_owner_exclusive','quote_promise_bytes_off_and_promotion_unmounted','prices_totals_shipments_and_other_orders_unchanged')),
 ('R24-PUBLIC-READ-HAS-NO-WRITER-AUTHORITY',('get_patch_and_totals_no_history_write','no_catalog_listing_or_manager_grant','no_payment_or_refund_money_authority','csrf_scope_refusals_zero_effect')),
 ('R24-LATE-C07-AND-SOURCE-RACE',('prebind_source_changes_and_late_native_mutation_refused','control_pause_refuses_gateway_and_free_completion','policy_packet_in_local_frozen_binding','no_post_freeze_packet_recompute')),
 ('R24-TRACKED-PHYSICAL-CLEANUP',('all_declared_owned_fixtures_removed','all_protected_native_c01_config_and_options_restored','no_untracked_row_or_prefix_deletion','restore_derivative_removed')),
 ('R24-OWNED-LISTENER-AND-PRIVATE-CLOSEOUT',('listeners_stopped_and_waited','exact_owned_mu_removed','private_material_removed','cleanup_acknowledged')),
)
CASE_FIELDS=dict(CASES)
HEADER=('source_head','candidate_head','source_tree','installed_php_sources','installed_php_sources_hash','production_php_count','producer_sha256','prerequisite_receipts','package_identity')
ENV_FIELDS=('php','wordpress','woocommerce','database_version','backend','hpos','sync','schema','flags_count')
TAMPER_VARIANTS=('mac','session','cart_key','product','variation_parent','quantity','offer','context','purpose','review_generation','replayed_round_trip')
HISTORY_VARIANTS=('missing_packet','missing_format','missing_line_key','missing_manifest','missing_manifest_format','duplicate_packet','duplicate_format','duplicate_line_key','duplicate_manifest','duplicate_manifest_format','null_packet','null_format','null_line_key','null_manifest','null_manifest_format')

def require(value,message):
 if not value:raise ValueError(message)
def exact(value,keys,label):require(type(value)is dict and set(value)==set(keys),label)
def canonical(value):return json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(',',':'),allow_nan=False).encode()
def digest(value):return hashlib.sha256(value).hexdigest()
def pairs(values):
 out={}
 for key,value in values:require(key not in out,'Duplicate decoded JSON key');out[key]=value
 return out
def load_json(path,maximum=MAX_RECEIPT_BYTES):
 path=Path(path);require(path.is_file()and not path.is_symlink()and path.stat().st_size<=maximum,'Regular bounded receipt required')
 return json.loads(path.read_text(),object_pairs_hook=pairs,parse_constant=lambda _ :(_ for _ in()).throw(ValueError('Nonfinite number')))
def regular_private(path,maximum=262144):
 path=Path(path);require(path.is_file()and not path.is_symlink()and not(stat.S_IMODE(path.stat().st_mode)&0o077),'Private regular fixture required');return load_json(path,maximum)
def git(repo,*args):return subprocess.check_output(['git',*args],cwd=repo,stderr=subprocess.PIPE)
def sources(repo,head):
 result={}
 for item in git(repo,'ls-tree','-r','-z',head,'--','src','database','cetech-woocommerce-delivery-engine.php','uninstall.php').split(b'\0'):
  if not item:continue
  metadata,path=item.split(b'\t',1);mode,kind,_=metadata.split();path=path.decode()
  require(mode in(b'100644',b'100755')and kind==b'blob','Production source mode refused')
  if path.endswith('.php'):result[path]=digest(git(repo,'show',head+':'+path))
 return dict(sorted(result.items()))
def authority(repo,work,package):
 repo=Path(repo);work=Path(work);package=Path(package);head=git(repo,'rev-parse','HEAD').decode().strip();tree=git(repo,'rev-parse','HEAD^{tree}').decode().strip();require(re.fullmatch('[a-f0-9]{40}',head)and re.fullmatch('[a-f0-9]{40}',tree),'Exact Git identity required')
 candidate=os.environ.get('CETECH_DE_QUALIFICATION_CANDIDATE_HEAD',head);require(re.fullmatch('[a-f0-9]{40}',candidate),'Candidate identity refused')
 source=sources(repo,head);require(all(path in source for path in REQUIRED_SOURCES),'Required current sources missing')
 producers={}
 for path in PRODUCERS:
  raw=git(repo,'show',head+':'+path);require((repo/path).read_bytes()==raw,'Uncommitted producer refused');producers[path]=digest(raw)
 for support in ('scripts/qualification/extract-promise-qualification-package.py','scripts/qualification/build-promise-qualification-package.py'):require((repo/support).read_bytes()==git(repo,'show',head+':'+support),'Package replay helper differs from committed source')
 spec=importlib.util.spec_from_file_location('r24_exact_package_replay',repo/'scripts/qualification/extract-promise-qualification-package.py');replay=importlib.util.module_from_spec(spec);spec.loader.exec_module(replay);replay.verify(package,package.with_suffix('.json'),repo,head)
 report=load_json(package.with_suffix('.json'));require(report.get('format')=='cetech-promise-qualification-package-v1'and report.get('status')=='PASS'and report.get('source_head')==head and report.get('source_tree')==tree,'Current package authority differs');require(report.get('production_php_sources')==source,'Package source map differs');require(package.is_file()and not package.is_symlink()and report['zip_bytes']==package.stat().st_size and report['zip_sha256']==digest(package.read_bytes()),'Exact package bytes differ')
 with zipfile.ZipFile(package)as archive:
  names=archive.namelist();require(len(names)==len(set(names)),'Duplicate ZIP path')
  for path,sha in source.items():require(digest(archive.read('cetech-woocommerce-delivery-engine/'+path))==sha,'Package source member differs')
 native_path=work/'opening-qualification-results.json';native=load_json(native_path)
 require(native.get('format')=='cetech-opening-native-qualification-v1'and native.get('status')=='PASS'and len(native.get('cases',[]))==495,'Original native495 prerequisite incomplete')
 require(all(native.get(key)==value for key,value in {'source_head':head,'candidate_head':candidate,'source_tree':tree,'installed_php_sources':source,'installed_php_sources_hash':digest(json.dumps(source,separators=(',',':'),ensure_ascii=False).encode())}.items()),'Original native prerequisite identity differs')
 require(all(type(row)is dict and row.get('status')=='PASS'for row in native['cases']),'Original native prerequisite failed')
 return {'source_head':head,'candidate_head':candidate,'source_tree':tree,'installed_php_sources':source,'installed_php_sources_hash':digest(canonical(source)),'production_php_count':len(source),'producer_sha256':producers,'prerequisite_receipts':{'opening-qualification-results.json':digest(native_path.read_bytes())},'package_identity':{'zip_sha256':report['zip_sha256'],'zip_bytes':report['zip_bytes'],'report_sha256':digest(package.with_suffix('.json').read_bytes())}}
def backend_cases(backend):
 require(backend in BACKENDS,'Unknown backend')
 other='CPT'if backend=='hpos'else'HPOS'
 return tuple((name,fields)for name,fields in CASES if not name.startswith('R24-'+other+'-'))
def skeleton(auth,backend):
 return {'format':BACKEND_FORMAT,**auth,'environment':{'php':'','wordpress':'','woocommerce':'','database_version':'','backend':backend,'hpos':'yes'if backend=='hpos'else'no','sync':'no','schema':'12','flags_count':24},'limits':LIMITS,'status':'FAIL','cases':[{'id':name,'status':'FAIL','evidence':dict.fromkeys(fields,False)}for name,fields in backend_cases(backend)],'tamper_variants':dict.fromkeys(TAMPER_VARIANTS,False),'history_variants':dict.fromkeys(HISTORY_VARIANTS,False)}
def check_case(report,name,evidence):
 require(name in CASE_FIELDS,'Unknown native case');exact(evidence,CASE_FIELDS[name],'Closed native case fields differ');require(all(type(value)is bool for value in evidence.values()),'Native observations must be booleans')
 rows=[row for row in report['cases']if row['id']==name];require(len(rows)==1,'Case does not belong to backend');rows[0].update(status='PASS'if all(evidence.values())else'FAIL',evidence=evidence)
def verify_backend(report,backend,auth):
 exact(report,('format',*HEADER,'environment','limits','status','cases','tamper_variants','history_variants'),'Backend report shape differs');require(report['format']==BACKEND_FORMAT and report['status']=='PASS','Backend receipt incomplete')
 require(all(report[key]==auth[key]for key in HEADER),'Backend source/package authority differs');require(report['limits']==LIMITS,'Native limits differ')
 env=report['environment'];exact(env,ENV_FIELDS,'Environment shape differs');require(type(env['flags_count'])is int and env['flags_count']==24,'Native flag count differs');require(all(type(env[key])is str for key in ENV_FIELDS if key!='flags_count'),'Environment types differ');require(env['php']=='8.5.11'and env['wordpress']=='7.1.2'and env['woocommerce']=='11.1.2'and re.fullmatch(r'11\.4\.13(?:-.*)?',env['database_version'])is not None,'Pinned native stack differs');require(env['backend']==backend and env['hpos']==('yes'if backend=='hpos'else'no')and env['sync']=='no'and env['schema']=='12','Native backend differs')
 expected=backend_cases(backend);require(type(report['cases'])is list and len(report['cases'])==len(expected),'Native case census differs')
 for row,(name,fields)in zip(report['cases'],expected):
  exact(row,('id','status','evidence'),'Native row shape differs');require(row['id']==name and row['status']=='PASS','Native case missing or failed');exact(row['evidence'],fields,'Native observations differ');require(all(value is True for value in row['evidence'].values()),'Native observation did not pass')
 for key,variants in [('tamper_variants',TAMPER_VARIANTS),('history_variants',HISTORY_VARIANTS)]:exact(report[key],variants,'Variant census differs');require(all(value is True for value in report[key].values()),'Variant missing or failed')
 return True
def verify_closeout(report,auth):
 fields=('source_database_unchanged','source_files_unchanged','all_owned_databases_removed','all_scoped_users_removed','all_owned_sites_removed','all_listeners_stopped_and_waited','all_private_material_removed')
 exact(report,('format',*HEADER,'status','evidence'),'Closeout shape differs');require(report['format']==CLOSEOUT_FORMAT and report['status']=='PASS'and all(report[k]==auth[k]for k in HEADER),'Closeout authority differs');exact(report['evidence'],fields,'Closeout observations differ');require(all(v is True for v in report['evidence'].values()),'Closeout incomplete');return True
def aggregate(auth,reports,closeout):
 for backend in BACKENDS:verify_backend(reports[backend],backend,auth)
 verify_closeout(closeout,auth)
 rows=[]
 for name,fields in CASES:
  relevant=[r for b,r in reports.items()if any(x['id']==name for x in r['cases'])];require(bool(relevant),'Missing matrix case');rows.append({'id':name,'status':'PASS','evidence':{field:all(next(x for x in r['cases']if x['id']==name)['evidence'][field]is True for r in relevant)for field in fields}})
 return {'format':FORMAT,**auth,'status':'PASS','limits':LIMITS,'backends':{b:digest(canonical(reports[b]))for b in BACKENDS},'closeout_sha256':digest(canonical(closeout)),'cases':rows}
def verify_aggregate(report,auth,reports,closeout):require(report==aggregate(auth,reports,closeout),'Aggregate is not the exact independently verified closure');return True
def main():
 p=argparse.ArgumentParser();p.add_argument('--repo',type=Path,default=ROOT);p.add_argument('--work',type=Path,required=True);p.add_argument('--package',type=Path,required=True);args=p.parse_args();auth=authority(args.repo,args.work,args.package);reports={b:load_json(args.work/f'customer-policy-native-{b}-results.json')for b in BACKENDS};closeout=load_json(args.work/'customer-policy-native-closeout.json');verify_aggregate(load_json(args.work/'customer-policy-native-results.json'),auth,reports,closeout);print('customer_policy_native=PASS cases=30 backends=2 cases_per_backend=26')
if __name__=='__main__':
 try:main()
 except(ValueError,OSError,KeyError,TypeError,subprocess.SubprocessError,zipfile.BadZipFile)as error:raise SystemExit('R24 receipt refused: '+str(error))
