'use strict';
// Pure tests of the actual nested producer helper. No browser, HTTP or WordPress.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');

const source = fs.readFileSync(path.join(__dirname, 'customer-policy-browser.cjs'), 'utf8');
function extractFunction(name) {
 const marker = 'async function ' + name + '(';
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
