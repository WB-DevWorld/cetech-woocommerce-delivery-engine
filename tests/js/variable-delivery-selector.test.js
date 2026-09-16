/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const productSelectorSource = readFileSync(
	resolve(root, 'assets/frontend/product-delivery-selector.js'),
	'utf8'
);
const scriptSource = readFileSync(
	resolve(root, 'assets/frontend/variable-delivery-selector.js'),
	'utf8'
);

function createDeferred(outcome) {
	const api = {
		abort: vi.fn(),
		done(cb) {
			if (outcome.type === 'success') {
				queueMicrotask(() => cb(outcome.payload));
			}
			return api;
		},
		fail(cb) {
			if (outcome.type === 'error') {
				queueMicrotask(() => cb({}, outcome.textStatus || 'error'));
			}
			return api;
		},
		always(cb) {
			queueMicrotask(() => cb());
			return api;
		},
	};
	return api;
}

function flush() {
	return new Promise((resolve) => setTimeout(resolve, 0));
}

function loadController(options = {}) {
	const emptyLocation = Boolean(options.emptyLocation);
	const locationFields = emptyLocation
		? `
					<select name="cetech_de_matching_country"><option value="">Select…</option><option value="GH">Ghana</option></select>
					<select name="cetech_de_matching_state"><option value=""></option></select>
					<input name="cetech_de_matching_city" value="" />
					<input name="cetech_de_matching_postcode" value="" />
		`
		: `
					<select name="cetech_de_matching_country"><option value="GH" selected>Ghana</option></select>
					<select name="cetech_de_matching_state"><option value="AA" selected>Greater Accra</option></select>
					<input name="cetech_de_matching_city" value="Accra" />
					<input name="cetech_de_matching_postcode" value="GA-123" />
		`;
	document.body.innerHTML = `
		<form class="variations_form cart">
			<div class="cetech-de-product-delivery-selector cetech-de-product-delivery-selector--variable"
				data-cetech-de-variable-selector="1" data-cetech-de-selector="1" data-product-id="100">
				<div data-cetech-de-matching-location="1">
					${locationFields}
				</div>
				<div class="cetech-de-delivery-selector__status" role="status" aria-live="polite" data-cetech-de-status></div>
				<div class="cetech-de-delivery-selector__options" data-cetech-de-options></div>
				<input type="hidden" name="cetech_de_delivery_variation_id" value="" data-cetech-de-variation-id disabled />
			</div>
		</form>
	`;

	window.cetechDeVariableDelivery = {
		ajaxUrl: '/admin-ajax.php',
		action: 'cetech_de_variation_delivery_options',
		nonce: 'test-nonce',
		productId: 100,
		postField: 'cetech_de_delivery_option_key',
		postVariationField: 'cetech_de_delivery_variation_id',
		i18n: {
			selectOptions: 'Select your product options to see delivery choices.',
			loading: 'Loading delivery choices…',
			unavailable: 'Delivery options are not available for this variation.',
			error: 'Delivery options are temporarily unavailable. Please try again.',
			choose: 'Choose a delivery option.',
			title: 'Delivery options',
			estimatedDelivery: 'Estimated delivery',
			readyForPickup: 'Ready for pickup',
			delivery: 'Delivery',
			storePickup: 'Store pickup',
			pickupLocation: 'Pickup location',
			pickupAddress: 'Pickup address',
			pickupInstructions: 'Pickup instructions',
			fulfilment: 'Fulfilment',
			free: 'Free',
		},
	};

	const handlers = {};
	const $form = {
		length: 1,
		jquery: 'test',
		on(events, handler) {
			String(events)
				.split(/\s+/)
				.forEach((eventName) => {
					const key = eventName.replace(/\.cetechDe$/, '');
					handlers[key] = handlers[key] || [];
					handlers[key].push(handler);
				});
			return $form;
		},
		trigger(eventName, data) {
			(handlers[eventName] || []).forEach((handler) => handler({}, data));
		},
		closest() {
			return $form;
		},
		filter() {
			return $form;
		},
		first() {
			return $form;
		},
	};

	const ajax = vi.fn();
	window.jQuery = Object.assign((selector) => {
		if (typeof selector === 'function') {
			selector();
			return $form;
		}
		return $form;
	}, { ajax, fn: {} });

	// eslint-disable-next-line no-eval
	eval(productSelectorSource);
	// eslint-disable-next-line no-eval
	eval(scriptSource);

	return {
		controller: window.CetechDeVariableDeliveryController,
		$form,
		ajax,
	};
}

function okPayload(variationId, label = `Offer ${variationId}`) {
	return {
		success: true,
		data: {
			status: 'ok',
			product_id: 100,
			variation_id: variationId,
			message: '',
			options: [
				{
					display_key: `in_warehouse:delivery:${variationId}`,
					fulfilment_availability_label: 'In Warehouse',
					fulfilment_choice_label: 'Delivery',
					delivery_offer_public_label: label,
					delivery_offer_public_description: null,
					estimate_text: '2 days',
					is_available: true,
					unavailable_reason: null,
					price_amount: '25.0000',
					price_currency: 'GHS',
					price_text: 'GHS 25.00',
					price_basis: 'per_shipment',
				},
			],
		},
	};
}

describe('Variable delivery selector controller', () => {
	beforeEach(() => {
		vi.restoreAllMocks();
		delete window.CetechDeVariableDeliveryController;
		delete window.CetechDeProductDeliverySelector;
		delete window.cetechDeVariableDelivery;
		delete window.jQuery;
		document.body.innerHTML = '';
	});

	it('starts in select-options state with no variation', () => {
		const { controller } = loadController();
		expect(controller.statusEl.textContent).toContain('Select your product options');
		expect(controller.optionsEl.innerHTML).toBe('');
		expect(controller.currentVariationId).toBe(0);
	});

	it('found_variation requests and renders options for A', async () => {
		const { controller, $form, ajax } = loadController();
		ajax.mockReturnValue(createDeferred({ type: 'success', payload: okPayload(11, 'Offer A') }));

		$form.trigger('found_variation', { variation_id: 11 });
		expect(controller.statusEl.textContent).toContain('Loading delivery choices');
		expect(ajax).toHaveBeenCalled();

		await flush();
		expect(controller.optionsEl.textContent).toContain('Offer A');
		expect(controller.optionsEl.textContent).toContain('GHS 25.00');
		expect(controller.optionsEl.textContent).toContain('2 days');
		expect(controller.optionsEl.querySelector('.cetech-de-delivery-option__price')).not.toBeNull();
		expect(controller.optionsEl.querySelector('.cetech-de-delivery-option__body')).not.toBeNull();
		expect(controller.optionsEl.querySelector('.cetech-de-delivery-option__description')).toBeNull();
		expect(controller.variationInput.value).toBe('11');
		expect(controller.variationInput.disabled).toBe(false);
	});

	it('omits public description from compact product hierarchy', async () => {
		const { controller, $form, ajax } = loadController();
		const payload = okPayload(11, 'FLAIROC QA Standard Delivery');
		payload.data.options[0].delivery_offer_public_description =
			'QA-only delivery option for Delivery Engine Stage 0B.';
		payload.data.options[0].estimate_text = 'Estimated 3–6 business days';
		ajax.mockReturnValue(createDeferred({ type: 'success', payload }));

		$form.trigger('found_variation', { variation_id: 11 });
		await flush();

		expect(controller.optionsEl.textContent).toContain('FLAIROC QA Standard Delivery');
		expect(controller.optionsEl.textContent).toContain('3–6 business days');
		expect(controller.optionsEl.textContent).not.toContain('QA-only');
		expect(controller.optionsEl.textContent).not.toContain('In Warehouse');
	});

	it('reset_data clears options and selected DE state', async () => {
		const { controller, $form, ajax } = loadController();
		ajax.mockReturnValue(createDeferred({ type: 'success', payload: okPayload(11, 'Offer A') }));

		$form.trigger('found_variation', { variation_id: 11 });
		await flush();
		expect(controller.optionsEl.textContent).toContain('Offer A');

		$form.trigger('reset_data');
		expect(controller.optionsEl.innerHTML).toBe('');
		expect(controller.variationInput.value).toBe('');
		expect(controller.variationInput.disabled).toBe(true);
		expect(controller.statusEl.textContent).toContain('Select your product options');
	});

	it('switching A → B clears A and renders B', async () => {
		const { controller, $form, ajax } = loadController();
		ajax.mockImplementation((settings) => {
			const id = settings.data.variation_id;
			return createDeferred({
				type: 'success',
				payload: okPayload(id, id === 11 ? 'Offer A' : 'Offer B'),
			});
		});

		$form.trigger('found_variation', { variation_id: 11 });
		await flush();
		expect(controller.optionsEl.textContent).toContain('Offer A');

		$form.trigger('found_variation', { variation_id: 22 });
		await flush();
		expect(controller.optionsEl.textContent).toContain('Offer B');
		expect(controller.optionsEl.textContent).not.toContain('Offer A');
		expect(controller.variationInput.value).toBe('22');
	});

	it('ignores stale A response after rapid A → B', async () => {
		const { controller, $form, ajax } = loadController();
		const resolvers = {};

		ajax.mockImplementation((settings) => {
			const id = settings.data.variation_id;
			const api = {
				abort: vi.fn(),
				done(cb) {
					resolvers[id] = cb;
					return api;
				},
				fail() {
					return api;
				},
				always(cb) {
					queueMicrotask(() => cb());
					return api;
				},
			};
			return api;
		});

		$form.trigger('found_variation', { variation_id: 11 });
		$form.trigger('found_variation', { variation_id: 22 });

		resolvers[22](okPayload(22, 'Offer B'));
		await flush();
		expect(controller.optionsEl.textContent).toContain('Offer B');

		if (resolvers[11]) {
			resolvers[11](okPayload(11, 'Offer A'));
		}
		await flush();
		expect(controller.optionsEl.textContent).toContain('Offer B');
		expect(controller.optionsEl.textContent).not.toContain('Offer A');
		expect(controller.variationInput.value).toBe('22');
	});

	it('AJAX error ends loading and shows safe error state', async () => {
		const { controller, $form, ajax } = loadController();
		ajax.mockReturnValue(createDeferred({ type: 'error', textStatus: 'error' }));

		$form.trigger('found_variation', { variation_id: 11 });
		await flush();
		expect(controller.statusEl.getAttribute('data-state')).toBe('error');
		expect(controller.statusEl.textContent).toContain('temporarily unavailable');
		expect(controller.optionsEl.innerHTML).toBe('');
	});

	it('unavailable response shows unavailable state', async () => {
		const { controller, $form, ajax } = loadController();
		ajax.mockReturnValue(
			createDeferred({
				type: 'success',
				payload: {
					success: true,
					data: {
						status: 'unavailable',
						message: 'Delivery options are not available for this variation.',
						options: [],
					},
				},
			})
		);

		$form.trigger('found_variation', { variation_id: 11 });
		await flush();
		expect(controller.statusEl.getAttribute('data-state')).toBe('unavailable');
		expect(controller.statusEl.textContent).toContain('not available for this variation');
	});

	it('selected offer radios are bound to current variation', async () => {
		const { controller, $form, ajax } = loadController();
		ajax.mockReturnValue(createDeferred({ type: 'success', payload: okPayload(33, 'Offer C') }));

		$form.trigger('found_variation', { variation_id: 33 });
		await flush();

		const radio = controller.optionsEl.querySelector('input[type="radio"]');
		expect(radio).toBeTruthy();
		expect(radio.getAttribute('data-cetech-de-variation-bound')).toBe('33');
		expect(controller.variationInput.value).toBe('33');
	});

	it('does not render technical/internal fields', async () => {
		const { controller, $form, ajax } = loadController();
		ajax.mockReturnValue(
			createDeferred({
				type: 'success',
				payload: {
					success: true,
					data: {
						status: 'ok',
						options: [
							{
								display_key: 'in_warehouse:delivery:1',
								fulfilment_choice: 'delivery',
								delivery_offer_public_label: 'Safe Offer',
								estimate_text: '2–3 business days',
								is_available: true,
								supplier_id: 99,
								configuration_fingerprint: 'abc',
								provenance: 'secret',
							},
						],
					},
				},
			})
		);

		$form.trigger('found_variation', { variation_id: 11 });
		await flush();

		const html = controller.root.innerHTML;
		expect(html).toContain('Safe Offer');
		expect(html).not.toContain('configuration_fingerprint');
		expect(html).not.toContain('provenance');
		expect(html).not.toContain('supplier_id');
		expect(html).not.toContain('EffectiveConfigurationResolver');
		expect(html).not.toContain('slice_key');
	});

	it('renders Delivery and Store Pickup switcher and hides delivery ETA when pickup is selected', async () => {
		const { controller, $form, ajax } = loadController();
		ajax.mockReturnValue(
			createDeferred({
				type: 'success',
				payload: {
					success: true,
					data: {
						status: 'ok',
						product_id: 100,
						variation_id: 11,
						message: '',
						options: [
							{
								display_key: 'in_store:delivery:11',
								fulfilment_choice: 'delivery',
								fulfilment_choice_label: 'Delivery',
								delivery_offer_public_label: 'Standard Delivery',
								estimate_text: 'Estimated 2–4 business days',
								is_available: true,
								is_default: true,
							},
							{
								display_key: 'in_store:store_pickup:pickup',
								fulfilment_choice: 'store_pickup',
								fulfilment_choice_label: 'Store pickup',
								delivery_offer_public_label: 'Store pickup',
								estimate_text: 'Ready in 2 hours',
								is_available: true,
								is_default: false,
								pickup_location_label: 'Main showroom',
								pickup_instructions: 'Bring your order number',
							},
						],
					},
				},
			})
		);

		$form.trigger('found_variation', { variation_id: 11 });
		await flush();

		expect(controller.optionsEl.textContent).toContain('Standard Delivery');
		expect(controller.optionsEl.textContent).toContain('2–4 business days');
		expect(controller.optionsEl.querySelector('[data-cetech-de-choice-switch][value="delivery"]').checked).toBe(true);

		const pickupPanel = controller.optionsEl.querySelector('[data-cetech-de-choice-panel="store_pickup"]');
		const deliveryPanel = controller.optionsEl.querySelector('[data-cetech-de-choice-panel="delivery"]');
		expect(pickupPanel.hidden).toBe(true);
		expect(deliveryPanel.hidden).toBe(false);

		const pickupSwitch = controller.optionsEl.querySelector('[data-cetech-de-choice-switch][value="store_pickup"]');
		pickupSwitch.checked = true;
		pickupSwitch.dispatchEvent(new Event('change', { bubbles: true }));

		expect(deliveryPanel.hidden).toBe(true);
		expect(pickupPanel.hidden).toBe(false);
		expect(pickupPanel.textContent).toContain('Main showroom');
		expect(pickupPanel.textContent).toContain('Ready in 2 hours');
		expect(pickupPanel.querySelector('.cetech-de-delivery-option__estimate')).toBeNull();
		expect(deliveryPanel.querySelector('input[type="radio"]').disabled).toBe(true);
		expect(pickupPanel.querySelector('input[name="cetech_de_delivery_option_key"]').checked).toBe(true);
	});

	it('includes matching location in the variation option request', async () => {
		const { $form, ajax } = loadController();
		ajax.mockReturnValue(createDeferred({ type: 'success', payload: okPayload(11, 'Offer A') }));

		$form.trigger('found_variation', { variation_id: 11 });
		expect(ajax).toHaveBeenCalled();
		const data = ajax.mock.calls[0][0].data;
		expect(data.variation_id).toBe(11);
		expect(data.country).toBe('GH');
		expect(data.city).toBe('Accra');
		expect(data.postcode).toBe('GA-123');
	});

	it('changing city invalidates cached options and refetches', async () => {
		const { controller, $form, ajax } = loadController();
		ajax.mockImplementation((settings) => {
			const city = settings.data.city;
			return createDeferred({
				type: 'success',
				payload: okPayload(11, city === 'Kumasi' ? 'Kumasi Offer' : 'Accra Offer'),
			});
		});

		$form.trigger('found_variation', { variation_id: 11 });
		await flush();
		expect(controller.optionsEl.textContent).toContain('Accra Offer');

		const city = document.querySelector('[name="cetech_de_matching_city"]');
		city.value = 'Kumasi';
		city.dispatchEvent(new Event('change', { bubbles: true }));
		await flush();
		expect(controller.optionsEl.textContent).toContain('Kumasi Offer');
		expect(controller.optionsEl.textContent).not.toContain('Accra Offer');
	});

	it('sends current quantity on variation option fetch', async () => {
		const { controller, $form, ajax } = loadController();
		const form = document.querySelector('form.variations_form');
		const qty = document.createElement('input');
		qty.type = 'number';
		qty.name = 'quantity';
		qty.className = 'qty';
		qty.value = '3';
		form.insertBefore(qty, form.firstChild);
		ajax.mockReturnValue(createDeferred({ type: 'success', payload: okPayload(11, 'Offer A') }));

		$form.trigger('found_variation', { variation_id: 11 });
		await flush();

		expect(ajax).toHaveBeenCalled();
		expect(ajax.mock.calls[0][0].data.quantity).toBe(3);
		expect(controller.optionsEl.textContent).toContain('GHS 25.00');
	});

	it('shows Delivery/Pickup switch on need_location using capability metadata', async () => {
		const { controller, $form, ajax } = loadController();
		ajax.mockReturnValue(
			createDeferred({
				type: 'success',
				payload: {
					success: true,
					data: {
						status: 'need_location',
						product_id: 100,
						variation_id: 11,
						message: '',
						has_delivery: true,
						has_pickup: true,
						available_choices: ['delivery', 'store_pickup'],
						options: [
							{
								display_key: 'in_store:store_pickup:pickup',
								fulfilment_choice: 'store_pickup',
								delivery_offer_public_label: 'QA Accra Pickup',
								is_available: true,
								price_text: 'Free',
							},
						],
					},
				},
			})
		);

		$form.trigger('found_variation', { variation_id: 11 });
		await flush();

		expect(controller.optionsEl.querySelectorAll('[data-cetech-de-choice-switch]').length).toBe(2);
		expect(controller.optionsEl.querySelector('[data-cetech-de-choice-panel="delivery"]')).not.toBeNull();
		expect(controller.optionsEl.textContent).toContain('QA Accra Pickup');
		expect(controller.optionsEl.textContent).not.toContain('₵10.00');
	});

	it('sends empty country before location and keeps need_location instead of unavailable', async () => {
		const { controller, $form, ajax } = loadController({ emptyLocation: true });
		ajax.mockReturnValue(
			createDeferred({
				type: 'success',
				payload: {
					success: true,
					data: {
						status: 'need_location',
						product_id: 100,
						variation_id: 11,
						message: '',
						has_delivery: true,
						has_pickup: false,
						available_choices: ['delivery'],
						options: [],
					},
				},
			})
		);

		$form.trigger('found_variation', { variation_id: 11 });
		await flush();

		expect(ajax.mock.calls[0][0].data.country).toBe('');
		expect(ajax.mock.calls[0][0].data.city).toBe('');
		expect(controller.statusEl.textContent).not.toContain('not available for this variation');
		expect(controller.optionsEl.textContent).not.toMatch(/₵\d/);
	});
});
