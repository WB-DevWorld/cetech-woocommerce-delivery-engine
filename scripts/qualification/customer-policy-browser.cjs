'use strict';
/** Actual finite browser journeys on the driver-declared owned CI derivative. */
const fs=require('fs'),path=require('path'),crypto=require('crypto'),cp=require('child_process');
const inputPath=path.resolve(process.argv[2]||''),output=path.resolve(process.argv[3]||'');
const PHASES=['initialize','shopper_guest','shopper_customer','pdp_simple','pdp_variation','native_add','cart_editor','cart_review','cart_quantity','checkout_classic','checkout_blocks','history','order_pay','public_read','source_race','complete'];let phase='initialize';
class ObservationError extends Error{constructor(code){super('R24 native observation refused');this.name='ObservationError';this.code=code;}}
function setPhase(value){if(!PHASES.includes(value))throw new ObservationError('observation_required');phase=value;}
function need(value,message='R24 native browser observation refused'){if(!value)throw new ObservationError(message==='R24 private native bridge refused'?'native_bridge_failed':message.startsWith('R24 native case refused: R24-')?'case_refused':'observation_required');}
function requireCode(value,code){if(!value)throw new ObservationError(code);}
function emitDiagnostic(error){try{if(lastCheckout&&['checkout_classic','checkout_blocks'].includes(phase))emitCheckoutDiagnostic(lastCheckout.before,lastCheckout.after||inspect(),lastCheckout.route);}catch{}const kind=['ObservationError','TimeoutError','TypeError','SyntaxError','Error'].includes(error?.name)?error.name:'UnknownError';const code=kind==='ObservationError'?error.code:kind==='TimeoutError'?'browser_timeout':kind==='TypeError'?'harness_type':kind==='SyntaxError'?'invalid_json':error?.code==='ENOENT'?'private_file_missing':kind==='Error'?'browser_error':'unknown_error';process.stdout.write('customer_policy_native_browser_diagnostic='+JSON.stringify({backend:input.backend,phase,error_class:kind,code})+'\n');}
/** Emit finite facts only. Native IDs and original journal bytes remain private. */
function emitCheckoutDiagnostic(before,after,route){
 const classes=['none','Exception','RuntimeException','LogicException','InvalidArgumentException','UnexpectedValueException','Error','TypeError','ParseError','ValueError','JsonException','mysqli_sql_exception','OtherThrowable'],categories=['delivery','policy','control','shipping','payment','terms','field_required','field_invalid','other'];
 const d=after.checkout_diagnostic||{},v=d.validation||{},observed=v.observed===true;
 const validation={observed,has_errors:observed&&v.has_errors===true,categories:observed?categories.filter(x=>Array.isArray(v.categories)&&v.categories.includes(x)):[],notice_errors:observed&&v.notice_errors===true};
 const checkpoints=Object.fromEntries(['before_v2_line','after_v2_line','before_policy_line','after_policy_line'].map(x=>[x,d.checkpoints?.[x]===true]));
 const stage=key=>{const s=d[key]||{},status=['entered','returned','threw'].includes(s.status)?s.status:'unobserved';return{status,error_class:status==='threw'?(classes.includes(s.error_class)&&s.error_class!=='none'?s.error_class:'OtherThrowable'):'none',cause_class:status==='threw'?(classes.includes(s.cause_class)&&s.cause_class!=='none'?s.cause_class:'OtherThrowable'):'none'};};
 const created=key=>{const a=before.native_physical_census?.[key],b=after.native_physical_census?.[key];if(!Array.isArray(a)||!Array.isArray(b)||a.some(x=>!Number.isInteger(x)||x<1)||b.some(x=>!Number.isInteger(x)||x<1))return'unknown';const old=new Set(a);return b.some(x=>!old.has(x))?'positive':'zero';};
 const delta=key=>{const a=before[key],b=after[key];if(!Array.isArray(a)||!Array.isArray(b)||b.length<a.length||JSON.stringify(b.slice(0,a.length))!==JSON.stringify(a))return'unknown';const count=b.length-a.length;return count===0?'zero':count===1?'one':'multiple';};
 const snapshot=lastCheckout||{},operation=['click','final_response','final_body','classification','inspect_after','native_cart','headers'].includes(snapshot.operation)?snapshot.operation:'unobserved',response_seen=snapshot.response_seen===true,http_class=response_seen&&['2xx','3xx','4xx','5xx','other'].includes(snapshot.http_class)?snapshot.http_class:'unobserved',native_result=response_seen&&['success','failure','other'].includes(snapshot.native_result)?snapshot.native_result:'unobserved';
 let originals=after.originals.filter(row=>!before.originals.some(prior=>prior.attempt_id===row.attempt_id));if(originals.length===0)originals=after.originals.filter(row=>row.state!=='complete');const original_state=originals.length===0?'absent':originals.length>1?'multiple':['frozen','bound','complete'].includes(originals[0].state)?originals[0].state:'unknown';
 process.stdout.write('customer_policy_native_checkout_diagnostic='+JSON.stringify({backend:input.backend,route,operation,response_seen,http_class,native_result,gateway_entries:delta('gateway_entry_calls'),gateway_delegations:delta('gateway_calls'),payment_completions:delta('completion_calls'),unsaved_preflight:after.unsaved_ownership_preflight===true?'pass':'unobserved',validation,checkpoints,stage_classic:stage('stage_classic'),stage_store_post:stage('stage_store_post'),new_native_orders:created('orders'),new_native_lines:created('lines'),original_state})+'\n');
}

function loadPrivate(file){const s=fs.lstatSync(file);need(s.isFile()&&!s.isSymbolicLink()&&(s.mode&0o077)===0&&s.size<=4194304);return JSON.parse(fs.readFileSync(file,'utf8'));}
const input=loadPrivate(inputPath);need(input.format==='cetech-r24-browser-input-v1'&&['hpos','cpt'].includes(input.backend)&&process.env.GITHUB_ACTIONS==='true');
need(/^http:\/\/127\.0\.0\.1:808[67]$/.test(input.url)&&path.dirname(inputPath)===path.resolve(input.private)&&path.dirname(output)===path.resolve(input.private));
const prepared=input.prepared,families=['return_policy_v1','refund_policy_v1'],fieldNames=['customer_title','summary','full_text','exceptions'];
const report={format:'cetech-r24-browser-observations-v1',cases:{},tamper_variants:Object.fromEntries(['mac','session','cart_key','product','variation_parent','quantity','offer','context','purpose','review_generation','replayed_round_trip'].map(x=>[x,false])),history_variants:Object.fromEntries(['missing','duplicate','null'].flatMap(x=>['packet','format','line_key','manifest','manifest_format'].map(y=>[x+'_'+y,false])))};
let sequence=0,browser,feeCouponEvidence=false,lastCheckout=null;const contexts=[],historyIds=[],positive=[];
function write(){const temporary=output+'.tmp';fs.writeFileSync(temporary,JSON.stringify(report)+'\n',{mode:0o600});fs.chmodSync(temporary,0o600);fs.renameSync(temporary,output);}
function bridge(mode,options={}){if(mode.startsWith('history-'))setPhase('history');need(/^[a-z0-9-]+$/.test(mode));const out=path.join(input.private,'browser-bridge-'+(++sequence)+'.json');let site=input.site;if(options.restore){const p=loadPrivate(process.env.CETECH_R24_PLAN);site=p.backends[input.backend].restore.site;}const result=cp.spawnSync(input.php,[input.wpcli,'--allow-root','--path='+site,'--require='+path.join(input.repo,'scripts/qualification/admin-context.php'),'eval-file',input.fixture,'--use-include',mode,input.state,out],{env:process.env,timeout:300000,encoding:'utf8',maxBuffer:1024*1024});fs.appendFileSync(path.join(input.private,'browser-bridge.log'),(result.stdout||'')+(result.stderr||''),{mode:0o600});need(result.status===0,'R24 private native bridge refused');return loadPrivate(out);}
function inspect(){return bridge('inspect');}
function check(id,evidence){need(id.startsWith('R24-')&&evidence&&Object.values(evidence).every(x=>typeof x==='boolean')&&!Object.hasOwn(report.cases,id),'Duplicate or malformed native observation');report.cases[id]=evidence;write();need(Object.values(evidence).every(x=>x===true),'R24 native case refused: '+id);}
function orderFacts(order){const value={...order};delete value.order_pay_url;return value;}
function orderMap(value){return Object.fromEntries(Object.entries(value).map(([key,order])=>[key,orderFacts(order)]));}
function unchanged(before,after){return JSON.stringify(orderMap(before.orders))===JSON.stringify(orderMap(after.orders))&&JSON.stringify(before.gateway_calls)===JSON.stringify(after.gateway_calls)&&JSON.stringify(before.completion_calls)===JSON.stringify(after.completion_calls);}
function local(url){need(typeof url==='string'&&new URL(url,input.url).origin===input.url);return new URL(url,input.url).href;}
async function native(shopper,mode,method='GET'){const response=await shopper.context.request.fetch(local('/?cetech_r24_native='+mode),{method});const checkoutCart=mode==='cart'&&['checkout_classic','checkout_blocks'].includes(phase);if(checkoutCart)requireCode(response.ok(),'checkout_cart_read_failed');else need(response.ok());const body=await response.json();if(checkoutCart)requireCode(body.success===true&&body.data,'checkout_cart_read_failed');else need(body.success===true&&body.data);return body.data;}
async function makeShopper(principal='guest',actorKey='a'){setPhase(principal==='guest'?'shopper_guest':'shopper_customer');need(['guest','customer'].includes(principal)&&['a','b'].includes(actorKey));const context=await browser.newContext({extraHTTPHeaders:{'X-Cetech-R24-Probe':input.probe}});contexts.push(context);await context.route('**/*',route=>{const target=new URL(route.request().url());return target.origin===input.url?route.continue():route.abort();});const page=await context.newPage();page.setDefaultTimeout(15000);const shopper={context,page,principal,actorKey};if(principal==='guest'){const r=await context.request.get(local('/?cetech_r24_guest=1'));need(r.ok()&&(await r.json()).success===true);}else{const account=prepared.customers[actorKey];await page.goto(local('/wp-login.php'));await page.locator('#user_login').fill(account.login);await page.locator('#user_pass').fill(account.password);await Promise.all([page.waitForURL(u=>!u.pathname.endsWith('/wp-login.php')),page.locator('#wp-submit').click()]);need((await context.cookies()).some(c=>c.name.startsWith('wordpress_logged_in_')));}await native(shopper,'location','POST');return shopper;}
async function readFields(root){return root.locator('[data-cetech-de-policy-family]').evaluateAll(nodes=>{const out={};for(const node of nodes){const code=node.getAttribute('data-cetech-de-policy-family'),fields={};for(const name of ['customer_title','summary','full_text','exceptions']){const field=node.querySelector('[data-cetech-de-policy-field="'+name+'"]');if(field)fields[name]=field.textContent;else if(name==='exceptions')fields[name]='';}if(Object.hasOwn(out,code)&&JSON.stringify(out[code])!==JSON.stringify(fields))throw new Error('Conflicting policy fields');out[code]=fields;}return out;});}
async function productView(shopper,params={}){const {kind='simple',quantity=1,offer='a'}=params;setPhase(kind==='parent'?'pdp_variation':'pdp_simple');need(['simple','parent'].includes(kind)&&Number.isInteger(quantity)&&quantity>0&&['a','b'].includes(offer));await shopper.page.goto(local(prepared.product_urls[kind]));const form=shopper.page.locator('form.cart').first();await form.waitFor();const qty=form.locator('input.qty').first();if(await qty.count())await qty.fill(String(quantity));if(kind==='parent'){const attribute=form.locator('select[name^="attribute_"]').first();await attribute.selectOption({label:'One'});await shopper.page.waitForFunction(()=>{const input=document.querySelector('input.variation_id');return input&&Number(input.value)>0;});}const displayKey='in_warehouse:delivery:'+prepared.offers[offer];const radio=form.locator('input[name="cetech_de_delivery_option_key"]').filter({hasNot:form.locator('[disabled]')});const selected=form.locator('input[name="cetech_de_delivery_option_key"][value="'+displayKey+'"]');await selected.waitFor();await selected.check();const ticket=form.locator('input[data-cetech-de-policy-ticket][name="cetech_de_policy_tickets['+displayKey+']"]');await ticket.waitFor({state:'attached'});await shopper.page.waitForFunction(key=>{const nodes=[...document.querySelectorAll('form.cart input[data-cetech-de-policy-ticket]')];return nodes.some(n=>n.name==='cetech_de_policy_tickets['+key+']'&&n.value);},displayKey);const value=await ticket.inputValue();need(value&&value.length<=256);return{ticket:value,displayKey,fields:await readFields(form.locator('[data-cetech-de-policy-option="'+displayKey+'"]')),form};}
async function addProduct(shopper,params={}){const viewed=await productView(shopper,params);setPhase('native_add');if(Object.hasOwn(params,'ticketOverride')&&params.ticketOverride!==null){const supplied=typeof params.ticketOverride==='function'?params.ticketOverride(viewed.ticket):params.ticketOverride;need(typeof supplied==='string');await viewed.form.locator('input[data-cetech-de-policy-ticket][name="cetech_de_policy_tickets['+viewed.displayKey+']"]').evaluate((node,value)=>{node.value=value;},supplied);}const navigated=shopper.page.waitForNavigation({waitUntil:'domcontentloaded'}),posted=shopper.page.waitForResponse(r=>r.request().method()==='POST'&&r.request().isNavigationRequest()&&new URL(r.url()).origin===input.url);await viewed.form.locator('button.single_add_to_cart_button').click();const nativePost=await posted;need(nativePost.status()<400);await navigated;await shopper.page.goto(local(prepared.cart_url));const cart=await native(shopper,'cart');const rows=shopper.page.locator('.woocommerce-cart-form__cart-item');need(cart.cart_keys.length>0&&await rows.count()>0,'Native PDP add did not create cart line');const key=cart.cart_keys[cart.cart_keys.length-1];return{key,ticket:viewed.ticket,displayKey:viewed.displayKey,fields:viewed.fields};}
async function reviewCart(shopper,options={}) {
 setPhase('cart_editor');
 const {completeAddress=true,accept=true,offer}=options;
 const page=shopper.page;
 await page.goto(local(prepared.cart_url));
 if(completeAddress) {
  const editors=page.locator('.woocommerce-cart-form .cetech-de-cart-context__editor');
  need(await editors.count()===1);
  const editor=editors.first(),contextRoot=editor.locator('..');
  const button=editor.locator('button.cetech-de-cart-context__save');
  need(await button.count()===1);
  const formId=await button.getAttribute('form');
  need(typeof formId==='string'&&/^cetech-de-delivery-[a-f0-9]{16}-form$/.test(formId));
  const form=page.locator('form.cetech-de-cart-context__form[id="'+formId+'"]');
  need(await form.count()===1&&await contextRoot.getAttribute('data-cetech-de-form-id')===formId);
  const hidden=async name=>{const node=form.locator('input[name="'+name+'"]');need(await node.count()===1);return node.inputValue();};
  const submittedKey=await hidden('cetech_de_cart_item_key'),editNonce=await hidden('_wpnonce');
  need(await hidden('cetech_de_cart_context_edit')==='1'&&submittedKey&&editNonce);
  const before=await native(shopper,'cart');
  need(Array.isArray(before.cart_keys)&&before.cart_keys.includes(submittedKey));
  if((await editor.getAttribute('open'))===null)await editor.locator('summary').first().click();
  const recipient=editor.locator('details.cetech-de-cart-context__recipient');
  need(await recipient.count()===1);
  if((await recipient.getAttribute('open'))===null)await recipient.locator('summary').first().click();
  let unchanged=await contextRoot.getAttribute('data-cetech-de-address-complete')==='1'&&before.contexts?.[submittedKey]==='full';
  for(const [name,value]of Object.entries({cetech_de_address_1:'PRIVATE-R24-TEST-ADDRESS',cetech_de_first_name:'Synthetic',cetech_de_last_name:'Shopper',cetech_de_phone:'0200000000'})) {
   const node=editor.locator('[name="'+name+'"]');
   need(await node.count()===1&&await node.getAttribute('form')===formId);
   unchanged=unchanged&&await node.inputValue()===value;
   await node.fill(value);
  }
  const country=editor.locator('[name="cetech_de_matching_country"]');
  need(await country.count()===1&&await country.getAttribute('form')===formId);
  const countryValue=await country.inputValue();
  need(countryValue==='GH');
  if(offer) {
   const selector=editor.locator('select[name="cetech_de_delivery_option_key"]');
   need(await selector.count()===1&&await selector.getAttribute('form')===formId);
   const selected='in_warehouse:delivery:'+prepared.offers[offer];
   unchanged=unchanged&&await selector.inputValue()===selected;
   await selector.selectOption(selected);
  }
  if(!unchanged) {
   const target=new URL(await form.getAttribute('action'),page.url());
   need(target.origin===input.url&&(await form.getAttribute('method')).toLowerCase()==='post');
   const expected={cetech_de_cart_context_edit:'1',cetech_de_cart_item_key:submittedKey,_wpnonce:editNonce,cetech_de_matching_country:countryValue,cetech_de_address_1:'PRIVATE-R24-TEST-ADDRESS'};
   const submitted=request=>{
    if(request.method()!=='POST'||!request.isNavigationRequest()||request.url()!==target.href)return false;
    const values=new URLSearchParams(request.postData()||'');
    return Object.entries(expected).every(([name,value])=>values.getAll(name).length===1&&values.get(name)===value);
   };
   const navigated=page.waitForNavigation({waitUntil:'domcontentloaded'}),posted=page.waitForResponse(response=>submitted(response.request()));
   await button.click();
   const response=await posted;
   need(response.status()<400);
   await response.finished();await navigated;
  }
 }
 setPhase('cart_review');
 const controls=page.locator('[data-cetech-de-policy-controls]').first();
 await controls.waitFor();
 const key=await controls.getAttribute('data-cetech-de-policy-cart-key'),ticket=await controls.locator('[data-cetech-de-policy-ticket]').inputValue(),nonce=await controls.locator('[name="cetech_de_policy_nonce"]').inputValue();
 need(key&&ticket&&nonce);
 if(completeAddress) {
  const row=controls.locator('xpath=ancestor::tr[contains(concat(" ",normalize-space(@class)," ")," cart_item ")][1]');
  need(await row.count()===1);
  const context=row.locator('.cetech-de-cart-context');
  need(await context.count()===1&&await context.getAttribute('data-cetech-de-address-complete')==='1');
  const formId=await context.getAttribute('data-cetech-de-form-id');
  need(typeof formId==='string'&&/^cetech-de-delivery-[a-f0-9]{16}-form$/.test(formId));
  const owner=page.locator('form.cetech-de-cart-context__form[id="'+formId+'"] input[name="cetech_de_cart_item_key"]');
  need(await owner.count()===1&&await owner.inputValue()===key);
  const actual=await native(shopper,'cart');
  need(Array.isArray(actual.cart_keys)&&actual.cart_keys.includes(key)&&actual.contexts?.[key]==='full');
 }
 const wordingRoot=page.locator('[data-cetech-de-policy-cart-key="'+key+'"]:not([data-cetech-de-policy-controls])').filter({has:page.locator('[data-cetech-de-policy-view]')});
 need(await wordingRoot.count()===1);
 const fields=await readFields(wordingRoot),wording=JSON.stringify(fields);
 if(accept) {
  const response=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('admin-ajax.php')&&(r.request().postData()||'').includes('cetech_de_customer_policy_review'));
  await controls.locator('[data-cetech-de-policy-review]').click();
  const actual=await response,body=await actual.json();
  need(actual.ok()&&(body.data||body).accepted===true);
  await controls.waitFor({state:'detached'});
 }
 return{key,ticket,nonce,wording,fields};
}
async function setQuantity(shopper,quantity){setPhase('cart_quantity');need(Number.isInteger(quantity)&&quantity>0);await shopper.page.goto(local(prepared.cart_url));const state=await native(shopper,'cart');need(state.cart_keys.length===1,'One actual native cart line required for quantity review');const key=state.cart_keys[0],form=shopper.page.locator('.woocommerce-cart-form'),oldForm=await form.elementHandle();need(oldForm!==null);const qty=form.locator('input.qty').first();await qty.fill(String(quantity));await qty.dispatchEvent('change');const observed=shopper.page.waitForResponse(response=>{const request=response.request();if(request.method()!=='POST'||new URL(request.url()).origin!==input.url||new URL(request.url()).pathname!==new URL(prepared.cart_url).pathname)return false;const body=new URLSearchParams(request.postData()||'');return body.has('update_cart')&&body.get('cart['+key+'][qty]')===String(quantity);});await form.locator('button[name="update_cart"]').click();const response=await observed;need(response.status()>=200&&response.status()<400,'Actual native quantity update was not acknowledged');if(response.status()>=300){const target=await response.headerValue('location');need(typeof target==='string'&&new URL(target,input.url).origin===input.url,'Native quantity redirect left the owned listener');}await shopper.page.waitForFunction(node=>!node.isConnected,oldForm);need(await shopper.page.locator('.woocommerce-cart-form input.qty').first().inputValue()===String(quantity),'Actual updated cart DOM quantity differs');const actual=await native(shopper,'cart');need(actual.cart_keys.length===1&&actual.cart_keys[0]===key&&actual.cart_count===quantity);return actual;}
function finalSelection(request,route){if(request.method()!=='POST')return null;const url=new URL(request.url());if(url.origin!==input.url)return null;if(route==='classic')return url.searchParams.get('wc-ajax')==='checkout'?{transport:'direct',index:0,count:1}:null;const native=leaf=>{const expected=new URL(prepared.store_checkout_url);if(expected.origin!==input.url)return false;if(expected.searchParams.has('rest_route'))return url.pathname===expected.pathname&&url.searchParams.get('rest_route')===leaf;const suffix='/wc/store/v1/checkout';return expected.pathname.endsWith(suffix)&&!url.searchParams.has('rest_route')&&url.pathname===expected.pathname.slice(0,-suffix.length)+leaf;};if(native('/wc/store/v1/checkout'))return{transport:'direct',index:0,count:1};if(!native('/wc/store/v1/batch'))return null;let parsed;try{parsed=JSON.parse(request.postData()||'');}catch{return null;}if(!parsed||Object.keys(parsed).join(',')!=='requests'||!Array.isArray(parsed.requests)||parsed.requests.length<1||parsed.requests.length>25)return null;const matches=[];for(let index=0;index<parsed.requests.length;index++){const row=parsed.requests[index];if(!row||typeof row.path!=='string'||typeof row.method!=='string')return null;const target=new URL(row.path,input.url);if(target.origin!==input.url)return null;if(target.pathname==='/wc/store/v1/checkout'&&row.method==='POST')matches.push(index);}return matches.length===1?{transport:'batch',index:matches[0],count:parsed.requests.length}:null;}
function isFinal(request,route){return finalSelection(request,route)!==null;}
async function checkout(shopper,route='classic',options={}){setPhase(route==='classic'?'checkout_classic':'checkout_blocks');need(['classic','blocks'].includes(route));bridge('checkout-'+route);await shopper.page.goto(local(prepared[route+'_url']));const page=shopper.page;const values={billing_first_name:'Synthetic',billing_last_name:'Shopper',billing_address_1:'PRIVATE-R24-TEST-ADDRESS',billing_city:'Accra',billing_postcode:'00001',billing_phone:'0200000000',billing_email:'r24-shopper@example.invalid',email:'r24-shopper@example.invalid','billing-first_name':'Synthetic','billing-last_name':'Shopper','billing-address_1':'PRIVATE-R24-TEST-ADDRESS','billing-city':'Accra','billing-postcode':'00001','billing-phone':'0200000000'};for(const [id,value]of Object.entries(values)){const field=page.locator('[id="'+id+'"]');if(await field.count()&&await field.isVisible())await field.fill(value);}for(const id of ['billing_country','billing-country']){const field=page.locator('[id="'+id+'"]');if(await field.count()&&await field.isVisible())await field.selectOption('GH');}for(const id of ['billing_state','billing-state']){const field=page.locator('[id="'+id+'"]');if(await field.count()&&await field.isVisible())await field.selectOption('AA');}const gateway=page.locator('input[value="cetech_r24_local"]');if(await gateway.count()&&await gateway.isVisible())await gateway.check();const terms=page.locator('#terms');if(await terms.count()&&await terms.isVisible())await terms.check();const button=page.locator(route==='classic'?'#place_order':'.wc-block-components-checkout-place-order-button').first();await button.waitFor();await page.waitForFunction(selector=>{const b=document.querySelector(selector);return b&&!b.disabled&&b.getAttribute('aria-disabled')!=='true';},route==='classic'?'#place_order':'.wc-block-components-checkout-place-order-button');const before=inspect();
 lastCheckout={before,after:null,route,operation:'click',response_seen:false,http_class:'unobserved',native_result:'unobserved'};
 // Read the matched response while the original native click/navigation is still pending.
 // Detached outcomes are always consumed; only the awaited native boundary can throw them.
 async function observeFinalResponse(button,pendingResponse,observation) {
  const record=(key,value)=>{try{observation[key]=value;}catch{}};
  const received=Promise.resolve(pendingResponse).then(response=>{
   record('response_seen',true);
   const status=response.status();
   record('http_class',status>=200&&status<300?'2xx':status>=300&&status<400?'3xx':status>=400&&status<500?'4xx':status>=500&&status<600?'5xx':'other');
   const body=Promise.resolve().then(()=>response.json()).then(value=>({ok:true,value}),error=>({ok:false,error}));
   return{ok:true,response,body};
  }).catch(error=>({ok:false,error}));
  record('operation','click');
  await button.click();
  record('operation','final_response');
  const response=await received;
  if(!response.ok)throw response.error;
  record('operation','final_body');
  const body=await response.body;
  if(!body.ok)throw body.error;
  return{response:response.response,body:body.value};
 }
 const pending=page.waitForResponse(r=>isFinal(r.request(),route),{timeout:30000});
 const observed=await observeFinalResponse(button,pending,lastCheckout),response=observed.response;
 let body=observed.body;
 lastCheckout.operation='classification';
 const request=response.request(),selection=finalSelection(request,route);need(selection!==null);
 let status=response.status();
 if(selection.transport==='batch'){
  need(status===207&&body&&Object.keys(body).join(',')==='responses'&&Array.isArray(body.responses)&&body.responses.length===selection.count);
  const selected=body.responses[selection.index];need(selected&&Number.isInteger(selected.status)&&selected.body&&typeof selected.body==='object');body=selected.body;status=selected.status;
 }
 lastCheckout.http_class=status>=200&&status<300?'2xx':status>=300&&status<400?'3xx':status>=400&&status<500?'4xx':status>=500&&status<600?'5xx':'other';
 lastCheckout.native_result=route==='classic'?(body&&['success','failure'].includes(body.result)?body.result:'other'):(body?.payment_result?.payment_status==='success'?'success':body?.payment_result?.payment_status==='failure'||status>=400&&status<600?'failure':'other');
 lastCheckout.operation='inspect_after';
 let after=inspect();lastCheckout.after=after;
 const added=after.gateway_calls.slice(before.gateway_calls.length),orderId=added.length===1?added[0]:null;
 lastCheckout.operation='classification';
 if(options.expectSuccess){
  requireCode(status>=200&&status<300,'checkout_http_refused');
  if(route==='classic'&&body.result==='failure'){
   const originals=after.originals.filter(row=>!before.originals.some(prior=>prior.attempt_id===row.attempt_id));
   const state=originals.length===0?'no_original':originals.length===1&&['frozen','bound','complete'].includes(originals[0].state)?originals[0].state:'unknown';
   throw new ObservationError('checkout_native_refused_'+state);
  }
  if(route==='classic')requireCode(body.result==='success','checkout_result_unrecognized');
  requireCode(after.gateway_calls.length>before.gateway_calls.length,'checkout_gateway_missing');
  requireCode(after.gateway_calls.length===before.gateway_calls.length+1&&orderId!==null,'checkout_gateway_count');
  requireCode(after.completion_calls.length===before.completion_calls.length+1,'checkout_completion_count');
  lastCheckout.operation='native_cart';
  const emptied=await native(shopper,'cart');requireCode(emptied.cart_count===0&&emptied.cart_keys.length===0,'checkout_cart_not_empty');
  lastCheckout.operation='inspect_after';after=inspect();lastCheckout.after=after;
 }
 lastCheckout.operation='headers';
 const headers=await request.allHeaders();
 return{status,body,before,after,orderId,request:{url:request.url(),method:request.method(),postData:request.postData(),headers}};
}
async function boundStoreReadRefusals(api){const shopper=await makeShopper();await addProduct(shopper);await reviewCart(shopper);bridge('interrupt');const failed=await checkout(shopper,'blocks',{expectSuccess:false});const before=inspect(),id=before.interrupted_order;need(Number.isInteger(id)&&before.originals.some(row=>row.state==='bound'&&row.binding?.order_id===id),'Actual interrupted bound Store original required');const cart=await native(shopper,'cart');need(Number(cart.store_api_draft_order)===id,'Native Store pointer does not identify the interrupted original');const upper=new URL(local(prepared.store_checkout_url));const nativePath='/wc/store/v1/checkout';if(upper.searchParams.has('rest_route')){need(upper.searchParams.get('rest_route')===nativePath);upper.searchParams.set('rest_route',nativePath.toUpperCase());}else{need(upper.pathname.endsWith(nativePath));upper.pathname=upper.pathname.slice(0,-nativePath.length)+nativePath.toUpperCase();}const replies=[];for(const disabled of [false,true]){if(disabled)bridge('off');try{for(const route of [local(prepared.store_checkout_url),upper.href]){const responseGet=await shopper.context.request.get(route,{headers:{Nonce:cart.store_nonce}});const responsePatch=await shopper.context.request.patch(route,{headers:{Nonce:cart.store_nonce},data:{billing_address:{first_name:'Denied rewrite'}}});const observed=inspect();replies.push(responseGet.status()>=400&&responsePatch.status()>=400&&unchanged(before,observed)&&JSON.stringify(before.originals)===JSON.stringify(observed.originals)&&JSON.stringify(before.indices)===JSON.stringify(observed.indices));}}finally{if(disabled)bridge('on');}}const after=inspect();const denied=replies.length===4&&replies.every(Boolean)&&unchanged(before,after);need(denied,'Attempted Store pointer GET/PATCH rewrote native history');const evidence=report.cases['R24-PUBLIC-READ-HAS-NO-WRITER-AUTHORITY'];need(evidence);evidence.get_patch_and_totals_no_history_write=evidence.get_patch_and_totals_no_history_write&&denied;write();}
async function atomicBindFaults(api){
 const frozen=record=>{const value={...record};for(const key of ['revision','state','binding','completion','order_index_row_id'])delete value[key];return JSON.stringify(value);};
 const partialOwned=(proof,attempt)=>proof.attempted===true&&proof.saved_physical_payment_scope_refused===true&&proof.unpaid===true&&proof.history_unchanged===true&&Array.isArray(proof.attempt_rows)&&proof.attempt_rows.length===1&&proof.attempt_rows[0].meta_key==='_cetech_de_policy_attempt'&&proof.attempt_rows[0].meta_value===attempt;
 for(const kind of ['unsent','lost-ack']){
  const shopper=await makeShopper();await addProduct(shopper);await reviewCart(shopper);const before=inspect();bridge('fault-'+kind);
  await checkout(shopper,'classic',{expectSuccess:false});const failed=inspect(),fault=failed.sql_faults.at(-1);
  need(failed.sql_faults.length===before.sql_faults.length+1&&fault?.index_insert_observed===true&&fault.retired===true&&failed.gateway_entry_calls.length===before.gateway_entry_calls.length&&failed.gateway_calls.length===before.gateway_calls.length&&failed.completion_calls.length===before.completion_calls.length,'Atomic typed native bind did not refuse before gateway entry');
  const newOrders=Object.keys(failed.orders).filter(id=>!Object.hasOwn(before.orders,id));need(newOrders.length===1);const id=Number(newOrders[0]);
  const attempts=failed.originals.filter(r=>!before.originals.some(old=>old.attempt_id===r.attempt_id));need(attempts.length===1);const original=attempts[0];let oldPartial=null;
  if(kind==='unsent'){
   need(fault.kind==='unsent'&&fault.commit_sent===false&&fault.rollback_acknowledged===true&&failed.indices.length===before.indices.length&&original.state==='frozen'&&original.binding===null,'Proven-unsent native bind was not atomically rolled back');
   oldPartial=bridge('verify-partial-order-'+id);need(partialOwned(oldPartial,original.attempt_id),'Unbound saved order lost permanent exact-attempt ownership');
   const payBefore=inspect();bridge('source-unavailable');
   try{bridge('off');try{
    const proof=bridge('verify-partial-order-'+id);need(partialOwned(proof,original.attempt_id)&&proof.native_rows_sha256===oldPartial.native_rows_sha256);
    const pay=await require('./customer-policy-browser-history.cjs').nativePayAttempt(api,shopper,payBefore.orders[String(id)]),payAfter=inspect();
    need(pay.nativeRouteVisited===true&&pay.formUnavailable===false&&unchanged(payBefore,payAfter)&&JSON.stringify(payBefore.originals)===JSON.stringify(payAfter.originals)&&JSON.stringify(payBefore.indices)===JSON.stringify(payAfter.indices)&&JSON.stringify(payBefore.gateway_entry_calls)===JSON.stringify(payAfter.gateway_entry_calls),'Native same-owner partial order-pay was not refused without effect');
    const afterPay=bridge('verify-partial-order-'+id);need(partialOwned(afterPay,original.attempt_id)&&afterPay.native_rows_sha256===oldPartial.native_rows_sha256&&JSON.stringify(afterPay.attempt_rows)===JSON.stringify(oldPartial.attempt_rows));
   }finally{bridge('on');}}finally{bridge('source-restore');}
  }else{
   need(fault.kind==='lost_ack'&&fault.commit_sent===true&&fault.actual_commit_acknowledged===true&&failed.indices.length===before.indices.length+1&&failed.originals.some(r=>r.state==='bound'&&r.binding?.order_id===id),'Lost ACK did not retain exact atomic original and order index');
  }
  const completed=await checkout(shopper,'classic',{expectSuccess:true}),recovered=completed.after.originals.filter(r=>r.attempt_id===original.attempt_id);
  need(recovered.length===1&&recovered[0].state==='complete'&&recovered[0].binding?.order_id===completed.orderId&&frozen(recovered[0])===frozen(original)&&completed.after.originals.length===failed.originals.length&&JSON.stringify(completed.after.original_row_handles[original.attempt_id])===JSON.stringify(failed.original_row_handles[original.attempt_id]),'Native recovery changed the original row or frozen material');
  if(kind==='lost-ack')need(completed.orderId===id,'Durably bound recovery changed original order identity');
  else{
   need(completed.orderId!==id&&completed.after.indices.length===before.indices.length+1&&completed.after.gateway_calls.filter(value=>value===completed.orderId).length===1,'Marker-only unbound order was adopted instead of fresh native creation');
   const oldAfter=bridge('verify-partial-order-'+id);
   need(partialOwned(oldAfter,original.attempt_id)&&oldAfter.native_rows_sha256===oldPartial.native_rows_sha256&&JSON.stringify(oldAfter.attempt_rows)===JSON.stringify(oldPartial.attempt_rows)&&JSON.stringify(orderFacts(completed.after.orders[String(id)]))===JSON.stringify(orderFacts(failed.orders[String(id)]))&&!completed.after.gateway_entry_calls.includes(id)&&!completed.after.gateway_calls.includes(id)&&!completed.after.completion_calls.some(row=>row.order_id===id),'Old permanent partial order was rewritten or paid');
  }
  const proof=bridge('verify-order-'+completed.orderId);need(proof.physical_authoritative_metadata_exact&&proof.gateway_once_after_complete_binding&&proof.money_and_delivery_bytes_unchanged);historyIds.push(completed.orderId);positive.push(proof);
 }
 const evidence=report.cases['R24-EXACT-RETRY-AND-INTERRUPTION'];need(evidence&&evidence.no_gateway_before_confirmed_completion===true);write();
}
function exactFields(actual,expected){return JSON.stringify(actual)===JSON.stringify(expected);}
async function run(){const module=process.env.CETECH_DE_EMERGENCY_PLAYWRIGHT_MODULE;need(typeof module==='string'&&path.isAbsolute(module));need(require(path.join(module,'package.json')).version==='1.58.2','Pinned Playwright differs');const playwright=require(module);browser=await playwright.chromium.launch({headless:true,args:['--no-sandbox']});need(browser.version()==='145.0.7632.6','Pinned Chromium differs');const initial=inspect(),plan=loadPrivate(process.env.CETECH_R24_PLAN);check('R24-OWNED-FIXTURE-BASELINE',{all_targets_declared_before_effects:plan.backends[input.backend].primary.site===input.site&&plan.backends[input.backend].restore.site!==input.site,baseline_complete:initial.schema==='12'&&initial.flags_count===24&&Object.keys(initial.orders).length===0&&initial.source_counts.rule_versions>=2,adoptions_off:initial.adoption.quote_enabled===false&&initial.adoption.promise_enabled===false&&initial.adoption.promotion_unmounted===true});
 const shoppers={guest:await makeShopper('guest','a'),customer:await makeShopper('customer','a')};let publicObserved=false;
 for(const route of ['classic','blocks'])for(const principal of ['guest','customer']){const shopper=shoppers[principal];const empty=await native(shopper,'cart');need(empty.cart_count===0,'Previous completed cart owner was not released');const added=await addProduct(shopper);need(exactFields(added.fields,prepared.expected_fields));const reviewed=await reviewCart(shopper);need(exactFields(reviewed.fields,prepared.expected_fields));if(!publicObserved){const markup=await shopper.page.locator('[data-cetech-de-policy-view]').evaluateAll(nodes=>nodes.map(n=>n.innerHTML).join(''));const views=await shopper.page.locator('[data-cetech-de-policy-view]').evaluateAll(nodes=>nodes.map(n=>n.textContent).join(''));const nonceCart=await native(shopper,'cart');const apiResponse=await shopper.context.request.get(local(prepared.store_cart_url),{headers:{Nonce:nonceCart.store_nonce}});need(apiResponse.ok());const api=await apiResponse.json(),envelope=api.items[0].extensions['cetech-delivery-engine'].customer_policy;const publicKeys=Object.keys(envelope).sort().join(',')==='families,status,ticket,version'&&families.every(f=>Object.keys(envelope.families[f]).sort().join(',')==='customer_title,disposition,exceptions,full_text,summary');const text=JSON.stringify(envelope);check('R24-PUBLIC-WORDING-AND-PRIVACY',{all_four_fields_exact_and_escaped:exactFields(reviewed.fields,prepared.expected_fields)&&!/<em(?:\s|>)/i.test(markup)&&markup.includes('&lt;em&gt;'),public_allowlist_closed:publicKeys,private_names_references_and_authors_absent:prepared.private_names.every(name=>!text.includes(name)&&!views.includes(name))&&!/logical_uuid|version_uuid|author_user_id|content_hash/.test(text)});publicObserved=true;}
 const completed=await checkout(shopper,route,{expectSuccess:true});const proof=bridge('verify-order-'+completed.orderId);historyIds.push(completed.orderId);positive.push(proof);check('R24-'+input.backend.toUpperCase()+'-'+route.toUpperCase()+'-'+principal.toUpperCase()+'-ORIGINAL-CAPTURE',{actual_display_and_native_final_submit:!!added.ticket&&!!reviewed.ticket&&completed.request.method==='POST',both_policy_families_captured_completely:proof.both_policy_families_captured_completely,original_item_and_order_coordinates_verified:proof.native_product_variation_quantity_context_equal&&proof.positive_offer_equal,physical_authoritative_metadata_exact:proof.physical_authoritative_metadata_exact,gateway_once_after_complete_binding:proof.gateway_once_after_complete_binding,money_and_delivery_bytes_unchanged:proof.money_and_delivery_bytes_unchanged});}
 check('R24-EXACT-RAW-LINE-TO-NATIVE-ITEM',Object.fromEntries(['unique_original_cart_key_item_bijection','native_product_variation_quantity_context_equal','positive_offer_equal','no_product_or_quantity_fallback'].map(key=>[key,positive.every(p=>p[key]===true)])));
 const api={input,makeShopper,productView,addProduct,reviewCart,setQuantity,checkout,bridge,restoreBridge:mode=>bridge(mode,{restore:true}),inspect,check,require:need,unchanged,fields:prepared.expected_fields,historyIds,report,native,readFields,orderFacts,phase:setPhase,setFeeCouponEvidence:value=>{need(typeof value==='boolean');feeCouponEvidence=value;}};
 const extension=require('./customer-policy-browser-history.cjs');need(typeof extension.runHistoryProbes==='function');const extra=await extension.runHistoryProbes(api);if(extra?.tamper_variants)report.tamper_variants=extra.tamper_variants;if(typeof extra?.unsupported_fee_coupon==='boolean')feeCouponEvidence=extra.unsupported_fee_coupon;await boundStoreReadRefusals(api);await atomicBindFaults(api);
 const saved=bridge('history-saved');const restored=bridge('history-restore');check('R24-EXPORT-RESTORE-FRESH-PROCESS',Object.fromEntries(['new_owned_destination_and_exact_manifest','fresh_saved_reader_and_renderers_equal','raw_packet_and_delivery_digest_equal','private_database_credentials_not_reused'].map(k=>[k,restored[k]===true])));
 const variants=bridge('history-variants',{restore:true});report.history_variants=variants.variants;check('R24-PARTIAL-DUPLICATE-NULL-HISTORY',Object.fromEntries(['presence_census_detects_every_variant','incomplete_history_not_rendered_or_repaired','original_bytes_preserved','no_gateway_or_paid_rewrite'].map(k=>[k,variants[k]===true])));const reinstalled=bridge('history-reinstall',{restore:true});check('R24-SAME-PACKAGE-REINSTALL',Object.fromEntries(['package_source_exact','saved_packet_and_delivery_bytes_exact','saved_html_and_email_same','adoptions_remain_off'].map(k=>[k,reinstalled[k]===true])));bridge('history-future-seed',{restore:true});const futureReinstall=bridge('history-reinstall',{restore:true});const future=bridge('history-future-check',{restore:true});check('R24-FUTURE-FORMAT-HISTORY',{unknown_not_absence:future.unknown_not_absence===true,unsupported_customer_projection_closed:future.unsupported_customer_projection_closed===true,raw_bytes_preserved:future.raw_bytes_preserved===true,no_live_fallback_or_reinstall_rewrite:future.no_live_fallback_or_reinstall_rewrite===true&&futureReinstall.saved_packet_and_delivery_bytes_exact===true});const lifecycle=bridge('history-lifecycle',{restore:true}),unavailable=bridge('history-no-current',{restore:true});check('R24-SAVED-AFTER-SOURCE-RETIREMENT-DELETION',{saved_packet_bytes_unchanged:lifecycle.saved_packet_bytes_unchanged===true&&unavailable.saved_packet_bytes_unchanged===true,saved_wording_unchanged:lifecycle.saved_wording_unchanged===true&&unavailable.saved_wording_unchanged===true,saved_reads_succeed_with_current_catalog_tables_unavailable:unavailable.saved_reads_succeed_with_current_catalog_tables_unavailable===true&&unavailable.exact_unavailable_source_tables===3,missing_legacy_policy_not_backfilled:lifecycle.missing_legacy_policy_not_backfilled===true});check('R24-SAVED-HTML-AND-CUSTOMER-EMAIL',{saved_only_four_fields_exact:saved.fields_exact===true,html_literal_markup_escaped:saved.html_escaped===true,plain_text_complete:saved.plain_complete===true,shipment_cards_do_not_hide_policy:lifecycle.shipment_cards_do_not_hide_policy===true,foreign_order_projection_denied:saved.foreign_denied===true});
 // Native subset only; exhaustive bounds are mandatory separate unit and physical SQL prerequisites.
 const dispositions=extra?.dispositions;check('R24-DISPOSITIONS-AND-BOUNDS',{both_family_dispositions_distinct:dispositions?.both_family_dispositions_distinct===true,unresolved_assigned_never_silent_inherit:dispositions?.unresolved_assigned_never_silent_inherit===true,no_fabricated_terms_or_pickup_offer:dispositions?.no_fabricated_terms_or_pickup_offer===true,frozen_bounds_declared_and_native_scope_exact:positive.length===6&&positive.every(p=>p.physical_authoritative_metadata_exact),unsupported_fee_and_coupon_refused_before_crud:feeCouponEvidence===true});
 const final=inspect();check('R24-RETAINED-DELIVERY-AND-ADOPTION',{v2_line_and_package_bytes_unchanged:positive.every(p=>p.money_and_delivery_bytes_unchanged),retained_v3_v4_c05_prerequisite_exact_and_own_owner_exclusive:extra?.owner_exclusive===true&&loadPrivate(process.env.CETECH_R24_NATIVE).cases.length===495,quote_promise_bytes_off_and_promotion_unmounted:JSON.stringify(initial.adoption)===JSON.stringify(final.adoption),prices_totals_shipments_and_other_orders_unchanged:final.protected_native_unchanged===true&&historyIds.every(id=>JSON.stringify(orderFacts(final.orders[String(id)]))===JSON.stringify(orderFacts(positive.find(p=>p.history.order_id===id).history)))});
}
(async()=>{let failed=false;try{await run();}catch(error){failed=true;emitDiagnostic(error);fs.appendFileSync(path.join(input.private,'browser-failure.log'),String(error?.stack||'R24 native browser refused')+'\n',{mode:0o600});}finally{for(const context of contexts)try{await context.close();}catch{}if(browser)try{await browser.close();}catch{}write();}if(failed)process.exitCode=1;})();
