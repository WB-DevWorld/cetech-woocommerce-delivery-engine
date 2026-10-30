/**
 * @vitest-environment jsdom
 */
import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const scriptSource = readFileSync(resolve(root, 'assets/frontend/cart-customer-context-editor.js'), 'utf8');

function loadScript() {
	delete window.CetechDeCartContextEditor;
	eval(scriptSource);
	return window.CetechDeCartContextEditor;
}

function editorMarkup(options = {}) {
	const hasMatching = options.hasMatching !== false;
	const country = options.country ?? 'GH';
	const region = options.region ?? 'AA';
	return `
		<div class="cetech-de-cart-context" id="cetech-de-delivery-aaaaaaaaaaaaaaaa" data-cetech-de-ui-anchor="cetech-de-delivery-aaaaaaaaaaaaaaaa" data-cetech-de-form-id="cetech-de-delivery-aaaaaaaaaaaaaaaa-form" data-cetech-de-has-matching-location="${hasMatching ? '1' : '0'}">
			<details class="cetech-de-cart-context__editor" data-cetech-de-ui-anchor="cetech-de-delivery-aaaaaaaaaaaaaaaa">
				<summary class="cetech-de-cart-context__summary-action">Add delivery address</summary>
				<section class="cetech-de-cart-context__location" data-cetech-de-editor-location="1">
					<details class="cetech-de-cart-context__disclosure"${hasMatching ? '' : ' open'}>
						<summary>Change destination</summary>
						<p data-cetech-de-field="country"><select data-cetech-de-destination-control="country" form="cetech-de-delivery-aaaaaaaaaaaaaaaa-form"><option value="${country}" ${country ? 'selected' : ''}>${country || 'Select'}</option></select></p>
						<p data-cetech-de-field="region" data-cetech-de-reveal="region"${country ? '' : ' hidden'}><select data-cetech-de-destination-control="region" form="cetech-de-delivery-aaaaaaaaaaaaaaaa-form"><option value="${region}">${region || 'Select'}</option></select></p>
					</details>
				</section>
				<input form="cetech-de-delivery-aaaaaaaaaaaaaaaa-form" type="text" name="cetech_de_address_1" data-cetech-de-required-address="1" value="Rendered Street" />
				<input form="cetech-de-delivery-aaaaaaaaaaaaaaaa-form" type="text" name="cetech_de_first_name" value="" />
				<button type="button" data-cetech-de-cancel="1">Cancel</button>
				<button type="submit" form="cetech-de-delivery-aaaaaaaaaaaaaaaa-form" class="cetech-de-cart-context__save">Save delivery details</button>
			</details>
		</div>
		<form id="cetech-de-delivery-aaaaaaaaaaaaaaaa-form" class="cetech-de-cart-context__form"></form>
	`;
}

describe('Classic cart customer context editor', () => {
	it('opens the matching details and focuses Address line 1 for a deep-link hash', () => {
		document.body.innerHTML = editorMarkup();
		window.history.replaceState(null, '', '/cart/#cetech-de-delivery-aaaaaaaaaaaaaaaa');
		const api = loadScript();
		api.resetDeepLink();
		expect(api.applyDeepLink()).toBe(true);
		const details = document.querySelector('details.cetech-de-cart-context__editor');
		expect(details.open).toBe(true);
		expect(document.activeElement).toBe(document.querySelector('[data-cetech-de-required-address]'));
	});

	it('does not steal focus when the cart has no matching fragment', () => {
		document.body.innerHTML = editorMarkup();
		window.history.replaceState(null, '', '/cart/');
		const api = loadScript();
		api.resetDeepLink();
		const before = document.activeElement;
		expect(api.applyDeepLink()).toBe(false);
		expect(document.querySelector('details.cetech-de-cart-context__editor').open).toBe(false);
		expect(document.activeElement).toBe(before);
	});

	it('cancels without submitting, resets values, closes the editor, and restores summary focus', () => {
		document.body.innerHTML = editorMarkup();
		window.history.replaceState(null, '', '/cart/');
		const api = loadScript();
		const details = document.querySelector('details.cetech-de-cart-context__editor');
		const form = document.getElementById('cetech-de-delivery-aaaaaaaaaaaaaaaa-form');
		const address = document.querySelector('[name="cetech_de_address_1"]');
		details.open = true;
		address.value = 'Unsaved Street';
		let submitted = false;
		form.addEventListener('submit', () => {
			submitted = true;
		});
		document.querySelector('[data-cetech-de-cancel]').click();
		expect(submitted).toBe(false);
		expect(address.value).toBe('Rendered Street');
		expect(details.open).toBe(false);
		expect(document.activeElement).toBe(details.querySelector('summary'));
		expect(typeof api.bindCancel).toBe('function');
	});

	it('focuses Address line 1 when a matching destination already exists', () => {
		document.body.innerHTML = editorMarkup({ hasMatching: true, country: 'GH', region: 'AA' });
		window.history.replaceState(null, '', '/cart/#cetech-de-delivery-aaaaaaaaaaaaaaaa');
		const api = loadScript();
		api.resetDeepLink();
		expect(api.applyDeepLink()).toBe(true);
		expect(document.activeElement).toBe(document.querySelector('[data-cetech-de-required-address]'));
	});

	it('focuses the first missing destination field instead of Address line 1', () => {
		document.body.innerHTML = editorMarkup({ hasMatching: false, country: '', region: '' });
		window.history.replaceState(null, '', '/cart/#cetech-de-delivery-aaaaaaaaaaaaaaaa');
		const api = loadScript();
		api.resetDeepLink();
		expect(api.applyDeepLink()).toBe(true);
		const destDisclosure = document.querySelector('.cetech-de-cart-context__location details');
		expect(destDisclosure.open).toBe(true);
		expect(document.activeElement).toBe(document.querySelector('[data-cetech-de-destination-control="country"]'));
		expect(document.activeElement).not.toBe(document.querySelector('[data-cetech-de-required-address]'));
	});

	it('hides Delivery-only actions on unsaved Pickup and restores them on Delivery or Cancel', () => {
		document.body.innerHTML = `
			<div class="cetech-de-cart-context" id="cetech-de-delivery-aaaaaaaaaaaaaaaa" data-cetech-de-form-id="cetech-de-delivery-aaaaaaaaaaaaaaaa-form">
				<details class="cetech-de-cart-context__editor">
					<summary>Add delivery address</summary>
					<section data-cetech-de-editor-location="1">Location</section>
					<section data-cetech-de-editor-address="1">Address</section>
					<section data-cetech-de-editor-recipient="1">Recipient</section>
					<details data-cetech-de-qty-split="1"><summary>Apply to quantity</summary></details>
					<select name="cetech_de_delivery_option_key" form="cetech-de-delivery-aaaaaaaaaaaaaaaa-form">
						<option value="in_warehouse:delivery:1" data-cetech-de-choice="delivery" selected>Delivery</option>
						<option value="in_store:store_pickup:4" data-cetech-de-choice="store_pickup">Pickup</option>
					</select>
					<button type="submit" class="cetech-de-cart-context__use-for-all" form="cetech-de-delivery-aaaaaaaaaaaaaaaa-form">Use this address for all delivery items</button>
					<button type="button" data-cetech-de-cancel="1">Cancel</button>
				</details>
			</div>
			<form id="cetech-de-delivery-aaaaaaaaaaaaaaaa-form" class="cetech-de-cart-context__form"></form>
		`;
		window.history.replaceState(null, '', '/cart/');
		loadScript();
		const select = document.querySelector('select[name="cetech_de_delivery_option_key"]');
		const useForAll = document.querySelector('.cetech-de-cart-context__use-for-all');
		const qtySplit = document.querySelector('[data-cetech-de-qty-split]');
		const location = document.querySelector('[data-cetech-de-editor-location]');
		expect(useForAll.hidden).toBe(false);
		expect(qtySplit.hidden).toBe(false);
		select.value = 'in_store:store_pickup:4';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		expect(location.hidden).toBe(true);
		expect(useForAll.hidden).toBe(true);
		expect(useForAll.disabled).toBe(true);
		expect(qtySplit.hidden).toBe(true);
		select.value = 'in_warehouse:delivery:1';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		expect(location.hidden).toBe(false);
		expect(useForAll.hidden).toBe(false);
		expect(qtySplit.hidden).toBe(false);
		select.value = 'in_store:store_pickup:4';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		document.querySelector('[data-cetech-de-cancel]').click();
		expect(select.value).toBe('in_warehouse:delivery:1');
		expect(useForAll.hidden).toBe(false);
		expect(qtySplit.hidden).toBe(false);
		expect(location.hidden).toBe(false);
	});
});

function destinationMarkup(options = {}) {
	const country = options.country ?? 'GH';
	const region = options.region ?? '';
	const city = options.city ?? '';
	const formId = 'cetech-de-delivery-bbbbbbbbbbbbbbbb-form';
	return `
		<div class="cetech-de-cart-context" id="cetech-de-delivery-bbbbbbbbbbbbbbbb" data-cetech-de-form-id="${formId}" data-cetech-de-has-matching-location="${region ? '1' : '0'}">
			<details class="cetech-de-cart-context__editor" open>
				<summary class="cetech-de-cart-context__summary-action">Change</summary>
				<section class="cetech-de-cart-context__location" data-cetech-de-editor-location="1">
					<details class="cetech-de-cart-context__disclosure" open>
						<summary>Change destination</summary>
						<fieldset class="cetech-de-matching-location" data-cetech-de-matching-location="1">
							<input type="hidden" name="cetech_de_matching_location_key" value="" data-cetech-de-location-key="1" form="${formId}" />
							<p class="cetech-de-matching-location__field" data-cetech-de-field="country">
								<select name="cetech_de_matching_country" data-cetech-de-destination-control="country" form="${formId}">
									<option value="">Select…</option>
									<option value="GH"${country === 'GH' ? ' selected' : ''}>Ghana</option>
									<option value="NG"${country === 'NG' ? ' selected' : ''}>Nigeria</option>
								</select>
							</p>
							<p class="cetech-de-matching-location__field" data-cetech-de-field="region" data-cetech-de-reveal="region"${country ? '' : ' hidden'}>
								<select name="cetech_de_matching_state" data-cetech-de-destination-control="region" form="${formId}">
									<option value="">Select…</option>
									<option value="AA"${region === 'AA' ? ' selected' : ''}>Greater Accra</option>
									<option value="AH"${region === 'AH' ? ' selected' : ''}>Ashanti</option>
								</select>
							</p>
							<p class="cetech-de-matching-location__field" data-cetech-de-field="locality" data-cetech-de-reveal="locality"${region ? '' : ' hidden'}>
								<input type="search" name="cetech_de_matching_city" value="${city}" data-cetech-de-locality-input="1" data-cetech-de-destination-control="locality" role="combobox" aria-expanded="false" form="${formId}" />
								<ul class="cetech-de-locality-results" role="listbox" hidden></ul>
							</p>
							<p class="cetech-de-matching-location__field" data-cetech-de-field="postcode" data-cetech-de-reveal="postcode" hidden>
								<input type="text" name="cetech_de_matching_postcode" value="" data-cetech-de-destination-control="postcode" form="${formId}" />
							</p>
						</fieldset>
					</details>
				</section>
				<select name="cetech_de_delivery_option_key" form="${formId}">
					<option value="in_store:delivery:1" data-cetech-de-choice="delivery" selected>Standard Delivery</option>
					<option value="in_store:store_pickup:pickup" data-cetech-de-choice="store_pickup">CETECH Ghana Store</option>
				</select>
				<button type="button" data-cetech-de-cancel="1">Cancel</button>
			</details>
		</div>
		<form id="${formId}" class="cetech-de-cart-context__form"></form>
	`;
}

function wrapper(field) {
	return document.querySelector(`[data-cetech-de-reveal="${field}"]`);
}

function control(field) {
	return document.querySelector(`[data-cetech-de-destination-control="${field}"]`);
}

function change(el, value) {
	el.value = value;
	el.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('Cart editor destination cascade (C12)', () => {
	it('reveals City / Town as soon as a Region is chosen and hides it again when the Region is cleared', () => {
		document.body.innerHTML = destinationMarkup({ country: 'GH', region: '' });
		window.history.replaceState(null, '', '/cart/');
		loadScript();
		expect(wrapper('locality').hidden).toBe(true);

		change(control('region'), 'AA');
		expect(wrapper('locality').hidden).toBe(false);

		control('locality').value = 'Accra';
		document.querySelector('[data-cetech-de-location-key]').value = 'key-accra';
		change(control('region'), '');
		expect(wrapper('locality').hidden).toBe(true);
		expect(control('locality').value).toBe('');
		expect(document.querySelector('[data-cetech-de-location-key]').value).toBe('');
	});

	it('keeps a stored destination visible on load', () => {
		document.body.innerHTML = destinationMarkup({ country: 'GH', region: 'AA', city: 'Accra' });
		window.history.replaceState(null, '', '/cart/');
		loadScript();
		expect(wrapper('region').hidden).toBe(false);
		expect(wrapper('locality').hidden).toBe(false);
		expect(control('locality').value).toBe('Accra');
	});

	it('clearing the Country hides Region and City and resets their values', () => {
		document.body.innerHTML = destinationMarkup({ country: 'GH', region: 'AA', city: 'Accra' });
		window.history.replaceState(null, '', '/cart/');
		loadScript();

		change(control('country'), '');
		expect(wrapper('region').hidden).toBe(true);
		expect(wrapper('locality').hidden).toBe(true);
		expect(control('region').value).toBe('');
		expect(control('locality').value).toBe('');
	});

	it('offers locality suggestions from the geography search and fills the location key on pick', async () => {
		vi.useFakeTimers();
		try {
			document.body.innerHTML = destinationMarkup({ country: 'GH', region: 'AA' });
			window.history.replaceState(null, '', '/cart/');
			window.cetechDeCartContextEditor = {
				ajaxUrl: '/wp-admin/admin-ajax.php',
				geography: { searchAction: 'cetech_de_geography_locality_search', searchNonce: 'search-nonce' },
			};
			const bodies = [];
			window.fetch = async (_url, init) => {
				bodies.push(new URLSearchParams(init.body));
				return {
					json: async () => ({
						success: true,
						data: {
							items: [
								{ key: 'key-accra', name: 'Accra', label: 'Accra — Accra, Greater Accra Region' },
								{ key: 'key-accra-central', name: 'Accra Central', label: 'Accra Central — Accra, Greater Accra Region' },
							],
							has_more: false,
						},
					}),
				};
			};
			loadScript();

			const city = control('locality');
			city.value = 'Acc';
			city.dispatchEvent(new Event('input', { bubbles: true }));
			expect(bodies).toHaveLength(0);
			await vi.advanceTimersByTimeAsync(300);
			expect(bodies).toHaveLength(1);
			expect(bodies[0].get('action')).toBe('cetech_de_geography_locality_search');
			expect(bodies[0].get('nonce')).toBe('search-nonce');
			expect(bodies[0].get('country')).toBe('GH');
			expect(bodies[0].get('q')).toBe('Acc');

			const list = document.querySelector('.cetech-de-locality-results');
			expect(list.hidden).toBe(false);
			expect(city.getAttribute('aria-expanded')).toBe('true');
			const options = list.querySelectorAll('[role="option"]');
			expect(options).toHaveLength(2);
			expect(options[0].textContent).toBe('Accra — Accra, Greater Accra Region');

			options[0].click();
			expect(city.value).toBe('Accra');
			expect(document.querySelector('[data-cetech-de-location-key]').value).toBe('key-accra');
			expect(list.hidden).toBe(true);
			expect(city.getAttribute('aria-expanded')).toBe('false');

			// Editing the City again drops the stale key.
			city.value = 'Accra C';
			city.dispatchEvent(new Event('input', { bubbles: true }));
			expect(document.querySelector('[data-cetech-de-location-key]').value).toBe('');
		} finally {
			vi.useRealTimers();
			delete window.cetechDeCartContextEditor;
			delete window.fetch;
		}
	});

	it('loads the regions of a newly chosen Country and waits for a Region before showing City', async () => {
		document.body.innerHTML = destinationMarkup({ country: 'GH', region: 'AA', city: 'Accra' });
		window.history.replaceState(null, '', '/cart/');
		window.cetechDeCartContextEditor = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			geography: { childrenAction: 'cetech_de_geography_children', childrenNonce: 'children-nonce' },
		};
		const bodies = [];
		window.fetch = async (_url, init) => {
			bodies.push(new URLSearchParams(init.body));
			return {
				json: async () => ({
					success: true,
					data: { items: [ { key: 'key-lagos', code: 'LA', name: 'Lagos' }, { key: 'key-abuja', code: 'FC', name: 'Abuja' } ], has_more: false },
				}),
			};
		};
		try {
			loadScript();
			change(control('country'), 'NG');
			expect(wrapper('locality').hidden).toBe(true);
			expect(control('locality').value).toBe('');
			await new Promise((resolve) => setTimeout(resolve, 0));
			expect(bodies).toHaveLength(1);
			expect(bodies[0].get('action')).toBe('cetech_de_geography_children');
			expect(bodies[0].get('country')).toBe('NG');
			expect(bodies[0].get('type')).toBe('administrative');

			const region = control('region');
			const labels = Array.from(region.options).map((o) => o.textContent);
			expect(labels).toEqual(['Select…', 'Lagos', 'Abuja']);
			expect(region.options[1].getAttribute('data-location-key')).toBe('key-lagos');
			expect(wrapper('region').hidden).toBe(false);
			expect(wrapper('locality').hidden).toBe(true);

			change(region, 'LA');
			expect(wrapper('locality').hidden).toBe(false);
		} finally {
			delete window.cetechDeCartContextEditor;
			delete window.fetch;
		}
	});
});
