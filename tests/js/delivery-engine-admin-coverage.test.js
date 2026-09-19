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

	it('ignores a stale admin root response after a newer country is selected', async () => {
		const deferred = {};
		window.fetch = vi.fn((url, init) => {
			const body = String(init && init.body ? init.body : '');
			if (body.includes('op=children') && body.includes('country=GH') && !body.includes('parent_key=')) {
				return new Promise((resolve) => {
					deferred.resolveGhana = resolve;
				});
			}
			if (body.includes('op=children') && body.includes('country=GB')) {
				return jsonResponse({
					items: [
						{ id: 20, key: 'loc-gb', name: 'Entire United Kingdom', entire_country: true },
						{ id: 21, key: 'loc-eng', name: 'England' }
					],
					root: { id: 20, key: 'loc-gb', name: 'United Kingdom' },
					request_token: '2'
				});
			}
			return jsonResponse({ items: [], request_token: '0' });
		});
		document.body.innerHTML = `
			<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana","GB":"United Kingdom"}'>
				<fieldset class="cetech-de-coverage-group">
					<select data-cetech-de-coverage-country>
						<option value="">Select…</option>
						<option value="GH">Ghana</option>
						<option value="GB">United Kingdom</option>
					</select>
					<select data-cetech-de-coverage-root><option value="">Select…</option></select>
					<input type="hidden" data-cetech-de-root-key value="" />
				</fieldset>
			</div>
		`;
		loadAdmin();
		const country = document.querySelector('[data-cetech-de-coverage-country]');
		country.value = 'GH';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		country.value = 'GB';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await vi.waitFor(() => {
			expect(document.querySelector('[data-cetech-de-coverage-root] option[value="loc-eng"]')).not.toBeNull();
		});
		deferred.resolveGhana({
			json: () => Promise.resolve({
				success: true,
				data: {
					items: [
						{ id: 1, key: 'loc-gh', name: 'Entire Ghana', entire_country: true },
						{ id: 2, key: 'loc-ga', name: 'Greater Accra' }
					],
					root: { id: 1, key: 'loc-gh', name: 'Ghana' },
					request_token: '1'
				}
			})
		});
		await new Promise((resolve) => setTimeout(resolve, 40));
		expect(document.querySelector('[data-cetech-de-coverage-root] option[value="loc-ga"]')).toBeNull();
		expect(document.querySelector('[data-cetech-de-coverage-root] option[value="loc-eng"]')).not.toBeNull();
	});

	it('submits confirm_drop_canonical using the actual unchecked checkbox', () => {
		window.confirm = vi.fn(() => true);
		document.body.innerHTML = `
			<form>
				<input type="checkbox" name="confirm_drop_canonical" value="1" />
				<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana"}'>
					<div data-cetech-de-coverage-groups>
						<fieldset class="cetech-de-coverage-group">
							<input type="hidden" name="coverage_groups[0][country]" value="GH" />
							<button type="button" data-cetech-de-remove-coverage-group>Remove</button>
						</fieldset>
					</div>
				</div>
			</form>
		`;
		loadAdmin();
		const checkbox = document.querySelector('input[name="confirm_drop_canonical"]');
		expect(checkbox.checked).toBe(false);
		document.querySelector('[data-cetech-de-remove-coverage-group]').click();
		expect(document.querySelectorAll('.cetech-de-coverage-group').length).toBe(0);
		expect(checkbox.checked).toBe(true);
		expect(checkbox.value).toBe('1');
	});

	it('loads administrative child 51 and 251 through bounded pages and restores a saved later-page root', async () => {
		const pages = {
			1: Array.from({ length: 50 }, (_, i) => ({ id: i + 1, key: `adm-${i + 1}`, name: `Admin ${i + 1}` })),
			2: Array.from({ length: 50 }, (_, i) => ({ id: i + 51, key: `adm-${i + 51}`, name: `Admin ${i + 51}` })),
			3: Array.from({ length: 50 }, (_, i) => ({ id: i + 101, key: `adm-${i + 101}`, name: `Admin ${i + 101}` })),
			4: Array.from({ length: 50 }, (_, i) => ({ id: i + 151, key: `adm-${i + 151}`, name: `Admin ${i + 151}` })),
			5: Array.from({ length: 50 }, (_, i) => ({ id: i + 201, key: `adm-${i + 201}`, name: `Admin ${i + 201}` })),
			6: [{ id: 251, key: 'adm-251', name: 'Admin 251' }]
		};
		window.fetch = vi.fn((url, init) => {
			const body = String(init && init.body ? init.body : '');
			const pageMatch = body.match(/page=(\d+)/);
			const page = pageMatch ? Number(pageMatch[1]) : 1;
			if (body.includes('op=children')) {
				const items = pages[page] || [];
				return jsonResponse({
					items,
					page,
					total: 251,
					has_more: page < 6,
					request_token: String(body.match(/request_token=(\d+)/)?.[1] || '1')
				});
			}
			return jsonResponse({ items: [] });
		});
		document.body.innerHTML = `
			<form>
				<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana"}'>
					<div data-cetech-de-coverage-groups>
						<fieldset class="cetech-de-coverage-group">
							<select data-cetech-de-coverage-country><option value="GH" selected>Ghana</option></select>
							<select data-cetech-de-coverage-root data-cetech-de-saved-root="adm-51"><option value="">Select…</option></select>
						</fieldset>
					</div>
				</div>
			</form>
		`;
		loadAdmin();
		const country = document.querySelector('[data-cetech-de-coverage-country]');
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('option[value="adm-51"]')).not.toBeNull();
		for (let i = 0; i < 5; i += 1) {
			document.querySelector('[data-cetech-de-load-more-roots]')?.click();
			await new Promise((resolve) => setTimeout(resolve, 20));
		}
		await expect.poll(() => document.querySelector('option[value="adm-251"]')).not.toBeNull();
	});

	it('ignores a stale page response after Country change', async () => {
		let resolveStale;
		window.fetch = vi.fn((url, init) => {
			const body = String(init && init.body ? init.body : '');
			if (body.includes('country=GH') && body.includes('page=2')) {
				return new Promise((resolve) => {
					resolveStale = resolve;
				});
			}
			if (body.includes('country=GB')) {
				return jsonResponse({
					items: [{ id: 9, key: 'loc-eng', name: 'England' }],
					page: 1,
					has_more: false,
					request_token: String(body.match(/request_token=(\d+)/)?.[1] || '2')
				});
			}
			return jsonResponse({
				items: [{ id: 1, key: 'loc-ga', name: 'Greater Accra' }],
				page: 1,
				has_more: true,
				request_token: String(body.match(/request_token=(\d+)/)?.[1] || '1')
			});
		});
		document.body.innerHTML = `
			<form>
				<div data-cetech-de-coverage-builder data-countries='{"GH":"Ghana","GB":"United Kingdom"}'>
					<div data-cetech-de-coverage-groups>
						<fieldset class="cetech-de-coverage-group">
							<select data-cetech-de-coverage-country>
								<option value="GH" selected>Ghana</option>
								<option value="GB">United Kingdom</option>
							</select>
							<select data-cetech-de-coverage-root><option value="">Select…</option></select>
						</fieldset>
					</div>
				</div>
			</form>
		`;
		loadAdmin();
		const country = document.querySelector('[data-cetech-de-coverage-country]');
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('option[value="loc-ga"]')).not.toBeNull();
		document.querySelector('[data-cetech-de-load-more-roots]').click();
		country.value = 'GB';
		country.dispatchEvent(new Event('change', { bubbles: true }));
		await expect.poll(() => document.querySelector('option[value="loc-eng"]')).not.toBeNull();
		if (resolveStale) {
			resolveStale({
				json: () => Promise.resolve({
					success: true,
					data: {
						items: [{ id: 51, key: 'adm-51', name: 'Stale Admin 51' }],
						page: 2,
						has_more: false,
						request_token: '1'
					}
				})
			});
		}
		await new Promise((resolve) => setTimeout(resolve, 40));
		expect(document.querySelector('option[value="adm-51"]')).toBeNull();
		expect(document.querySelector('option[value="loc-eng"]')).not.toBeNull();
	});
});
