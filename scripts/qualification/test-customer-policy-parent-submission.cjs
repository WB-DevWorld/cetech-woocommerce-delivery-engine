'use strict';
// Actual served helper; native WHATWG form serialization only, with no HTTP or browser.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {test}=require('node:test');
const {nativeParentSubmission}=require('./customer-policy-browser-history.cjs');
const ticketKey='cetech_de_policy_tickets[in_warehouse:delivery:4]';
const expected={'add-to-cart':'101',product_id:'101',variation_id:'102',[ticketKey]:'cp1p.'+'a'.repeat(32)+'.'+'b'.repeat(64)};
async function packet(entries=Object.entries(expected),urlencoded=false){
 const fields=urlencoded?new URLSearchParams():new FormData();for(const [key,value]of entries)fields.append(key,value);
 const request=new Request('http://127.0.0.1/owned-variable-product',{method:'POST',body:fields});
 const bytes=Buffer.from(await request.arrayBuffer()),type=request.headers.get('content-type');
 return{bytes,type,method:()=> 'POST',isNavigationRequest:()=>true,headers:()=>({'content-type':type}),postDataBuffer:()=>bytes};
}
test('served parent tamper validates the actual request with unchanged native navigation',()=>{
 const source=fs.readFileSync(path.join(__dirname,'customer-policy-browser-history.cjs'),'utf8');
 assert.equal((source.match(/await nativeParentSubmission\(actualParentPost\.request\(\)/g)||[]).length,1);
 assert.ok(source.includes('form.locator(\'button.single_add_to_cart_button\').click()'));
 assert.ok(!source.includes("new URLSearchParams(actualParentPost.request().postData()"));
});
test('actual multipart bytes retain parent, variation and original served ticket',async()=>{
 const request=await packet();assert.ok(request.type.startsWith('multipart/form-data; boundary='));
 assert.equal(new URLSearchParams(request.bytes.toString('utf8')).get('add-to-cart'),null);
 const before=Buffer.from(request.bytes);assert.equal(await nativeParentSubmission(request,expected),true);assert.deepEqual(request.bytes,before);
});
test('actual URLencoded native form remains supported without changing literal field values',async()=>{
 const request=await packet([...Object.entries(expected),['native_note','é 東京 + &='] ],true);
 assert.equal(await nativeParentSubmission(request,expected),true);
});
test('quoted native multipart boundary and canonical final CRLF are accepted',async()=>{
 const request=await packet(),boundary=request.type.split('boundary=')[1];request.headers=()=>({'content-type':'multipart/form-data; boundary="'+boundary+'"'});
 const bare=request.bytes.subarray(-2).equals(Buffer.from('\r\n'))?request.bytes.subarray(0,-2):request.bytes;
 for(const bytes of [bare,Buffer.concat([bare,Buffer.from('\r\n')])]){request.postDataBuffer=()=>bytes;assert.equal(await nativeParentSubmission(request,expected),true);}
});
for(const key of Object.keys(expected))test('duplicate actual '+key+' cannot supply authority',async()=>{
 const request=await packet([...Object.entries(expected),[key,expected[key]]]);assert.equal(await nativeParentSubmission(request,expected),false);
});
for(const key of Object.keys(expected))test('changed actual '+key+' is refused',async()=>{
 const request=await packet(Object.entries(expected).map(([name,value])=>[name,name===key?value+'-foreign':value]));assert.equal(await nativeParentSubmission(request,expected),false);
});
test('URLencoded duplicate fields are refused with the same exact multiplicity rule',async()=>{
 const request=await packet([...Object.entries(expected),['variation_id',expected.variation_id]],true);assert.equal(await nativeParentSubmission(request,expected),false);
});
test('missing native field is refused',async()=>{
 assert.equal(await nativeParentSubmission(await packet(Object.entries(expected).filter(([key])=>key!=='product_id')),expected),false);
});
test('native file parts never replace scalar form observations',async()=>{
 const file=new Blob(['PRIVATE owned file bytes'],{type:'text/plain'});
 const request=await packet([...Object.entries(expected),['unrelated_file',file]]);assert.equal(await nativeParentSubmission(request,expected),false);
});
test('non-navigation or non-POST request cannot stand in for the actual form',async()=>{
 let request=await packet();request.method=()=> 'GET';assert.equal(await nativeParentSubmission(request,expected),false);
 request=await packet();request.isNavigationRequest=()=>false;assert.equal(await nativeParentSubmission(request,expected),false);
});
test('missing, unknown, malformed and oversized Content-Type values are refused',async()=>{
 for(const type of [undefined,'text/plain','multipart/form-data','multipart/form-data; boundary=','multipart/form-data; boundary='+ 'x'.repeat(71),'application/json','application/x-www-form-urlencoded; charset=latin1','multipart/form-data; boundary=x\r\nPRIVATE','x'.repeat(1025)]){
  const request=await packet();request.headers=()=>({'content-type':type});assert.equal(await nativeParentSubmission(request,expected),false);
 }
});
test('raw byte absence, wrong type, overflow and truncated multipart are refused',async()=>{
 for(const raw of [null,'PRIVATE',Buffer.alloc(0),Buffer.alloc(262145),Buffer.from('--incomplete')]){
  const request=await packet();request.postDataBuffer=()=>raw;assert.equal(await nativeParentSubmission(request,expected),false);
 }
});
test('bounded form census and scalar values refuse excessive payloads',async()=>{
 let request=await packet([...Object.entries(expected),['oversized','x'.repeat(65537)]]);assert.equal(await nativeParentSubmission(request,expected),false);
 request=await packet([...Object.entries(expected),...Array.from({length:253},(_,index)=>['extra_'+index,'x'])]);assert.equal(await nativeParentSubmission(request,expected),false);
});
test('native getter failures and invalid expected field shape remain closed',async()=>{
 const request=await packet();request.postDataBuffer=()=>{throw new Error('PRIVATE native reader failure');};assert.equal(await nativeParentSubmission(request,expected),false);
 assert.equal(await nativeParentSubmission(await packet(),{...expected,unexpected:'PRIVATE'}),false);
});
