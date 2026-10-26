#!/usr/bin/env python3
"""Create, run and dispose only the predeclared R24 CI derivatives.

All unobserved native cases remain FAIL. Setup never implies native acceptance.
"""
from __future__ import annotations
import argparse
import atexit
from concurrent.futures import ThreadPoolExecutor
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
import time
import urllib.request
sys.dont_write_bytecode=True
HERE=Path(__file__).resolve().parent
SPEC=importlib.util.spec_from_file_location('r24_verifier',HERE/'verify-customer-policy-native.py');V=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(V)
def write_json(path,value):
 path=Path(path);V.require(not path.is_symlink()and(not path.exists()or path.is_file()),'Unsafe output path')
 temporary=path.with_name('.r24-'+secrets.token_hex(8))
 try:
  with temporary.open('x',encoding='utf-8')as stream:os.chmod(temporary,0o600);json.dump(value,stream,ensure_ascii=False,separators=(',',':'),allow_nan=False);stream.write('\n')
  os.replace(temporary,path)
 finally:temporary.unlink(missing_ok=True)
def file_census(site):
 result={}
 for path in sorted(site.rglob('*')):
  V.require(not path.is_symlink(),'Native source symlink refused')
  if path.is_file():result[str(path.relative_to(site))]=hashlib.sha256(path.read_bytes()).hexdigest()
 V.require(100<=len(result)<=200000,'Native source file census refused');return result
def run(command,env,log,timeout=180):
 with Path(log).open('ab')as output:
  os.chmod(log,0o600);completed=subprocess.run([str(x)for x in command],env=env,stdin=subprocess.DEVNULL,stdout=output,stderr=subprocess.STDOUT,timeout=timeout)
 V.require(completed.returncode==0,'Owned native command refused')
def config_value(php,wpcli,site,key,kind,env):
 value=subprocess.run([str(php),str(wpcli),'--allow-root','--path='+str(site),'config','get',key,'--type='+kind],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True,timeout=30).stdout.decode().strip()
 V.require(value and len(value)<=256 and not any(ord(x)<32 for x in value),'Native configuration refused');return value
def config_derivative(site,target):
 path=site/'wp-config.php';V.require(path.is_file()and not path.is_symlink(),'Owned derivative config refused');content=path.read_text()
 for name,value in {'DB_NAME':target['database'],'DB_USER':target['user'],'DB_PASSWORD':target['password']}.items():
  pattern=re.compile(r"define\(\s*(['\"])"+name+r"\1\s*,\s*(['\"])(?:\\.|(?!\2).)*?\2\s*\)\s*;")
  content,count=pattern.subn("define( '"+name+"', '"+value+"' );",content);V.require(count==1,'Owned literal configuration differs')
 for name,value in [('DISABLE_WP_CRON','true'),('WP_ENVIRONMENT_TYPE',"'local'")]:
  if name in content:
   content,count=re.subn(r"define\(\s*['\"]"+name+r"['\"]\s*,\s*(?:true|false|'[^']*'|\"[^\"]*\")\s*\)\s*;","define( '"+name+"', "+value+" );",content);V.require(count==1,'Ambiguous containment configuration')
  else:
   marker="require_once ABSPATH . 'wp-settings.php';";V.require(content.count(marker)==1,'Native bootstrap marker differs');content=content.replace(marker,"define( '"+name+"', "+value+" );\n"+marker)
 path.write_text(content);os.chmod(path,0o600)
class Harness:
 def __init__(self,args):
  self.args=args;self.work=args.work.resolve();self.site=args.site.resolve();self.repo=args.repo.resolve();self.php=args.php.resolve();self.wpcli=self.work/'wp-cli.phar'
  V.require(os.environ.get('GITHUB_ACTIONS')=='true'and os.environ.get('CETECH_DE_NATIVE_OPENING_QUALIFICATION')=='1'and os.environ.get('CETECH_DE_HTTP_OPENING_QUALIFICATION')=='1'and os.environ.get('CETECH_DE_WP_DB_HOST')=='127.0.0.1'and self.site==self.work/'opening-qualification','Owned CI environment required')
  self.auth=V.authority(self.repo,self.work,args.package.resolve());self.token=secrets.token_hex(12);self.private=self.work/('customer-policy-private-'+self.token);self.private.mkdir(mode=0o700);self.fixture=HERE/'customer-policy-native.php';self.plan_path=self.private/'plan.json';self.initialized=False;atexit.register(self.cleanup_initial_failure)
  self.env=dict(os.environ,CETECH_R24_PRIVATE=str(self.private),CETECH_R24_PLAN=str(self.plan_path),CETECH_R24_REPO=str(self.repo),CETECH_R24_NATIVE=str(self.work/'opening-qualification-results.json'),CETECH_R24_PACKAGE=str(args.package.resolve()),CETECH_R24_PACKAGE_REPORT=str(args.package.resolve().with_suffix('.json')))
  source_database=config_value(self.php,self.wpcli,self.site,'DB_NAME','constant',self.env);prefix=config_value(self.php,self.wpcli,self.site,'table_prefix','variable',self.env)
  V.require(re.fullmatch(r'cetech_wp_opening_qualification_[a-z0-9]+',source_database)and re.fullmatch(r'[A-Za-z0-9_]{1,30}',prefix),'Owned source database differs')
  self.plan={'format':'cetech-r24-owned-plan-v1','token':self.token,'probe':secrets.token_hex(24),'private':str(self.private),'work':str(self.work),'source_site':str(self.site),'source_database':source_database,'prefix':prefix,'installed_php_sources':self.auth['installed_php_sources'],'backends':{}}
  for index,backend in enumerate(V.BACKENDS):
   self.plan['backends'][backend]={}
   for kind,tag in [('primary','main'),('restore','restore')]:self.plan['backends'][backend][kind]={'database':f'cetech_r24_{self.token}_{backend}_{tag}','user':f'r24_{self.token[:16]}_{backend}_{tag}','password':secrets.token_hex(32),'site':str(self.work/f'customer-policy-{self.token}-{backend}-{tag}'),'url':f'http://127.0.0.1:{8086+index}','port':8086+index}
  for targets in self.plan['backends'].values():
   targets['source_table_renames']={prefix+'delivery_engine_'+suffix:prefix+'r24_hidden_'+short for suffix,short in [('rule_family_guards','guard'),('logical_rules','logical'),('rule_versions','version')]}
   for kind in ('primary','restore'):
    target=targets[kind];V.require(not Path(target['site']).exists()and not Path(target['site']).is_symlink(),'Owned destination already exists')
  write_json(self.plan_path,self.plan);self.baseline_files=file_census(self.site);installed=file_census(self.site/'wp-content/plugins/cetech-woocommerce-delivery-engine');package_report=V.load_json(args.package.resolve().with_suffix('.json'),16*1024*1024);V.require(installed==package_report['package_files'],'Actual source plugin files differ from exact current package');V.require({name:installed[name]for name in self.auth['installed_php_sources']}==self.auth['installed_php_sources'],'Actual installed production source differs');self.infrastructure('baseline','',self.private/'baseline.json');self.baseline=V.regular_private(self.private/'baseline.json');self.initialized=True
 def cleanup_initial_failure(self):
  if not self.initialized and self.private.is_dir()and not self.private.is_symlink()and self.private.parent==self.work and self.private.name=='customer-policy-private-'+self.token:shutil.rmtree(self.private)
 def infrastructure(self,mode,backend,output):run([self.php,self.fixture,'--infrastructure',mode,backend,output],self.env,self.private/'infrastructure.log',300)
 def bridge(self,backend,mode,output,restore=False):
  directory=self.private/backend;target=self.plan['backends'][backend]['restore'if restore else'primary'];env=dict(self.env,CETECH_R24_BACKEND=backend,CETECH_R24_STATE=str(directory/'state.json'))
  run([self.php,self.wpcli,'--allow-root','--path='+target['site'],'--require='+str(HERE/'admin-context.php'),'eval-file',self.fixture,'--use-include',mode,directory/'state.json',output],env,directory/'bridge.log',300);return V.regular_private(output,V.MAX_RECEIPT_BYTES)
 def backend(self,backend):
  directory=self.private/backend;directory.mkdir(mode=0o700);target=self.plan['backends'][backend]['primary'];site=Path(target['site']);report=V.skeleton(self.auth,backend);server=None;stopped=False;mu_removed=False;sites_removed=False;database_removed=False;failed=None;phase='clone'
  try:
   self.infrastructure('clone',backend,directory/'clone.json');shutil.copytree(self.site,site,symlinks=False);config_derivative(site,target)
   mu=site/'wp-content/mu-plugins/cetech-r24-fixture.php';V.require(not mu.exists()and not mu.is_symlink(),'Owned MU already exists');mu.parent.mkdir(parents=True,exist_ok=True);mu.write_bytes(self.fixture.read_bytes());os.chmod(mu,0o600)
   env=dict(self.env,CETECH_R24_BACKEND=backend,CETECH_R24_STATE=str(directory/'state.json'))
   phase='prepare';prepared=self.bridge(backend,'prepare',directory/'prepare.json');V.require(prepared.get('ready')is True,'Native preparation incomplete');report['environment']=prepared['environment']
   phase='listener';args=[self.php];jit=env.get('CETECH_DE_HTTP_LISTENER_JIT','');V.require(jit in('','disable'),'Unexpected listener policy')
   if jit:args+=['-d','opcache.jit=disable']
   args+=['-S','127.0.0.1:'+str(target['port']),'-t',site]
   stream=(directory/'listener.log').open('ab');os.chmod(directory/'listener.log',0o600);server=subprocess.Popen([str(x)for x in args],env=env,stdin=subprocess.DEVNULL,stdout=stream,stderr=subprocess.STDOUT);stream.close()
   expected={'format':'cetech-r24-owned-listener-v1','probe_sha256':V.digest(self.plan['probe'].encode()),'database_sha256':V.digest(target['database'].encode()),'site_sha256':V.digest(str(site.resolve()).encode()),'source_head':self.auth['source_head'],'source_tree':self.auth['source_tree'],'php':'8.5.11'};observed=None;deadline=time.monotonic()+20
   while time.monotonic()<deadline:
    V.require(server.poll()is None,'Owned listener exited')
    try:
     request=urllib.request.Request(target['url']+'/?cetech_r24_probe=1',headers={'X-Cetech-R24-Probe':self.plan['probe']});observed=json.loads(urllib.request.urlopen(request,timeout=2).read(4096));break
    except(OSError,ValueError):time.sleep(.15)
   V.require(observed==expected,'Listener identity refused before credentials');V.check_case(report,'R24-OWNED-SOURCE-LISTENER',dict.fromkeys(V.CASE_FIELDS['R24-OWNED-SOURCE-LISTENER'],True))
   browser_input={'format':'cetech-r24-browser-input-v1','backend':backend,'url':target['url'],'private':str(directory),'probe':self.plan['probe'],'prepared':prepared,'php':str(self.php),'wpcli':str(self.wpcli),'site':str(site),'fixture':str(self.fixture),'repo':str(self.repo),'state':str(directory/'state.json')};write_json(directory/'browser-input.json',browser_input)
   phase='browser'
   try:run(['node',HERE/'customer-policy-browser.cjs',directory/'browser-input.json',directory/'browser-results.json'],env,directory/'browser.log',1200)
   finally:
    browser_log=directory/'browser.log'
    if browser_log.is_file()and not browser_log.is_symlink():
     for line in browser_log.read_bytes()[-65536:].decode('utf-8',errors='replace').splitlines():
      diagnostic=V.browser_diagnostic(line,backend) or V.checkout_diagnostic(line,backend)
      if diagnostic is not None:print(diagnostic,flush=True)
    if(directory/'browser-results.json').is_file():
     actual=V.regular_private(directory/'browser-results.json',V.MAX_RECEIPT_BYTES);V.require(set(actual)=={'format','cases','tamper_variants','history_variants'}and actual['format']=='cetech-r24-browser-observations-v1','Browser observations refused')
     for name,evidence in actual['cases'].items():V.check_case(report,name,evidence)
     report['tamper_variants']=actual['tamper_variants'];report['history_variants']=actual['history_variants']
   V.require(all(row['status']=='PASS' for row in report['cases'][:-2]),'Native observations incomplete')
  except BaseException as error:failed=type(error).__name__;report['status']='FAIL'
  finally:
   if server is not None:
    try:
     live=server.poll()is None
     if live:server.terminate()
     status=server.wait(timeout=15);stopped=live and status in(0,-15,143)
    except BaseException:
     try:server.kill();server.wait(timeout=10)
     except BaseException:pass
   try:
    for kind,tag in [('restore','restore'),('primary','main')]:
     path=Path(self.plan['backends'][backend][kind]['site'])
     if path.exists():
      V.require(path.is_dir()and not path.is_symlink()and path.parent==self.work and path.name==f'customer-policy-{self.token}-{backend}-{tag}','Owned site cleanup route differs');mu=path/'wp-content/mu-plugins/cetech-r24-fixture.php'
      if mu.exists():V.require(not mu.is_symlink()and mu.read_bytes()==self.fixture.read_bytes(),'Owned MU changed');mu.unlink()
      shutil.rmtree(path)
    mu_removed=all(not(Path(self.plan['backends'][backend][k]['site'])/'wp-content/mu-plugins/cetech-r24-fixture.php').exists()for k in('primary','restore'));sites_removed=all(not Path(self.plan['backends'][backend][k]['site']).exists()for k in('primary','restore'))
    self.infrastructure('drop',backend,directory/'drop.json');drop=V.regular_private(directory/'drop.json');database_removed=drop.get('removed')is True
   except BaseException:failed=failed or'CleanupRefused';report['status']='FAIL'
   self.backend_cleanup[backend]={'stopped':stopped,'mu_removed':mu_removed,'sites_removed':sites_removed,'database_removed':database_removed}
   cleanup={'all_declared_owned_fixtures_removed':database_removed and sites_removed,'all_protected_native_c01_config_and_options_restored':database_removed,'no_untracked_row_or_prefix_deletion':database_removed,'restore_derivative_removed':database_removed and sites_removed};V.check_case(report,'R24-TRACKED-PHYSICAL-CLEANUP',cleanup)
   V.check_case(report,'R24-OWNED-LISTENER-AND-PRIVATE-CLOSEOUT',{'listeners_stopped_and_waited':stopped,'exact_owned_mu_removed':mu_removed,'private_material_removed':False,'cleanup_acknowledged':database_removed and sites_removed})
   self.reports[backend]=report
  if failed:print('customer_policy_native_backend='+backend+' result=FAIL phase='+phase+' error='+(failed if failed in('ValueError','TimeoutExpired','OSError','KeyError','TypeError')else'UnknownError'),flush=True)
 def execute(self):
  self.reports={};self.backend_cleanup={}
  try:
   with ThreadPoolExecutor(max_workers=2)as pool:list(pool.map(self.backend,V.BACKENDS))
   self.infrastructure('baseline','',self.private/'after.json');after=V.regular_private(self.private/'after.json');database_exact=after==self.baseline;files_exact=file_census(self.site)==self.baseline_files
  finally:
   checks=self.backend_cleanup;private_removed=False
   resources_closed=len(checks)==2 and all(all(x[k]for k in('stopped','mu_removed','sites_removed','database_removed'))for x in checks.values())
   if resources_closed and self.private.is_dir()and not self.private.is_symlink()and self.private.parent==self.work and self.private.name=='customer-policy-private-'+self.token:shutil.rmtree(self.private);private_removed=not self.private.exists()
   for backend,report in self.reports.items():
    cleanup=report['cases'][-2]['evidence'];cleanup['all_protected_native_c01_config_and_options_restored']=cleanup['all_declared_owned_fixtures_removed']and locals().get('database_exact',False)and locals().get('files_exact',False);V.check_case(report,'R24-TRACKED-PHYSICAL-CLEANUP',cleanup)
    last=report['cases'][-1]['evidence'];last['private_material_removed']=private_removed;V.check_case(report,'R24-OWNED-LISTENER-AND-PRIVATE-CLOSEOUT',last);report['status']='PASS'if all(row['status']=='PASS'for row in report['cases'])and all(report['tamper_variants'].values())and all(report['history_variants'].values())else'FAIL';write_json(self.work/f'customer-policy-native-{backend}-results.json',report)
   evidence={'source_database_unchanged':locals().get('database_exact',False),'source_files_unchanged':locals().get('files_exact',False),'all_owned_databases_removed':len(checks)==2 and all(x['database_removed']for x in checks.values()),'all_scoped_users_removed':len(checks)==2 and all(x['database_removed']for x in checks.values()),'all_owned_sites_removed':len(checks)==2 and all(x['sites_removed']for x in checks.values()),'all_listeners_stopped_and_waited':len(checks)==2 and all(x['stopped']for x in checks.values()),'all_private_material_removed':private_removed};closeout={'format':V.CLOSEOUT_FORMAT,**self.auth,'status':'PASS'if all(evidence.values())else'FAIL','evidence':evidence};write_json(self.work/'customer-policy-native-closeout.json',closeout)
  try:aggregate=V.aggregate(self.auth,self.reports,closeout)
  except BaseException:
   rows=[]
   for name,fields in V.CASES:
    relevant=[next((r for r in report['cases']if r['id']==name),None)for report in self.reports.values()];relevant=[r for r in relevant if r is not None]
    evidence={field:bool(relevant)and all(r['evidence'][field]is True for r in relevant)for field in fields};rows.append({'id':name,'status':'PASS'if all(evidence.values())else'FAIL','evidence':evidence})
   write_json(self.work/'customer-policy-native-results.json',{'format':V.FORMAT,**self.auth,'status':'FAIL','limits':V.LIMITS,'backends':{b:V.digest(V.canonical(r))for b,r in self.reports.items()},'closeout_sha256':V.digest(V.canonical(closeout)),'cases':rows});raise
  write_json(self.work/'customer-policy-native-results.json',aggregate);print('customer_policy_native=PASS cases=30 backends=2 cases_per_backend=26')
def main():
 p=argparse.ArgumentParser();p.add_argument('--work',type=Path,required=True);p.add_argument('--site',type=Path,required=True);p.add_argument('--package',type=Path,required=True);p.add_argument('--repo',type=Path,required=True);p.add_argument('--php',type=Path,required=True);Harness(p.parse_args()).execute()
if __name__=='__main__':
 try:main()
 except(BaseException)as error:raise SystemExit('R24 owned qualification refused: '+type(error).__name__)
