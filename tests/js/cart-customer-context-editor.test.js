/**
 * @vitest-environment jsdom
 */
import { describe, expect, it } from 'vitest';
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
