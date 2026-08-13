import { defineConfig } from '@playwright/test';

export default defineConfig({
	testDir: '.',
	testMatch: '*.spec.ts',
	timeout: 15_000,
	fullyParallel: true,
	forbidOnly: !!process.env.CI,
	retries: 0,
	use: {
		trace: 'off',
	},
});
