#!/usr/bin/env python3
"""Driver transport and unchanged Node reader; no site or native acceptance."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

HERE=Path(__file__).resolve().parent
SPEC=importlib.util.spec_from_file_location('r24_driver_private_copy',HERE/'customer-policy-native-driver.py')
D=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(D)

class PrivatePrerequisite(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory()
  self.addCleanup(self.temp.cleanup)
  self.root=Path(self.temp.name)
  self.source=self.root/'original.json'
  self.raw=b'{"cases":[{"id":"owned-public-prerequisite"}]}\n'
  self.source.write_bytes(self.raw)
  self.source.chmod(0o644)
  self.private=self.root/'private'
  self.private.mkdir(mode=0o700)
  self.sha=hashlib.sha256(self.raw).hexdigest()
  self.destination=self.private/'opening-qualification-results.json'
 def copy(self,**options):
  return D.copy_private_prerequisite(self.source,self.private,self.sha,**options)
 def test_actual_unchanged_node_reader_refuses_public_and_reads_exact_private_copy(self):
  original=self.source.stat()
  copied=self.copy()
  script=r"""
const fs=require('fs'),vm=require('vm'),crypto=require('crypto');
class ObservationError extends Error{};
const source=fs.readFileSync(process.argv[1],'utf8');
const need=source.match(/^function need\(.*$/m)[0],load=source.match(/^function loadPrivate\(.*$/m)[0];
const context=vm.createContext({fs,ObservationError});vm.runInContext(need+'\n'+load,context);
let refused=false;try{context.loadPrivate(process.argv[2]);}catch(e){refused=e instanceof ObservationError;}
const value=context.loadPrivate(process.argv[3]);
process.stdout.write(JSON.stringify({refused,value,sha:crypto.createHash('sha256').update(fs.readFileSync(process.argv[3])).digest('hex')}));
"""
  result=subprocess.run(['node','-e',script,str(HERE/'customer-policy-browser.cjs'),str(self.source),str(copied)],capture_output=True,text=True,timeout=10,check=True)
  value=json.loads(result.stdout)
  self.assertTrue(value['refused'])
  self.assertEqual(json.loads(self.raw),value['value'])
  self.assertEqual(self.sha,value['sha'])
  self.assertEqual(self.raw,copied.read_bytes())
  self.assertEqual(0o600,copied.stat().st_mode&0o777)
  after=self.source.stat()
  self.assertEqual((original.st_ino,original.st_mtime_ns,original.st_ctime_ns,original.st_mode),(after.st_ino,after.st_mtime_ns,after.st_ctime_ns,after.st_mode))
  self.assertNotEqual(after.st_ino,copied.stat().st_ino)
 def test_wrong_authenticated_hash_refuses_without_copy(self):
  self.sha='0'*64
  with self.assertRaises(ValueError):self.copy()
  self.assertFalse(self.destination.exists())
 def test_source_symlink_refuses(self):
  link=self.root/'link.json'
  link.symlink_to(self.source)
  self.source=link
  with self.assertRaises(ValueError):self.copy()
 def test_nonregular_source_refuses(self):
  self.source.unlink()
  self.source.mkdir()
  with self.assertRaises(ValueError):self.copy()
 def test_oversize_source_refuses(self):
  with self.assertRaises(ValueError):self.copy(maximum=len(self.raw)-1)
 def test_empty_source_refuses(self):
  self.source.write_bytes(b'')
  self.sha=hashlib.sha256(b'').hexdigest()
  with self.assertRaises(ValueError):self.copy()
 def test_existing_destination_is_not_overwritten(self):
  self.destination.write_bytes(b'keep')
  with self.assertRaises(FileExistsError):self.copy()
  self.assertEqual(b'keep',self.destination.read_bytes())
 def test_destination_symlink_is_not_followed(self):
  self.destination.symlink_to(self.source)
  with self.assertRaises(FileExistsError):self.copy()
  self.assertTrue(self.destination.is_symlink())
  self.assertEqual(self.raw,self.source.read_bytes())
 def test_nonprivate_directory_refuses(self):
  self.private.chmod(0o755)
  with self.assertRaises(ValueError):self.copy()
 def test_private_directory_symlink_refuses(self):
  link=self.root/'private-link'
  link.symlink_to(self.private,target_is_directory=True)
  self.private=link
  with self.assertRaises(ValueError):self.copy()
 def test_invalid_bounds_and_authority_are_closed(self):
  for maximum in [0,-1,True,4194305]:
   with self.subTest(maximum=maximum):
    with self.assertRaises(ValueError):self.copy(maximum=maximum)
  for digest in ['',self.sha.upper(),None,'x'*64]:
   with self.subTest(digest=digest):
    with self.assertRaises(ValueError):D.copy_private_prerequisite(self.source,self.private,digest)
 def test_source_change_during_copy_refuses_and_cleans_only_copy(self):
  original_open=D.os.open
  changed=False
  def opening(path,flags,*args,**kwargs):
   nonlocal changed
   if Path(path)==self.destination and flags&os.O_CREAT and not changed:
    changed=True
    self.source.write_bytes(b'changed-source')
   return original_open(path,flags,*args,**kwargs)
  with patch.object(D.os,'open',side_effect=opening):
   with self.assertRaises(ValueError):self.copy()
  self.assertTrue(changed)
  self.assertFalse(self.destination.exists())
  self.assertEqual(b'changed-source',self.source.read_bytes())
 def test_copy_change_before_readback_refuses_and_cleans(self):
  original_open=D.os.open
  changed=False
  def opening(path,flags,*args,**kwargs):
   nonlocal changed
   if Path(path)==self.destination and not(flags&os.O_CREAT) and not changed:
    changed=True
    self.destination.write_bytes(b'changed-copy')
   return original_open(path,flags,*args,**kwargs)
  with patch.object(D.os,'open',side_effect=opening):
   with self.assertRaises(ValueError):self.copy()
  self.assertTrue(changed)
  self.assertFalse(self.destination.exists())
  self.assertEqual(self.raw,self.source.read_bytes())
 def test_cleanup_preserves_foreign_replacement_inode(self):
  original_open=D.os.open
  replaced=False
  held=[]
  def opening(path,flags,*args,**kwargs):
   nonlocal replaced
   if Path(path)==self.destination and not(flags&os.O_CREAT) and not replaced:
    replaced=True
    held.append(self.destination.open('rb'))
    self.destination.unlink()
    self.destination.write_bytes(b'foreign-replacement')
   return original_open(path,flags,*args,**kwargs)
  try:
   with patch.object(D.os,'open',side_effect=opening):
    with self.assertRaises(ValueError):self.copy()
   self.assertTrue(replaced)
   self.assertEqual(b'foreign-replacement',self.destination.read_bytes())
   self.assertEqual(self.raw,self.source.read_bytes())
  finally:
   for stream in held:stream.close()

if __name__=='__main__':unittest.main()
