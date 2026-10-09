"use strict";
/** P04 actual native Blocks final button with the required recorded promise packet. */
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const args=process.argv.slice(2);
function argument(name) { const index=args.indexOf(name); if(index<0 || !args[index+1]) throw new Error('Missing native placement browser argument'); return args[index+1]; }
const statePath=path.resolve(argument('--state')); const receiptPath=path.resolve(argument('--receipt')); const base=new URL(argument('--base-url'));
if(base.protocol!=='http:' || base.hostname!=='127.0.0.1' || !base.port || base.username || base.password || base.pathname!=='/' || base.search || base.hash || fs.lstatSync(statePath).isSymbolicLink() || (fs.statSync(statePath).mode&0o077)!==0 || fs.statSync(statePath).size>262144 || fs.existsSync(receiptPath)) throw new Error('Native placement requires private state and exact owned loopback');
let state; try { state=JSON.parse(fs.readFileSync(statePath,'utf8')); } catch(_) { throw new Error('Native placement private state malformed'); }
const ids=['HTTP-W2P04-BLOCKS-PAID-ACTUAL-FINAL-BUTTON','HTTP-W2P04-BLOCKS-FREE-ACTUAL-FINAL-BUTTON'];
const report={format:'cetech-w2p04-promise-browser-v1',source_head:state.identity.source_head,candidate_head:state.identity.candidate_head,source_tree:state.identity.source_tree,installed_php_sources_hash:state.identity.installed_php_sources_hash,runtime:{playwright:'1.58.2'},status:'RUNNING',cases:[]};
let stage='ownership';
function write() { const temporary=receiptPath+'.tmp'; fs.writeFileSync(temporary,JSON.stringify(report,null,2)+'\n',{mode:0o600}); fs.renameSync(temporary,receiptPath); }
function own(value) { try { const url=new URL(value,base); return url.origin===base.origin && !url.username && !url.password && !url.hash; } catch(_) { return false; } }
function check(id,condition,evidence) { if(report.cases.some(item=>item.id===id)) throw new Error('Duplicate native placement case'); report.cases.push({id,status:condition?'PASS':'FAIL',evidence}); write(); if(!condition) throw new Error('Actual Blocks final placement diverged'); }
function nativeRouteMatches(observedValue, nativeValue, expectedRoute = '/wc/store/v1/cart/extensions') {
  try {
    const observed = new URL(observedValue, base); const native = new URL(nativeValue, base);
    if (!own(observed.href) || !own(native.href)) return false;
    const left = observed.searchParams.getAll('rest_route'); const right = native.searchParams.getAll('rest_route');
    if (left.length > 1 || right.length > 1) return false;
    const normalize = value => value.endsWith('/') ? value.slice(0, -1) : value;
    if (!['/wc/store/v1/cart/extensions','/wc/store/v1/checkout','/wc/store/v1/batch'].includes(expectedRoute)) return false;
    if (right.length === 1) return normalize(right[0]) === expectedRoute && left.length === 1 && observed.pathname === native.pathname && normalize(left[0]) === expectedRoute;
    return normalize(native.pathname).endsWith(expectedRoute) && left.length === 0 && normalize(observed.pathname) === normalize(native.pathname);
  } catch (_) { return false; }
}
function nativeBatchUrl(nativeValue) {
  try {
    const url=new URL(nativeValue,base); if (!own(url.href)) return null;
    const routes=url.searchParams.getAll('rest_route'); if (routes.length > 1) return null;
    if (routes.length === 1) { if (routes[0].replace(/\/$/,'') !== '/wc/store/v1/cart/extensions') return null; url.searchParams.set('rest_route','/wc/store/v1/batch'); }
    else { if (!url.pathname.replace(/\/$/,'').endsWith('/wc/store/v1/cart/extensions')) return null; url.pathname=url.pathname.replace(/\/cart\/extensions\/?$/,'/batch'); }
    return url.href;
  } catch (_) { return null; }
}
function nativeBatchRequests(observedValue,nativeValue,postData) {
  const batch=nativeBatchUrl(nativeValue); if (!batch || !nativeRouteMatches(observedValue,batch,'/wc/store/v1/batch') || typeof postData !== 'string' || Buffer.byteLength(postData) > 262144) return null;
  try {
    const payload=JSON.parse(postData); if (!exact(payload,['requests']) || !Array.isArray(payload.requests) || payload.requests.length < 1 || payload.requests.length > 25) return null;
    for (const item of payload.requests) {
      if (!item || typeof item !== 'object' || Array.isArray(item) || Object.keys(item).some(key => !['path','method','data','body','cache','headers'].includes(key)) || typeof item.path !== 'string' || !/^\/wc\/store\/v1\/[a-z0-9_-]+(?:\/[a-z0-9_-]+)*$/.test(item.path) || !['GET','POST','PUT','PATCH','DELETE','OPTIONS'].includes(item.method) || !item.body || typeof item.body !== 'object' || Array.isArray(item.body) || (Object.hasOwn(item,'cache') && item.cache !== 'no-store') || (Object.hasOwn(item,'data') && JSON.stringify(item.data) !== JSON.stringify(item.body)) || (Object.hasOwn(item,'headers') && (!exact(item.headers,['Nonce']) || typeof item.headers.Nonce !== 'string' || item.headers.Nonce.length > 256 || /[\u0000-\u001f\u007f]/.test(item.headers.Nonce)))) return null;
    }
    return payload.requests;
  } catch (_) { return null; }
}
function nativeReviewBody(body,action) {
  return ['refresh','confirm'].includes(action) && exact(body,['namespace','data']) && body.namespace === 'cetech-delivery-quote-review'
    && exact(body.data,action === 'refresh' ? ['action','generation','review_token'] : ['action','generation']) && body.data.action === action
    && Number.isSafeInteger(body.data.generation) && body.data.generation >= 0 && (action !== 'refresh' || (uuid(body.data.review_token) && body.data.review_token[14] === '4'));
}
function nativeReviewRequest(observedValue,nativeValue,postData,action) {
  if (typeof postData !== 'string' || Buffer.byteLength(postData) > 262144) return null;
  try {
    if (nativeRouteMatches(observedValue,nativeValue)) return nativeReviewBody(JSON.parse(postData),action) ? {transport:'direct',index:0,count:1} : null;
    const requests=nativeBatchRequests(observedValue,nativeValue,postData); if (!requests) return null;
    const matches=[]; for (let i=0;i<requests.length;i++) { if (requests[i].path === '/wc/store/v1/cart/extensions' && requests[i].method === 'POST') { if (!nativeReviewBody(requests[i].body,action)) return null; matches.push(i); } }
    return matches.length === 1 ? {transport:'batch',index:matches[0],count:requests.length} : null;
  } catch (_) { return null; }
}
function nativeReviewResponse(selection,payload,httpStatus) {
  if (!exact(selection,['transport','index','count']) || !['direct','batch'].includes(selection.transport) || !Number.isInteger(selection.index) || !Number.isInteger(selection.count) || selection.index < 0 || selection.index >= selection.count || selection.count > 25 || httpStatus !== (selection.transport === 'batch' ? 207 : 200)) return null;
  let body=payload;
  if (selection.transport === 'batch') {
    if (!exact(payload,['responses']) || !Array.isArray(payload.responses) || payload.responses.length !== selection.count) return null;
    for (const response of payload.responses) { if (!exact(response,['status','headers','body']) || !Number.isInteger(response.status) || response.status < 100 || response.status > 599 || !response.headers || typeof response.headers !== 'object' || (Array.isArray(response.headers) && response.headers.length !== 0)) return null; }
    const response=payload.responses[selection.index]; if (response.status !== 200) return null; body=response.body;
  } else if (selection.index !== 0 || selection.count !== 1) { return null; }
  const facts=body && body.extensions && body.extensions['cetech-delivery-quote-review']; return safeFacts(facts) ? {status:200,facts} : null;
}
function nativeCheckoutMethod(observedValue,method,headers={}) {
  try {
    const url=new URL(observedValue,base); if (!own(url.href) || !['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS'].includes(method) || !headers || typeof headers !== 'object' || Array.isArray(headers)) return null;
    const query=url.searchParams.getAll('_method'); if (query.length > 1 || [...url.searchParams.keys()].some(key => /[.\u0000-\u0020\u007f]/.test(key) || key.startsWith('_method['))) return null;
    const keys=Object.keys(headers).filter(key => key.toLowerCase() === 'x-http-method-override'); if (keys.length > 1) return null;
    const override=keys.length ? headers[keys[0]] : null; if (override !== null && !['PUT','PATCH','DELETE'].includes(override)) return null;
    if (query.length) return ['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS'].includes(query[0]) ? query[0] : null;
    return override || method;
  } catch (_) { return null; }
}
function nativeCheckoutPosts(observedValue,method,postData,nativeExtensions,nativeCheckout,headers={}) {
  if (nativeRouteMatches(observedValue,nativeCheckout,'/wc/store/v1/checkout')) {
    const effective=nativeCheckoutMethod(observedValue,method,headers);
    return effective === 'POST' ? 1 : ['PUT','PATCH'].includes(effective) || (method === 'GET' && effective === 'GET') ? 0 : null;
  }
  if (method !== 'POST') return 0;
  const batch=nativeBatchUrl(nativeExtensions); if (!batch || !nativeRouteMatches(observedValue,batch,'/wc/store/v1/batch')) return 0;
  if (typeof postData !== 'string' || Buffer.byteLength(postData) > 262144) return null;
  try {
    const payload=JSON.parse(postData); if (!exact(payload,['requests']) || !Array.isArray(payload.requests) || payload.requests.length < 1 || payload.requests.length > 25) return null;
    let count=0;
    for (const item of payload.requests) {
      if (!item || typeof item !== 'object' || Array.isArray(item) || typeof item.path !== 'string' || !/^\/wc\/store\/v1\/[a-z0-9_-]+(?:\/[a-z0-9_-]+)*$/.test(item.path.split('?')[0]) || !['GET','POST','PUT','PATCH','DELETE','OPTIONS'].includes(item.method)) return null;
      const route=new URL(item.path,base); if (!own(route.href) || !/^\/wc\/store\/v1\/[a-z0-9_-]+(?:\/[a-z0-9_-]+)*$/.test(route.pathname) || route.pathname === '/wc/store/v1/batch') return null;
      if (item.method === 'POST' && route.pathname === '/wc/store/v1/checkout') count++;
    }
    return count;
  } catch (_) { return null; }
}
function safeMoney(value) { return exact(value,['amount','currency','precision']) && typeof value.amount === 'string' && /^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/.test(value.amount) && typeof value.currency === 'string' && /^[A-Z]{3}$/.test(value.currency) && Number.isInteger(value.precision) && value.precision >= 0 && value.precision <= 6 && (value.amount.split('.')[1] || '').length <= value.precision; }
function exact(value,keys) { return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === keys.length && keys.every(key => Object.hasOwn(value,key)); }
function plain(value) { return typeof value === 'string' && value.length > 0 && value.length <= 120 && !/[\u0000-\u001f\u007f<>]/.test(value); }
function uuid(value) { return typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value); }
function safePromiseView(view) {
  if (!view || typeof view !== 'object' || Array.isArray(view) || !['absolute_window','relative_window','unavailable','ineligible'].includes(view.state)) return false;
  const keys=['format_version','service_label','state','display_timezone','reason_codes'];
  if (view.state==='absolute_window') keys.push('from','until');
  if (view.state==='relative_window') keys.push('relative_explanation','min','max','unit','known_zero');
  const text=value=>typeof value==='string' && value.length>0 && value.length<=160 && !/[\u0000-\u001f\u007f<>]/.test(value);
  const reasons=['estimate_unavailable','missing_source','unsupported_policy','capacity_unknown','capacity_unavailable','capacity_stale','outside_service_window','missing_destination','unsupported_anchor','budget_exceeded','unknown_timezone','source_changed','acceptance_expired'];
  if (!exact(view,keys) || view.format_version!==1 || !text(view.service_label) || typeof view.display_timezone!=='string' || view.display_timezone.length>128 || !/^[A-Za-z0-9_+\-/]+$/.test(view.display_timezone) || !Array.isArray(view.reason_codes) || view.reason_codes.length>reasons.length || view.reason_codes.some(reason=>!reasons.includes(reason)) || new Set(view.reason_codes).size!==view.reason_codes.length || JSON.stringify(view.reason_codes)!==JSON.stringify([...view.reason_codes].sort())) return false;
  try { new Intl.DateTimeFormat('en',{timeZone:view.display_timezone}); } catch (_) { return false; }
  if (['absolute_window','relative_window'].includes(view.state) !== (view.reason_codes.length===0)) return false;
  if (view.state==='absolute_window') {
    const instant=value=>{ if(typeof value!=='string' || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/.test(value)) return false; const epoch=Date.parse(value.replace(' ','T')+'Z'); return Number.isFinite(epoch) && new Date(epoch).toISOString().slice(0,19)===value.slice(0,19).replace(' ','T'); };
    if (!instant(view.from) || !instant(view.until) || view.from>view.until) return false;
  }
  if (view.state==='relative_window' && (view.relative_explanation!=='after_payment_confirmation' || !Number.isSafeInteger(view.min) || !Number.isSafeInteger(view.max) || view.min<0 || view.max<view.min || !['elapsed_minutes','calendar_days','business_minutes','business_days'].includes(view.unit) || typeof view.known_zero!=='boolean' || view.known_zero!==(view.min===0 && view.max===0))) return false;
  return true;
}
function safePromise(promise) {
  if (!exact(promise,['contract_version','original','groups']) || promise.contract_version!==1 || promise.original!==true || !Array.isArray(promise.groups) || promise.groups.length<1 || promise.groups.length>200) return false;
  return promise.groups.every(group=>exact(group,['views','customer_text']) && Array.isArray(group.views) && group.views.length>0 && group.views.length<=16 && group.views.every(safePromiseView) && typeof group.customer_text==='string' && group.customer_text.length>0 && Buffer.byteLength(group.customer_text)<=2048 && !/[\u0000-\u001f\u007f<>]/.test(group.customer_text));
}
function safeFacts(value) {
  const keys=['contract_version','status','generation','quote','can_refresh','can_confirm','can_retry','message_code','correlation_id'];
  if (!exact(value,keys) || value.contract_version !== 1 || !['no_quote','review_required','confirmed','expired','changed','unconfirmed','unavailable'].includes(value.status) || value.message_code !== value.status || !Number.isSafeInteger(value.generation) || value.generation < 0 || !uuid(value.correlation_id) || ['can_refresh','can_confirm','can_retry'].some(key => typeof value[key] !== 'boolean')) return false;
  if (value.quote !== null) {
    const quote=value.quote;
    const quoteKeys=['contract_version','decision_kind','quote_id','status','currently_applicable','expires_at','customer_label','money','reason_code','recovery_action','correlation_id'];
    if (Object.hasOwn(quote,'promise')) quoteKeys.push('promise');
    if (!exact(quote,quoteKeys) || Object.hasOwn(quote,'promise') && !safePromise(quote.promise) || quote.contract_version !== 1 || quote.decision_kind !== 'delivery_quote' || !['issued','accepted','invalidated','expired','stripped'].includes(quote.status) || typeof quote.currently_applicable !== 'boolean' || !uuid(quote.quote_id) || !uuid(quote.correlation_id) || !plain(quote.customer_label) || typeof quote.expires_at !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/.test(quote.expires_at) || ![null,'quote_expired','quote_invalidated','quote_unavailable'].includes(quote.reason_code) || ![null,'refresh_and_review','retry_later'].includes(quote.recovery_action) || !Array.isArray(quote.money) || quote.money.length > 200) return false;
    for (const part of quote.money) {
      if (!exact(part,['customer_label','list_price','promotion','final_price','tax','rounded_tax','total','display_total']) || !plain(part.customer_label) || ['list_price','final_price','tax','total'].some(key => !safeMoney(part[key])) || ['rounded_tax','display_total'].some(key => part[key] !== null && !safeMoney(part[key])) || !exact(part.promotion,['state','amount']) || !['none','applied','unavailable'].includes(part.promotion.state) || (part.promotion.state === 'unavailable' ? part.promotion.amount !== null : !safeMoney(part.promotion.amount))) return false;
    }
  }
  if (value.can_confirm && (value.status !== 'review_required' || value.quote === null || !value.quote.currently_applicable)) return false;
  const encoded=JSON.stringify(value);
  return Buffer.byteLength(encoded) <= 65536 && !['PRIVATE-','acceptance_handle','owner_digest','session_hash','principal_hash','body_digest','material_digest','origin_id','supplier','rate_card','provider_json','issue_context_json','review_token'].some(marker => encoded.includes(marker));
}

const countKeys=['orders','paid','sealed','prepared','gateway_calls','payment_complete_calls','free_completion_calls'];
function counts(value) { return exact(value,countKeys) && countKeys.every(key=>Number.isSafeInteger(value[key]) && value[key]>=0 && value[key]<=1000000); }
/** Closed private transport of the original HTTP session, never a new login. */
function nativeCookieHandoff(packet,nativeState,now=Math.floor(Date.now()/1000)) {
  try {
    const identityKeys=['source_head','candidate_head','source_tree','installed_php_sources_hash'];
    if(!exact(packet,['format','origin','identity','created_at','customer_id','logged_in_sha256','cookies']) || packet.format!=='cetech-q06-cookie-handoff-v1' || packet.origin!==base.origin || nativeState.base_url.replace(/\/$/,'')!==base.origin
      || !exact(packet.identity,identityKeys) || identityKeys.some(key=>packet.identity[key]!==nativeState.identity[key] || !(key==='installed_php_sources_hash'?/^[a-f0-9]{64}$/:/^[a-f0-9]{40}$/).test(packet.identity[key]))
      || !Number.isSafeInteger(now) || !Number.isSafeInteger(packet.created_at) || packet.created_at>now+5 || packet.created_at<now-60 || !Number.isSafeInteger(packet.customer_id) || packet.customer_id<1 || packet.customer_id!==nativeState.user_id
      || typeof packet.logged_in_sha256!=='string' || !/^[a-f0-9]{64}$/.test(packet.logged_in_sha256) || !Array.isArray(packet.cookies) || packet.cookies.length<4 || packet.cookies.length>16 || Buffer.byteLength(JSON.stringify(packet))>65536) return null;
    const seen=new Set(),hashes=new Set(),auth=[],logged=[],woo=[],result=[];
    for(const cookie of packet.cookies) {
      if(!exact(cookie,['name','value','domain','path','expires','secure','httpOnly','sameSite']) || typeof cookie.name!=='string' || typeof cookie.value!=='string' || Buffer.byteLength(cookie.value)<1 || Buffer.byteLength(cookie.value)>4096 || /[\u0000-\u001f\u007f]/.test(cookie.value)
        || cookie.domain!=='127.0.0.1' || cookie.secure!==false || typeof cookie.httpOnly!=='boolean' || ![null,'Lax','Strict'].includes(cookie.sameSite) || !Number.isSafeInteger(cookie.expires) || cookie.expires!==-1 && (cookie.expires<=now || cookie.expires>packet.created_at+366*86400)) return null;
      const match=/^(wordpress_|wordpress_logged_in_|wp_woocommerce_session_)([a-f0-9]{32})$/.exec(cookie.name); const family=match?match[1]:null;
      if(match) hashes.add(match[2]); else if(!['wordpress_test_cookie','woocommerce_cart_hash','woocommerce_items_in_cart'].includes(cookie.name)) return null;
      if(!(family==='wordpress_'?['/wp-admin','/wp-content/plugins']:['/']).includes(cookie.path)) return null;
      const key=JSON.stringify([cookie.name,cookie.domain,cookie.path]); if(seen.has(key)) return null; seen.add(key);
      if(family==='wordpress_' || family==='wordpress_logged_in_') {
        const facts=decodeURIComponent(cookie.value).split('|');
        if(facts.length!==4 || !facts[0] || !/^[1-9][0-9]{0,10}$/.test(facts[1]) || Number(facts[1])<=now || facts.slice(2).some(value=>!value || value.length>256) || !cookie.httpOnly) return null;
        (family==='wordpress_'?auth:logged).push({cookie,facts:facts.slice(0,3)});
      } else if(family==='wp_woocommerce_session_') {
        const facts=decodeURIComponent(cookie.value).split('|');
        if(facts.length!==4 || facts[0]!==String(packet.customer_id) || !/^[1-9][0-9]{0,10}$/.test(facts[1]) || Number(facts[1])<=now || !cookie.httpOnly) return null;
        woo.push(cookie);
      }
      const item={name:cookie.name,value:cookie.value,domain:cookie.domain,path:cookie.path,expires:cookie.expires,secure:cookie.secure,httpOnly:cookie.httpOnly}; if(cookie.sameSite!==null) item.sameSite=cookie.sameSite; result.push(item);
    }
    if(hashes.size!==1 || auth.length!==2 || new Set(auth.map(item=>item.cookie.path)).size!==2 || logged.length!==1 || woo.length!==1 || auth.some(item=>JSON.stringify(item.facts)!==JSON.stringify(logged[0].facts))
      || crypto.createHash('sha256').update(logged[0].cookie.value,'utf8').digest('hex')!==packet.logged_in_sha256) return null;
    return result;
  } catch(_) { return null; }
}
function nativeCookieReadback(packet,observed,nativeState,now=Math.floor(Date.now()/1000)) {
  const expected=nativeCookieHandoff(packet,nativeState,now); if(!expected || !Array.isArray(observed) || observed.length!==expected.length) return false;
  const seen=new Set();
  for(const cookie of observed) {
    if(!exact(cookie,['name','value','domain','path','expires','httpOnly','secure','sameSite'])) return false;
    const key=JSON.stringify([cookie.name,cookie.domain,cookie.path]); if(seen.has(key)) return false; seen.add(key);
    const original=expected.find(item=>item.name===cookie.name && item.domain===cookie.domain && item.path===cookie.path);
    if(!original || ['value','expires','httpOnly','secure'].some(field=>cookie[field]!==original[field]) || cookie.sameSite!==(original.sameSite || 'Lax')) return false;
  }
  return true;
}
/** Exactly one final command and its response, never a sibling batch response. */
function checkoutSelection(url,method,headers,postData) {
  if(nativeRouteMatches(url,state.checkout_url,'/wc/store/v1/checkout')) return nativeCheckoutMethod(url,method,headers)==='POST' ? {transport:'direct',index:0,count:1} : null;
  if(method!=='POST' || nativeCheckoutPosts(url,method,postData,state.store_extensions_url,state.checkout_url,headers)!==1) return null;
  let requests;
  try { const payload=JSON.parse(postData); if(!exact(payload,['requests']) || !Array.isArray(payload.requests) || payload.requests.length<1 || payload.requests.length>25) return null; requests=payload.requests; } catch(_) { return null; }
  const indexes=[];
  for(let index=0;index<requests.length;index++) {
    const item=requests[index]; if(!item || typeof item!=='object' || Array.isArray(item) || Object.keys(item).some(key=>!['path','method','data','body','cache','headers'].includes(key))) return null;
    const route=new URL(item.path,base);
    if(route.pathname==='/wc/store/v1/checkout' && item.method==='POST') {
      if(!item.body || typeof item.body!=='object' || Array.isArray(item.body) || (Object.hasOwn(item,'data') && JSON.stringify(item.data)!==JSON.stringify(item.body))) return null;
      indexes.push(index);
    }
  }
  return indexes.length===1 ? {transport:'batch',index:indexes[0],count:requests.length} : null;
}
function checkoutResponse(selection,payload,status) {
  if(!selection || !exact(selection,['transport','index','count']) || !Number.isSafeInteger(selection.index) || !Number.isSafeInteger(selection.count) || selection.index<0 || selection.index>=selection.count || selection.count>25) return null;
  if(selection.transport==='direct') return status===200 && selection.index===0 && selection.count===1 && payload && typeof payload==='object' && !Array.isArray(payload) ? payload : null;
  if(selection.transport!=='batch' || status!==207 || !exact(payload,['responses']) || !Array.isArray(payload.responses) || payload.responses.length!==selection.count) return null;
  const response=payload.responses[selection.index];
  return exact(response,['status','headers','body']) && response.status===200 && response.body && typeof response.body==='object' && !Array.isArray(response.body) ? response.body : null;
}
write();
(async()=>{
  const modulePath=process.env.CETECH_DE_EMERGENCY_PLAYWRIGHT_MODULE;
  if(!modulePath || !path.isAbsolute(modulePath) || require(path.join(modulePath,'package.json')).version!=='1.58.2') throw new Error('Pinned Playwright version unavailable');
  const {chromium}=require(modulePath); const browser=await chromium.launch({headless:true}); report.runtime.chromium=browser.version();
  if(report.runtime.chromium!=='145.0.7632.6') throw new Error('Pinned Chromium differs');
  const context=await browser.newContext({baseURL:base.origin,userAgent:'CETECH-W2P04-Promise-Qualification/1'}); context.setDefaultTimeout(20000);
  const page=await context.newPage(); const headers={'X-Cetech-Q06-Fixture':state.fixture_token,'X-Cetech-P04-Fixture':state.fixture_token};
  let requestCounts={posts:0,unknown:0};
  async function inspect() {
    const response=await context.request.get('/?cetech_p04_fixture=inspect',{headers,timeout:20000}); const result=await response.json();
    if(response.status()!==200 || result.success!==true || !counts(result.data.counts) || ['source_head','candidate_head','source_tree','installed_php_sources_hash'].some(key=>result.data.source_identity[key]!==state.identity[key])) throw new Error('Actual browser fixture identity unavailable'); return result.data;
  }
  async function fixture(mode) {
    const before=await inspect(); const response=await context.request.post('/?cetech_q06_fixture='+mode,{headers,form:{nonce:before.nonce},timeout:20000});
    if(response.status()!==200 || (await response.json()).success!==true) throw new Error('Actual native browser fixture setup refused'); return inspect();
  }
  async function review(action) {
    const observed=page.waitForResponse(response=>response.request().method()==='POST' && nativeReviewRequest(response.url(),state.store_extensions_url,response.request().postData(),action)!==null,{timeout:20000});
    await page.locator('#cetech-de-quote-review-blocks [data-quote-review-action="'+action+'"]').click();
    const response=await observed; const selection=nativeReviewRequest(response.url(),state.store_extensions_url,response.request().postData(),action);
    const returned=nativeReviewResponse(selection,await response.json(),response.status()); if(!returned) throw new Error('Actual native Blocks review response refused'); return returned.facts;
  }
  try {
    stage='login'; const originalCookies=nativeCookieHandoff(state.cookie_handoff,state);
    if(!originalCookies || Object.hasOwn(state,'password') || Object.hasOwn(state,'username')) throw new Error('Original private browser session handoff unavailable');
    await context.addCookies(originalCookies);
    if(!nativeCookieReadback(state.cookie_handoff,await context.cookies(),state)) throw new Error('Original private browser session readback differs');
    await page.route('**/*',route=>own(route.request().url()) ? route.continue() : route.abort());
    page.on('request',request=>{
      const batch=nativeBatchUrl(state.store_extensions_url);
      if(!nativeRouteMatches(request.url(),state.checkout_url,'/wc/store/v1/checkout') && !(batch && nativeRouteMatches(request.url(),batch,'/wc/store/v1/batch'))) return;
      const count=nativeCheckoutPosts(request.url(),request.method(),request.postData(),state.store_extensions_url,state.checkout_url,request.headers());
      if(count===null) requestCounts.unknown++; else requestCounts.posts+=count;
    });
    const probe=await context.request.get('/?cetech_opening_http_probe=1',{headers:{'X-CETECH-Opening-Probe':state.probe_token},timeout:20000}); const identity=await probe.json();
    if(probe.status()!==200 || ['source_head','candidate_head','source_tree'].some(key=>identity[key]!==state.identity[key]) || identity.probe_sha256!==crypto.createHash('sha256').update(state.probe_token).digest('hex') || identity.site_path_sha256!==crypto.createHash('sha256').update(state.site_path).digest('hex') || identity.database_name_sha256!==crypto.createHash('sha256').update(state.database_name).digest('hex')) throw new Error('Owned native listener identity differs');
    for(let index=0;index<ids.length;index++) {
      const free=index===1; stage='fixture'; await fixture(free?'free':'seed'); requestCounts={posts:0,unknown:0};
      stage='render'; if(!own(state.blocks_page_url)) throw new Error('Unowned native Blocks page'); await page.goto(state.blocks_page_url,{waitUntil:'domcontentloaded'});
      const blocks=page.locator('.wc-block-checkout'); const mount=page.locator('#cetech-de-quote-review-blocks');
      await blocks.waitFor({state:'visible'}); await mount.waitFor({state:'visible'}); await mount.locator('[data-quote-review-action="refresh"]').waitFor({state:'visible'});
      // All address edits precede review. Native Store API synchronization remains in use.
      const values={'first_name':'Synthetic','last_name':'Shopper','address_1':'PRIVATE-Q04-NATIVE-FIXTURE-ADDRESS','address_2':'','city':'Accra','postcode':'00001','phone':'0200000000','email':'q06@example.invalid'};
      for(const prefix of ['shipping','billing']) { for(const [name,value] of Object.entries(values)) { const field=page.locator('#'+prefix+'-'+name); if(await field.count()===1 && await field.isVisible()) await field.fill(value); } }
      const contact=page.locator('#email'); if(await contact.count()===1 && await contact.isVisible()) await contact.fill('q06@example.invalid');
      if(!free) { const payment=page.locator('input[value="cetech_q06_local_gateway"]'); await payment.waitFor({state:'visible'}); await payment.check(); }
      stage='review'; const issued=await review('refresh'); if(issued.status!=='review_required' || !issued.can_confirm) throw new Error('Actual final Blocks review unavailable');
      await mount.locator('[data-quote-review-action="confirm"]').waitFor({state:'visible'});
      stage='confirm'; const accepted=await review('confirm'); if(accepted.status!=='confirmed' || accepted.quote.status!=='accepted') throw new Error('Actual Blocks confirmation not acknowledged');
      await mount.getByText('Delivery price confirmed for these delivery details.',{exact:true}).waitFor({state:'visible'});
      const before=await inspect(); const postsBefore=requestCounts.posts; const unknownBefore=requestCounts.unknown;
      const responseWait=page.waitForResponse(response=>checkoutSelection(response.url(),response.request().method(),response.request().headers(),response.request().postData())!==null,{timeout:20000});
      stage='submit'; const button=page.locator('.wc-block-components-checkout-place-order-button'); await button.waitFor({state:'visible'}); await button.click();
      const response=await responseWait; const selection=checkoutSelection(response.url(),response.request().method(),response.request().headers(),response.request().postData()); const result=checkoutResponse(selection,await response.json(),response.status());
      stage='observe'; const after=await inspect(); const order=after.orders[String(after.last_order_id)] || {};
      const priorOrder=before.orders[String(after.last_order_id)];
      const exactNewSeal=result && Number.isSafeInteger(result.order_id) && result.order_id===after.last_order_id && accepted.quote.quote_id===order.quote_id && before.facts.quote && before.facts.quote.quote_id===order.quote_id && (!priorOrder || priorOrder.binding_state!=='sealed' && priorOrder.paid===false) && after.counts.sealed===before.counts.sealed+1 && order.binding_state==='sealed' && order.binding_revision===3;
      const evidence={exact_confirmed_quote_and_new_seal:!!exactNewSeal,real_pinned_chromium:true,actual_blocks_final_button:true,separate_native_refresh_confirm_final_post:selection!==null,exactly_one_checkout_post:requestCounts.posts===postsBefore+1 && requestCounts.unknown===0 && unknownBefore===0,safe_native_shopper_response:safeFacts(issued) && safeFacts(accepted),actual_promise_profile_packet:order.promise_profile===true && order.outer3===true && order.marker2===true && order.required_packet_present===true,original_q06_seal_linked:order.original_seal_linked===true,same_installed_source:['source_head','candidate_head','source_tree','installed_php_sources_hash'].every(key=>after.source_identity[key]===state.identity[key])};
      if(free) { evidence.free_completion_exactly_once_after_seal=after.counts.free_completion_calls===before.counts.free_completion_calls+1 && !after.completion_before_seal && order.binding_state==='sealed'; evidence.native_order_completed=order.paid===true; }
      else { evidence.gateway_exactly_once_after_seal=after.counts.gateway_calls===before.counts.gateway_calls+1 && !after.gateway_before_seal && order.binding_state==='sealed'; evidence.native_order_paid=order.paid===true; }
      const condition=result && result.payment_result && result.payment_result.payment_status==='success' && Object.values(evidence).every(value=>value===true);
      check(ids[index],condition,evidence);
    }
    stage='complete'; report.status='PASS'; write();
  } finally { await context.close(); await browser.close(); }
})().catch(error=>{
  report.status='FAIL'; const next=ids.find(id=>!report.cases.some(item=>item.id===id));
  if(next && !report.cases.some(item=>item.status==='FAIL')) report.cases.push({id:next,status:'FAIL',evidence:{stage,error_class:error && error.constructor && error.constructor.name==='TimeoutError'?'TimeoutError':error instanceof TypeError?'TypeError':'OtherError',required_case_incomplete:true}});
  // A failed condition has no PASS-schema receipt; retain only bounded typed failure facts.
  const failed=report.cases.find(item=>item.status==='FAIL'); if(failed) failed.evidence={stage,error_class:error && error.constructor && error.constructor.name==='TimeoutError'?'TimeoutError':error instanceof TypeError?'TypeError':'OtherError',required_case_incomplete:true};
  write(); process.stderr.write('P04 browser qualification incomplete; inspect bounded receipt.\n'); process.exitCode=1;
});
