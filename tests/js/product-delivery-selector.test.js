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
		expect(deliveryPanel.textContent).toContain('Estimated delivery: 2–4 business days');
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
		)).toBe('Estimated delivery: 3–5 business days');
		expect(api.formatEstimateLine(
			{ fulfilment_choice: 'delivery', estimate_text: 'Estimated 3–5 business days' },
			{ i18n: { estimated: 'Estimated delivery' } }
		)).toBe('Estimated delivery: 3–5 business days');
	});
});
