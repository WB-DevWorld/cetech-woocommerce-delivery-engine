/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
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

function loadController() {
	document.body.innerHTML = `
		<form class="variations_form cart">
			<div class="cetech-de-product-delivery-selector cetech-de-product-delivery-selector--variable"
				data-cetech-de-variable-selector="1" data-product-id="100">
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
				},
			],
		},
	};
}

describe('Variable delivery selector controller', () => {
	beforeEach(() => {
		vi.restoreAllMocks();
		delete window.CetechDeVariableDeliveryController;
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
		expect(controller.variationInput.value).toBe('11');
		expect(controller.variationInput.disabled).toBe(false);
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
								delivery_offer_public_label: 'Safe Offer',
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
});
