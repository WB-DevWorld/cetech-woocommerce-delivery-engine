/**
 * @vitest-environment jsdom
 */
import { beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const scriptSource = readFileSync(
	resolve(root, 'assets/admin/delivery-engine-admin.js'),
	'utf8'
);

describe('Delivery Area country picker controls', () => {
	beforeAll(() => {
		// eslint-disable-next-line no-new-func
		new Function(scriptSource)();
	});

	beforeEach(() => {
		document.body.innerHTML = `
			<div data-cetech-de-condition-builder>
				<table class="cetech-de-condition-table"><tbody>
					<tr class="cetech-de-condition-row">
						<td>
							<select name="destination_rules[0][rule_type]" data-cetech-de-rule-type>
								<option value="">— Select —</option>
								<option value="country">Country</option>
								<option value="region">State / Region</option>
							</select>
						</td>
						<td class="cetech-de-condition-value" data-cetech-de-condition-value data-cetech-de-value-name="destination_rules[0][rule_value]">
							<select class="cetech-de-country-select" data-cetech-de-country-select hidden disabled>
								<option value="">Select a country</option>
								<option value="GH">Ghana</option>
								<option value="GB">United Kingdom (UK)</option>
							</select>
							<input type="text" class="regular-text cetech-de-rule-text" data-cetech-de-rule-text name="destination_rules[0][rule_value]" value="" />
						</td>
					</tr>
				</tbody></table>
				<button type="button" data-cetech-de-add-condition>+ Add</button>
				<table class="hidden"><tbody>
					<tr data-cetech-de-condition-template>
						<td>
							<select name="destination_rules[{{index}}][rule_type]" data-cetech-de-rule-type>
								<option value="">— Select —</option>
								<option value="country">Country</option>
							</select>
						</td>
						<td class="cetech-de-condition-value" data-cetech-de-condition-value data-cetech-de-value-name="destination_rules[{{index}}][rule_value]">
							<select class="cetech-de-country-select" data-cetech-de-country-select hidden disabled>
								<option value="">Select a country</option>
								<option value="NG">Nigeria</option>
							</select>
							<input type="text" class="regular-text cetech-de-rule-text" data-cetech-de-rule-text name="destination_rules[{{index}}][rule_value]" value="" />
						</td>
					</tr>
				</tbody></table>
			</div>
		`;

		document.dispatchEvent(new Event('DOMContentLoaded'));
	});

	it('moves the submitted name onto the country select when Country is chosen', () => {
		const row = document.querySelector('.cetech-de-condition-row');
		const type = row.querySelector('[data-cetech-de-rule-type]');
		const country = row.querySelector('[data-cetech-de-country-select]');
		const text = row.querySelector('[data-cetech-de-rule-text]');

		type.value = 'country';
		type.dispatchEvent(new Event('change', { bubbles: true }));

		expect(country.hidden).toBe(false);
		expect(country.disabled).toBe(false);
		expect(country.getAttribute('name')).toBe('destination_rules[0][rule_value]');
		expect(text.hidden).toBe(true);
		expect(text.disabled).toBe(true);
		expect(text.hasAttribute('name')).toBe(false);

		country.value = 'GH';
		expect(country.value).toBe('GH');
	});

	it('restores the text field for non-country conditions', () => {
		const row = document.querySelector('.cetech-de-condition-row');
		const type = row.querySelector('[data-cetech-de-rule-type]');
		const country = row.querySelector('[data-cetech-de-country-select]');
		const text = row.querySelector('[data-cetech-de-rule-text]');

		type.value = 'country';
		type.dispatchEvent(new Event('change', { bubbles: true }));
		type.value = 'region';
		type.dispatchEvent(new Event('change', { bubbles: true }));

		expect(country.hidden).toBe(true);
		expect(country.disabled).toBe(true);
		expect(text.hidden).toBe(false);
		expect(text.getAttribute('name')).toBe('destination_rules[0][rule_value]');
	});

	it('syncs cloned condition rows', () => {
		document.querySelector('[data-cetech-de-add-condition]').click();
		const rows = document.querySelectorAll('.cetech-de-condition-table tbody .cetech-de-condition-row');
		expect(rows).toHaveLength(2);

		const type = rows[1].querySelector('[data-cetech-de-rule-type]');
		const country = rows[1].querySelector('[data-cetech-de-country-select]');
		type.value = 'country';
		type.dispatchEvent(new Event('change', { bubbles: true }));
		expect(country.getAttribute('name')).toBe('destination_rules[1][rule_value]');
	});
});
