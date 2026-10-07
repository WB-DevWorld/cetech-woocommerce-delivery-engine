'use strict';
/** Disposable real Chromium/Blocks UI proof. No screenshots, cookies or form bodies in receipt. */
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const args = process.argv.slice(2);
function argument(name) { const index = args.indexOf(name); if (index < 0 || !args[index + 1]) throw new Error('Missing bounded browser argument'); return args[index + 1]; }
const statePath = path.resolve(argument('--state'));
const receiptPath = path.resolve(argument('--receipt'));
const base = new URL(argument('--base-url'));
if (base.origin !== 'http://127.0.0.1:8085' || fs.lstatSync(statePath).isSymbolicLink() || (fs.statSync(statePath).mode & 0o077) !== 0) throw new Error('Browser fixture requires a private state and owned loopback origin');
const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
const report = {format: 'cetech-c07-blocks-browser-v1', source_head:state.identity.source_head, candidate_head: state.identity.candidate_head,
  source_tree: state.identity.source_tree, installed_php_sources_hash: state.identity.installed_php_sources_hash,
  runtime: {playwright: '1.58.2'}, status: 'RUNNING', cases: []};
const caseIds = ['HTTP-C07-BLOCKS-REAL-CHROMIUM-UI-RENDERED','HTTP-C07-BLOCKS-AFTER-RENDER-PAUSE-UI-SUBMIT-REFUSED'];
let stage = 'ownership';
const dom = {checkout_visible:false,place_order_visible:false,shopper_pause_visible:false};
function write() { const temp = receiptPath + '.tmp'; fs.writeFileSync(temp, JSON.stringify(report, null, 2) + '\n', {mode:0o600}); fs.renameSync(temp, receiptPath); }
function check(id, condition, evidence) { report.cases.push({id, status: condition ? 'PASS' : 'FAIL', evidence}); write(); if (!condition) throw new Error('Blocks browser qualification assertion failed: ' + id); }
function owned(url) { return new URL(url, base).origin === base.origin; }
function isCheckoutPost(url, method, nativeUrl, origin) {
  if (method !== 'POST') return false;
  try {
    const observed = new URL(url, origin); const native = new URL(nativeUrl, origin);
    if (observed.origin !== origin || native.origin !== origin || observed.username || observed.password || native.username || native.password || observed.hash || native.hash) return false;
    const route = '/wc/store/v1/checkout';
    const normalize = value => value.endsWith('/') ? value.slice(0, -1) : value;
    const nativeQueries = native.searchParams.getAll('rest_route'); const observedQueries = observed.searchParams.getAll('rest_route');
    if (nativeQueries.length > 1 || observedQueries.length > 1) return false;
    if (nativeQueries.length === 1) return observedQueries.length === 1 && normalize(nativeQueries[0]) === route && observed.pathname === native.pathname && normalize(observedQueries[0]) === route;
    return normalize(native.pathname).endsWith(route) && observedQueries.length === 0 && normalize(observed.pathname) === normalize(native.pathname);
  } catch (_) { return false; }
}

(async () => {
  const modulePath = process.env.CETECH_DE_EMERGENCY_PLAYWRIGHT_MODULE;
  if (!modulePath || !path.isAbsolute(modulePath)) throw new Error('Pinned disposable Playwright module was not supplied');
  const {chromium} = require(modulePath);
  const browser = await chromium.launch({headless:true});
  report.runtime.chromium = browser.version();
  const context = await browser.newContext({baseURL:base.origin, userAgent:'CETECH-C07-Blocks-Qualification/1'});
  context.setDefaultTimeout(20000);
  const page = await context.newPage();
  try {
    await page.route('**/*', route => owned(route.request().url()) ? route.continue() : route.abort());
    const probe = await context.request.get('/?cetech_opening_http_probe=1', {headers:{'X-CETECH-Opening-Probe':state.probe_token}, timeout:20000});
    const identity = await probe.json();
    if (probe.status() !== 200 || ['source_head','candidate_head','source_tree'].some(key => identity[key] !== state.identity[key]) || identity.probe_sha256 !== crypto.createHash('sha256').update(state.probe_token).digest('hex') || identity.site_path_sha256 !== crypto.createHash('sha256').update(state.site_path).digest('hex') || identity.database_name_sha256 !== crypto.createHash('sha256').update(state.database_name).digest('hex')) throw new Error('Browser listener ownership was not confirmed');
    stage = 'login';
    await page.goto('/wp-login.php', {waitUntil:'domcontentloaded'});
    await page.locator('#user_login').fill(state.username);
    await page.locator('#user_pass').fill(state.password);
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}), page.locator('#wp-submit').click()]);
    stage = 'fixture';
    const headers = {'X-CETECH-C07-FIXTURE':state.fixture_token};
    let current = await context.request.get('/?cetech_c07_fixture=inspect', {headers,timeout:20000});
    let data = await current.json();
    if (!data.success || ['source_head','candidate_head','source_tree','installed_php_sources_hash'].some(key => data.data.source_identity[key] !== state.identity[key])) throw new Error('Browser fixture principal or actual installed map was not authorized');
    stage = 'cart';
    const seeded = await context.request.post('/?cetech_c07_fixture=seed', {headers, form:{nonce:data.data.nonce,scenario:'pickup'},timeout:20000});
    data = await seeded.json();
    if (seeded.status() !== 200 || !data.success || Number(data.data.total) !== 0) throw new Error('Browser free-pickup fixture was not prepared');
    stage = 'render';
    await page.goto(state.blocks_url, {waitUntil:'domcontentloaded'});
    const button = page.locator('.wc-block-components-checkout-place-order-button');
    await button.waitFor({state:'visible'});
    await page.locator('.wc-block-checkout').waitFor({state:'visible'});
    dom.checkout_visible = true; dom.place_order_visible = true; stage = 'form';
    // Fill the actual visible native form where the saved Woo customer has not
    // supplied a field. This does not fabricate a Store API submission.
    for (const [selector,value] of [['#email','c07-shopper@example.invalid'],['#billing-first_name','Synthetic'],['#billing-last_name','Shopper'],['#billing-address_1','PRIVATE-C07-SYNTHETIC-ADDRESS'],['#billing-city','Accra'],['#billing-postcode','00001'],['#billing-phone','0200000000']]) {
      const field = page.locator(selector); if (await field.count() && await field.isVisible()) await field.fill(value);
    }
    const rendered = await page.locator('.wc-block-checkout').innerText();
    check('HTTP-C07-BLOCKS-REAL-CHROMIUM-UI-RENDERED', await button.isVisible() && rendered.includes('C07') && Number(data.data.total) === 0,
      {real_chromium:true,actual_woocommerce_blocks_ui:true,synthetic_cart_preparation:'native fixture existing pickup choice',free_pickup:true,chromium:report.runtime.chromium});
    stage = 'pause';
    current = await context.request.get('/?cetech_c07_fixture=inspect', {headers,timeout:20000}); data = await current.json(); const gatewayBefore = data.data.gateway_count;
    const paused = await context.request.post('/?cetech_c07_fixture=pause', {headers,form:{nonce:data.data.nonce},timeout:20000});
    if (paused.status() !== 200 || !(await paused.json()).success) throw new Error('Browser after-render control transition failed');
    stage = 'submit';
    const requestObserved = page.waitForResponse(response => isCheckoutPost(response.url(), response.request().method(), state.store_checkout_url, base.origin), {timeout:20000});
    await button.click();
    const checkout = await requestObserved;
    const value = await checkout.json();
    stage = 'refusal';
    await page.getByText('Delivery and pickup checkouts are temporarily paused.', {exact:false}).first().waitFor({state:'visible'});
    dom.shopper_pause_visible = true;
    const observed = await context.request.get('/?cetech_c07_fixture=inspect', {headers,timeout:20000}); const after = await observed.json();
    check('HTTP-C07-BLOCKS-AFTER-RENDER-PAUSE-UI-SUBMIT-REFUSED', checkout.status() === 409 && value.code === 'cetech_de_checkout_control' && value.message.includes('temporarily paused') && value.message.includes('Reference:') && after.success && after.data.gateway_count === gatewayBefore,
      {actual_ui_button_clicked:true,actual_store_api_post:true,status:checkout.status(),code:value.code,shopper_pause_visible:true,provisional_update_is_not_admission:true,gateway_count_unchanged:true});
    stage = 'complete'; report.status = 'PASS'; report.stage = stage; write();
  } catch (error) {
    // These selectors expose presence only. Never retain HTML, field values,
    // error text, screenshots, cookies or nonce-bearing URLs in diagnostics.
    try { dom.checkout_visible = await page.locator('.wc-block-checkout').isVisible(); dom.place_order_visible = await page.locator('.wc-block-components-checkout-place-order-button').isVisible(); dom.shopper_pause_visible = await page.getByText('Delivery and pickup checkouts are temporarily paused.', {exact:false}).first().isVisible(); } catch (_) {}
    throw error;
  } finally { await context.close(); await browser.close(); }
})().catch(error => {
  report.status='FAIL'; report.stage=stage; report.error_class=error instanceof SyntaxError ? 'SyntaxError' : error instanceof TypeError ? 'TypeError' : error.constructor.name === 'TimeoutError' ? 'TimeoutError' : 'Error';
  const next = caseIds.find(id => !report.cases.some(item => item.id === id));
  if (next && !report.cases.some(item => item.status === 'FAIL')) report.cases.push({id:next,status:'FAIL',evidence:{stage,error_class:report.error_class,dom:{...dom},required_case_incomplete:true}});
  const existingFailure = report.cases.find(item => item.status === 'FAIL');
  if (existingFailure) Object.assign(existingFailure.evidence, {stage,error_class:report.error_class,dom:{...dom}});
  write(); process.stderr.write('C07 browser failure; inspect private bounded receipt.\n'); process.exitCode=1;
});
