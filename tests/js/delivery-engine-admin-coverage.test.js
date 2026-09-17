/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const scriptSource = readFileSync(
	resolve(root, 'assets/admin/delivery-engine-admin.js'),
	'utf8'
);

function loadAdmin() {
	// eslint-disable-next-line no-new-func
	new Function(scriptSource)();
}

function jsonResponse(data) {
	return Promise.resolve({
		json: () => Promise.resolve({ success: true, data })
	});
}

describe('Admin coverage builder', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
		window.cetechDeGeography = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			searchAction: 'cetech_de_admin_geography_search',
			searchNonce: 'nonce'
		};
		window.alert = vi.fn();
		window.fetch = vi.fn((url, init) => {
			const body = String(init && init.body ? init.body : '');
			if (body.includes('op=children')) {
				return jsonResponse({
					items: [
						{ id: 1, key: 'loc-gh', name: 'Entire Ghana', entire_country: true },
						{ id: 2, key: 'loc-ga', name: 'Greater Accra' }
					],
					root: { id: 1, key: 'loc-gh', name: 'Ghana' },
					request_token: '1'
				});
			}
			if (body.includes('select_all=1') || body.includes('op=descendants')) {
				if (body.includes('parent_key=loc-huge')) {
					return jsonResponse({
						items: [],
						recommend_entire_area: true,
						total: 500,
						request_token: '1'
					});
				}
				return jsonResponse({
					items: [
						{ id: 11, key: 'loc-accra', name: 'Accra' },
						{ id: 12, key: 'loc-tema', name: 'Tema' }
					],
					request_token: '1'
				});
			}
			return jsonResponse({
				items: [
					{ id: 11, key: 'loc-accra', name: 'Accra' },
					{ id: 12, key: 'loc-tema', name: 'Tema' }
				],
				request_token: '1'
			});
		});
	});

	it('adds a second coverage group with country, root, mode, include, exclude and postcodes', () => {
		document.body.innerHTML = `
			<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana","US":"United States"}'>
				<div data-cetech-de-coverage-groups></div>
				<button type="button" data-cetech-de-add-coverage-group>Add</button>
			</div>
		`;
		loadAdmin();
		document.querySelector('[data-cetech-de-add-coverage-group]').click();
		document.querySelector('[data-cetech-de-add-coverage-group]').click();
		const groups = document.querySelectorAll('.cetech-de-coverage-group');
		expect(groups.length).toBe(2);
		const second = groups[1];
		expect(second.querySelector('[data-cetech-de-coverage-country]')).toBeTruthy();
		expect(second.querySelector('[data-cetech-de-coverage-root]')).toBeTruthy();
		expect(second.querySelector('[data-cetech-de-coverage-mode]')).toBeTruthy();
		expect(second.querySelector('[data-cetech-de-search-target="include"]')).toBeTruthy();
		expect(second.querySelector('[data-cetech-de-search-target="exclude"]')).toBeTruthy();
		expect(second.querySelector('[data-cetech-de-postcode-table]')).toBeTruthy();
		expect(second.querySelector('[name="coverage_groups[1][country]"]')).toBeTruthy();
		expect(second.querySelector('[name="coverage_groups[1][root_location_id]"]')).toBeTruthy();
		expect(second.querySelector('[name="coverage_groups[1][root_key]"]')).toBeTruthy();
		const countryOptions = Array.from(second.querySelectorAll('[data-cetech-de-coverage-country] option')).map(
			(option) => option.value
		);
		expect(countryOptions).toContain('US');
		expect(countryOptions).toContain('GH');
	});

	it('does not auto-select search results as chips', async () => {
		vi.useFakeTimers();
		document.body.innerHTML = `
			<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana"}'>
				<fieldset class="cetech-de-coverage-group">
					<select data-cetech-de-coverage-country><option value="GH" selected>Ghana</option></select>
					<input type="hidden" data-cetech-de-root-key value="loc-ga" />
					<input data-cetech-de-locality-search data-cetech-de-search-target="include" />
					<ul data-cetech-de-search-results="include" hidden></ul>
					<ul data-cetech-de-chips="include"></ul>
				</fieldset>
			</div>
		`;
		loadAdmin();
		const input = document.querySelector('[data-cetech-de-locality-search]');
		input.value = 'Acc';
		input.dispatchEvent(new Event('input', { bubbles: true }));
		await vi.advanceTimersByTimeAsync(320);
		await Promise.resolve();
		expect(document.querySelectorAll('[data-cetech-de-chips="include"] li').length).toBe(0);
		const results = document.querySelector('[data-cetech-de-search-results="include"]');
		expect(results.hidden).toBe(false);
		expect(results.querySelectorAll('[role="option"]').length).toBe(2);
		vi.useRealTimers();
	});

	it('adds an explicit search choice as a chip and can remove it', async () => {
		vi.useFakeTimers();
		document.body.innerHTML = `
			<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana"}'>
				<fieldset class="cetech-de-coverage-group">
					<select data-cetech-de-coverage-country><option value="GH" selected>Ghana</option></select>
					<input type="hidden" data-cetech-de-root-key value="loc-ga" />
					<input data-cetech-de-locality-search data-cetech-de-search-target="include" />
					<ul data-cetech-de-search-results="include" hidden></ul>
					<ul data-cetech-de-chips="include"></ul>
				</fieldset>
			</div>
		`;
		loadAdmin();
		const input = document.querySelector('[data-cetech-de-locality-search]');
		input.value = 'Acc';
		input.dispatchEvent(new Event('input', { bubbles: true }));
		await vi.advanceTimersByTimeAsync(320);
		await Promise.resolve();
		const option = document.querySelector('[data-cetech-de-search-results="include"] [role="option"]');
		option.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		expect(document.querySelectorAll('[data-cetech-de-chips="include"] li').length).toBe(1);
		document.querySelector('[data-remove-member]').click();
		expect(document.querySelectorAll('[data-cetech-de-chips="include"] li').length).toBe(0);
		vi.useRealTimers();
	});

	it('select all adds bounded members and large areas switch to entire area', async () => {
		document.body.innerHTML = `
			<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana"}'>
				<fieldset class="cetech-de-coverage-group">
					<select data-cetech-de-coverage-country><option value="GH" selected>Ghana</option></select>
					<select data-cetech-de-coverage-mode>
						<option value="selected_descendants" selected>Selected</option>
						<option value="entire_area">Entire</option>
					</select>
					<input type="hidden" data-cetech-de-root-key value="loc-ga" />
					<ul data-cetech-de-chips="include"></ul>
					<button type="button" data-cetech-de-select-all>Select all</button>
				</fieldset>
			</div>
		`;
		loadAdmin();
		document.querySelector('[data-cetech-de-select-all]').click();
		await expect.poll(() => document.querySelectorAll('[data-cetech-de-chips="include"] li').length).toBe(2);

		document.querySelector('[data-cetech-de-root-key]').value = 'loc-huge';
		document.querySelector('[data-cetech-de-chips="include"]').innerHTML = '';
		document.querySelector('[data-cetech-de-select-all]').click();
		await expect.poll(() => document.querySelector('[data-cetech-de-coverage-mode]').value).toBe('entire_area');
	});

	it('exclusion search adds and removes exclude chips independently', async () => {
		vi.useFakeTimers();
		document.body.innerHTML = `
			<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana"}'>
				<fieldset class="cetech-de-coverage-group">
					<select data-cetech-de-coverage-country><option value="GH" selected>Ghana</option></select>
					<select data-cetech-de-coverage-mode>
						<option value="entire_except" selected>Except</option>
					</select>
					<input type="hidden" data-cetech-de-root-key value="loc-ga" />
					<div data-cetech-de-exclude-panel>
						<input data-cetech-de-locality-search data-cetech-de-search-target="exclude" />
						<ul data-cetech-de-search-results="exclude" hidden></ul>
						<ul data-cetech-de-chips="exclude"></ul>
					</div>
					<ul data-cetech-de-chips="include"></ul>
				</fieldset>
			</div>
		`;
		loadAdmin();
		const input = document.querySelector('[data-cetech-de-search-target="exclude"]');
		input.value = 'Acc';
		input.dispatchEvent(new Event('input', { bubbles: true }));
		await vi.advanceTimersByTimeAsync(320);
		await Promise.resolve();
		document.querySelector('[data-cetech-de-search-results="exclude"] [role="option"]').click();
		expect(document.querySelectorAll('[data-cetech-de-chips="exclude"] li').length).toBe(1);
		expect(document.querySelector('[name="coverage_groups[0][exclusions][]"]')).toBeTruthy();
		expect(document.querySelectorAll('[data-cetech-de-chips="include"] li').length).toBe(0);
		document.querySelector('[data-cetech-de-chips="exclude"] [data-remove-member]').click();
		expect(document.querySelectorAll('[data-cetech-de-chips="exclude"] li').length).toBe(0);
		vi.useRealTimers();
	});

	it('uses independent search tokens for two coverage groups', async () => {
		vi.useFakeTimers();
		const tokens = [];
		window.fetch = vi.fn((url, init) => {
			const body = String(init && init.body ? init.body : '');
			const match = body.match(/request_token=(\d+)/);
			tokens.push(match ? match[1] : '');
			return jsonResponse({
				items: [{ id: 11, key: 'loc-accra', name: 'Accra' }],
				request_token: match ? match[1] : '1',
				has_more: false,
				total: 1
			});
		});
		document.body.innerHTML = `
			<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana"}'>
				<fieldset class="cetech-de-coverage-group">
					<select data-cetech-de-coverage-country><option value="GH" selected>Ghana</option></select>
					<input type="hidden" data-cetech-de-root-key value="loc-ga" />
					<input data-cetech-de-locality-search data-cetech-de-search-target="include" />
					<ul data-cetech-de-search-results="include" hidden></ul>
				</fieldset>
				<fieldset class="cetech-de-coverage-group">
					<select data-cetech-de-coverage-country><option value="GH" selected>Ghana</option></select>
					<input type="hidden" data-cetech-de-root-key value="loc-ga" />
					<input data-cetech-de-locality-search data-cetech-de-search-target="include" />
					<ul data-cetech-de-search-results="include" hidden></ul>
				</fieldset>
			</div>
		`;
		loadAdmin();
		const inputs = document.querySelectorAll('[data-cetech-de-locality-search]');
		inputs[0].value = 'Acc';
		inputs[0].dispatchEvent(new Event('input', { bubbles: true }));
		inputs[1].value = 'Tem';
		inputs[1].dispatchEvent(new Event('input', { bubbles: true }));
		await vi.advanceTimersByTimeAsync(320);
		await Promise.resolve();
		expect(tokens.length).toBe(2);
		expect(tokens[0]).toBe('1');
		expect(tokens[1]).toBe('1');
		vi.useRealTimers();
	});

	it('applies the server administrative label after country change', async () => {
		window.fetch = vi.fn(() =>
			jsonResponse({
				items: [{ id: 2, key: 'loc-ga', name: 'Greater Accra' }],
				label: 'Region',
				request_token: '1'
			})
		);
		document.body.innerHTML = `
			<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana","US":"United States"}'>
				<fieldset class="cetech-de-coverage-group">
					<select data-cetech-de-coverage-country>
						<option value="">Select…</option>
						<option value="GH">Ghana</option>
						<option value="US">United States</option>
					</select>
					<div data-cetech-de-admin-browser>
						<p><label>Administrative area<br /><select data-cetech-de-coverage-root><option value="">Select…</option></select></label></p>
					</div>
				</fieldset>
			</div>
		`;
		loadAdmin();
		const country = document.querySelector('[data-cetech-de-coverage-country]');
		country.value = 'GH';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('[data-cetech-de-admin-browser] label')?.textContent || '').toContain('Region');
	});

	it('shows disambiguated search labels without selecting them as the stored name', async () => {
		vi.useFakeTimers();
		window.fetch = vi.fn(() =>
			jsonResponse({
				items: [
					{
						id: 11,
						key: 'loc-akwatia',
						name: 'Akwatia',
						label: 'Akwatia — Denkyembour District, Eastern Region'
					}
				],
				request_token: '1'
			})
		);
		document.body.innerHTML = `
			<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana"}'>
				<fieldset class="cetech-de-coverage-group">
					<select data-cetech-de-coverage-country><option value="GH" selected>Ghana</option></select>
					<input type="hidden" data-cetech-de-root-key value="loc-gh" />
					<input data-cetech-de-locality-search data-cetech-de-search-target="include" />
					<ul data-cetech-de-search-results="include" hidden></ul>
					<ul data-cetech-de-chips="include"></ul>
				</fieldset>
			</div>
		`;
		loadAdmin();
		const input = document.querySelector('[data-cetech-de-locality-search]');
		input.value = 'Akw';
		input.dispatchEvent(new Event('input', { bubbles: true }));
		await vi.advanceTimersByTimeAsync(320);
		await Promise.resolve();
		const option = document.querySelector('[data-cetech-de-search-results="include"] [role="option"]');
		expect(option.textContent).toContain('Denkyembour District');
		expect(option.getAttribute('aria-label')).toContain('Eastern Region');
		option.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		const chip = document.querySelector('[data-cetech-de-chips="include"] li');
		expect(chip.textContent).toContain('Akwatia');
		expect(chip.textContent).not.toContain('Denkyembour');
		expect(chip.querySelector('input[type="hidden"]').value).toBe('11');
		vi.useRealTimers();
	});

	it('removes a middle coverage group and reindexes remaining form names', () => {
		window.confirm = vi.fn(() => true);
		document.body.innerHTML = `
			<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana","US":"United States","CA":"Canada"}'>
				<div data-cetech-de-coverage-groups></div>
				<button type="button" data-cetech-de-add-coverage-group>Add</button>
			</div>
		`;
		loadAdmin();
		document.querySelector('[data-cetech-de-add-coverage-group]').click();
		document.querySelector('[data-cetech-de-add-coverage-group]').click();
		document.querySelector('[data-cetech-de-add-coverage-group]').click();
		const groups = document.querySelectorAll('.cetech-de-coverage-group');
		expect(groups.length).toBe(3);
		groups[0].querySelector('[data-cetech-de-coverage-country]').value = 'GH';
		groups[1].querySelector('[data-cetech-de-coverage-country]').value = 'US';
		groups[2].querySelector('[data-cetech-de-coverage-country]').value = 'CA';
		groups[1].querySelector('[data-cetech-de-remove-coverage-group]').click();
		const remaining = document.querySelectorAll('.cetech-de-coverage-group');
		expect(remaining.length).toBe(2);
		expect(remaining[0].querySelector('[name="coverage_groups[0][country]"]').value).toBe('GH');
		expect(remaining[1].querySelector('[name="coverage_groups[1][country]"]').value).toBe('CA');
		expect(remaining[1].querySelector('legend').textContent).toBe('Coverage group 2');
	});
});
