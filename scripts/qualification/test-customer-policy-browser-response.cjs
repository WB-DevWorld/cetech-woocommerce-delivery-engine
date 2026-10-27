'use strict';
// Actual pinned Chromium capability test on one owned loopback HTTP listener.
// It exercises the served observer helpers, not WordPress or a payment provider.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, 'customer-policy-browser.cjs'), 'utf8');
function extractFunction(name) {
 const marker = 'async function ' + name + '(';
 const start = source.indexOf(marker);
 assert.ok(start >= 0, 'Actual observer helper is absent');
 assert.equal(source.indexOf(marker, start + marker.length), -1, 'Observer helper is ambiguous');
 const body = source.indexOf('{', start);
 let level = 0, quote = null, escaped = false, line = false, block = false;
 for (let i = body; i < source.length; ++i) {
  const c = source[i], next = source[i + 1];
  if (line) {if (c === '\n') line = false; continue;}
  if (block) {if (c === '*' && next === '/') {block = false; ++i;} continue;}
  if (quote !== null) {if (escaped) escaped = false; else if (c === '\\') escaped = true; else if (c === quote) quote = null; continue;}
  if (c === '/' && next === '/') {line = true; ++i; continue;}
  if (c === '/' && next === '*') {block = true; ++i; continue;}
  if (c === '"' || c === "'" || c === '`') {quote = c; continue;}
  if (c === '{') ++level;
  else if (c === '}' && --level === 0) return source.slice(start, i + 1);
 }
 throw new Error('Observer helper is unclosed');
}
const sandbox = {Buffer, TextDecoder, setTimeout, clearTimeout};
const createDurable = vm.runInNewContext(extractFunction('createDurableFinalBody') + '; createDurableFinalBody', sandbox);
const observeFinal = vm.runInNewContext(extractFunction('observeFinalResponse') + '; observeFinalResponse', sandbox);
const modulePath = process.env.CETECH_DE_EMERGENCY_PLAYWRIGHT_MODULE;
assert.ok(modulePath && path.isAbsolute(modulePath), 'Pinned CI Playwright module is required');
assert.equal(require(path.join(modulePath, 'package.json')).version, '1.58.2', 'Playwright version differs from qualification pin');
const {chromium} = require(modulePath);
const token = crypto.randomBytes(16).toString('hex');
const nativePacket = {result:'success',redirect:'/received?token=' + token,declared:'actual-loopback-response'};
let origin, browser, activeCase, activeObservation, completions = 0;
const cases = new Map();
const sockets = new Set();
const server = http.createServer(async (request, response) => {
 try {
  const url = new URL(request.url, origin);
  if (url.searchParams.get('token') !== token) {response.writeHead(404); response.end(); return;}
  if (url.pathname === '/page') {
   const mode = url.searchParams.get('mode');
   const script = `const target=${JSON.stringify('/final?token=' + token + '&mode=' + mode)};
    const mode=${JSON.stringify(mode)};
    document.querySelector('button').onclick=()=>{
     const navigate=body=>{if(body.result==='success')window.location.assign(body.redirect);};
     const xhr=()=>{const r=new XMLHttpRequest();r.open('POST',target);r.setRequestHeader('Content-Type','application/x-www-form-urlencoded');r.onload=()=>{try{navigate(JSON.parse(r.responseText));}catch{}};r.send('declared='+mode);};
     if(mode==='fetch')fetch(target,{method:'POST',body:'declared='+mode}).then(r=>r.json()).then(navigate).catch(()=>{});
     else if(mode==='duplicate'){xhr();xhr();}
     else if(mode==='noise'){fetch('/noise?token=${token}',{method:'POST',body:'foreign'}).catch(()=>{});fetch('http://localhost:'+location.port+'/final?token=${token}',{method:'POST',body:'foreign'}).catch(()=>{});xhr();}
     else xhr();
    };`;
   response.writeHead(200, {'content-type':'text/html; charset=utf-8','cache-control':'no-store'});
   response.end('<!doctype html><button type="button">Submit native request</button><script>' + script + '</script>');
   return;
  }
  if (url.pathname === '/received') {response.writeHead(200, {'content-type':'text/html'}); response.end('<!doctype html><p>Native navigation arrived</p>'); return;}
  if (url.pathname === '/noise') {response.writeHead(200, {'content-type':'application/json'}); response.end(JSON.stringify({result:'foreign'})); return;}
  if (url.pathname !== '/final' || request.method !== 'POST') {response.writeHead(404); response.end(); return;}
  let input = '';
  for await (const chunk of request) {input += chunk; if (input.length > 256) throw new Error('Loopback request bound exceeded');}
  const record = cases.get(activeCase);
  assert.ok(record, 'Unexpected native loopback request');
  ++record.requests;
  record.inputs.push(input);
  const mode = url.searchParams.get('mode');
  if (mode === 'duplicate') {
   if (record.requests === 2) record.releaseDuplicate();
   await record.duplicateBarrier;
  }
  const packet = ['failure','http_failure','malformed','overflow','duplicate'].includes(mode) ? {result:'failure',declared:'actual-loopback-response'} : nativePacket;
  const body = mode === 'malformed' ? '{"result":broken}' : mode === 'overflow' ? JSON.stringify({result:'failure',declared:'x'.repeat(4194305)}) : JSON.stringify(packet);
  // The browser receives the complete original packet before its own success navigation.
  response.writeHead(mode === 'http_failure' ? 503 : 200, {'content-type':'application/json; charset=utf-8','cache-control':'no-store','content-length':Buffer.byteLength(body)});
  response.end(body);
 } catch {
  response.destroy();
 }
});
server.on('connection', socket => {sockets.add(socket); socket.on('close', () => sockets.delete(socket));});
async function runCase(name, mode, expected) {
 activeCase = name;
 const record = {requests:0,inputs:[],clicks:0,foreignAborts:0}; cases.set(name, record);
 record.duplicateBarrier = new Promise(resolve => {record.releaseDuplicate = resolve;});
 const context = await browser.newContext();
 let observer;
 try {
  await context.route('**/*', route => {
   const target = new URL(route.request().url());
   if (target.origin === origin) return route.continue();
   ++record.foreignAborts; return route.abort();
  });
  const page = await context.newPage();
  page.setDefaultTimeout(10000);
  await page.goto(origin + '/page?token=' + token + '&mode=' + mode);
  const matches = request => request.method() === 'POST' && new URL(request.url()).origin === origin && new URL(request.url()).pathname === '/final' && new URL(request.url()).searchParams.get('token') === token;
  const observation = {};
  activeObservation = observation;
  observer = await createDurable(page, matches, observation);
  const pending = page.waitForResponse(response => matches(response.request()), {timeout:10000});
  const button = {click:async()=>{++record.clicks; await page.locator('button').click();}};
  let result, error;
  try {result = await observeFinal(button, pending, observation, response => observer.read(response));} catch (caught) {error = caught;}
  if (expected === 'success') {
   assert.equal(error, undefined, name + ' refused');
   assert.deepEqual(JSON.parse(JSON.stringify(result.body)), nativePacket);
   assert.equal(result.response.status(), 200);
   await page.waitForURL(url => url.origin === origin && url.pathname === '/received', {timeout:10000});
  } else if (expected === 'failure') {
   assert.equal(error, undefined, name + ' refused');
   assert.deepEqual(JSON.parse(JSON.stringify(result.body)), {result:'failure',declared:'actual-loopback-response'});
   assert.equal(result.response.status(), mode === 'http_failure' ? 503 : 200);
   assert.equal(new URL(page.url()).pathname, '/page');
  } else {
   assert.ok(error instanceof Error || error && typeof error.name === 'string', name + ' did not refuse');
   assert.equal(result, undefined, name + ' fabricated a packet');
   if (mode === 'malformed') {assert.equal(error.name, 'SyntaxError'); assert.equal(observation.body_access, 'body_invalid');}
   if (mode === 'duplicate') assert.equal(observation.body_access, 'request_ambiguous');
   if (mode === 'overflow') assert.ok(['body_overflow','body_unavailable'].includes(observation.body_access), 'Oversize did not reach a bounded body refusal');
  }
  if (expected !== 'refuse') assert.equal(observation.body_access, 'captured', name + ' did not capture the original body');
  assert.equal(record.clicks, 1, name + ' repeated the native click');
  assert.equal(record.requests, mode === 'duplicate' ? 2 : 1, name + ' changed native request count');
  assert.ok(record.inputs.every(input => input === 'declared=' + mode), name + ' changed the native body');
  if (mode === 'noise') assert.equal(record.foreignAborts, 1);
  ++completions;
  process.stdout.write('customer_policy_durable_browser_case=' + name + ' result=PASS\n');
 } finally {
  if (observer) await observer.dispose();
  await context.close();
 }
}
async function main() {
 await new Promise((resolve, reject) => {server.once('error', reject); server.listen(0, '127.0.0.1', resolve);});
 origin = 'http://127.0.0.1:' + server.address().port;
 browser = await chromium.launch({headless:true});
 assert.equal(browser.version(), '145.0.7632.6', 'Chromium version differs from qualification pin');
 await runCase('xhr_original_json_before_native_navigation', 'xhr', 'success');
 await runCase('fetch_original_json_before_native_navigation', 'fetch', 'success');
 await runCase('native_failure_remains_failure', 'failure', 'failure');
 await runCase('http_failure_remains_failure', 'http_failure', 'failure');
 await runCase('foreign_requests_do_not_supply_packet', 'noise', 'success');
 await runCase('malformed_original_json_refused', 'malformed', 'refuse');
 await runCase('duplicate_native_candidates_refused', 'duplicate', 'refuse');
 await runCase('oversized_original_packet_refused', 'overflow', 'refuse');
 assert.equal(completions, 8);
 process.stdout.write('customer_policy_durable_browser cases=8 result=PASS physical_wordpress=false\n');
}
const deadline = setTimeout(() => {process.stderr.write('Owned durable browser capability deadline exceeded\n'); process.exitCode = 1; for (const socket of sockets) socket.destroy(); void browser?.close(); server.close();}, 90000);
main().catch(error => {
 const names = ['xhr_original_json_before_native_navigation','fetch_original_json_before_native_navigation','native_failure_remains_failure','http_failure_remains_failure','foreign_requests_do_not_supply_packet','malformed_original_json_refused','duplicate_native_candidates_refused','oversized_original_packet_refused'];
 const operations = ['click','final_response','final_body'];
 const accesses = ['pending','ready','captured','enable_failed','request_invalid','request_ambiguous','response_mismatch','request_failed','body_unavailable','body_overflow','body_invalid','body_timeout'];
 process.stderr.write('customer_policy_durable_browser_refusal=' + JSON.stringify({case:names.includes(activeCase)?activeCase:'setup',operation:operations.includes(activeObservation?.operation)?activeObservation.operation:'unobserved',body_access:accesses.includes(activeObservation?.body_access)?activeObservation.body_access:'unobserved',error_class:['AssertionError','Error','SyntaxError','TypeError','TimeoutError'].includes(error?.name)?error.name:'OtherError'}) + '\n');
 process.exitCode = 1;
}).finally(async()=>{clearTimeout(deadline); if(browser)await browser.close(); for(const socket of sockets)socket.destroy(); await new Promise(resolve=>server.close(resolve));});
