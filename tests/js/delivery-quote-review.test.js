import { afterEach, describe, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const source = readFileSync(resolve(root, 'assets/frontend/delivery-quote-review.js'), 'utf8');
const uuid = 'cf29a426-b733-4b7a-9e13-dbf83b014a07';
const money = (amount) => ({ amount, currency: 'GHS', precision: 2 });
let dom;

function facts(status = 'review_required', extras = {}) {
	return {
		contract_version: 1, status, generation: 3,
		quote: { contract_version: 1, decision_kind: 'delivery_quote', quote_id: uuid,
			status: status === 'confirmed' ? 'accepted' : 'issued', currently_applicable: true,
			expires_at: '2099-10-07T11:05:00.000000Z', customer_label: 'Delivery',
			money: [{ customer_label: 'Standard delivery', list_price: money('7.00'), promotion: { state: 'none', amount: money('0.00') }, final_price: money('7.00'), tax: money('0.00'), rounded_tax: money('0.00'), total: money('7.00'), display_total: money('7.00') }],
			reason_code: null, recovery_action: null, correlation_id: uuid },
		can_refresh: true, can_confirm: status === 'review_required', can_retry: status === 'unconfirmed', message_code: status, correlation_id: uuid,
		...extras
	};
}
function load(html = '<div class="wc-block-components-sidebar"></div>', setup = () => {}) {
	dom = new JSDOM(html, { url: 'https://shop.example.invalid/checkout', runScripts: 'outside-only' });
	setup(dom.window); dom.window.eval(source); return dom.window.CetechDeQuoteReview;
}
function classic(api, state = facts()) {
	const mount = dom.window.document.createElement('div'); mount.className = 'cetech-de-quote-review'; mount.dataset.quoteReviewTransport = 'classic'; dom.window.document.body.appendChild(mount); api.render(mount, state); return mount;
}
afterEach(() => { dom?.window.close(); vi.restoreAllMocks(); });

describe('Delivery quote review uses explicit native transports', () => {
	it('mounts actual Blocks summary without issuing or confirming on data subscriptions', () => {
		const update = vi.fn(); let subscriber; const state = facts();
		const api = load(undefined, (window) => {
			window.wp = { data: { select: () => ({ getCartData: () => ({ extensions: { 'cetech-delivery-quote-review': state } }) }), subscribe: (callback) => { subscriber = callback; } } };
			window.wc = { blocksCheckout: { extensionCartUpdate: update } };
		});
		dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
		api.renderBlocks(); subscriber(); subscriber();
		expect(update).not.toHaveBeenCalled();
		const mount = dom.window.document.getElementById('cetech-de-quote-review-blocks');
		expect(mount.textContent).toContain('GHS 7.00'); expect(mount.textContent).toContain('Confirm delivery price');
		expect(mount.querySelectorAll('[type="submit"]')).toHaveLength(0);
	});

	it('native Blocks confirmation sends only explicit action and reviewed generation', async () => {
		const update = vi.fn(() => Promise.resolve()); const api = load(undefined, (window) => { window.wc = { blocksCheckout: { extensionCartUpdate: update } }; });
		const mount = classic(api); mount.dataset.quoteReviewTransport = 'blocks';
		expect(await api.submit(mount, 'confirm')).toBe(true);
		expect(update).toHaveBeenCalledExactlyOnceWith({ namespace: 'cetech-delivery-quote-review', data: { action: 'confirm', generation: 3 } });
	});

	it('Classic refresh uses POST, same-origin cookies and the real route nonce with no private quote fields', async () => {
		const fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, data: facts() }) }));
		const api = load(undefined, (window) => { window.cetechDeQuoteReview = { ajaxUrl: '/?wc-ajax=cetech_delivery_quote_review', nonce: 'route-nonce' }; window.fetch = fetch; });
		const mount = classic(api, facts('no_quote', { quote: null, generation: 0 }));
		expect(await api.submit(mount, 'refresh')).toBe(true);
		const [url, request] = fetch.mock.calls[0]; const fields = new URLSearchParams(request.body);
		expect(url).toBe('/?wc-ajax=cetech_delivery_quote_review'); expect(request.method).toBe('POST'); expect(request.credentials).toBe('same-origin');
		expect([...fields.keys()].sort()).toEqual(['_wpnonce', 'action', 'generation', 'review_token']);
		expect(fields.get('review_token')).toMatch(/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/);
		expect(fields.get('generation')).toBe('0'); expect(fields.get('_wpnonce')).toBe('route-nonce');
	});

	it('an uncertain network response retries the exact original refresh envelope without minting another token', async () => {
		const fetch = vi.fn().mockRejectedValueOnce(new Error('PRIVATE-RAW-CREDENTIAL')).mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ success: true, data: facts() }) });
		const api = load(undefined, (window) => { window.cetechDeQuoteReview = { ajaxUrl: '/review', nonce: 'route-nonce' }; window.fetch = fetch; });
		const mount = classic(api, facts('no_quote', { quote: null, generation: 0 }));
		expect(await api.submit(mount, 'refresh')).toBe(false); const first = fetch.mock.calls[0][1].body;
		expect(mount.textContent).toContain('Retry same request'); expect(mount.textContent).not.toContain('PRIVATE');
		expect(mount.querySelector('[data-quote-review-action="refresh"]')).toBeNull();
		expect(await api.submit(mount, 'transport_retry')).toBe(true); expect(fetch.mock.calls[1][1].body).toBe(first);
		expect(mount.querySelector('[data-quote-review-action="confirm"]')).not.toBeNull();
	});

	it('refresh displays the changed price before a separate confirmation and preserves native fields', async () => {
		const next = facts(); next.generation = 4; next.quote.money[0].total = money('9.00'); next.quote.money[0].display_total = money('9.00');
		const fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, data: next }) }));
		const api = load('<input id="native-choice" value="offer-1"><input id="native-address" value="chosen destination">', (window) => { window.cetechDeQuoteReview = { ajaxUrl: '/review', nonce: 'route-nonce' }; window.fetch = fetch; });
		const mount = classic(api, facts('changed'));
		expect(await api.submit(mount, 'refresh')).toBe(true); expect(fetch).toHaveBeenCalledTimes(1); expect(mount.textContent).toContain('GHS 9.00');
		expect(mount.textContent).toContain('Review the delivery price'); expect(dom.window.document.getElementById('native-choice').value).toBe('offer-1'); expect(dom.window.document.getElementById('native-address').value).toBe('chosen destination');
		expect(api.command('confirm', mount._cetechQuoteFacts)).toEqual({ action: 'confirm', generation: 4 });
	});

	it('the local timer prevents visible expiry confirmation but does not refresh, alter status or perform work', async () => {
		const update = vi.fn(); const api = load(undefined, (window) => { window.wc = { blocksCheckout: { extensionCartUpdate: update } }; });
		const expired = facts(); expired.quote.expires_at = '2000-10-07T11:05:00.000000Z'; const mount = classic(api, expired); mount.dataset.quoteReviewTransport = 'blocks';
		expect(mount.querySelector('[data-quote-review-action="confirm"]').disabled).toBe(true); expect(mount._cetechQuoteFacts.status).toBe('review_required');
		expect(await api.submit(mount, 'confirm')).toBe(false); expect(update).not.toHaveBeenCalled();
	});

	it('confirmed or unavailable views never create an automatic confirmation or native checkout submit', async () => {
		const api = load(); const mount = classic(api, facts('confirmed'));
		expect(mount.querySelector('[data-quote-review-action="confirm"]')).toBeNull(); expect(await api.submit(mount, 'confirm')).toBe(false);
		api.render(mount, facts('unavailable', { quote: null, can_refresh: false, can_retry: false }));
		expect(mount.querySelector('button')).toBeNull(); expect(mount.querySelector('form')).toBeNull();
	});

	it('a pending explicit action prevents a duplicate concurrent call', async () => {
		let resolve; const update = vi.fn(() => new Promise((done) => { resolve = done; }));
		const api = load(undefined, (window) => { window.wc = { blocksCheckout: { extensionCartUpdate: update } }; }); const mount = classic(api); mount.dataset.quoteReviewTransport = 'blocks';
		const first = api.submit(mount, 'confirm'); expect(await api.submit(mount, 'confirm')).toBe(false); expect(update).toHaveBeenCalledTimes(1); resolve(); expect(await first).toBe(true);
	});
});

describe('Quote review projection and DOM stay finite and detached', () => {
	it.each([
		['renamed private root', (view) => { view.customer_context = { owner: 'PRIVATE-OWNER' }; }],
		['private quote field', (view) => { view.quote.acceptance_handle = 'PRIVATE-HANDLE'; }],
		['private nested money', (view) => { view.quote.money[0].total.internal_cost = 'PRIVATE-COST'; }],
		['private nested promotion', (view) => { view.quote.money[0].promotion.receipt = ['PRIVATE-RATE']; }],
		['private list', (view) => { view.quote.money.push({ unknown: ['PRIVATE-ADDRESS'] }); }],
		['preencoded nested data', (view) => { view.quote.money = '{"owner":"PRIVATE-OWNER"}'; }],
		['html label', (view) => { view.quote.customer_label = '<script>PRIVATE</script>'; }],
		['unbounded list', (view) => { view.quote.money = Array(201).fill(view.quote.money[0]); }],
		['foreign contract', (view) => { view.contract_version = 2; }],
		['noninteger generation', (view) => { view.generation = '3'; }],
		['unsafe large generation', (view) => { view.generation = Number.MAX_SAFE_INTEGER + 1; }],
		['unknown status', (view) => { view.status = 'PRIVATE-REASON'; }]
	])('refuses %s before rendering or constructing a command', (_name, corrupt) => {
		const api = load(); const view = facts(); corrupt(view); const mount = dom.window.document.createElement('div');
		expect(api.validFacts(view)).toBe(false); expect(api.render(mount, view)).toBe(false); expect(mount.textContent).toBe(''); expect(() => api.command('refresh', view)).toThrow('Quote review unavailable.');
	});

	it('does not read private source arrays when rendering a valid detached shopper projection', () => {
		const api = load(); const view = facts(); const mount = classic(api, view);
		expect(mount.textContent).toContain('Standard delivery'); expect(mount.innerHTML).not.toMatch(/acceptance_handle|body_digest|owner_digest|supplier|native_money_digest|review_token|PRIVATE/);
		expect(mount.querySelector('time').dateTime).toBe(view.quote.expires_at); expect(mount.querySelector('[data-quote-review-action="confirm"]').type).toBe('button');
	});
});
