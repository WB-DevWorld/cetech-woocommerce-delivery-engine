import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { config as loadEnv } from 'dotenv';

// Load secrets from repo-root .env.local only (gitignored). Never commit credentials.
loadEnv({ path: path.resolve(__dirname, '../../.env.local') });

const baseURL =
	process.env.FLAIROC_BASE_URL?.replace(/\/?$/, '/') ||
	process.env.WP_SITE_URL?.replace(/\/?$/, '/') ||
	'https://flairoc.com/intl/';

const storageStatePath = path.resolve(__dirname, 'auth/storage-state.json');

/**
 * Documentation / training Playwright harness for CETECH Delivery Engine 1.0.0-rc.2.
 * Focus: user-visible surfaces for screenshots and tutorial validation — not a PHPUnit replacement.
 */
export default defineConfig({
	testDir: './scenarios',
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: 0,
	workers: 1,
	reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
	timeout: 60_000,
	expect: { timeout: 15_000 },
	use: {
		baseURL,
		...devices['Desktop Chrome'],
		viewport: { width: 1440, height: 1000 },
		locale: 'en-US',
		colorScheme: 'light',
		trace: 'off',
		video: 'off',
		screenshot: 'off',
		actionTimeout: 15_000,
		navigationTimeout: 45_000,
	},
	projects: [
		{
			name: 'public',
			testMatch: /customer-|cart-|checkout-/,
			use: {
				// Reuse human-assisted FLAIROC session so /intl/ storefront is not CF-blocked when possible.
				storageState: storageStatePath,
			},
		},
		{
			name: 'admin',
			testMatch: /admin-|default-|product-|variation-|preview-|legacy-|order-/,
			use: {
				storageState: storageStatePath,
			},
		},
		{
			name: 'setup',
			testMatch: /save-storage-state/,
			testDir: './auth',
		},
	],
	outputDir: 'test-results',
});
