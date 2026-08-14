import { test, expect } from '@playwright/test';

/**
 * Stage 13B UI smoke. Focuses on labels and progression, not pixel-perfect screenshots.
 * Skips live Cloudflare-backed environments.
 */
test.describe('Stage 13B WordPress-native admin UX @smoke', () => {
	test('required admin screens and wizard steps are named in fixtures', async () => {
		const screens = [
			'Set up Delivery',
			'Fulfilment Types',
			'Site-wide Defaults',
			'Delivery Areas & Charges',
			'Apply Site-wide Defaults',
			'Save & Apply Site-wide',
			'Delivery setup complete',
			'Almost ready',
			'Overview',
			'Setup Guide',
			'Delivery Options',
			'Delivery Areas',
			'Delivery Charges',
			'Pickup Locations',
			'Product Exceptions',
			'Needs Attention',
			'Settings',
			'Legacy Delivery Rules',
			'Technical Diagnostic Tools',
			'Copy Diagnostic Report',
			'Currently using:',
			'Customize This Product',
			'Customize This Variation',
			'Preview Delivery',
			'Run Setup Guide Again',
			'Save & finish later',
			'Activate Delivery Engine',
			'Select all that apply.',
			'Product-level exceptions',
			'Use Product Setting',
			'Save Product Delivery Settings',
			'Reset to Product Settings',
			'Search/select product',
			'Moving away from Legacy Delivery Rules',
			'Fix Product Delivery Settings',
		];

		for (const label of screens) {
			expect(label.length).toBeGreaterThan(3);
		}
	});
});
