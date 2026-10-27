'use strict';

// Real browser/native HTTP observations only. This module never creates a grant,
// accepted receipt, captured packet or native order through a test-only shortcut.
const crypto = require('node:crypto');
const TAMPER = ['mac','session','cart_key','product','variation_parent','quantity','offer','context','purpose','review_generation','replayed_round_trip'];
const REVIEW_ACTION = 'cetech_de_customer_policy_review';
const EXTENSION = 'cetech-delivery-engine';
const equal = (a,b) => JSON.stringify(a) === JSON.stringify(b);
// Native payment URLs follow Woo's currently selected checkout page. That
// navigation auxiliary is not an order write when the fixture switches routes.
const orderFacts = order => { const {order_pay_url,...facts}=order; return facts; };
const ids = value => Object.keys(value.orders || {}).map(Number).sort((a,b)=>a-b);
const failed = result => !!(result && (result.body?.result === 'failure' || result.status >= 400 || result.body?.code || result.body?.payment_result?.payment_status === 'failure'));
const flip = ticket => ticket.slice(0,-1)+(ticket.at(-1)==='a'?'b':'a');
const packetDigest = bytes => crypto.createHash('sha256').update('cetech-customer-policy-line-packet-v1:').update(bytes).digest('hex');
const manifestDigest = bytes => crypto.createHash('sha256').update('cetech-customer-policy-order-manifest-v1:').update(bytes).digest('hex');
function originalBytesEqual(order,original) {
  const binding=original?.binding;
  if(!binding||typeof order?.policy?.manifest!=='string'||manifestDigest(order.policy.manifest)!==binding.manifest_digest)return false;
  const items=order.policy.items||[];
  return items.length===binding.packet_digests.length&&binding.packet_digests.every(row=>{
    const item=items.find(value=>value.id===row.item_id);
    return item&&typeof item.packet==='string'&&packetDigest(item.packet)===row.sha256;
  });
}
function noRecomputedPolicy(before,after) {
  if(!Object.values(before.orders||{}).every(order=>after.orders[String(order.order_id)]?.policy_sha256===order.policy_sha256))return false;
  return Object.values(after.orders||{}).filter(order=>!before.orders[String(order.order_id)]).every(order=>{
    const original=(after.originals||[]).find(row=>row.binding?.order_id===order.order_id);
    return original?originalBytesEqual(order,original):!order.policy.manifest&&(order.policy.items||[]).every(item=>!item.packet&&!item.format&&!item.key);
  });
}


async function responseValue(response) {
  let body = null;
  try { body = await response.json(); } catch {}
  return {status:response.status(),body};
}
async function review(api,shopper,view,{ticket=view.ticket,key=view.key,nonce=view.nonce,method='POST',extra={}}={}) {
  api.require(typeof ticket === 'string' && typeof key === 'string','Actual served review candidate required.');
  const url = new URL('/wp-admin/admin-ajax.php',api.input.url).href;
  const data = {action:REVIEW_ACTION,cart_key:key,ticket,cetech_de_policy_nonce:nonce,...extra};
  const response = method==='GET'
    ? await shopper.context.request.get(url,{params:data})
    : method==='PATCH'
      ? await shopper.context.request.patch(url+'?action='+REVIEW_ACTION,{form:data})
      : await shopper.context.request.post(url,{form:data});
  return responseValue(response);
}
const refusedReview = value => value.status >= 400 && value.body?.success !== true;
/** Observe the actual native form bytes; never replay or reconstruct a submission. */
async function nativeParentSubmission(request,expected) {
  try {
    const keys=Object.keys(expected),native=['add-to-cart','product_id','variation_id'];
    if(keys.length!==4||!native.every(key=>Object.hasOwn(expected,key))||!keys.every(key=>typeof expected[key]==='string'&&expected[key].length>0&&expected[key].length<=512))return false;
    const ticket=keys.find(key=>!native.includes(key));
    if(!ticket||!/^cetech_de_policy_tickets\[[^\[\]\r\n]{1,256}\]$/.test(ticket)||request.method()!=='POST'||request.isNavigationRequest()!==true)return false;
    const headers=request.headers(),type=headers&&headers['content-type'],bytes=request.postDataBuffer();
    if(typeof type!=='string'||type.length>1024||/[\r\n]/.test(type)||!Buffer.isBuffer(bytes)||bytes.length===0||bytes.length>262144)return false;
    const multipart=/^multipart\/form-data[ \t]*;[ \t]*boundary=(?:"([A-Za-z0-9'()+_,./:=?-]{1,70})"|([A-Za-z0-9'()+_,./:=?-]{1,70}))[ \t]*$/i.exec(type);
    if(!multipart&&!/^application\/x-www-form-urlencoded(?:[ \t]*;[ \t]*charset=utf-8)?[ \t]*$/i.test(type))return false;
    if(multipart){
      const boundary=multipart[1]||multipart[2],first=Buffer.from('--'+boundary+'\r\n'),last=Buffer.from('\r\n--'+boundary+'--');
      const end=bytes.length>=2&&bytes.subarray(-2).equals(Buffer.from('\r\n'))?bytes.subarray(0,-2):bytes;
      if(!bytes.subarray(0,first.length).equals(first)||!end.subarray(-last.length).equals(last))return false;
    }
    const fields=await new Response(bytes,{headers:{'content-type':type}}).formData();
    let count=0;
    for(const [key,value]of fields){if(++count>256||typeof key!=='string'||key.length>512||typeof value!=='string'||Buffer.byteLength(value,'utf8')>65536)return false;}
    return keys.every(key=>{const values=fields.getAll(key);return values.length===1&&typeof values[0]==='string'&&values[0]===expected[key];});
  }catch{return false;}
}
function nativeRefundUrl(api,orderId) {
  const url=new URL(api.input.prepared.store_checkout_url);
  const checkout='/wc/store/v1/checkout',refund='/wc/v3/orders/'+orderId+'/refunds';
  api.require(Number.isInteger(orderId)&&orderId>0&&url.origin===new URL(api.input.url).origin,'Owned native REST refund route required.');
  if(url.searchParams.has('rest_route')){
    api.require(url.searchParams.get('rest_route')===checkout,'Actual native Store checkout route required.');
    url.searchParams.set('rest_route',refund);
  }else{
    api.require(url.pathname.endsWith(checkout),'Actual native REST pathname prefix required.');
    url.pathname=url.pathname.slice(0,-checkout.length)+refund;
  }
  return url.href;
}
async function cart(api,shopper) {
  const url = api.input.prepared.store_cart_url;
  api.require(typeof url==='string'&&new URL(url).origin===new URL(api.input.url).origin,'Owned native cart route required.');
  const native=await api.native(shopper,'cart');
  const response=await shopper.context.request.get(url,{headers:{Nonce:native.store_nonce}});
  api.require(response.ok(),'Actual Store cart read required.');
  const body=await response.json();
  api.require(Array.isArray(body.items),'Native cart items required.');
  return body;
}
const policy = item => item?.extensions?.[EXTENSION]?.customer_policy;
async function clearCart(api,shopper) {
  // Use native served remove links, including their actual Woo nonce.
  await shopper.page.goto(api.input.prepared.cart_url,{waitUntil:'domcontentloaded'});
  for(let remaining=8;remaining>0;--remaining) {
    const remove=shopper.page.locator('.woocommerce-cart-form .product-remove a.remove').first();
    if(!await remove.count())break;
    const before=await shopper.page.locator('.woocommerce-cart-form .product-remove a.remove').count();
    const response=shopper.page.waitForResponse(r=>r.request().method()==='GET'&&r.url().includes('remove_item='),{timeout:15000});
    await remove.click();await response;
    await shopper.page.waitForFunction(n=>document.querySelectorAll('.woocommerce-cart-form .product-remove a.remove').length<n,before,{timeout:15000});
  }
  api.require((await cart(api,shopper)).items.length===0,'Native cart removal did not finish.');
}
async function forceCandidate(api,shopper,quantity=1) {
  await api.setQuantity(shopper,quantity+1);
  await api.setQuantity(shopper,quantity);
  const view=await api.reviewCart(shopper,{completeAddress:true,accept:false});
  api.require(typeof view.ticket==='string'&&view.ticket.startsWith('cp1c.'),'Native current-line review ticket was not served.');
  return view;
}
async function requireRejectedAdd(api,shopper,target,source) {
  await clearCart(api,shopper);
  const served=await api.productView(shopper,source);
  const added=await api.addProduct(shopper,{...target,ticketOverride:served.ticket});
  const actual=await cart(api,shopper);
  const line=actual.items.find(item=>item.key===added.key);
  api.require(line&&policy(line)?.status==='review_required','Foreign frame ticket was silently accepted.');
  return true;
}
async function nativeAddressChange(api,shopper) {
  await shopper.page.goto(api.input.prepared.cart_url,{waitUntil:'domcontentloaded'});
  const editor=shopper.page.locator('details.cetech-de-cart-context__editor').first();
  if((await editor.getAttribute('open'))===null)await editor.locator('summary').first().click();
  const input=editor.locator('input[name="cetech_de_address_1"]');
  await input.fill('PRIVATE-R24-CHANGED-ADDRESS');
  const response=shopper.page.waitForResponse(r=>r.request().method()==='POST'&&r.request().isNavigationRequest()&&new URL(r.url()).origin===new URL(api.input.url).origin,{timeout:15000});
  await Promise.all([shopper.page.waitForNavigation({waitUntil:'domcontentloaded'}),response,editor.locator('button.cetech-de-cart-context__save').click()]);
  return api.reviewCart(shopper,{completeAddress:false,accept:false});
}
async function replayActual(api,shopper,request) {
  api.require(request&&request.method==='POST'&&typeof request.postData==='string'&&new URL(request.url).origin===new URL(api.input.url).origin,'Actual original native request required.');
  const headers={};
  for(const [key,value]of Object.entries(request.headers||{})) {
    if(['content-type','nonce','x-wc-store-api-nonce'].includes(key.toLowerCase()))headers[key]=value;
  }
  const response=await shopper.context.request.post(request.url,{data:request.postData,headers,maxRedirects:0});
  return responseValue(response);
}
async function nativePayAttempt(api,shopper,order) {
  api.phase('order_pay');
  api.require(typeof order.order_pay_url==='string'&&new URL(order.order_pay_url).origin===new URL(api.input.url).origin,'Actual native order-pay URL required.');
  const pageResponse=await shopper.page.goto(order.order_pay_url,{waitUntil:'domcontentloaded'});
  const form=shopper.page.locator('form#order_review');
  if(!await form.count())return {formUnavailable:true,status:pageResponse?.status()||0,nativeRouteVisited:true};
  const method=form.locator('input[name="payment_method"][value="cetech_r24_local"]');
  if(await method.count())await method.check();
  const terms=form.locator('input[name="terms"]');if(await terms.count())await terms.check();
  const response=shopper.page.waitForResponse(r=>r.request().method()==='POST'&&r.request().isNavigationRequest()&&new URL(r.url()).origin===new URL(api.input.url).origin,{timeout:15000});
  const [actual]=await Promise.all([response,shopper.page.waitForNavigation({waitUntil:'domcontentloaded'}),form.locator('button#place_order,input#place_order').click()]);
  return {...await responseValue(actual),formUnavailable:false,nativeRouteVisited:true};
}

async function runHistoryProbes(api) {
  const owned=[];
  const create=async(principal='guest',key='a')=>{const s=await api.makeShopper(principal,key);owned.push(s);return s;};
  const stable = (a,b) => api.unchanged(a,b);
  const results={tamper_variants:Object.fromEntries(TAMPER.map(key=>[key,false])),unsupported_fee_coupon:false,dispositions:{both_family_dispositions_distinct:false,unresolved_assigned_never_silent_inherit:false,no_fabricated_terms_or_pickup_offer:false},owner_exclusive:false};
  try {
    // A derived variation is captured after an actual same-key policy redraw.
    const variation=await create();
    await api.addProduct(variation,{kind:'parent',quantity:1,offer:'a'});
    const old=await forceCandidate(api,variation);
    await api.reviewCart(variation,{completeAddress:true,accept:true});
    api.bridge('assignment-disable');
    const changed=await api.reviewCart(variation,{completeAddress:true,accept:false});
    const stale=await review(api,variation,changed,{ticket:old.ticket});
    api.require(refusedReview(stale),'Old same-key candidate was accepted.');
    await api.reviewCart(variation,{completeAddress:true,accept:true});
    const disabledCart=await cart(api,variation);
    const disabledView=policy(disabledCart.items.find(item=>item.key===changed.key));
    const captured=await api.checkout(variation,'classic',{expectSuccess:true});
    const capturedState=api.inspect();
    const order=capturedState.orders[String(captured.orderId)];
    const packets=(order?.policy?.items||[]).map(item=>JSON.parse(item.packet));
    const nativeParent=packets.length>0&&packets.every(packet=>packet.lineage.product_id===api.input.prepared.products.parent&&packet.lineage.variation_id===api.input.prepared.products.variation&&packet.lineage.parent_id===api.input.prepared.products.parent&&packet.lineage.real_offer_id===api.input.prepared.offers.a);
    const independent=packets.length>0&&packets.every(packet=>packet.families.return_policy_v1.disposition==='disabled'&&packet.families.refund_policy_v1.disposition==='assigned');
    api.check('R24-OFFER-VARIATION-AND-SAMEKEY-REDRAW',{
      real_offer_and_native_parent_bound:nativeParent,
      both_families_independent:independent,
      changed_material_redraws:changed.key===old.key&&changed.ticket!==old.ticket&&changed.wording!==old.wording,
      old_candidate_not_silently_accepted:refusedReview(stale)
    });
    api.bridge('assignment-restore');

    // Native Store API distinguishes intentional absence from a broken assigned source.
    const dispositionShopper=await create();
    await api.addProduct(dispositionShopper,{kind:'simple',quantity:1,offer:'a'});
    await api.reviewCart(dispositionShopper,{completeAddress:true,accept:true});
    let unassignedView,unavailableView,unavailableResult;
    api.bridge('assignment-unassigned');
    try {
      const changed=await api.reviewCart(dispositionShopper,{completeAddress:true,accept:false});
      unassignedView=policy((await cart(api,dispositionShopper)).items.find(item=>item.key===changed.key));
    } finally { api.bridge('assignment-restore'); }
    await api.reviewCart(dispositionShopper,{completeAddress:true,accept:true});
    api.bridge('source-unavailable');
    try {
      unavailableView=policy((await cart(api,dispositionShopper)).items[0]);
      unavailableResult=await api.checkout(dispositionShopper,'classic',{expectSuccess:false});
    } finally { api.bridge('source-restore'); }
    const emptyTerms=family=>family&&['customer_title','summary','full_text','exceptions'].every(key=>family[key]===null);
    const disabled=disabledView?.families?.return_policy_v1,unassigned=unassignedView?.families?.return_policy_v1;
    results.dispositions.both_family_dispositions_distinct=independent&&disabled?.disposition==='disabled'&&unassigned?.disposition==='not_assigned'&&disabledView?.families?.refund_policy_v1?.disposition==='assigned'&&unassignedView?.families?.refund_policy_v1?.disposition==='assigned';
    const unresolved=unavailableView?.families;
    results.dispositions.unresolved_assigned_never_silent_inherit=unavailableView?.status==='unavailable'&&unavailableView.ticket===null&&equal(Object.keys(unresolved||{}).sort(),['refund_policy_v1','return_policy_v1'])&&Object.values(unresolved||{}).every(family=>family.disposition==='unresolved'&&emptyTerms(family))&&failed(unavailableResult)&&stable(unavailableResult.before,unavailableResult.after);
    results.dispositions.no_fabricated_terms_or_pickup_offer=emptyTerms(disabled)&&emptyTerms(unassigned)&&nativeParent&&disabledView?.version===1&&unassignedView?.version===1;

    const staleAssignment=await create();
    await api.addProduct(staleAssignment,{kind:'simple',quantity:1,offer:'a'});
    const assignmentBeforeView=await forceCandidate(api,staleAssignment);
    await api.reviewCart(staleAssignment,{completeAddress:true,accept:true});
    const assignmentBefore=api.inspect();api.bridge('assignment-disable');
    const assignmentResult=await api.checkout(staleAssignment,'classic',{expectSuccess:false});
    const assignmentAfter=api.inspect();
    const assignmentFresh=await api.reviewCart(staleAssignment,{completeAddress:true,accept:false});
    api.check('R24-STALE-ASSIGNMENT-BEFORE-NATIVE-CRUD',{
      real_native_submission_refused:failed(assignmentResult),
      no_new_or_destructive_order_write:stable(assignmentBefore,assignmentAfter)&&equal(ids(assignmentBefore),ids(assignmentAfter)),
      no_gateway_or_completion:equal(assignmentBefore.gateway_calls,assignmentAfter.gateway_calls)&&equal(assignmentBefore.completion_calls,assignmentAfter.completion_calls),
      explicit_fresh_display_required:typeof assignmentFresh.ticket==='string'&&assignmentFresh.ticket!==assignmentBeforeView.ticket
    });
    api.bridge('assignment-restore');

    const stalePublication=await create();
    await api.addProduct(stalePublication,{kind:'simple',quantity:1,offer:'a'});
    const publicationView=await forceCandidate(api,stalePublication);
    await api.reviewCart(stalePublication,{completeAddress:true,accept:true});
    api.bridge('replace');
    const publicationResult=await api.checkout(stalePublication,'blocks',{expectSuccess:false});
    // Blocks may create its native checkout draft on GET. The refusal protects
    // the exact snapshot immediately before the final POST, including that draft.
    const publicationBefore=publicationResult.before;
    const publicationAfter=api.inspect();
    const publicationFresh=await api.reviewCart(stalePublication,{completeAddress:true,accept:false});
    api.check('R24-STALE-PUBLICATION-BEFORE-NATIVE-CRUD',{
      original_material_not_substituted:stable(publicationBefore,publicationAfter)&&publicationFresh.wording!==publicationView.wording,
      real_native_submission_refused:failed(publicationResult),
      no_gateway_or_completion:equal(publicationBefore.gateway_calls,publicationAfter.gateway_calls)&&equal(publicationBefore.completion_calls,publicationAfter.completion_calls),
      saved_history_untouched:stable(publicationBefore,publicationAfter)
    });

    // Four actual cookie jars, two guests and two independently authenticated customers.
    const shoppers=[await create('guest','a'),await create('guest','b'),await create('customer','a'),await create('customer','b')];
    const baseline=api.inspect();const views=[];
    for(const s of shoppers){await api.addProduct(s,{kind:'simple',quantity:1,offer:'a',ticketOverride:flip});views.push(await forceCandidate(api,s));}
    results.tamper_variants.mac=refusedReview(await review(api,shoppers[0],views[0],{ticket:flip(views[0].ticket)}));
    const cross=[];
    for(const [from,to]of [[0,1],[1,0],[2,3],[3,2]])cross.push(refusedReview(await review(api,shoppers[to],views[to],{ticket:views[from].ticket})));
    results.tamper_variants.session=cross.every(Boolean);
    results.tamper_variants.cart_key=refusedReview(await review(api,shoppers[0],views[0],{key:views[0].key+'-foreign'}));
    results.tamper_variants.purpose=refusedReview(await review(api,shoppers[0],views[0],{ticket:(await api.productView(shoppers[0],{kind:'simple',quantity:1,offer:'a'})).ticket}));
    results.tamper_variants.product=await requireRejectedAdd(api,shoppers[0],{kind:'parent',quantity:1,offer:'a'},{kind:'simple',quantity:1,offer:'a'});
    results.tamper_variants.quantity=await requireRejectedAdd(api,shoppers[0],{kind:'simple',quantity:2,offer:'a'},{kind:'simple',quantity:1,offer:'a'});
    results.tamper_variants.offer=await requireRejectedAdd(api,shoppers[0],{kind:'simple',quantity:1,offer:'b'},{kind:'simple',quantity:1,offer:'a'});

    await clearCart(api,shoppers[0]);
    const parentView=await api.productView(shoppers[0],{kind:'parent',quantity:1,offer:'a'});
    const form=parentView.form;
    api.requireCode(form&&await form.locator('input[name="variation_id"]').count()===1,'parent_form_invalid');
    const nativeVariation=await form.locator('input[name="variation_id"]').inputValue();
    api.requireCode(Number(nativeVariation)===api.input.prepared.products.variation,'parent_variation_mismatch');
    // Woo's native handler selects the parent from add-to-cart. Mutating only
    // product_id would leave a valid native parent/variation request unchanged.
    for(const name of ['add-to-cart','product_id']){
      const field=form.locator('input[name="'+name+'"]');
      api.requireCode(await field.count()===1,'parent_field_invalid');
      await field.evaluate((el,value)=>{el.value=String(value);},api.input.prepared.products.simple);
    }
    const beforeParent=await cart(api,shoppers[0]);
    const parentResponse=shoppers[0].page.waitForResponse(r=>r.request().method()==='POST'&&r.request().isNavigationRequest()&&new URL(r.url()).origin===new URL(api.input.url).origin,{timeout:15000});
    const [,actualParentPost]=await Promise.all([shoppers[0].page.waitForNavigation({waitUntil:'domcontentloaded'}),parentResponse,form.locator('button.single_add_to_cart_button').click()]);
    api.requireCode(await nativeParentSubmission(actualParentPost.request(),{'add-to-cart':String(api.input.prepared.products.simple),product_id:String(api.input.prepared.products.simple),variation_id:nativeVariation,['cetech_de_policy_tickets['+parentView.displayKey+']']:parentView.ticket}),'parent_submission_mismatch');
    const afterParent=await cart(api,shoppers[0]);
    results.tamper_variants.variation_parent=equal(beforeParent.items,afterParent.items)||afterParent.items.every(item=>policy(item)?.status==='review_required');

    await clearCart(api,shoppers[0]);await api.addProduct(shoppers[0],{kind:'simple',quantity:1,offer:'a',ticketOverride:flip});
    const contextBefore=await forceCandidate(api,shoppers[0]);
    const contextAfter=await nativeAddressChange(api,shoppers[0]);
    results.tamper_variants.context=refusedReview(await review(api,shoppers[0],contextAfter,{ticket:contextBefore.ticket}));

    const generationBefore=await forceCandidate(api,shoppers[1]);
    await api.setQuantity(shoppers[1],3);await api.setQuantity(shoppers[1],1);
    const generationAfter=await api.reviewCart(shoppers[1],{completeAddress:true,accept:false});
    results.tamper_variants.review_generation=generationBefore.ticket!==generationAfter.ticket&&refusedReview(await review(api,shoppers[1],generationAfter,{ticket:generationBefore.ticket}));
    const roundtripBefore=await forceCandidate(api,shoppers[2]);
    await clearCart(api,shoppers[2]);await api.addProduct(shoppers[2],{kind:'simple',quantity:1,offer:'a',ticketOverride:flip});
    const roundtripAfter=await api.reviewCart(shoppers[2],{completeAddress:true,accept:false});
    results.tamper_variants.replayed_round_trip=refusedReview(await review(api,shoppers[2],roundtripAfter,{ticket:roundtripBefore.ticket}));
    const afterTamper=api.inspect();
    api.report.tamper_variants={...results.tamper_variants};
    api.check('R24-TAMPERED-AND-FOREIGN-CANDIDATE',{
      every_closed_tamper_variant_refused:Object.values(results.tamper_variants).every(value=>value===true),
      two_guests_and_two_customers_isolated:cross.length===4&&cross.every(Boolean),
      nonce_alone_not_authority:cross.every(Boolean),
      no_native_history_or_payment_effect:stable(baseline,afterTamper)
    });

    const publicShopper=shoppers[3];const publicView=await forceCandidate(api,publicShopper);
    api.phase('public_read');
    const publicBefore=api.inspect();
    await cart(api,publicShopper);
    const get=await review(api,publicShopper,publicView,{method:'GET'});
    const patch=await review(api,publicShopper,publicView,{method:'PATCH'});
    const csrf=await review(api,publicShopper,publicView,{nonce:'forged-nonce'});
    const extra=await review(api,publicShopper,publicView,{extra:{manage_policy:'1'}});
    const catalog=await publicShopper.context.request.get(new URL('/wp-admin/admin.php?page=cetech-de-return-policies',api.input.url).href,{maxRedirects:0});
    const firstId=api.historyIds[0];api.require(Number.isInteger(firstId)&&firstId>0,'Actual original saved order required.');
    const customerNonce=await api.native(publicShopper,'cart');
    api.require(publicShopper.principal==='customer'&&typeof customerNonce.rest_nonce==='string'&&customerNonce.rest_nonce.length>0,'Actual authenticated customer REST nonce required.');
    const refund=await publicShopper.context.request.post(nativeRefundUrl(api,firstId),{headers:{'X-WP-Nonce':customerNonce.rest_nonce},data:{amount:'1.00',reason:'Owned R24 denied customer refund'}});
    const refundBody=await refund.json();
    const refundDenied=refund.status()===403&&refundBody?.code==='woocommerce_rest_cannot_create'&&refundBody?.data?.status===403;
    const publicAfter=api.inspect();
    api.check('R24-PUBLIC-READ-HAS-NO-WRITER-AUTHORITY',{
      get_patch_and_totals_no_history_write:refusedReview(get)&&refusedReview(patch)&&stable(publicBefore,publicAfter),
      no_catalog_listing_or_manager_grant:catalog.status()>=300&&refusedReview(extra),
      no_payment_or_refund_money_authority:refundDenied&&stable(publicBefore,publicAfter),
      csrf_scope_refusals_zero_effect:refusedReview(csrf)&&refusedReview(extra)&&stable(publicBefore,publicAfter)
    });

    const lateObservations=[];
    api.phase('source_race');
    for(const mode of ['late-assignment','late-publication','late-native','late-policy','late-pause']){
      const s=await create();await api.addProduct(s,{kind:'simple',quantity:1,offer:'a'});await api.reviewCart(s,{completeAddress:true,accept:true});
      const before=api.inspect();api.bridge(mode);
      const outcome=await api.checkout(s,'classic',{expectSuccess:false});const after=api.inspect();
      lateObservations.push({mode,refused:failed(outcome),noPayment:equal(before.gateway_calls,after.gateway_calls)&&equal(before.completion_calls,after.completion_calls),noRecompute:noRecomputedPolicy(before,after)});
      if(mode==='late-assignment')api.bridge('assignment-restore');
      if(mode==='late-pause')api.bridge('resume');
    }
    let zeroPause=false;
    api.bridge('zero-on');
    try{
      const free=await create();await api.addProduct(free,{kind:'simple',quantity:1,offer:'a'});await api.reviewCart(free,{completeAddress:true,accept:true});
      const before=api.inspect();api.bridge('late-pause');const result=await api.checkout(free,'classic',{expectSuccess:false});const after=api.inspect();const added=Object.values(after.orders).filter(order=>!before.orders[String(order.order_id)]);
      zeroPause=failed(result)&&added.some(order=>Number(order.total)===0&&!order.paid)&&equal(before.gateway_calls,after.gateway_calls)&&equal(before.completion_calls,after.completion_calls);
    }finally{api.bridge('resume');api.bridge('zero-off');}
    api.check('R24-LATE-C07-AND-SOURCE-RACE',{
      prebind_source_changes_and_late_native_mutation_refused:lateObservations.filter(row=>['late-assignment','late-publication','late-native'].includes(row.mode)).every(row=>row.refused&&row.noPayment),
      control_pause_refuses_gateway_and_free_completion:zeroPause&&lateObservations.find(row=>row.mode==='late-pause')?.noPayment===true,
      policy_packet_in_local_frozen_binding:lateObservations.find(row=>row.mode==='late-policy')?.refused===true&&lateObservations.find(row=>row.mode==='late-policy')?.noPayment===true,
      no_post_freeze_packet_recompute:lateObservations.every(row=>row.noRecompute)
    });

    // Interruption occurs after the actual Stage, before final C07 completion.
    // A valid key authorizes native guest order-pay. Use a customer-owned
    // original so the foreign guest and second logged-in account are actually
    // foreign audiences even when they know the private test URL.
    const retry=await create('customer','a');await clearCart(api,retry);await api.addProduct(retry,{kind:'simple',quantity:1,offer:'a'});await api.reviewCart(retry,{completeAddress:true,accept:true});
    const retryBefore=api.inspect();api.bridge('interrupt');const interrupted=await api.checkout(retry,'classic',{expectSuccess:false});const interruptedState=api.inspect();
    const newIds=ids(interruptedState).filter(id=>!ids(retryBefore).includes(id));
    api.require(newIds.length===1,'Interrupted native order must be tracked exactly.');
    const original=interruptedState.orders[String(newIds[0])];const foreign=await create();const foreignCustomer=await create('customer','b');const foreignPartial=await nativePayAttempt(api,foreign,original);const foreignCustomerPartial=await nativePayAttempt(api,foreignCustomer,original);const foreignState=api.inspect();
    api.bridge('replace');const completed=await api.checkout(retry,'classic',{expectSuccess:true});const completedState=api.inspect();const saved=completedState.orders[String(newIds[0])];
    const replayBefore=api.inspect();await replayActual(api,retry,completed.request);const replayAfter=api.inspect();
    const payBefore=api.inspect();
    const foreignPaid=await nativePayAttempt(api,foreign,saved);
    const ownPaid=await nativePayAttempt(api,retry,saved);
    const payAfter=api.inspect();

    // An existing authorized unpaid order pays from its complete saved physical
    // history. Neither current policy availability nor the new-capture flag is
    // authority to recapture or to replace the frozen packet on order-pay.
    const savedPay=await create('customer','b');await clearCart(api,savedPay);await api.addProduct(savedPay,{kind:'simple',quantity:1,offer:'a'});await api.reviewCart(savedPay,{completeAddress:true,accept:true});
    const savedPayStart=api.inspect();api.bridge('interrupt');const savedPayInterrupted=await api.checkout(savedPay,'classic',{expectSuccess:false});const savedPayBefore=api.inspect();
    const savedPayIds=ids(savedPayBefore).filter(id=>!ids(savedPayStart).includes(id));
    api.require(failed(savedPayInterrupted)&&savedPayIds.length===1,'One complete unpaid saved order required for native order-pay.');
    const savedUnpaid=savedPayBefore.orders[String(savedPayIds[0])];
    api.require(savedUnpaid.customer_id===api.input.prepared.customers.b.id&&savedUnpaid.needs_payment===true&&!savedUnpaid.paid,'Actual authorized unpaid native order required.');
    let savedPayResult,savedPayAfter;
    api.bridge('source-unavailable');
    try {
      api.bridge('off');
      try { savedPayResult=await nativePayAttempt(api,savedPay,savedUnpaid);savedPayAfter=api.inspect(); }
      finally { api.bridge('on'); }
    } finally { api.bridge('source-restore'); }
    const savedPaid=savedPayAfter.orders[String(savedPayIds[0])];
    const savedPayCalls=savedPayAfter.order_pay_calls.slice(savedPayBefore.order_pay_calls.length);
    const savedCompletion=savedPayAfter.completion_calls.slice(savedPayBefore.completion_calls.length);
    const continuedSavedOnly=savedPayResult.nativeRouteVisited===true&&!savedPayResult.formUnavailable&&savedPaid.paid===true&&equal(ids(savedPayBefore),ids(savedPayAfter))&&equal(savedPayAfter.gateway_calls.slice(savedPayBefore.gateway_calls.length),savedPayIds)&&savedPayCalls.length===1&&savedPayCalls[0].order_id===savedPayIds[0]&&savedPayCalls[0].actual_native_guarded_boundary===true&&savedPayCalls[0].saved_physical_payment_scope===true&&savedCompletion.length===1&&savedCompletion[0].order_id===savedPayIds[0]&&savedCompletion[0].saved_order_pay===true&&savedCompletion[0].after_confirmed_binding===true;
    const savedPayPreserved=equal(savedPayBefore.originals,savedPayAfter.originals)&&equal(savedPayBefore.indices,savedPayAfter.indices)&&savedUnpaid.policy_sha256===savedPaid.policy_sha256&&savedUnpaid.legacy_sha256===savedPaid.legacy_sha256&&savedUnpaid.total===savedPaid.total&&savedUnpaid.currency===savedPaid.currency&&Object.values(savedPayBefore.orders).filter(order=>order.order_id!==savedPayIds[0]).every(order=>equal(orderFacts(order),orderFacts(savedPayAfter.orders[String(order.order_id)])));
    api.check('R24-FOREIGN-PAID-AND-ORDERPAY',{
      native_order_authorization_required:original.customer_id===api.input.prepared.customers.a.id&&stable(interruptedState,foreignState)&&foreignPartial.nativeRouteVisited===true&&foreignCustomerPartial.nativeRouteVisited===true&&foreignPaid.nativeRouteVisited===true,
      paid_original_read_only:saved.paid===true&&ownPaid.nativeRouteVisited===true&&stable(payBefore,payAfter),
      existing_orderpay_never_recaptures:continuedSavedOnly&&savedPayPreserved&&stable(interruptedState,foreignState)&&stable(payBefore,payAfter)&&equal(replayBefore.originals,replayAfter.originals)&&equal(payBefore.originals,payAfter.originals),
      money_and_packet_bytes_preserved:savedPayPreserved&&stable(payBefore,payAfter)&&saved.policy_sha256===original.policy_sha256&&saved.legacy_sha256===original.legacy_sha256
    });
    api.check('R24-EXACT-RETRY-AND-INTERRUPTION',{
      completed_retry_no_duplicate_or_rewrite:stable(replayBefore,replayAfter)&&equal(ids(replayBefore),ids(replayAfter)),
      partial_owner_not_taken_over:stable(interruptedState,foreignState),
      original_candidate_and_item_binding_retained:completed.orderId===newIds[0]&&saved.policy_sha256===original.policy_sha256&&saved.legacy_sha256===original.legacy_sha256,
      stage_interruption_not_final_completion:failed(interrupted)&&!original.paid&&interruptedState.originals.some(row=>row.binding?.order_id===newIds[0]&&row.state==='bound'),
      no_gateway_before_confirmed_completion:equal(retryBefore.gateway_calls,interruptedState.gateway_calls)&&equal(retryBefore.completion_calls,interruptedState.completion_calls)&&!completedState.gateway_before_completion
    });

    // A foreign marker is a declared synthetic competing owner, never a V3/V4
    // producer claim. Actual retained producers are a separate pinned prerequisite.
    const exclusive=await create();await api.addProduct(exclusive,{kind:'simple',quantity:1,offer:'a'});await api.reviewCart(exclusive,{completeAddress:true,accept:true});
    const foreignMarker=await api.native(exclusive,'foreign-owner','POST');
    const exclusiveResult=await api.checkout(exclusive,'classic',{expectSuccess:false});
    results.owner_exclusive=foreignMarker.declared_synthetic_marker_saved===true&&failed(exclusiveResult)&&stable(exclusiveResult.before,exclusiveResult.after);

    let fee=false,coupon=false;
    const feeShopper=await create();await api.addProduct(feeShopper,{kind:'simple',quantity:1,offer:'a'});await api.reviewCart(feeShopper,{completeAddress:true,accept:true});
    api.bridge('fee-on');
    try{const actual=await cart(api,feeShopper);api.require(Array.isArray(actual.fees)&&actual.fees.length>0,'Actual unsupported native fee required.');const result=await api.checkout(feeShopper,'classic',{expectSuccess:false});fee=failed(result)&&stable(result.before,result.after);}finally{api.bridge('fee-off');}
    const couponShopper=await create();await api.addProduct(couponShopper,{kind:'simple',quantity:1,offer:'a'});await api.reviewCart(couponShopper,{completeAddress:true,accept:true});
    await couponShopper.page.goto(api.input.prepared.cart_url,{waitUntil:'domcontentloaded'});
    await couponShopper.page.locator('input[name="coupon_code"]').fill(api.input.prepared.coupon_code);
    const couponResponse=couponShopper.page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).origin===new URL(api.input.url).origin&&(r.request().postData()||'').includes('coupon_code'),{timeout:15000});
    await couponShopper.page.locator('button[name="apply_coupon"]').click();const couponApplied=await couponResponse;await couponApplied.finished();
    // Woo may enhance the cart form with AJAX. Await the resulting native cart
    // row, which also covers a full form navigation, before the independent read.
    await couponShopper.page.waitForFunction(code=>[...document.querySelectorAll('.cart-discount')].some(row=>[...row.classList].includes('coupon-'+code)),api.input.prepared.coupon_code);
    api.require((await cart(api,couponShopper)).coupons?.some(row=>row.code===api.input.prepared.coupon_code),'Actual native coupon was not applied.');
    const couponBefore=api.inspect();const couponResult=await api.checkout(couponShopper,'classic',{expectSuccess:false});coupon=failed(couponResult)&&stable(couponBefore,api.inspect());
    results.unsupported_fee_coupon=fee&&coupon;
    return results;
  }finally{
    // Only local browser contexts created by this module are closed here.
    for(const shopper of owned)await shopper.context.close();
  }
}
module.exports={runHistoryProbes,nativePayAttempt,nativeParentSubmission};
