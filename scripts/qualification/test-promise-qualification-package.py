#!/usr/bin/env python3
"""Negative controls for strict package metadata; no installed/native claims."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
spec=importlib.util.spec_from_file_location("p06_package",Path(__file__).with_name("extract-promise-qualification-package.py"));module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)

class PackageBoundary(unittest.TestCase):
    def test_duplicate_json_fields_refuse(self):
        with self.assertRaises(ValueError):module.decode('{"status":"PASS","status":"PASS"}')
    def test_boolean_number_is_not_evidence(self):
        with tempfile.TemporaryDirectory() as task:
            root=Path(task);package=root/"original.zip";package.write_bytes(b"fixture")
            report={"format":"cetech-promise-qualification-package-v1","status":"PASS","source_head":"a"*40,"source_tree":"b"*40,"zip_sha256":module.sha(b"fixture"),"zip_bytes":7,"committed_files":{},"package_files":{},"production_php_sources":{},"production_php_sources_hash":module.sha(b"{}"),"checks":{"clean_committed_source":1,"no_dev_dependencies":True,"extracted_exact":True,"autoload_passed":True,"php_lint_passed":True},"validation_tools":{},"limits":[]}
            metadata=root/"original.json";metadata.write_text(json.dumps(report))
            def git(_repo,*args):
                if args[0]=="rev-parse":return (("a"*40 if args[1].endswith("^{commit}") else "b"*40)+"\n").encode()
                return b""
            with patch.object(module,"git",side_effect=git),self.assertRaisesRegex(ValueError,"checks incomplete"):
                module.verify(package,metadata,root,"HEAD")
    def test_unknown_metadata_and_source_refuse(self):
        with tempfile.TemporaryDirectory() as task:
            root=Path(task);package=root/"original.zip";package.write_bytes(b"fixture");metadata=root/"original.json";metadata.write_text('{"status":"PASS"}')
            with self.assertRaisesRegex(ValueError,"Unknown package report"):module.verify(package,metadata,root,"HEAD")
    def test_non_zip_destination_refuse_before_build(self):
        spec=importlib.util.spec_from_file_location("p06_builder",Path(__file__).with_name("build-promise-qualification-package.py"));builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
        with self.assertRaisesRegex(ValueError,"regular .zip"):builder.build(Path.cwd(),"HEAD",Path("artifact.json"))
    def test_existing_archive_refused_before_build(self):
        spec=importlib.util.spec_from_file_location("p06_builder",Path(__file__).with_name("build-promise-qualification-package.py"));builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
        with tempfile.TemporaryDirectory() as task:
            path=Path(task)/"frozen.zip";path.write_bytes(b"original")
            with patch.object(builder,"run",side_effect=[("a"*40+"\n").encode(),("b"*40+"\n").encode(),b""]),self.assertRaisesRegex(ValueError,"immutable"):
                builder.build(Path.cwd(),"HEAD",path)
            self.assertEqual(path.read_bytes(),b"original")

if __name__=="__main__":unittest.main()
