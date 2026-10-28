/** @vitest-environment jsdom */
import { beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const scopedScript = readFileSync(resolve('assets/admin/scoped-configuration.js'), 'utf8');
const adminScript = readFileSync(resolve('assets/admin/delivery-engine-admin.js'), 'utf8');

function choose(field, mode) {
	const radio = field.querySelector(`input[type="radio"][value="${mode}"]`);
	radio.checked = true;
	radio.dispatchEvent(new Event('change', { bubbles: true }));
}
function posted() {
	return new FormData(document.querySelector('form.cetech-de-customize-form'));
}

describe('Scoped delivery editor working controls', () => {
	beforeAll(() => {
		new Function(scopedScript)();
		new Function(adminScript)();
	});
	beforeEach(() => {
		document.body.innerHTML = `
			<form class="cetech-de-customize-form" data-cetech-de-customize="1" data-unsaved-label="Unsaved changes">
				<input type="hidden" name="expected_revision" value="4">
				<input type="hidden" name="request_token" value="retained-token">
				<fieldset class="cetech-de-customize-field" data-field="fulfilment_availability" data-inherited-fulfilment="in_store">
					<input type="radio" name="fields[fulfilment_availability][mode]" value="inherit" checked>
					<input type="radio" name="fields[fulfilment_availability][mode]" value="override">
					<div class="cetech-de-customize-override" data-show-for="override">
						<select name="fields[fulfilment_availability][value]" data-cetech-de-fulfilment-select>
							<option value="in_store">Store</option><option value="international_fulfilment" selected>International</option>
						</select>
						<input name="forbidden" value="private" disabled>
						<input name="read_only" value="fixed" readonly>
					</div>
				</fieldset>
				<fieldset class="cetech-de-customize-field" data-field="estimated_delivery">
					<input type="radio" name="fields[estimated_delivery][mode]" value="inherit" checked>
					<input type="radio" name="fields[estimated_delivery][mode]" value="override">
					<div class="cetech-de-customize-override" data-show-for="override"><input required name="fields[estimated_delivery][value]" value="10 business days"></div>
				</fieldset>
				<fieldset class="cetech-de-customize-field" data-field="delivery_offer_ids">
					<input type="radio" name="fields[delivery_offer_ids][mode]" value="inherit" checked>
					<input type="radio" name="fields[delivery_offer_ids][mode]" value="add">
					<input type="radio" name="fields[delivery_offer_ids][mode]" value="remove">
					<input type="radio" name="fields[delivery_offer_ids][mode]" value="replace">
					<div class="cetech-de-customize-override cetech-de-option-search" data-show-for="add,remove,replace">
						<div class="cetech-de-option-search-controls" hidden><input type="search" data-cetech-de-option-search></div>
						<p data-cetech-de-search-empty hidden>No matching loaded options</p>
						<div class="cetech-de-option-search-row"><p class="cetech-de-compatible-option" data-profiles="in_store"><label><input type="checkbox" name="fields[delivery_offer_ids][members][]" value="11" checked>Local delivery</label></p></div>
						<div class="cetech-de-option-search-row"><p class="cetech-de-compatible-option" data-profiles="international_fulfilment" hidden><label><input type="checkbox" name="fields[delivery_offer_ids][members][]" value="12">Air shipping</label></p></div>
						<div class="cetech-de-option-search-row"><p><label><input type="checkbox" name="fields[delivery_offer_ids][members][]" value="601" checked>Selected option #601 — outside loaded list</label></p></div>
					</div>
				</fieldset>
				<span class="cetech-de-unsaved-status" role="status" hidden></span>
				<button type="submit">Save</button>
			</form>
			<form class="unrelated"><input name="other" value="keep"><input type="radio" name="mode" value="inherit"></form>
			<div class="cetech-de-field-editor"><input type="radio" name="advanced_mode" value="inherit" data-mode="inherit" checked><input type="radio" name="advanced_mode" value="override" data-mode="override"><div class="cetech-de-mode-panel" data-show-for="override"><input name="advanced_value" value="draft"><input name="restricted" value="fixed" disabled></div></div>`;
		document.dispatchEvent(new Event('DOMContentLoaded'));
	});

	it('posts only selected mode values and preserves inactive drafts through repeated switches', () => {
		const field = document.querySelector('[data-field="estimated_delivery"]');
		const panel = field.querySelector('.cetech-de-customize-override');
		const value = panel.querySelector('input');
		expect(panel.hidden).toBe(true);
		expect(posted().get('fields[estimated_delivery][value]')).toBeNull();
		expect(value.disabled).toBe(true);
		choose(field, 'override');
		value.value = '14 business days';
		value.dispatchEvent(new Event('input', { bubbles: true }));
		expect(posted().get('fields[estimated_delivery][value]')).toBe('14 business days');
		choose(field, 'inherit');
		expect(posted().get('fields[estimated_delivery][value]')).toBeNull();
		choose(field, 'override');
		expect(value.value).toBe('14 business days');
		expect(panel.hidden).toBe(false);
		expect(posted().get('request_token')).toBe('retained-token');
		expect(posted().get('expected_revision')).toBe('4');
	});

	it('never enables originally forbidden controls or removes read-only restrictions', () => {
		const field = document.querySelector('[data-field="fulfilment_availability"]');
		choose(field, 'override');
		expect(field.querySelector('[name="forbidden"]').disabled).toBe(true);
		expect(posted().get('forbidden')).toBeNull();
		expect(field.querySelector('[name="read_only"]').readOnly).toBe(true);
		choose(field, 'inherit');
		choose(field, 'override');
		expect(field.querySelector('[name="forbidden"]').disabled).toBe(true);
		const advanced = document.querySelector('.cetech-de-field-editor');
		choose(advanced, 'override');
		expect(advanced.querySelector('[name="advanced_value"]').disabled).toBe(false);
		expect(advanced.querySelector('[name="restricted"]').disabled).toBe(true);
		expect(document.querySelector('.unrelated input').disabled).toBe(false);
	});

	it('keeps every selected option successful while search hides other rows', () => {
		const field = document.querySelector('[data-field="delivery_offer_ids"]');
		choose(field, 'replace');
		const search = field.querySelector('[type="search"]');
		search.value = 'air';
		search.dispatchEvent(new Event('input', { bubbles: true }));
		expect(posted().getAll('fields[delivery_offer_ids][members][]')).toEqual(['11', '601']);
		expect(field.querySelector('[value="11"]').closest('.cetech-de-option-search-row').hidden).toBe(false);
		field.querySelector('[value="11"]').checked = false;
		field.querySelector('[value="11"]').dispatchEvent(new Event('change', { bubbles: true }));
		expect(field.querySelector('[value="11"]').closest('.cetech-de-option-search-row').hidden).toBe(true);
		expect(posted().getAll('fields[delivery_offer_ids][members][]')).toEqual(['601']);
		choose(field, 'inherit');
		expect(posted().getAll('fields[delivery_offer_ids][members][]')).toEqual([]);
		choose(field, 'remove');
		expect(posted().getAll('fields[delivery_offer_ids][members][]')).toEqual(['601']);
		search.value = '';
		search.dispatchEvent(new Event('input', { bubbles: true }));
		expect(field.querySelector('[value="11"]').checked).toBe(false);
	});

	it('uses the active fulfilment mode for compatible display without changing selected IDs', () => {
		const field = document.querySelector('[data-field="fulfilment_availability"]');
		const local = document.querySelector('[data-profiles="in_store"]');
		const air = document.querySelector('[data-profiles="international_fulfilment"]');
		choose(field, 'override');
		expect(local.hidden).toBe(true);
		expect(air.hidden).toBe(false);
		choose(field, 'inherit');
		expect(local.hidden).toBe(false);
		expect(air.hidden).toBe(true);
		choose(document.querySelector('[data-field="delivery_offer_ids"]'), 'replace');
		expect(posted().getAll('fields[delivery_offer_ids][members][]')).toEqual(['11', '601']);
	});

	it('does not guess an unknown inherited profile when switching from a saved override', () => {
		const field = document.querySelector('[data-field="fulfilment_availability"]');
		field.removeAttribute('data-inherited-fulfilment');
		choose(field, 'override');
		expect(document.querySelector('[data-profiles="in_store"]').hidden).toBe(true);
		choose(field, 'inherit');
		expect(document.querySelector('[data-profiles="in_store"]').hidden).toBe(false);
		expect(document.querySelector('[data-profiles="international_fulfilment"]').hidden).toBe(false);
		choose(document.querySelector('[data-field="delivery_offer_ids"]'), 'replace');
		expect(posted().getAll('fields[delivery_offer_ids][members][]')).toEqual(['11', '601']);
	});

	it('explains an empty search when matching rows are incompatible without clearing selections', async () => {
		const list = document.querySelector('.cetech-de-option-search');
		choose(list.closest('fieldset'), 'replace');
		list.querySelectorAll('input[type="checkbox"]').forEach((input) => { input.checked = false; });
		const search = list.querySelector('[type="search"]');
		search.value = 'Air shipping';
		search.dispatchEvent(new Event('input', { bubbles: true }));
		expect(list.querySelector('[data-cetech-de-search-empty]').hidden).toBe(false);
		choose(document.querySelector('[data-field="fulfilment_availability"]'), 'override');
		await Promise.resolve();
		expect(list.querySelector('[data-cetech-de-search-empty]').hidden).toBe(true);
		expect(posted().getAll('fields[delivery_offer_ids][members][]')).toEqual([]);
	});

	it('shows unsaved feedback for posted edits, not local search, and restores it after cancelled submit', async () => {
		const form = document.querySelector('form.cetech-de-customize-form');
		const status = form.querySelector('[role="status"]');
		const search = form.querySelector('[type="search"]');
		search.value = 'a local filter';
		search.dispatchEvent(new Event('input', { bubbles: true }));
		expect(status.hidden).toBe(true);
		choose(form.querySelector('[data-field="estimated_delivery"]'), 'override');
		expect(status.textContent).toBe('Unsaved changes');
		form.addEventListener('submit', (event) => event.preventDefault(), { once: true });
		form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
		await Promise.resolve();
		expect(status.hidden).toBe(false);
		form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
		await Promise.resolve();
		expect(status.hidden).toBe(false);
		choose(form.querySelector('[data-field="estimated_delivery"]'), 'inherit');
		expect(status.hidden).toBe(true);
	});
});
