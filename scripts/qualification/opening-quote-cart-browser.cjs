'use strict';
/** Q05 real Blocks review controls; no checkout/place-order button is clicked. */
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const args = process.argv.slice(2);
function argument(name) { const index = args.indexOf(name); if (index < 0 || !args[index + 1]) throw new Error('Missing quote browser argument'); return args[index + 1]; }
const statePath = path.resolve(argument('--state'));
const receiptPath = path.resolve(argument('--receipt'));
const base = new URL(argument('--base-url'));
if (base.protocol !== 'http:' || base.hostname !== '127.0.0.1' || !base.port || base.username || base.password || base.pathname !== '/' || base.search || base.hash || fs.lstatSync(statePath).isSymbolicLink() || (fs.statSync(statePath).mode & 0o077) !== 0 || fs.statSync(statePath).size > 262144) throw new Error('Quote browser requires private state and an owned loopback origin');
const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
const ids = ['HTTP-W2Q05-BLOCKS-VISIBLE-REVIEW-CONTROLS', 'HTTP-W2Q05-BLOCKS-REFRESH-PRICE-REQUIRES-CONFIRM', 'HTTP-W2Q05-BLOCKS-CONFIRM-ONE-ACCEPT-NO-PLACEMENT'];
const report = {format:'cetech-w2q05-cart-browser-v1', source_head:state.identity.source_head, candidate_head:state.identity.candidate_head, source_tree:state.identity.source_tree,
  installed_php_sources_hash:state.identity.installed_php_sources_hash, runtime:{playwright:'1.58.2'}, status:'RUNNING', cases:[]};
let stage = 'ownership';
const dom = {blocks_visible:false,review_visible:false,refresh_visible:false,confirm_visible:false,price_visible:false,confirmed_visible:false};
let checkoutPosts = 0;
function write() { const tmp = receiptPath + '.tmp'; fs.writeFileSync(tmp, JSON.stringify(report, null, 2) + '\n', {mode:0o600}); fs.renameSync(tmp, receiptPath); }
function check(id, condition, evidence) { if (report.cases.some(item => item.id === id)) throw new Error('Duplicate quote browser case'); report.cases.push({id,status:condition ? 'PASS' : 'FAIL',evidence}); write(); if (!condition) throw new Error('Quote browser qualification diverged'); }
function own(value) { try { const url = new URL(value, base); return url.origin === base.origin && !url.username && !url.password && !url.hash; } catch (_) { return false; } }
function nativeRouteMatches(observedValue, nativeValue, expectedRoute = '/wc/store/v1/cart/extensions') {
  try {
    const observed = new URL(observedValue, base); const native = new URL(nativeValue, base);
    if (!own(observed.href) || !own(native.href)) return false;
    const left = observed.searchParams.getAll('rest_route'); const right = native.searchParams.getAll('rest_route');
    if (left.length > 1 || right.length > 1) return false;
    const normalize = value => value.endsWith('/') ? value.slice(0, -1) : value;
    if (!['/wc/store/v1/cart/extensions','/wc/store/v1/checkout'].includes(expectedRoute)) return false;
    if (right.length === 1) return normalize(right[0]) === expectedRoute && left.length === 1 && observed.pathname === native.pathname && normalize(left[0]) === expectedRoute;
    return normalize(native.pathname).endsWith(expectedRoute) && left.length === 0 && normalize(observed.pathname) === normalize(native.pathname);
  } catch (_) { return false; }
}
function noPlacement(before, after) { return before.history_counts.bindings === after.history_counts.bindings && before.orders_count === after.orders_count && before.gateway_count === after.gateway_count; }
function sameHistory(before, after) { return ['records','events','quotes','accepted','bindings','budget'].every(key => before.history_counts[key] === after.history_counts[key]) && noPlacement(before, after); }
function finiteCounts(value) { const keys=['records','events','quotes','accepted','bindings','budget']; return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === keys.length && keys.every(key => Number.isSafeInteger(value[key]) && value[key] >= 0); }
function safeMoney(value) { return exact(value,['amount','currency','precision']) && typeof value.amount === 'string' && /^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/.test(value.amount) && typeof value.currency === 'string' && /^[A-Z]{3}$/.test(value.currency) && Number.isInteger(value.precision) && value.precision >= 0 && value.precision <= 6 && (value.amount.split('.')[1] || '').length <= value.precision; }
function exact(value,keys) { return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === keys.length && keys.every(key => Object.hasOwn(value,key)); }
function plain(value) { return typeof value === 'string' && value.length > 0 && value.length <= 120 && !/[\u0000-\u001f\u007f<>]/.test(value); }
function uuid(value) { return typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value); }
function safeFacts(value) {
  const keys=['contract_version','status','generation','quote','can_refresh','can_confirm','can_retry','message_code','correlation_id'];
  if (!exact(value,keys) || value.contract_version !== 1 || !['no_quote','review_required','confirmed','expired','changed','unconfirmed','unavailable'].includes(value.status) || value.message_code !== value.status || !Number.isSafeInteger(value.generation) || value.generation < 0 || !uuid(value.correlation_id) || ['can_refresh','can_confirm','can_retry'].some(key => typeof value[key] !== 'boolean')) return false;
  if (value.quote !== null) {
    const quote=value.quote;
    if (!exact(quote,['contract_version','decision_kind','quote_id','status','currently_applicable','expires_at','customer_label','money','reason_code','recovery_action','correlation_id']) || quote.contract_version !== 1 || quote.decision_kind !== 'delivery_quote' || !['issued','accepted','invalidated','expired','stripped'].includes(quote.status) || typeof quote.currently_applicable !== 'boolean' || !uuid(quote.quote_id) || !uuid(quote.correlation_id) || !plain(quote.customer_label) || typeof quote.expires_at !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/.test(quote.expires_at) || ![null,'quote_expired','quote_invalidated','quote_unavailable'].includes(quote.reason_code) || ![null,'refresh_and_review','retry_later'].includes(quote.recovery_action) || !Array.isArray(quote.money) || quote.money.length > 200) return false;
    for (const part of quote.money) {
      if (!exact(part,['customer_label','list_price','promotion','final_price','tax','rounded_tax','total','display_total']) || !plain(part.customer_label) || ['list_price','final_price','tax','total'].some(key => !safeMoney(part[key])) || ['rounded_tax','display_total'].some(key => part[key] !== null && !safeMoney(part[key])) || !exact(part.promotion,['state','amount']) || !['none','applied','unavailable'].includes(part.promotion.state) || (part.promotion.state === 'unavailable' ? part.promotion.amount !== null : !safeMoney(part.promotion.amount))) return false;
    }
  }
  if (value.can_confirm && (value.status !== 'review_required' || value.quote === null || !value.quote.currently_applicable)) return false;
  const encoded=JSON.stringify(value);
  return Buffer.byteLength(encoded) <= 65536 && !['PRIVATE-','acceptance_handle','owner_digest','session_hash','principal_hash','body_digest','material_digest','origin_id','supplier','rate_card','provider_json','issue_context_json','review_token'].some(marker => encoded.includes(marker));
}
write();
(async () => {
  const modulePath=process.env.CETECH_DE_EMERGENCY_PLAYWRIGHT_MODULE;
  if (!modulePath || !path.isAbsolute(modulePath)) throw new Error('Pinned Playwright module not supplied');
  const version=require(path.join(modulePath,'package.json')).version;
  if (version !== '1.58.2') throw new Error('Pinned Playwright version differs'); report.runtime.playwright=version;
  const {chromium}=require(modulePath);
  const browser=await chromium.launch({headless:true}); report.runtime.chromium=browser.version();
  const context=await browser.newContext({baseURL:base.origin,userAgent:'CETECH-W2Q05-Quote-Review-Qualification/1'});
  context.setDefaultTimeout(20000);
  const page=await context.newPage();
  const headers={'X-Cetech-Q05-Fixture':state.fixture_token};
  async function inspect() {
    if (!own(state.fixture_url)) throw new Error('Unowned native fixture URL');
    const response=await context.request.get(state.fixture_url,{headers,timeout:20000}); const value=await response.json();
    if (response.status() !== 200 || value.success !== true || !finiteCounts(value.data.history_counts) || !Number.isSafeInteger(value.data.orders_count) || !Number.isSafeInteger(value.data.gateway_count) || ['source_head','candidate_head','source_tree','installed_php_sources_hash'].some(key => value.data.source_identity[key] !== state.identity[key])) throw new Error('Native quote fixture/source authorization refused');
    return value.data;
  }
  async function responseFacts(response) { const value=await response.json(); return value.extensions && value.extensions['cetech-delivery-quote-review']; }
  async function clickReview(action) {
    const observed=page.waitForResponse(response => response.request().method() === 'POST' && nativeRouteMatches(response.url(),state.store_extensions_url),{timeout:20000});
    await page.locator('#cetech-de-quote-review-blocks [data-quote-review-action="'+action+'"]').click();
    const response=await observed; const sent=JSON.parse(response.request().postData() || 'null');
    if (!exact(sent,['namespace','data']) || sent.namespace !== 'cetech-delivery-quote-review' || !sent.data || sent.data.action !== action) throw new Error('Actual quote button dispatched another native command');
    return [response,await responseFacts(response)];
  }
  try {
    await page.route('**/*',route => own(route.request().url()) ? route.continue() : route.abort());
    page.on('request',request => { if (request.method() === 'POST' && nativeRouteMatches(request.url(),state.checkout_url,'/wc/store/v1/checkout')) ++checkoutPosts; });
    const probe=await context.request.get('/?cetech_opening_http_probe=1',{headers:{'X-CETECH-Opening-Probe':state.probe_token},timeout:20000}); const identity=await probe.json();
    if (probe.status() !== 200 || ['source_head','candidate_head','source_tree'].some(key => identity[key] !== state.identity[key]) || identity.probe_sha256 !== crypto.createHash('sha256').update(state.probe_token).digest('hex') || identity.site_path_sha256 !== crypto.createHash('sha256').update(state.site_path).digest('hex') || identity.database_name_sha256 !== crypto.createHash('sha256').update(state.database_name).digest('hex')) throw new Error('Owned quote listener not confirmed');
    stage='login';
    await page.goto('/wp-login.php',{waitUntil:'domcontentloaded'}); await page.locator('#user_login').fill(state.username); await page.locator('#user_pass').fill(state.password);
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
    if (!(await context.cookies()).some(cookie => cookie.name.startsWith('wordpress_logged_in_'))) throw new Error('Actual native shopper login token absent');
    stage='fixture'; const initial=await inspect();
    if (!own(state.seed_url)) throw new Error('Unowned native seed URL');
    const seed=await context.request.post(state.seed_url,{headers,form:{nonce:initial.fixture_nonce},timeout:20000}); if (seed.status() !== 200 || (await seed.json()).success !== true) throw new Error('Native selected cart seed refused');
    const before=await inspect();
    stage='render'; if (!own(state.blocks_page_url)) throw new Error('Unowned native Blocks URL');
    await page.goto(state.blocks_page_url,{waitUntil:'domcontentloaded'});
    const blocks=page.locator('.wc-block-checkout'); const mount=page.locator('#cetech-de-quote-review-blocks');
    await blocks.waitFor({state:'visible'}); await mount.waitFor({state:'visible'}); await mount.locator('[data-quote-review-action="refresh"]').waitFor({state:'visible'});
    dom.blocks_visible=true; dom.review_visible=true; dom.refresh_visible=true;
    const afterRender=await inspect();
    check(ids[0], await blocks.isVisible() && await mount.isVisible() && await mount.locator('[data-quote-review-action="refresh"]').isVisible() && afterRender.facts.status === 'no_quote' && sameHistory(before,afterRender) && checkoutPosts === 0,
      {real_chromium:true,actual_native_blocks_ui:true,visible_review_controls:true,native_seed_is_setup_only:true,read_render_no_quote_acceptance:sameHistory(before,afterRender),no_checkout_post:checkoutPosts === 0,no_placement_or_payment:noPlacement(before,afterRender),runtime:{playwright:report.runtime.playwright,chromium:report.runtime.chromium},history_before:before.history_counts,history_after:afterRender.history_counts});
    stage='refresh'; const refreshBefore=await inspect(); const [refreshResponse,review]=await clickReview('refresh');
    await mount.locator('[data-quote-review-action="confirm"]').waitFor({state:'visible'}); await mount.locator('.cetech-de-quote-review-money').getByText('GHS 7.70',{exact:false}).waitFor({state:'visible'});
    dom.confirm_visible=true; dom.price_visible=true;
    const afterRefresh=await inspect();
    check(ids[1], refreshResponse.status() === 200 && safeFacts(review) && review.status === 'review_required' && review.quote.status === 'issued' && review.can_confirm && afterRefresh.history_counts.quotes === refreshBefore.history_counts.quotes+1 && afterRefresh.history_counts.accepted === refreshBefore.history_counts.accepted && noPlacement(refreshBefore,afterRefresh) && checkoutPosts === 0 && await mount.locator('[data-quote-review-action="confirm"]').isEnabled(),
      {actual_refresh_button_clicked:true,actual_store_api_extensions_post:true,status:refreshResponse.status(),safe_shopper_dto:safeFacts(review),native_price_visible:true,explicit_confirm_required:true,issued_not_accepted:afterRefresh.history_counts.accepted === refreshBefore.history_counts.accepted,no_checkout_post:checkoutPosts === 0,no_placement_or_payment:noPlacement(refreshBefore,afterRefresh),history_before:refreshBefore.history_counts,history_after:afterRefresh.history_counts});
    stage='confirm'; const [confirmResponse,confirmed]=await clickReview('confirm');
    await mount.getByText('Delivery price confirmed for these delivery details.',{exact:true}).waitFor({state:'visible'}); dom.confirmed_visible=true;
    const afterConfirm=await inspect(); const afterRead=await inspect();
    check(ids[2], confirmResponse.status() === 200 && safeFacts(confirmed) && confirmed.status === 'confirmed' && confirmed.quote.status === 'accepted' && !confirmed.can_confirm && afterConfirm.history_counts.accepted === afterRefresh.history_counts.accepted+1 && sameHistory(afterConfirm,afterRead) && noPlacement(afterRefresh,afterRead) && checkoutPosts === 0 && await mount.locator('[data-quote-review-action="confirm"]').count() === 0,
      {actual_confirm_button_clicked:true,actual_store_api_extensions_post:true,status:confirmResponse.status(),safe_shopper_dto:safeFacts(confirmed),one_acceptance:afterConfirm.history_counts.accepted === afterRefresh.history_counts.accepted+1,confirmed_visible:true,repeat_reads_no_acceptance:sameHistory(afterConfirm,afterRead),no_checkout_post:checkoutPosts === 0,no_placement_or_payment:noPlacement(afterRefresh,afterRead),history_before:afterRefresh.history_counts,history_after:afterRead.history_counts});
    stage='complete'; report.status='PASS'; report.stage=stage; write();
  } catch(error) {
    try { dom.blocks_visible=await page.locator('.wc-block-checkout').isVisible(); dom.review_visible=await page.locator('#cetech-de-quote-review-blocks').isVisible(); dom.refresh_visible=await page.locator('#cetech-de-quote-review-blocks [data-quote-review-action="refresh"]').isVisible(); dom.confirm_visible=await page.locator('#cetech-de-quote-review-blocks [data-quote-review-action="confirm"]').isVisible(); } catch(_) {}
    throw error;
  } finally { await context.close(); await browser.close(); }
})().catch(error => {
  report.status='FAIL'; report.stage=stage; report.error_class=error instanceof SyntaxError ? 'SyntaxError' : error instanceof TypeError ? 'TypeError' : error && error.constructor && error.constructor.name === 'TimeoutError' ? 'TimeoutError' : 'Error';
  const next=ids.find(id => !report.cases.some(item => item.id === id));
  if (next && !report.cases.some(item => item.status === 'FAIL')) report.cases.push({id:next,status:'FAIL',evidence:{stage,error_class:report.error_class,dom:{...dom},required_case_incomplete:true}});
  const failure=report.cases.find(item => item.status === 'FAIL'); if (failure) Object.assign(failure.evidence,{stage,error_class:report.error_class,dom:{...dom}});
  write(); process.stderr.write('Q05 browser failure; inspect bounded private receipt.\n'); process.exitCode=1;
});
