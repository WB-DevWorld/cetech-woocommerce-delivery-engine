import { afterEach, describe, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const source = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../assets/frontend/delivery-quote-review.js'), 'utf8');
const uuid = 'cf29a426-b733-4b7a-9e13-dbf83b014a07';
let dom;
const publicView = () => ({ format_version: 1, service_label: 'Standard', state: 'relative_window', display_timezone: 'Africa/Accra', reason_codes: [], relative_explanation: 'after_payment_confirmation', min: 1, max: 2, unit: 'business_days', known_zero: false });
function facts() {
	const money = { amount: '0.00', currency: 'GHS', precision: 2 };
	return { contract_version: 1, status: 'review_required', generation: 1, quote: { contract_version: 1, decision_kind: 'delivery_quote', quote_id: uuid, status: 'issued', currently_applicable: true, expires_at: '2099-10-07T11:05:00.000000Z', customer_label: 'Standard', money: [{ customer_label: 'Standard', list_price: money, promotion: { state: 'none', amount: money }, final_price: money, tax: money, rounded_tax: money, total: money, display_total: money }], reason_code: null, recovery_action: null, correlation_id: uuid, promise: { contract_version: 1, original: true, groups: [{ views: [publicView()], customer_text: 'Standard: 1–2 business days after payment confirmation (Africa/Accra)' }] } }, can_refresh: true, can_confirm: true, can_retry: false, message_code: 'review_required', correlation_id: uuid };
}
function load(state, setup = () => {}) {
	dom = new JSDOM('<div class="wc-block-components-sidebar"></div>', { url: 'https://shop.example.invalid/checkout', runScripts: 'outside-only' });
	dom.window.wp = { data: { select: () => ({ getCartData: () => ({ extensions: { 'cetech-delivery-quote-review': state } }) }), subscribe: () => {} } }; setup(dom.window); dom.window.eval(source); return dom.window.CetechDeQuoteReview;
}
afterEach(() => { dom?.window.close(); vi.restoreAllMocks(); });
describe('P05 promise presentation stays closed, original and read only', () => {
	it('Classic and actual Blocks mount render exact captured text with no acceptance or network side effect', () => {
		const state = facts(); const update = vi.fn(); const fetch = vi.fn(); const api = load(state, window => { window.wc = { blocksCheckout: { extensionCartUpdate: update } }; window.fetch = fetch; });
		const classic = dom.window.document.createElement('div'); expect(api.render(classic, state)).toBe(true); api.renderBlocks();
		const blocks = dom.window.document.getElementById('cetech-de-quote-review-blocks'); const selector = '[data-cetech-de-original-promise="1"]';
		expect(blocks.querySelector(selector).textContent).toBe(state.quote.promise.groups[0].customer_text); expect(classic.querySelector(selector).textContent).toBe(blocks.querySelector(selector).textContent); expect(update).not.toHaveBeenCalled(); expect(fetch).not.toHaveBeenCalled();
		expect(api.command('confirm', state)).toEqual({ action: 'confirm', generation: 1 });
	});
	it('a later UI locale changes controls while preserving original promise text', () => {
		const state = facts(); const api = load(state, window => { window.cetechDeQuoteReview = { i18n: { originalPromise: 'Délai de livraison initial', confirm: 'Confirmer le prix', messages: { review_required: 'Vérifiez la livraison.' } } }; });
		const mount = dom.window.document.createElement('div'); api.render(mount, state); expect(mount.textContent).toContain('Vérifiez la livraison.'); expect(mount.textContent).toContain('Confirmer le prix'); expect(mount.querySelector('.cetech-de-promise-original').getAttribute('aria-label')).toBe('Délai de livraison initial'); expect(mount.querySelector('[data-cetech-de-original-promise="1"]').textContent).toBe(state.quote.promise.groups[0].customer_text);
	});
	it.each([
		['private policy', state => { state.quote.promise.groups[0].views[0].policy_id = 'PRIVATE'; }],
		['private group identity', state => { state.quote.promise.groups[0].component_key = 'PRIVATE'; }],
		['private packet', state => { state.quote.promise.groups[0].input_json = 'PRIVATE'; }],
		['unknown state', state => { state.quote.promise.groups[0].views[0].state = 'fake_winning'; }],
		['HTML captured text', state => { state.quote.promise.groups[0].customer_text = '<img src=x onerror=alert(1)>'; }],
		['negative relative value', state => { state.quote.promise.groups[0].views[0].min = -1; }],
		['wrong zero', state => { state.quote.promise.groups[0].views[0].known_zero = true; }],
		['unknown anchor', state => { state.quote.promise.groups[0].views[0].relative_explanation = 'checkout_now'; }],
		['private refusal', state => { state.quote.promise.groups[0].views[0] = { format_version: 1, service_label: 'Standard', state: 'unavailable', display_timezone: 'UTC', reason_codes: ['PRIVATE-CREDENTIAL'] }; }],
		['unbounded view', state => { state.quote.promise.groups[0].views = Array(17).fill(publicView()); }],
		['unbounded groups', state => { state.quote.promise.groups = Array(201).fill(state.quote.promise.groups[0]); }],
	])('refuses %s before displaying or making any command', (_, mutate) => {
		const state = facts(); mutate(state); const api = load(state); const mount = dom.window.document.createElement('div'); expect(api.validFacts(state)).toBe(false); expect(api.render(mount, state)).toBe(false); expect(mount.textContent).toBe(''); expect(() => api.command('confirm', state)).toThrow('Quote review unavailable.');
	});
});
