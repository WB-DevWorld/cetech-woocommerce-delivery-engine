import { test as setup, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Saves authenticated WordPress admin storage state locally.
 * Credentials come from gitignored .env.local — never hard-code secrets.
 *
 * Note: Cloudflare may block automated wp-login on FLAIROC. If this fails,
 * create storage-state.json from a manual browser session instead.
 */
setup('save WordPress admin storage state @setup', async ({ page }) => {
	const username = process.env.FLAIROC_WP_USERNAME || process.env.WP_ADMIN_USER;
	const password =
		process.env.FLAIROC_WP_PASSWORD ||
		process.env.FLAIROC_WP_APP_PASSWORD ||
		process.env.WP_ADMIN_APP_PASSWORD;
	const adminUrl =
		process.env.FLAIROC_WP_ADMIN_URL?.replace(/\/?$/, '/') ||
		new URL('wp-admin/', process.env.FLAIROC_BASE_URL || 'https://flairoc.com/intl/').toString();

	setup.skip(!username || !password, 'Admin credentials not configured in .env.local');

	const out = path.resolve(__dirname, 'storage-state.json');
	await page.goto(`${adminUrl}`.replace(/\/?$/, '/') + '../wp-login.php', { waitUntil: 'domcontentloaded' });

	// Cloudflare challenge page detection — fail clearly rather than inventing auth.
	const title = await page.title();
	if (/just a moment|cloudflare|attention required/i.test(title)) {
		throw new Error(
			'Cloudflare challenge blocked automated login. Save storage-state.json manually and keep it gitignored.'
		);
	}

	await page.locator('#user_login').fill(username!);
	await page.locator('#user_pass').fill(password!);
	await page.locator('#wp-submit').click();
	await page.waitForURL(/wp-admin/, { timeout: 45_000 });
	await expect(page.locator('#wpadminbar, #wpbody')).toBeVisible({ timeout: 30_000 });

	await page.context().storageState({ path: out });
	expect(fs.existsSync(out)).toBeTruthy();
});
