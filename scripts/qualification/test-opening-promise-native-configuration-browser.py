#!/usr/bin/env python3
"""Adversarial pure public-schema checks, not browser parity evidence."""
import subprocess
import importlib.util
import copy
from pathlib import Path
import unittest

class PromisePublicProtocolTest(unittest.TestCase):
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
