import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { config as loadEnv } from 'dotenv';

/**
 * Separate training-VIDEO config.
 * video: 'on' — deliberate paced scenarios only.
 * Do not point the normal smoke/capture suite at this config.
 */
loadEnv({ path: path.resolve(__dirname, '../../.env.local') });

const baseURL =
	process.env.FLAIROC_BASE_URL?.replace(/\/?$/, '/') ||
	process.env.WP_SITE_URL?.replace(/\/?$/, '/') ||
	'https://flairoc.com/intl/';

// Reuse Stage 12 / 12B human auth file (gitignored).
const storageStatePath = path.resolve(__dirname, '../playwright/auth/storage-state.json');

export default defineConfig({
	testDir: './scenarios',
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: 0,
	workers: 1,
	reporter: [['list']],
	timeout: 10 * 60_000,
	expect: { timeout: 20_000 },
	use: {
		baseURL,
		...devices['Desktop Chrome'],
		channel: 'chrome',
		viewport: { width: 1440, height: 900 },
		locale: 'en-US',
		colorScheme: 'light',
		trace: 'off',
		screenshot: 'off',
		video: {
			mode: 'on',
			size: { width: 1440, height: 900 },
		},
		actionTimeout: 20_000,
		navigationTimeout: 60_000,
		launchOptions: {
			slowMo: Number(process.env.CETECH_DE_VIDEO_SLOWMO_MS || 350),
		},
	},
	projects: [
		{
			name: 'training-video-public',
			testMatch: /storefront-training-videos\.spec\.ts/,
		},
		{
			name: 'training-video-admin',
			testMatch: /admin-training-videos\.spec\.ts/,
			use: {
				storageState: storageStatePath,
			},
		},
	],
	outputDir: 'output/test-results',
});
