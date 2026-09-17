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
});
