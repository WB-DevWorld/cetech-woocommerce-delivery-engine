'use strict';
// Pure tests of the actual nested producer helper. No browser, HTTP or WordPress.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');
const {EventEmitter} = require('node:events');

const source = fs.readFileSync(path.join(__dirname, 'customer-policy-browser.cjs'), 'utf8');
function extractFunction(name) {
 const marker = (source.includes('async function ' + name + '(') ? 'async ' : '') + 'function ' + name + '(';
 const start = source.indexOf(marker);
 assert.ok(start >= 0, 'Actual named producer helper is absent');
 assert.equal(source.indexOf(marker, start + marker.length), -1, 'Ambiguous producer helper');
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
 throw new Error('Unclosed actual producer helper');
}
const helperSource = extractFunction('observeFinalResponse');
const observe = vm.runInNewContext(helperSource + '; observeFinalResponse', Object.create(null));
function deferred() {let resolve, reject; const promise = new Promise((yes, no) => {resolve = yes; reject = no;}); return {promise, resolve, reject};}
function outcome(promise) {return promise.then(value => ({ok:true,value}), error => ({ok:false,error}));}
async function flush() {await new Promise(resolve => setImmediate(resolve));}
function fixture({json, status = 200, observation = {}} = {}) {
 const click = deferred(), pending = deferred(); let clicks = 0, reads = 0;
 const response = {status: () => status, json: () => {++reads; return json;}};
 const result = outcome(observe({click: () => {++clicks; return click.promise;}}, pending.promise, observation));
 return {click,pending,response,result,observation,clicks:()=>clicks,reads:()=>reads};
}

test('actual named helper is used exactly once by the served checkout producer', () => {
 assert.equal((source.match(/await\s+observeFinalResponse\(/g) || []).length, 1);
 assert.ok(source.indexOf('async function checkout(') < source.indexOf(helperSource));
 assert.equal(typeof observe, 'function');
});

test('body read starts while the one native click is still pending', async () => {
 const body = deferred(), f = fixture({json:body.promise}); let settled = false;
 f.result.then(() => {settled = true;});
 assert.equal(f.clicks(), 1); f.pending.resolve(f.response); await flush();
 assert.equal(f.reads(), 1); assert.equal(f.clicks(), 1); assert.equal(settled, false);
 const packet = {result:'failure'}; body.resolve(packet); await flush(); assert.equal(settled, false);
 f.click.resolve(); const result = await f.result;
 assert.equal(result.ok, true); assert.equal(result.value.response, f.response); assert.equal(result.value.body, packet);
});

test('successful body identity is preserved when the click settles first', async () => {
 const packet = {result:'success'}, f = fixture({json:Promise.resolve(packet)});
 f.click.resolve(); await flush(); assert.equal(f.reads(), 0);
 f.pending.resolve(f.response); const result = await f.result;
 assert.equal(result.ok, true); assert.equal(result.value.body, packet); assert.equal(result.value.response, f.response); assert.equal(f.clicks(), 1); assert.equal(f.reads(), 1);
});

test('HTTP failure and native failure bodies are returned without fabricated success', async () => {
 const packet = {result:'failure',declared:true}, f = fixture({json:Promise.resolve(packet),status:503});
 f.pending.resolve(f.response); f.click.resolve(); const result = await f.result;
 assert.equal(result.ok, true); assert.equal(result.value.body, packet); assert.equal(result.value.response.status(), 503); assert.equal(packet.result, 'failure');
});

test('original click error wins even when response and body fail first', async () => {
 const clickError = new Error('declared click failure'), bodyError = new Error('declared body failure');
 const body = deferred(), f = fixture({json:body.promise}); f.pending.resolve(f.response); await flush(); body.reject(bodyError); await flush();
 f.click.reject(clickError); const result = await f.result;
 assert.equal(result.ok, false); assert.equal(result.error, clickError); assert.equal(f.clicks(), 1); assert.equal(f.reads(), 1);
});

test('click rejection is preserved before an eventual response rejection', async () => {
 const clickError = new Error('declared click failure'), responseError = new Error('declared response failure');
 const f = fixture(); f.click.reject(clickError); const result = await f.result;
 assert.equal(result.ok, false); assert.equal(result.error, clickError); f.pending.reject(responseError); await flush(); assert.equal(f.reads(), 0); assert.equal(f.clicks(), 1);
});

test('early response rejection is handled and rethrows the original error after click', async () => {
 const responseError = new Error('declared response failure'), f = fixture();
 f.pending.reject(responseError); await flush(); f.click.resolve(); const result = await f.result;
 assert.equal(result.ok, false); assert.equal(result.error, responseError); assert.equal(f.reads(), 0); assert.equal(f.clicks(), 1);
});

test('early asynchronous body rejection is handled and preserves original error', async () => {
 const bodyError = new SyntaxError('declared body failure'), body = deferred(), f = fixture({json:body.promise});
 f.pending.resolve(f.response); await flush(); body.reject(bodyError); await flush(); f.click.resolve(); const result = await f.result;
 assert.equal(result.ok, false); assert.equal(result.error, bodyError); assert.equal(f.reads(), 1);
});

test('synchronous body reader errors are preserved with no unhandled detached rejection', async () => {
 const bodyError = new TypeError('declared synchronous body failure'), f = fixture();
 f.response.json = () => {throw bodyError;}; f.pending.resolve(f.response); await flush(); f.click.resolve(); const result = await f.result;
 assert.equal(result.ok, false); assert.equal(result.error, bodyError); await flush();
});

test('diagnostic setter failures cannot replace actual click or body outcomes', async () => {
 const observation = new Proxy({}, {set(){throw new Error('declared diagnostic failure');}}), packet = {result:'failure'};
 const f = fixture({json:Promise.resolve(packet),observation}); f.pending.resolve(f.response); await flush(); f.click.resolve(); const result = await f.result;
 assert.equal(result.ok, true); assert.equal(result.value.response, f.response); assert.equal(result.value.body, packet);
 const clickError = new Error('declared click error'), g = fixture({observation}); g.click.reject(clickError); const failed = await g.result; assert.equal(failed.ok, false); assert.equal(failed.error, clickError); g.pending.resolve(g.response); await flush();
});

const durableSource=extractFunction('createDurableFinalBody');
async function durableFixture(options={}) {
 const events=new EventEmitter(),commands=[],timers=[],frame={},observation={};let detached=0;
 const payload=deferred();
 const session=Object.assign(events,{send:async(name,args)=>{
  commands.push({name,args});
  if(name==='Page.getFrameTree')return{frameTree:{frame:{id:'main-frame'}}};
  if(name==='Network.enable'){if(options.enableError)throw options.enableError;return{};}
  if(name==='Network.getResponseBody')return payload.promise;
  throw new Error('Unexpected active CDP command');
 },detach:async()=>{++detached;if(options.detachError)throw options.detachError;}});
 const page=Object.assign(new EventEmitter(),{context:()=>({newCDPSession:async target=>{assert.equal(target,page);return session;}}),mainFrame:()=>frame});
 const sandbox={Buffer,TextDecoder,setTimeout:(fn,ms)=>{const timer={fn,ms,cleared:false};timers.push(timer);return timer;},clearTimeout:timer=>{if(timer)timer.cleared=true;}};
 const create=vm.runInNewContext(durableSource+'; createDurableFinalBody',sandbox);
 const match=request=>request.method()==='POST'&&request.url()==='http://127.0.0.1:8999/?wc-ajax=checkout';
 const observer=await create(page,match,observation);
 const raw={requestId:'123.1',frameId:'main-frame',request:{url:'http://127.0.0.1:8999/?wc-ajax=checkout',method:'POST',postData:'native=one'}};
 const nativeRequest={url:()=>raw.request.url,method:()=>raw.request.method,postData:()=>raw.request.postData,frame:()=>frame};
 const nativeResponse={request:()=>nativeRequest,url:()=>raw.request.url,status:()=>200};
 const emitRequest=value=>events.emit('Network.requestWillBeSent',value||raw);
 const emitResponse=value=>events.emit('Network.responseReceived',value||{requestId:raw.requestId,frameId:'main-frame',response:{url:raw.request.url,status:200}});
 const finish=()=>events.emit('Network.loadingFinished',{requestId:raw.requestId});
 const complete=(body='{"result":"success"}',base64Encoded=false)=>{emitRequest();emitResponse();finish();payload.resolve({body,base64Encoded});};
 return{events,page,commands,timers,observation,payload,session,observer,raw,nativeRequest,nativeResponse,emitRequest,emitResponse,finish,complete,detached:()=>detached};
}
async function refused(f,category,expectedName) {
 const result=await outcome(f.observer.read(f.nativeResponse));
 assert.equal(result.ok,false);if(expectedName)assert.equal(result.error.name,expectedName);
 assert.equal(f.observation.body_access,category);await f.observer.dispose();
 assert.equal(f.detached(),1);assert.ok(f.timers.every(x=>x.cleared));assert.equal(f.events.eventNames().length,0);assert.equal(f.page.eventNames().length,0);
}

test('served producer installs the passive observer before the unchanged one-click helper',()=>{
 assert.equal((source.match(/await\s+createDurableFinalBody\(/g)||[]).length,1);
 assert.ok(source.includes('response=>durable.read(response)'));
 assert.ok(source.includes('finally{await durable.dispose();}'));
 assert.ok(!/Fetch\.|route\(|replayXHR|Runtime\.evaluate/.test(durableSource));
});

test('durable observer pins the main page and enables bounded passive retention before any request',async()=>{
 const f=await durableFixture();assert.equal(f.observation.body_access,'ready');
 assert.deepEqual(f.commands.map(x=>x.name),['Page.getFrameTree','Network.enable']);
 assert.deepEqual(JSON.parse(JSON.stringify(f.commands[1].args)),{maxTotalBufferSize:4194304,maxResourceBufferSize:1048576,maxPostDataSize:262144,enableDurableMessages:true});
 assert.equal(f.timers.length,1);assert.equal(f.timers[0].ms,30000);await f.observer.dispose();
 assert.equal(f.detached(),1);assert.equal(f.events.eventNames().length,0);assert.equal(f.timers[0].cleared,true);
});

test('durable body read retains the exact matched response while native click remains pending',async()=>{
 const f=await durableFixture(),click=deferred(),pending=deferred();let clicks=0;
 const result=outcome(observe({click:()=>{++clicks;return click.promise;}},pending.promise,f.observation,response=>f.observer.read(response)));
 f.complete();pending.resolve(f.nativeResponse);await flush();assert.equal(clicks,1);assert.equal(f.observation.body_access,'captured');
 assert.equal(f.commands.filter(x=>x.name==='Network.getResponseBody').length,1);
 click.resolve();const observed=await result;assert.equal(observed.ok,true);assert.equal(observed.value.response,f.nativeResponse);assert.equal(JSON.stringify(observed.value.body),'{"result":"success"}');
 await f.observer.dispose();assert.equal(f.detached(),1);
});

test('foreign origin, GET, and update traffic cannot substitute for the native final POST',async()=>{
 const f=await durableFixture();
 for(const request of [{url:'https://foreign.invalid/?wc-ajax=checkout',method:'POST',postData:'native=one'},{url:f.raw.request.url,method:'GET'},{url:'http://127.0.0.1:8999/?wc-ajax=update_order_review',method:'POST',postData:'native=one'}])f.emitRequest({...f.raw,requestId:'foreign',request});
 f.complete();const observed=await f.observer.read(f.nativeResponse);assert.equal(observed.result,'success');await f.observer.dispose();
});

test('loadingFinished is required before reading the one exact CDP request body',async()=>{
 const f=await durableFixture();f.emitRequest();f.emitResponse();await flush();assert.equal(f.commands.filter(x=>x.name==='Network.getResponseBody').length,0);
 f.finish();f.payload.resolve({body:'{"result":"failure"}',base64Encoded:false});assert.equal((await f.observer.read(f.nativeResponse)).result,'failure');
 const command=f.commands.find(x=>x.name==='Network.getResponseBody');assert.deepEqual(JSON.parse(JSON.stringify(command.args)),{requestId:'123.1'});await f.observer.dispose();
});

for(const [label,mutate]of [
 ['second final POST',f=>f.emitRequest({...f.raw,requestId:'second'})],
 ['redirect reuse',f=>f.emitRequest({...f.raw,redirectResponse:{status:302},request:{url:'http://127.0.0.1:8999/destination',method:'GET'}})],
 ['same request event twice',f=>f.emitRequest()]
])test('durable observer refuses ambiguous '+label,async()=>{const f=await durableFixture();f.emitRequest();mutate(f);await refused(f,'request_ambiguous');});

for(const [label,change]of [
 ['iframe',{frameId:'foreign-frame'}],['missing postData',{request:{url:'http://127.0.0.1:8999/?wc-ajax=checkout',method:'POST'}}],
 ['oversized postData',{request:{url:'http://127.0.0.1:8999/?wc-ajax=checkout',method:'POST',postData:'x'.repeat(262145)}}],
 ['already redirected',{redirectResponse:{status:302}}]
])test('durable observer refuses '+label,async()=>{const f=await durableFixture();f.emitRequest({...f.raw,...change});await refused(f,'request_invalid');});

for(const [label,change]of [
 ['URL',f=>{f.nativeRequest.url=()=>f.raw.request.url+'&foreign=1';}],
 ['method',f=>{f.nativeRequest.method=()=>'GET';}],
 ['literal POST bytes',f=>{f.nativeRequest.postData=()=>'native=two';}],
 ['response URL',f=>{f.nativeResponse.url=()=>'http://127.0.0.1:8999/other';}],
 ['status',f=>{f.nativeResponse.status=()=>503;}],
 ['frame object',f=>{f.nativeRequest.frame=()=>({});}]
])test('durable body cannot stand in for another Playwright '+label,async()=>{const f=await durableFixture();f.complete();change(f);await refused(f,'response_mismatch');});

test('durable observer preserves native failure JSON and non-success HTTP status',async()=>{
 const f=await durableFixture();f.emitRequest();f.emitResponse({requestId:f.raw.requestId,frameId:'main-frame',response:{url:f.raw.request.url,status:503}});f.nativeResponse.status=()=>503;
 f.finish();f.payload.resolve({body:'{"result":"failure","declared":true}',base64Encoded:false});const value=await f.observer.read(f.nativeResponse);
 assert.equal(value.result,'failure');assert.equal(value.declared,true);assert.equal(f.nativeResponse.status(),503);await f.observer.dispose();
});

for(const [label,payload]of [
 ['invalid JSON',{body:'not-json',base64Encoded:false}],['empty body',{body:'',base64Encoded:false}],
 ['lone surrogate',{body:'"\ud800"',base64Encoded:false}],['invalid base64',{body:'@@==',base64Encoded:true}],
 ['noncanonical base64',{body:'Zh==',base64Encoded:true}],['invalid UTF8',{body:Buffer.from([0xff]).toString('base64'),base64Encoded:true}],
 ['wrong protocol shape',{body:'{}',base64Encoded:1}]
])test('durable observer refuses '+label,async()=>{const f=await durableFixture();f.emitRequest();f.emitResponse();f.finish();f.payload.resolve(payload);await refused(f,'body_invalid');});

test('canonical base64 body decodes UTF8 without changing the native JSON',async()=>{
 const f=await durableFixture();f.complete(Buffer.from('{"result":"success","declared":"é😀"}').toString('base64'),true);
 const value=await f.observer.read(f.nativeResponse);assert.equal(value.declared,'é😀');await f.observer.dispose();
});

for(const [label,body,base64Encoded]of [['plain','x'.repeat(1048577),false],['encoded',Buffer.alloc(1048577,120).toString('base64'),true]])test('durable observer refuses '+label+' body overflow',async()=>{
 const f=await durableFixture();f.complete(body,base64Encoded);await refused(f,'body_overflow');
});

test('loading failure, detached session, missing body, and timeout remain closed finite outcomes',async()=>{
 let f=await durableFixture();f.emitRequest();f.events.emit('Network.loadingFailed',{requestId:f.raw.requestId});await refused(f,'request_failed');
 f=await durableFixture();f.events.emit('Inspector.detached');await refused(f,'body_unavailable');
 f=await durableFixture();f.page.emit('close');await refused(f,'body_unavailable');
 f=await durableFixture();const original=new Error('declared private protocol failure');f.emitRequest();f.emitResponse();f.finish();f.payload.reject(original);const result=await outcome(f.observer.read(f.nativeResponse));assert.equal(result.error,original);assert.equal(f.observation.body_access,'body_unavailable');await f.observer.dispose();
 f=await durableFixture();f.timers[0].fn();await refused(f,'body_timeout');
});

test('durable callback and detach failures cannot replace the original click error',async()=>{
 const f=await durableFixture({detachError:new Error('declared detach failure')}),pending=deferred(),clickError=new Error('declared click failure');
 const result=await outcome(observe({click:async()=>{throw clickError;}},pending.promise,f.observation,response=>f.observer.read(response)));
 assert.equal(result.error,clickError);await f.observer.dispose();pending.reject(new Error('declared late response failure'));await flush();assert.equal(f.detached(),1);assert.equal(f.events.eventNames().length,0);
});

for(const [label,late,category]of [
 ['duplicate POST',f=>f.emitRequest({...f.raw,requestId:'late-second'}),'request_ambiguous'],
 ['native loading failure',f=>f.events.emit('Network.loadingFailed',{requestId:f.raw.requestId}),'request_failed'],
 ['observer timeout',f=>f.timers[0].fn(),'body_timeout'],
 ['target detach',f=>f.events.emit('Inspector.detached'),'body_unavailable']
])test('a body captured before the native click finishes cannot survive a late '+label,async()=>{
 const f=await durableFixture(),click=deferred(),pending=deferred();let reads=0;
 const result=outcome(observe({click:()=>click.promise},pending.promise,f.observation,response=>{++reads;return f.observer.read(response);}));
 f.complete();pending.resolve(f.nativeResponse);await flush();assert.equal(reads,1);assert.equal(f.observation.body_access,'captured');
 late(f);click.resolve();const observed=await result;
 assert.equal(observed.ok,false);assert.equal(f.observation.body_access,category);assert.equal(reads,2);assert.equal(f.commands.filter(x=>x.name==='Network.getResponseBody').length,1);await f.observer.dispose();
});

const classifyBody=vm.runInNewContext(extractFunction('classifyCheckoutBody')+'; classifyCheckoutBody',Object.create(null));
test('native response diagnostics expose only two fixed labels from exact source-known error codes',()=>{
 const known=[['cetech_de_checkout_control','checkout_control'],['cetech_de_customer_policy_review','policy_review'],['cetech_de_quote_placement_unavailable','quote_placement'],['woocommerce_rest_unknown_server_error','unknown_server'],['woocommerce_rest_checkout_process_payment_error','payment_processing'],['woocommerce_rest_payment_error','gateway_payment'],['woocommerce_rest_checkout_invalid_payment_result','invalid_payment_result'],['woocommerce_rest_checkout_payment_method_disabled','gateway_disabled'],['woocommerce_rest_checkout_missing_order','missing_order'],['rest_invalid_param','invalid_param'],['woocommerce_rest_missing_nonce','nonce'],['woocommerce_rest_invalid_nonce','nonce'],['woocommerce_rest_checkout_total_mismatch','total_mismatch']];
 for(const [code,category]of known){
  const input={code,message:'PRIVATE message nonce=secret',data:{order_id:123,private:'raw'}};
  const actual=classifyBody(input);assert.equal(JSON.stringify(actual),JSON.stringify({body_kind:'rest_error',native_error:category}));
  assert.deepEqual(Object.keys(actual).sort(),['body_kind','native_error']);assert.ok(!JSON.stringify(actual).includes('PRIVATE'));assert.equal(input.code,code);
 }
});
test('unknown, inherited, malformed and non-object native bodies cannot leak an error code or imply native payment',()=>{
 for(const code of ['PRIVATE nonce=secret','__proto__','constructor','woocommerce_rest_checkout_process_payment_error_suffix','WOOCOMMERCE_REST_UNKNOWN_SERVER_ERROR','x'.repeat(8192)])assert.equal(JSON.stringify(classifyBody({code,message:'PRIVATE'})),JSON.stringify({body_kind:'rest_error',native_error:'other'}));
 for(const value of [null,undefined,'PRIVATE',42,[]])assert.equal(JSON.stringify(classifyBody(value)),JSON.stringify({body_kind:'non_object',native_error:'unobserved'}));
 for(const value of [{},{code:123},{payment_result:null},{payment_result:[]},Object.create({code:'cetech_de_checkout_control'})])assert.equal(JSON.stringify(classifyBody(value)),JSON.stringify({body_kind:'other_object',native_error:'unobserved'}));
 assert.equal(JSON.stringify(classifyBody({payment_result:{payment_status:'success',private:'PRIVATE'}})),JSON.stringify({body_kind:'payment_result',native_error:'unobserved'}));
});
test('selected Blocks body is classified before the unchanged native HTTP refusal',()=>{
 const classify=source.indexOf("if(route==='blocks')Object.assign(lastCheckout,classifyCheckoutBody(body));");
 assert.ok(classify>source.indexOf("if(selection.transport==='batch')"));
 assert.ok(classify<source.indexOf("requireCode(status>=200&&status<300,'checkout_http_refused')"));
 assert.equal((source.match(/Object\.assign\(lastCheckout,classifyCheckoutBody\(body\)\)/g)||[]).length,1);
});
