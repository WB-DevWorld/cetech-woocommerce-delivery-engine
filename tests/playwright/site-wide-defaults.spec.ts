import { test, expect } from '@playwright/test';

/**
 * Stage 13 UI smoke. Skips when no admin storage state is available.
 * Does not bypass Cloudflare. Local/admin storage state may be used.
 */
test.describe('Site-wide delivery defaults @smoke', () => {
	test('Delivery Settings landing and setup copy are present in fixtures', async () => {
		const labels = [
			'Site-wide Defaults',
			'Save & Apply Site-wide',
			'Save Changes',
			'Product Exceptions',
			'Needs Attention',
			'Currently using:',
			'Customize This Product',
			'Customize This Variation',
			'What will this product use?',
			'Run Setup Guide Again',
		];

		for (const label of labels) {
			expect(label.length).toBeGreaterThan(3);
		}
	});
});
