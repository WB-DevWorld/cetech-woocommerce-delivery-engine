/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const scriptSource = readFileSync(
	resolve(root, 'assets/frontend/product-delivery-selector.js'),
	'utf8'
);

function loadSelector() {
	delete window.CetechDeProductDeliverySelector;
	eval(scriptSource);
	return window.CetechDeProductDeliverySelector;
}

function fixtureHtml() {
	return `
		<form>
			<fieldset class="cetech-de-product-delivery-selector" data-cetech-de-selector="1">
				<div class="cetech-de-fulfilment-choice" role="radiogroup">
					<label><input type="radio" name="cetech_de_fulfilment_ui" value="delivery" data-cetech-de-choice-switch="1" checked /> Delivery</label>
					<label><input type="radio" name="cetech_de_fulfilment_ui" value="store_pickup" data-cetech-de-choice-switch="1" /> Store pickup</label>
				</div>
				<div class="cetech-de-delivery-option-group" data-cetech-de-choice-panel="delivery">
					<p class="cetech-de-delivery-option cetech-de-delivery-option--radio">
						<label>
							<input type="radio" name="cetech_de_delivery_option_key" value="in_store:delivery:11" required checked />
							<span class="cetech-de-delivery-option__body">
								<span class="cetech-de-delivery-option__label">Standard Delivery</span>
								<span class="cetech-de-delivery-option__estimate">Estimated delivery: 2–4 business days</span>
							</span>
						</label>
					</p>
				</div>
				<div class="cetech-de-delivery-option-group cetech-de-delivery-option-group--pickup" data-cetech-de-choice-panel="store_pickup" hidden>
					<p class="cetech-de-delivery-option cetech-de-delivery-option--radio">
						<label>
							<input type="radio" name="cetech_de_delivery_option_key" value="in_store:store_pickup:pickup" disabled />
							<span class="cetech-de-delivery-option__body">
								<span class="cetech-de-delivery-option__label">Store pickup</span>
							</span>
						</label>
					</p>
					<div class="cetech-de-pickup-details">
						<p>Pickup location Main showroom</p>
					</div>
				</div>
			</fieldset>
		</form>
	`;
}

describe('Product delivery fulfilment switcher', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
		delete window.CetechDeProductDeliverySelector;
	});

	it('defaults to Delivery with Store Pickup hidden', () => {
		document.body.innerHTML = fixtureHtml();
		const api = loadSelector();
		api.bindAll(document);

		const deliveryPanel = document.querySelector('[data-cetech-de-choice-panel="delivery"]');
		const pickupPanel = document.querySelector('[data-cetech-de-choice-panel="store_pickup"]');
		const deliveryRadio = document.querySelector('input[value="in_store:delivery:11"]');
		const pickupRadio = document.querySelector('input[value="in_store:store_pickup:pickup"]');

		expect(deliveryPanel.hidden).toBe(false);
		expect(pickupPanel.hidden).toBe(true);
		expect(deliveryRadio.checked).toBe(true);
		expect(deliveryRadio.disabled).toBe(false);
		expect(pickupRadio.disabled).toBe(true);
		expect(pickupRadio.checked).toBe(false);
		expect(document.body.textContent).toContain('Estimated delivery');
	});

	it('switching to Store Pickup hides delivery rate and ETA', () => {
		document.body.innerHTML = fixtureHtml();
		const api = loadSelector();
		api.bindAll(document);

		const pickupSwitch = document.querySelector('input[data-cetech-de-choice-switch][value="store_pickup"]');
		pickupSwitch.checked = true;
		pickupSwitch.dispatchEvent(new Event('change', { bubbles: true }));

		const deliveryPanel = document.querySelector('[data-cetech-de-choice-panel="delivery"]');
		const pickupPanel = document.querySelector('[data-cetech-de-choice-panel="store_pickup"]');
		const deliveryRadio = document.querySelector('input[value="in_store:delivery:11"]');
		const pickupRadio = document.querySelector('input[value="in_store:store_pickup:pickup"]');

		expect(deliveryPanel.hidden).toBe(true);
		expect(pickupPanel.hidden).toBe(false);
		expect(deliveryRadio.checked).toBe(false);
		expect(deliveryRadio.disabled).toBe(true);
		expect(pickupRadio.checked).toBe(true);
		expect(pickupRadio.disabled).toBe(false);
		expect(pickupPanel.textContent).toContain('Main showroom');
		expect(pickupPanel.querySelector('.cetech-de-delivery-option__estimate')).toBeNull();
	});

	it('switching back to Delivery restores the delivery option', () => {
		document.body.innerHTML = fixtureHtml();
		const api = loadSelector();
		api.bindAll(document);

		const pickupSwitch = document.querySelector('input[data-cetech-de-choice-switch][value="store_pickup"]');
		const deliverySwitch = document.querySelector('input[data-cetech-de-choice-switch][value="delivery"]');

		pickupSwitch.checked = true;
		pickupSwitch.dispatchEvent(new Event('change', { bubbles: true }));
		deliverySwitch.checked = true;
		deliverySwitch.dispatchEvent(new Event('change', { bubbles: true }));

		const deliveryPanel = document.querySelector('[data-cetech-de-choice-panel="delivery"]');
		const pickupPanel = document.querySelector('[data-cetech-de-choice-panel="store_pickup"]');
		const deliveryRadio = document.querySelector('input[value="in_store:delivery:11"]');
		const pickupRadio = document.querySelector('input[value="in_store:store_pickup:pickup"]');

		expect(deliveryPanel.hidden).toBe(false);
		expect(pickupPanel.hidden).toBe(true);
		expect(deliveryRadio.checked).toBe(true);
		expect(deliveryRadio.disabled).toBe(false);
		expect(pickupRadio.checked).toBe(false);
		expect(pickupRadio.disabled).toBe(true);
		expect(deliveryPanel.textContent).toContain('2–4 business days');
	});

	it('writes one authoritative PDP payload from the selector', () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div data-cetech-de-matching-location="1">
						<select name="cetech_de_matching_country"><option value="GH" selected>Ghana</option></select>
						<select name="cetech_de_matching_state"><option value="AH" selected>Ashanti</option></select>
						<input name="cetech_de_matching_city" value="Kumasi" />
						<input name="cetech_de_matching_postcode" value="AK-000" />
					</div>
					<input type="radio" name="cetech_de_delivery_option_key" value="in_warehouse:delivery:10" checked />
					<input type="hidden" name="cetech_de_pdp_context" data-cetech-de-pdp-context="1" value="" />
				</fieldset>
			</form>
		`;
		const api = loadSelector();
		api.bindAll(document);
		const payload = JSON.parse(document.querySelector('[data-cetech-de-pdp-context]').value);
		expect(payload.matching_location.city).toBe('Kumasi');
		expect(payload.matching_location.country).toBe('GH');
		expect(payload.matching_location.canonical_location_key).toBe('');
		expect(payload.display_key).toBe('in_warehouse:delivery:10');
		const extensions = api.storeApiExtensions();
		expect(extensions.delivery_option_key).toBe('in_warehouse:delivery:10');
		expect(extensions.matching_location.city).toBe('Kumasi');
	});

	it('uses server estimate_line and does not double-prefix', () => {
		const api = loadSelector();
		expect(api.formatEstimateLine(
			{ fulfilment_choice: 'delivery', estimate_text: 'Estimated 3–5 business days', estimate_line: 'Estimated delivery: 3–5 business days' },
			{ i18n: { estimated: 'Estimated delivery' } }
		)).toBe('3–5 business days');
		expect(api.formatEstimateLine(
			{ fulfilment_choice: 'delivery', estimate_text: 'Estimated 3–5 business days' },
			{ i18n: { estimated: 'Estimated delivery' } }
		)).toBe('3–5 business days');
	});

	it('formats server price_text and pickup Free without inventing zero', () => {
		const api = loadSelector();
		expect(api.formatPriceText(
			{ price_text: 'GHS 25.00', fulfilment_choice: 'delivery' },
			{ i18n: { free: 'Free', deliveryFee: 'Delivery fee' } }
		)).toBe('Delivery fee: GHS 25.00');
		expect(api.formatPriceText(
			{ fulfilment_choice: 'store_pickup' },
			{ i18n: { free: 'Free' } }
		)).toBe('Free');
		expect(api.formatPriceText(
			{ fulfilment_choice: 'delivery' },
			{ i18n: { free: 'Free' } }
		)).toBe('');
	});

	it('renders delivery cards as name, ETA, then labelled fee', () => {
		const api = loadSelector();
		const html = api.renderOptionsHtml(
			[{
				display_key: 'in_store:delivery:11',
				fulfilment_choice: 'delivery',
				delivery_offer_public_label: 'Standard Delivery',
				estimate_text: '1–3 business days',
				estimate_line: '1–3 business days',
				price_text: 'GH₵50.00',
				is_available: true
			}],
			'in_store:delivery:11',
			{ i18n: { deliveryFee: 'Delivery fee', delivery: 'Delivery', storePickup: 'Store Pickup' } },
			'Accra',
			'delivery',
			{ has_delivery: true, has_pickup: false }
		);
		const priceAt = html.indexOf('Delivery fee: GH₵50.00');
		const etaAt = html.indexOf('1–3 business days');
		const nameAt = html.indexOf('Standard Delivery');
		expect(nameAt).toBeGreaterThan(-1);
		expect(etaAt).toBeGreaterThan(nameAt);
		expect(priceAt).toBeGreaterThan(etaAt);
	});

	it('reads quantity from the cart form', () => {
		document.body.innerHTML = `
			<form class="cart">
				<input type="number" name="quantity" class="qty" value="4" />
				<div data-cetech-de-selector="1"></div>
			</form>
		`;
		const api = loadSelector();
		const root = document.querySelector('[data-cetech-de-selector]');
		expect(api.productQuantity(root)).toBe(4);
	});

	it('keeps Delivery/Pickup switch from capabilities before priced delivery cards exist', () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1" data-cetech-de-has-delivery="1" data-cetech-de-has-pickup="1">
					<div data-cetech-de-location-panel="1" hidden></div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		const api = loadSelector();
		const html = api.renderOptionsHtml(
			[{
				display_key: 'in_store:store_pickup:pickup',
				fulfilment_choice: 'store_pickup',
				is_available: true,
				delivery_offer_public_label: 'QA Accra Pickup',
				price_text: 'Free',
			}],
			'',
			{ i18n: { delivery: 'Delivery', storePickup: 'Store Pickup' }, postField: 'cetech_de_delivery_option_key' },
			'',
			'store_pickup',
			{ has_delivery: true, has_pickup: true }
		);
		document.querySelector('[data-cetech-de-options]').innerHTML = html;
		api.bindAll(document);

		expect(document.querySelectorAll('[data-cetech-de-choice-switch]').length).toBe(2);
		expect(document.querySelector('[data-cetech-de-choice-panel="delivery"]')).not.toBeNull();
		expect(document.querySelector('[data-cetech-de-choice-panel="delivery"] input[name="cetech_de_delivery_option_key"]')).toBeNull();
		expect(document.querySelector('[data-cetech-de-choice-switch][value="store_pickup"]').checked).toBe(true);

		const deliverySwitch = document.querySelector('[data-cetech-de-choice-switch][value="delivery"]');
		deliverySwitch.checked = true;
		deliverySwitch.dispatchEvent(new Event('change', { bubbles: true }));
		expect(document.querySelector('[data-cetech-de-location-panel]').hidden).toBe(false);
		expect(document.querySelector('[data-cetech-de-choice-panel="delivery"]').hidden).toBe(false);
	});

	it('omits priced delivery cards that have no estimate from AJAX HTML', () => {
		const api = loadSelector();
		const html = api.renderOptionsHtml(
			[
				{
					display_key: 'in_store:delivery:2',
					fulfilment_choice: 'delivery',
					is_available: true,
					delivery_offer_public_label: 'Same Day Delivery',
					price_text: '₵10.00',
				},
				{
					display_key: 'in_store:delivery:1',
					fulfilment_choice: 'delivery',
					is_available: true,
					delivery_offer_public_label: 'Standard Delivery',
					estimate_text: '2–3 business days',
					price_text: '₵12.00',
				},
			],
			'',
			{ i18n: { delivery: 'Delivery' }, postField: 'cetech_de_delivery_option_key' },
			'Accra',
			'delivery',
			{ has_delivery: true, has_pickup: false }
		);
		expect(html).toContain('Standard Delivery');
		expect(html).toContain('2–3 business days');
		expect(html).not.toContain('Same Day Delivery');
		expect(html).not.toContain('cetech-de-matching-location');
	});

	it('quantity change before location posts an empty country', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<input class="qty" name="quantity" value="1" />
				<fieldset class="cetech-de-product-delivery-selector" data-cetech-de-selector="1" data-product-id="16">
					<div data-cetech-de-location-panel="1">
						<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
							<select name="cetech_de_matching_country"><option value="">Select…</option><option value="GH">Ghana</option></select>
							<select name="cetech_de_matching_state"><option value=""></option></select>
							<input name="cetech_de_matching_city" value="" />
							<input name="cetech_de_matching_postcode" value="" />
						</div>
					</div>
					<div data-cetech-de-status></div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/admin-ajax.php',
			action: 'cetech_de_matching_location_options',
			nonce: 'n',
			productId: 16,
			i18n: { loading: 'Loading' },
		};
		window.fetch = async (_url, init) => {
			window.__cetechLastBody = String(init.body || '');
			return {
				json: async () => ({
					success: true,
					data: { status: 'need_location', message: '', options: [], has_delivery: true, has_pickup: true },
				}),
			};
		};
		const api = loadSelector();
		api.bindAll(document);
		const qty = document.querySelector('input.qty');
		qty.value = '3';
		qty.dispatchEvent(new Event('change', { bubbles: true }));
		await new Promise((r) => setTimeout(r, 350));
		expect(window.__cetechLastBody).toContain('quantity=3');
		expect(window.__cetechLastBody).not.toContain('country=GH');
		expect(document.querySelector('[data-cetech-de-status]').textContent).not.toContain('not available');
	});

	it('progressively reveals region then locality and resets children on ancestor change', () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<p data-cetech-de-field="country"><select name="cetech_de_matching_country"><option value="">Select…</option><option value="GH">Ghana</option></select></p>
						<p data-cetech-de-reveal="region" hidden><select name="cetech_de_matching_state"><option value="">Select…</option><option value="AA" data-location-key="loc-ga">Greater Accra</option></select></p>
						<p data-cetech-de-reveal="locality" hidden>
							<input name="cetech_de_matching_city" value="Accra" />
							<ul class="cetech-de-locality-results" hidden></ul>
						</p>
						<p data-cetech-de-reveal="postcode" hidden><input name="cetech_de_matching_postcode" value="GA-123" /></p>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="loc-accra" />
					</div>
					<div data-cetech-de-options><p>Standard Delivery</p></div>
					<input type="hidden" data-cetech-de-pdp-context="1" name="cetech_de_pdp_context" value="" />
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = { ajaxUrl: '', geography: {} };
		const api = loadSelector();
		api.bindAll(document);
		const country = document.querySelector('[name="cetech_de_matching_country"]');
		country.value = 'GH';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		expect(document.querySelector('[data-cetech-de-reveal="region"]').hidden).toBe(false);
		expect(document.querySelector('[data-cetech-de-reveal="locality"]').hidden).toBe(true);
		expect(document.querySelector('[name="cetech_de_matching_city"]').value).toBe('');
		expect(document.querySelector('[name="cetech_de_matching_location_key"]').value).toBe('');
		expect(document.querySelector('[data-cetech-de-options]').innerHTML).toBe('');

		const region = document.querySelector('[name="cetech_de_matching_state"]');
		region.value = 'AA';
		region.dispatchEvent(new Event('change', { bubbles: true }));
		expect(document.querySelector('[data-cetech-de-reveal="locality"]').hidden).toBe(false);
		expect(document.querySelector('[name="cetech_de_matching_postcode"]').value).toBe('');
	});

	it('selecting a locality requests delivery options with the canonical key', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1" data-product-id="16">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<select name="cetech_de_matching_country"><option value="GH" selected>Ghana</option></select>
						<select name="cetech_de_matching_state"><option value="AA" selected data-location-key="loc-ga">Greater Accra</option></select>
						<input name="cetech_de_matching_city" value="" />
						<ul class="cetech-de-locality-results" hidden></ul>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="" />
					</div>
					<div data-cetech-de-status></div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		const bodies = [];
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/admin-ajax.php',
			action: 'cetech_de_matching_location_options',
			nonce: 'n',
			productId: 16,
			geography: {
				searchAction: 'cetech_de_geography_locality_search',
				searchNonce: 's',
				postcodeAction: 'cetech_de_geography_postcode_relevance',
				postcodeNonce: 'p'
			}
		};
		window.fetch = async (_url, init) => {
			const body = String(init && init.body ? init.body : '');
			bodies.push(body);
			if (body.includes('cetech_de_geography_locality_search')) {
				return {
					json: async () => ({
						success: true,
						data: { items: [{ key: 'loc-accra', name: 'Accra' }], has_more: false, request_token: '1' }
					})
				};
			}
			if (body.includes('cetech_de_geography_postcode_relevance')) {
				return { json: async () => ({ success: true, data: { visible: false } }) };
			}
			return {
				json: async () => ({
					success: true,
					data: { status: 'ok', message: '', options: [], has_delivery: true, has_pickup: false }
				})
			};
		};
		const api = loadSelector();
		api.bindAll(document);
		const city = document.querySelector('[name="cetech_de_matching_city"]');
		city.value = 'Acc';
		city.dispatchEvent(new Event('input', { bubbles: true }));
		await new Promise((r) => setTimeout(r, 350));
		const option = document.querySelector('.cetech-de-locality-results [role="option"]');
		expect(option).toBeTruthy();
		option.click();
		await new Promise((r) => setTimeout(r, 50));
		expect(document.querySelector('[name="cetech_de_matching_city"]').value).toBe('Accra');
		expect(document.querySelector('[name="cetech_de_matching_location_key"]').value).toBe('loc-accra');
		const optionsRequest = bodies.find((body) => body.includes('cetech_de_matching_location_options') && body.includes('location_key=loc-accra'));
		expect(optionsRequest).toBeTruthy();
		expect(optionsRequest).toContain('city=Accra');
		const postcodeRequest = bodies.find((body) => body.includes('cetech_de_geography_postcode_relevance') && body.includes('parent_key=loc-accra'));
		expect(postcodeRequest).toBeTruthy();
	});

	it('applies the server region label after country change', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<p data-cetech-de-field="country"><select name="cetech_de_matching_country"><option value="">Select…</option><option value="GH">Ghana</option><option value="US">United States</option></select></p>
						<p data-cetech-de-reveal="region" hidden><select name="cetech_de_matching_state"><option value="">Select…</option></select></p>
						<p data-cetech-de-reveal="locality" hidden>
							<input name="cetech_de_matching_city" />
							<ul class="cetech-de-locality-results" hidden></ul>
						</p>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="" />
					</div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			geography: { childrenAction: 'cetech_de_geography_children', childrenNonce: 'n' }
		};
		window.fetch = async () => ({
			json: async () => ({ success: true, data: { items: [{ key: 'loc-ga', name: 'Greater Accra', code: 'AA' }], label: 'Region' } })
		});
		const api = loadSelector();
		api.bindAll(document);
		const country = document.querySelector('[name="cetech_de_matching_country"]');
		country.value = 'GH';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('.cetech-de-admin-label')?.textContent || '').toBe('Region');
		expect(document.querySelector('[name="cetech_de_matching_state"]').getAttribute('aria-label')).toBe('Region');
	});

	it('renders disambiguated locality results and stores the canonical key', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<p data-cetech-de-field="country"><select name="cetech_de_matching_country"><option value="GH" selected>Ghana</option></select></p>
						<p data-cetech-de-reveal="region"><select name="cetech_de_matching_state"><option value="AA" selected data-location-key="loc-ga">Greater Accra</option></select></p>
						<p data-cetech-de-reveal="locality">
							<input name="cetech_de_matching_city" />
							<ul class="cetech-de-locality-results" hidden></ul>
						</p>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="" />
					</div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			geography: { searchAction: 'cetech_de_geography_locality_search', searchNonce: 'n' }
		};
		window.fetch = async () => ({
			json: async () => ({
				success: true,
				data: {
					items: [
						{
							key: 'loc-akwatia',
							name: 'Akwatia',
							label: 'Akwatia — Denkyembour District, Eastern Region'
						}
					]
				}
			})
		});
		const api = loadSelector();
		api.bindAll(document);
		const city = document.querySelector('[name="cetech_de_matching_city"]');
		city.value = 'Akw';
		city.dispatchEvent(new Event('input', { bubbles: true }));
		await new Promise((r) => setTimeout(r, 350));
		const option = document.querySelector('.cetech-de-locality-results [role="option"]');
		expect(option.textContent).toContain('Denkyembour District');
		expect(option.getAttribute('aria-label')).toContain('Eastern Region');
		option.click();
		expect(document.querySelector('[name="cetech_de_matching_city"]').value).toBe('Akwatia');
		expect(document.querySelector('[name="cetech_de_matching_location_key"]').value).toBe('loc-akwatia');
	});

		it('replaces a Woo text region field with a canonical select when ADM1 children exist', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<p data-cetech-de-field="country"><select name="cetech_de_matching_country"><option value="">Select…</option><option value="XX">No Woo States</option></select></p>
						<p data-cetech-de-reveal="region" hidden>
							<label for="billing_state">Region / State</label>
							<input id="billing_state" class="input-text state_select woodmart-state" name="cetech_de_matching_state" type="text" aria-label="Region / State" aria-required="true" autocomplete="address-level1" data-placeholder="State" data-input-classes="state_select" />
						</p>
						<p data-cetech-de-reveal="locality" hidden>
							<input name="cetech_de_matching_city" />
							<ul class="cetech-de-locality-results" hidden></ul>
						</p>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="" />
					</div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			geography: { childrenAction: 'cetech_de_geography_children', childrenNonce: 'n' }
		};
		window.fetch = async () => ({
			json: async () => ({
				success: true,
				data: { items: [{ key: 'loc-xx-adm', name: 'Central District' }], skip_admin: false }
			})
		});
		const api = loadSelector();
		api.bindAll(document);
		const country = document.querySelector('[name="cetech_de_matching_country"]');
		country.value = 'XX';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('[name="cetech_de_matching_state"]')?.tagName || '').toBe('SELECT');
		expect(document.querySelector('[data-cetech-de-reveal="region"]').hidden).toBe(false);
		const region = document.querySelector('[name="cetech_de_matching_state"]');
		expect(region.options.length).toBeGreaterThan(1);
		expect(region.id).toBe('billing_state');
		expect(region.className).toContain('state_select');
		expect(region.className).toContain('woodmart-state');
		expect(region.getAttribute('aria-label')).toBe('Region / State');
		expect(region.getAttribute('aria-required')).toBe('true');
		expect(region.getAttribute('autocomplete')).toBe('address-level1');
		expect(region.getAttribute('data-placeholder')).toBe('State');
		expect(region.getAttribute('data-input-classes')).toBe('state_select');
		expect(document.querySelector('label[for="billing_state"]')).not.toBeNull();
	});

	it('restores the saved Woo region value after converting INPUT to a canonical SELECT', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<p data-cetech-de-field="country"><select name="cetech_de_matching_country"><option value="">Select…</option><option value="XX">No Woo States</option></select></p>
						<p data-cetech-de-reveal="region" hidden>
							<label for="billing_state">Region / State</label>
							<input id="billing_state" class="input-text state_select woodmart-state" name="cetech_de_matching_state" type="text" value="Central District" aria-label="Region / State" aria-required="true" autocomplete="address-level1" data-placeholder="State" data-input-classes="state_select" data-location-key="loc-xx-adm" required="required" />
						</p>
						<p data-cetech-de-reveal="locality" hidden>
							<input name="cetech_de_matching_city" />
							<ul class="cetech-de-locality-results" hidden></ul>
						</p>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="" />
					</div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			geography: { childrenAction: 'cetech_de_geography_children', childrenNonce: 'n' }
		};
		window.fetch = async () => ({
			json: async () => ({
				success: true,
				data: {
					items: [{ key: 'loc-xx-adm', name: 'Central District', code: 'CD' }],
					skip_admin: false
				}
			})
		});
		const api = loadSelector();
		api.bindAll(document);
		expect(document.querySelector('[name="cetech_de_matching_state"]').tagName).toBe('INPUT');
		expect(document.querySelector('select[name="cetech_de_matching_state"]')).toBeNull();
		const country = document.querySelector('[name="cetech_de_matching_country"]');
		country.value = 'XX';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('[name="cetech_de_matching_state"]')?.tagName || '').toBe('SELECT');
		const region = document.querySelector('[name="cetech_de_matching_state"]');
		expect(region.value).toBe('CD');
		expect(region.options[region.selectedIndex].textContent).toBe('Central District');
		expect(region.options[region.selectedIndex].getAttribute('data-location-key')).toBe('loc-xx-adm');
		expect(region.id).toBe('billing_state');
		expect(region.className).toContain('state_select');
		expect(region.getAttribute('aria-label')).toBe('Region / State');
		expect(region.required).toBe(true);
		expect(document.querySelector('label[for="billing_state"]')).not.toBeNull();
	});

	it('skips the administrative step when the country has no ADM1 children', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<p data-cetech-de-field="country"><select name="cetech_de_matching_country"><option value="">Select…</option><option value="SG">Singapore</option></select></p>
						<p data-cetech-de-reveal="region" hidden>
							<select name="cetech_de_matching_state"><option value="">Select…</option></select>
						</p>
						<p data-cetech-de-reveal="locality" hidden>
							<input name="cetech_de_matching_city" />
							<ul class="cetech-de-locality-results" hidden></ul>
						</p>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="" />
					</div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			geography: { childrenAction: 'cetech_de_geography_children', childrenNonce: 'n' }
		};
		window.fetch = async () => ({
			json: async () => ({ success: true, data: { items: [], skip_admin: true } })
		});
		const api = loadSelector();
		api.bindAll(document);
		const country = document.querySelector('[name="cetech_de_matching_country"]');
		country.value = 'SG';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('[data-cetech-de-reveal="locality"]')?.hidden === false).toBe(true);
		expect(document.querySelector('[data-cetech-de-reveal="region"]').hidden).toBe(true);
	});

	it('ignores a stale country children response after a newer country is selected', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<p data-cetech-de-field="country">
							<select name="cetech_de_matching_country">
								<option value="">Select…</option>
								<option value="GH">Ghana</option>
								<option value="GB">United Kingdom</option>
							</select>
						</p>
						<p data-cetech-de-reveal="region" hidden>
							<select name="cetech_de_matching_state"><option value="">Select…</option></select>
						</p>
						<p data-cetech-de-reveal="locality" hidden>
							<input name="cetech_de_matching_city" />
							<ul class="cetech-de-locality-results" hidden></ul>
						</p>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="" />
					</div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			geography: { childrenAction: 'cetech_de_geography_children', childrenNonce: 'n' }
		};
		const deferred = {};
		window.fetch = (url, init) => {
			const body = String(init && init.body ? init.body : '');
			if (body.includes('country=GH')) {
				return new Promise((resolve) => {
					deferred.resolveGhana = resolve;
				});
			}
			return Promise.resolve({
				json: async () => ({
					success: true,
					data: {
						items: [{ key: 'loc-eng', name: 'England', code: 'ENG' }],
						skip_admin: false,
						request_token: '2'
					}
				})
			});
		};
		const api = loadSelector();
		api.bindAll(document);
		const country = document.querySelector('[name="cetech_de_matching_country"]');
		country.value = 'GH';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		country.value = 'GB';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('[name="cetech_de_matching_state"] option[value="ENG"]')).not.toBeNull();
		deferred.resolveGhana({
			json: async () => ({
				success: true,
				data: {
					items: [{ key: 'loc-ga', name: 'Greater Accra', code: 'AA' }],
					skip_admin: false,
					request_token: '1'
				}
			})
		});
		await new Promise((resolve) => setTimeout(resolve, 40));
		expect(document.querySelector('[name="cetech_de_matching_state"] option[value="AA"]')).toBeNull();
		expect(document.querySelector('[name="cetech_de_matching_state"] option[value="ENG"]')).not.toBeNull();
	});

	it('ignores a stale postcode relevance response after geography changes', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<p data-cetech-de-field="country">
							<select name="cetech_de_matching_country">
								<option value="GH" selected>Ghana</option>
							</select>
						</p>
						<p data-cetech-de-reveal="region">
							<select name="cetech_de_matching_state">
								<option value="">Select…</option>
								<option value="AA" selected data-location-key="loc-ga">Greater Accra</option>
								<option value="AH" data-location-key="loc-ash">Ashanti</option>
							</select>
						</p>
						<p data-cetech-de-reveal="locality">
							<input name="cetech_de_matching_city" />
							<ul class="cetech-de-locality-results" hidden></ul>
						</p>
						<p data-cetech-de-reveal="postcode" hidden>
							<input name="cetech_de_matching_postcode" />
						</p>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="" />
					</div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			geography: { postcodeAction: 'cetech_de_geography_postcode_relevance', postcodeNonce: 'n' }
		};
		const deferred = {};
		window.fetch = (url, init) => {
			const body = String(init && init.body ? init.body : '');
			if (body.includes('parent_key=loc-ga')) {
				return new Promise((resolve) => {
					deferred.resolveAccra = resolve;
				});
			}
			return Promise.resolve({
				json: async () => ({ success: true, data: { visible: false, request_token: '2' } })
			});
		};
		const api = loadSelector();
		api.bindAll(document);
		const region = document.querySelector('[name="cetech_de_matching_state"]');
		region.dispatchEvent(new Event('change', { bubbles: true }));
		region.value = 'AH';
		region.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('[data-cetech-de-reveal="postcode"]')?.hidden === true).toBe(true);
		deferred.resolveAccra({
			json: async () => ({ success: true, data: { visible: true, request_token: '1' } })
		});
		await new Promise((resolve) => setTimeout(resolve, 40));
		expect(document.querySelector('[data-cetech-de-reveal="postcode"]').hidden).toBe(true);
	});

	it('canonicalizes a saved free-text state on initial bind without changing country', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<p data-cetech-de-field="country">
							<select name="cetech_de_matching_country">
								<option value="">Select…</option>
								<option value="XX" selected>No Woo States</option>
							</select>
						</p>
						<p data-cetech-de-reveal="region" hidden>
							<label for="billing_state">Region / State</label>
							<input id="billing_state" class="input-text state_select woodmart-state" name="cetech_de_matching_state" type="text" value="Central District" aria-label="Region / State" aria-required="true" autocomplete="address-level1" data-placeholder="State" data-input-classes="state_select" data-location-key="loc-xx-adm" required="required" />
						</p>
						<p data-cetech-de-reveal="locality" hidden>
							<input name="cetech_de_matching_city" />
							<ul class="cetech-de-locality-results" hidden></ul>
						</p>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="" />
					</div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			geography: { childrenAction: 'cetech_de_geography_children', childrenNonce: 'n' }
		};
		window.fetch = async () => ({
			json: async () => ({
				success: true,
				data: {
					items: [{ key: 'loc-xx-adm', name: 'Central District', code: 'CD' }],
					skip_admin: false
				}
			})
		});
		const api = loadSelector();
		api.bindAll(document);
		await expect.poll(() => document.querySelector('[name="cetech_de_matching_state"]')?.tagName || '').toBe('SELECT');
		const region = document.querySelector('[name="cetech_de_matching_state"]');
		expect(region.value).toBe('CD');
		expect(region.options[region.selectedIndex].textContent).toBe('Central District');
		expect(region.id).toBe('billing_state');
		expect(region.className).toContain('state_select');
		expect(region.getAttribute('aria-label')).toBe('Region / State');
		expect(region.required).toBe(true);
		expect(document.querySelector('label[for="billing_state"]')).not.toBeNull();
	});

	it('loads administrative child 51 and 251 and restores a saved later-page region', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<p data-cetech-de-field="country">
							<select name="cetech_de_matching_country"><option value="GH" selected>Ghana</option></select>
						</p>
						<p data-cetech-de-reveal="region" hidden>
							<select name="cetech_de_matching_state" data-cetech-de-saved-region="adm-51"><option value="">Select…</option></select>
						</p>
						<p data-cetech-de-reveal="locality" hidden>
							<input name="cetech_de_matching_city" />
						</p>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="" />
					</div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			geography: { childrenAction: 'cetech_de_geography_children', childrenNonce: 'n' }
		};
		window.fetch = async (url, init) => {
			const body = String(init && init.body ? init.body : '');
			const pageMatch = body.match(/page=(\d+)/);
			const page = pageMatch ? Number(pageMatch[1]) : 1;
			const start = (page - 1) * 50 + 1;
			const items = page < 6
				? Array.from({ length: 50 }, (_, i) => ({ key: `adm-${start + i}`, name: `Admin ${start + i}`, code: `adm-${start + i}` }))
				: [{ key: 'adm-251', name: 'Admin 251', code: 'adm-251' }];
			return {
				json: async () => ({
					success: true,
					data: { items, page, total: 251, has_more: page < 6, skip_admin: false }
				})
			};
		};
		const api = loadSelector();
		api.bindAll(document);
		await expect.poll(() => document.querySelector('option[value="adm-51"]')).not.toBeNull();
		const more = document.querySelector('[data-cetech-de-load-more-admin]');
		expect(more).not.toBeNull();
		for (let i = 0; i < 5; i += 1) {
			document.querySelector('[data-cetech-de-load-more-admin]')?.click();
			await new Promise((resolve) => setTimeout(resolve, 20));
		}
		await expect.poll(() => document.querySelector('option[value="adm-251"]')).not.toBeNull();
	});

	it('ignores a stale administrative page response after Country change', async () => {
		document.body.innerHTML = `
			<form class="cart">
				<fieldset data-cetech-de-selector="1">
					<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">
						<p data-cetech-de-field="country">
							<select name="cetech_de_matching_country">
								<option value="">Select…</option>
								<option value="GH">Ghana</option>
								<option value="GB">United Kingdom</option>
							</select>
						</p>
						<p data-cetech-de-reveal="region" hidden>
							<select name="cetech_de_matching_state"><option value="">Select…</option></select>
						</p>
						<input type="hidden" name="cetech_de_matching_location_key" data-cetech-de-location-key="1" value="" />
					</div>
					<div data-cetech-de-options></div>
				</fieldset>
			</form>
		`;
		window.cetechDeMatchingLocation = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			geography: { childrenAction: 'cetech_de_geography_children', childrenNonce: 'n' }
		};
		let resolveStale;
		window.fetch = (url, init) => {
			const body = String(init && init.body ? init.body : '');
			if (body.includes('country=GH') && body.includes('page=2')) {
				return new Promise((resolve) => {
					resolveStale = resolve;
				});
			}
			if (body.includes('country=GB')) {
				return Promise.resolve({
					json: async () => ({
						success: true,
						data: { items: [{ key: 'loc-eng', name: 'England', code: 'ENG' }], skip_admin: false, has_more: false }
					})
				});
			}
			return Promise.resolve({
				json: async () => ({
					success: true,
					data: { items: [{ key: 'loc-ga', name: 'Greater Accra', code: 'GA' }], skip_admin: false, has_more: true }
				})
			});
		};
		const api = loadSelector();
		api.bindAll(document);
		const country = document.querySelector('[name="cetech_de_matching_country"]');
		country.value = 'GH';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('option[value="GA"], option[value="loc-ga"]')).not.toBeNull();
		document.querySelector('[data-cetech-de-load-more-admin]')?.click();
		country.value = 'GB';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('option[value="ENG"], option[value="loc-eng"]')).not.toBeNull();
		if (resolveStale) {
			resolveStale({
				json: async () => ({
					success: true,
					data: { items: [{ key: 'adm-51', name: 'Stale 51', code: 'adm-51' }], page: 2, has_more: false }
				})
			});
		}
		await new Promise((resolve) => setTimeout(resolve, 40));
		expect(document.querySelector('option[value="adm-51"]')).toBeNull();
	});
});
