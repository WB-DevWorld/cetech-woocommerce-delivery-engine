#!/usr/bin/env python3
"""Strictly replay an original P06 package/report against committed Git blobs."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import shutil
import subprocess
import zipfile

SLUG='cetech-woocommerce-delivery-engine'
KEYS={'format','status','source_head','source_tree','zip_sha256','zip_bytes','committed_files','package_files','production_php_sources','production_php_sources_hash','validation_tools','checks','limits'}

def unique(pairs):
    result={}
    for key,value in pairs:
        if key in result: raise ValueError('Duplicate package JSON field')
        result[key]=value
    return result
def decode(data): return json.loads(data,object_pairs_hook=unique)
def sha(data): return hashlib.sha256(data).hexdigest()
def git(repo,*args): return subprocess.check_output(('git',*args),cwd=repo)
def require(ok,message):
    if not ok: raise ValueError(message)

def verify(package,report_path,repo,expected_ref):
    require(package.suffix=='.zip' and not package.is_symlink() and not report_path.is_symlink(),'Unknown package path')
    report=decode(report_path.read_bytes())
    require(isinstance(report,dict) and set(report)==KEYS and report['format']=='cetech-promise-qualification-package-v1' and report['status']=='PASS','Unknown package report')
    head=git(repo,'rev-parse',expected_ref+'^{commit}').decode().strip(); tree=git(repo,'rev-parse',head+'^{tree}').decode().strip()
    require(report['source_head']==head and report['source_tree']==tree,'Package source authority differs')
    require(report['zip_sha256']==sha(package.read_bytes()) and type(report['zip_bytes']) is int and report['zip_bytes']==package.stat().st_size,'Original ZIP bytes differ')
    spec=importlib.util.spec_from_file_location('package_builder',Path(__file__).with_name('build-promise-qualification-package.py')); builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
    tracked=git(repo,'ls-tree','-r','--name-only',head,'--',*builder.ITEMS).decode().splitlines()
    expected={path:sha(git(repo,'show',head+':'+path)) for path in tracked}
    require(report['committed_files']==expected,'Committed package closure differs')
    php={path:value for path,value in expected.items() if path.endswith('.php') and (path.startswith(('src/','database/')) or path in (SLUG+'.php','uninstall.php'))}
    require(report['production_php_sources']==php and report['production_php_sources_hash']==sha(json.dumps(php,separators=(',',':'),ensure_ascii=False).encode()),'Package production map differs')
    require(isinstance(report['checks'],dict) and set(report['checks'])=={'clean_committed_source','no_dev_dependencies','extracted_exact','autoload_passed','php_lint_passed'} and all(value is True for value in report['checks'].values()),'Package checks incomplete')
    tools=report['validation_tools']; require(isinstance(tools,dict) and set(tools)=={'autoload_sha256','lint_sha256','php','composer'},'Unknown package validators')
    require(tools['autoload_sha256']==sha(git(repo,'show',head+':scripts/verify-production-package-autoload.php')) and tools['lint_sha256']==sha(git(repo,'show',head+':scripts/ci-lint-php.sh')),'Package validator source differs')
    require(isinstance(tools['php'],str) and re.fullmatch(r'8\.[345]\.\d+',tools['php']) and isinstance(tools['composer'],str) and tools['composer'].startswith('Composer version 2.'),'Package runtime unknown')
    require(report['limits']==['Development qualification package only; no release/tag/deployment or target-stack acceptance.'],'Package scope differs')
    with zipfile.ZipFile(package) as archive:
        members=archive.infolist(); require(0<len(members)<=5000 and len({m.filename for m in members})==len(members),'Unknown package member inventory')
        actual={}
        for member in members:
            parts=Path(member.filename).parts
            require(len(parts)>1 and parts[0]==SLUG and '..' not in parts and not Path(member.filename).is_absolute() and not member.is_dir() and member.file_size<=32*1024*1024 and (member.external_attr>>16)&0o170000==0o100000,'Unsafe package member')
            actual['/'.join(parts[1:])]=sha(archive.read(member))
        require(actual==report['package_files'] and all(actual.get(path)==value for path,value in expected.items()),'Original package members differ')
        require(all(path in expected or path.startswith('vendor/') or path=='composer.lock' for path in actual),'Uncommitted non-Composer member')
        require(not any(path.startswith(('tests/','scripts/','.git/','node_modules/')) for path in actual),'Development files in package')
        installed=decode(archive.read(SLUG+'/vendor/composer/installed.json'))
        require(installed.get('dev') is False and not installed.get('dev-package-names'),'Development dependencies in package')
    return report

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('package',type=Path);parser.add_argument('report',type=Path);parser.add_argument('destination',type=Path);parser.add_argument('--ref',required=True);parser.add_argument('--repo',type=Path,default=Path(__file__).resolve().parents[2]);args=parser.parse_args()
    result=verify(args.package,args.report,args.repo,args.ref)
    require(not args.destination.exists() and not args.destination.is_symlink(),'Extract destination already exists')
    args.destination.mkdir(parents=True)
    with zipfile.ZipFile(args.package) as archive:archive.extractall(args.destination)
    extracted=args.destination/SLUG
    actual={path.relative_to(extracted).as_posix():sha(path.read_bytes()) for path in sorted(extracted.rglob('*')) if path.is_file()}
    require(actual==result['package_files'],'Extracted package differs')
    print(json.dumps({'status':'PASS','source_head':result['source_head'],'source_tree':result['source_tree'],'production_php_count':len(result['production_php_sources']),'zip_sha256':result['zip_sha256']}))
