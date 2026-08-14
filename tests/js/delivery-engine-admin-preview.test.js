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

function flush() {
	return new Promise((resolvePromise) => setTimeout(resolvePromise, 0));
}

describe('Delivery Settings Preview variation loading', () => {
	beforeEach(() => {
		document.body.innerHTML = `
			<form class="cetech-de-preview-form" data-cetech-de-preview-form>
				<select id="product_id" name="product_id" data-cetech-de-preview-product>
					<option value="">Search/select product</option>
					<option value="101" data-variable="0">Simple Lamp</option>
					<option value="39717" data-variable="1">FLAIROC Delivery Engine Variable QA Product</option>
				</select>
				<div class="cetech-de-preview-variation-row" hidden>
					<select id="variation_id" name="variation_id" data-cetech-de-preview-variation>
						<option value="">Select variation</option>
					</select>
				</div>
			</form>
		`;

		window.cetechDePreview = {
			ajaxUrl: '/admin-ajax.php',
			action: 'cetech_de_preview_variations',
			nonce: 'preview-nonce',
		};

		window.fetch = vi.fn(async () => ({
			json: async () => ({
				success: true,
				data: {
					product_id: 39717,
					variable: true,
					variations: [
						{ id: 39718, label: 'Variation A' },
						{ id: 39719, label: 'Variation B' },
					],
				},
			}),
		}));

		// Re-evaluate admin script in this jsdom document.
		// eslint-disable-next-line no-new-func
		new Function(scriptSource)();
		document.dispatchEvent(new Event('DOMContentLoaded'));
	});

	it('loads variations when a variable product is selected and clears them for simple products', async () => {
		const product = document.querySelector('[data-cetech-de-preview-product]');
		const row = document.querySelector('.cetech-de-preview-variation-row');
		const variation = document.querySelector('[data-cetech-de-preview-variation]');

		product.value = '39717';
		product.dispatchEvent(new Event('change', { bubbles: true }));
		await flush();
		await flush();

		expect(row.hidden).toBe(false);
		expect(window.fetch).toHaveBeenCalled();
		expect([...variation.options].map((o) => o.textContent)).toEqual([
			'Select variation',
			'Variation A',
			'Variation B',
		]);

		product.value = '101';
		product.dispatchEvent(new Event('change', { bubbles: true }));
		await flush();

		expect(row.hidden).toBe(true);
		expect(variation.options).toHaveLength(1);
		expect(variation.value).toBe('');
	});
});
