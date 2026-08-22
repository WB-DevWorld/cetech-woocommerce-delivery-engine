/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const scriptSource = readFileSync(resolve(root, 'assets/admin/bulk-tools.js'), 'utf8');

describe('Bulk Tools Catalog progressive disclosure', () => {
	beforeEach(() => {
		document.body.innerHTML = `
			<form class="cetech-de-bulk-form" data-cetech-de-bulk-catalog>
				<select id="cetech-de-target-scope" name="target_scope">
					<option value="selected_ids" selected>Search and select products</option>
					<option value="matching_filters">All products matching filters</option>
					<option value="entire_catalog">Entire catalog</option>
				</select>
				<div data-reveal-scope="selected_ids">products</div>
				<div data-reveal-scope="matching_filters" hidden>filters</div>
				<div data-reveal-scope="entire_catalog" hidden>confirm</div>
				<select id="cetech-de-fulfilment-action" name="fulfilment_action">
					<option value="no_change" selected>No change</option>
					<option value="set_override">Set</option>
				</select>
				<div data-reveal-fulfilment="set_override" hidden>fulfilment value</div>
				<select id="cetech-de-offers-action" name="offers_action">
					<option value="no_change" selected>No change</option>
					<option value="add">Add</option>
					<option value="remove">Remove</option>
					<option value="replace">Replace</option>
				</select>
				<div data-reveal-offers="add,remove,replace" hidden>offer picker</div>
				<label><input id="cetech-de-reset-scope" type="checkbox" name="reset_entire_scope" value="1" /> reset</label>
				<p data-reveal-reset="1" hidden>restores Site-wide inheritance</p>
			</form>
		`;
		// eslint-disable-next-line no-new-func
		new Function(scriptSource)();
	});

	it('hides irrelevant controls until the matching action is chosen', () => {
		const form = document.querySelector('[data-cetech-de-bulk-catalog]');
		window.cetechDeBulkCatalog.sync(form);

		expect(form.querySelector('[data-reveal-scope="selected_ids"]').hidden).toBe(false);
		expect(form.querySelector('[data-reveal-scope="matching_filters"]').hidden).toBe(true);
		expect(form.querySelector('[data-reveal-fulfilment]').hidden).toBe(true);
		expect(form.querySelector('[data-reveal-offers]').hidden).toBe(true);
		expect(form.querySelector('[data-reveal-reset]').hidden).toBe(true);

		form.querySelector('#cetech-de-target-scope').value = 'matching_filters';
		form.querySelector('#cetech-de-fulfilment-action').value = 'set_override';
		form.querySelector('#cetech-de-offers-action').value = 'add';
		form.querySelector('#cetech-de-reset-scope').checked = true;
		window.cetechDeBulkCatalog.sync(form);

		expect(form.querySelector('[data-reveal-scope="selected_ids"]').hidden).toBe(true);
		expect(form.querySelector('[data-reveal-scope="matching_filters"]').hidden).toBe(false);
		expect(form.querySelector('[data-reveal-fulfilment]').hidden).toBe(false);
		expect(form.querySelector('[data-reveal-offers]').hidden).toBe(false);
		expect(form.querySelector('[data-reveal-reset]').hidden).toBe(false);
	});
});
