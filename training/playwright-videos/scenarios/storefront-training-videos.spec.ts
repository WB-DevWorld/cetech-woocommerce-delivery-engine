import { test, expect } from '@playwright/test';
import {
	QA,
	isAccessBlocked,
	promoteVideoFromTestInfo,
	canonicalNameFromTitle,
	teachPause,
} from '../helpers/video-env';

/**
 * Paced storefront videos 07–08 (public project — no admin storage required).
 * Still subject to Cloudflare; skip cleanly for HUMAN fallback.
 * Base: https://flairoc.com/intl/
 */

test.afterEach(async ({}, testInfo) => {
	const name = canonicalNameFromTitle(testInfo.title);
	if (!name) return;
	await promoteVideoFromTestInfo(testInfo, name);
});

test('07 customer cart checkout @video07', async ({ page }, testInfo) => {
	testInfo.setTimeout(8 * 60_000);
	await page.goto(QA.simpleProductPath, { waitUntil: 'domcontentloaded' });
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked storefront — use HUMAN recording');
	await teachPause(page, 2800);
	const delivery = page.getByText(/Delivery options/i).first();
	if (await delivery.isVisible().catch(() => false)) {
		await teachPause(page, 2200);
		const option = page.locator('input[type="radio"][name*="delivery"], .cetech-de-delivery-selector input').first();
		if (await option.isVisible().catch(() => false)) {
			await option.check({ force: true }).catch(() => undefined);
			await teachPause(page, 1500);
		}
	}
	const add = page.getByRole('button', { name: /add to cart/i }).first();
	if (await add.isVisible().catch(() => false)) {
		await add.click();
		await teachPause(page, 2000);
	}
	await page.goto('/cart/', { waitUntil: 'domcontentloaded' });
	await teachPause(page, 2500);
	await page.reload({ waitUntil: 'domcontentloaded' });
	await teachPause(page, 2000);
	await page.goto('/checkout/', { waitUntil: 'domcontentloaded' });
	await teachPause(page, 2800);
	// Stop before payment — never submit order for video.
	await expect(page.locator('body')).toBeVisible();
	await teachPause(page, 2000);
});

test('08 multi-product shipping @video08', async ({ page }, testInfo) => {
	testInfo.setTimeout(8 * 60_000);
	await page.goto(QA.simpleProductPath, { waitUntil: 'domcontentloaded' });
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked storefront — use HUMAN recording');
	await teachPause(page, 2000);
	const option = page.locator('input[type="radio"][name*="delivery"], .cetech-de-delivery-selector input').first();
	if (await option.isVisible().catch(() => false)) {
		await option.check({ force: true }).catch(() => undefined);
		await teachPause(page, 1200);
	}
	const add = page.getByRole('button', { name: /add to cart/i }).first();
	if (await add.isVisible().catch(() => false)) {
		await add.click();
		await teachPause(page, 1800);
	}
	// Second compatible line: reopen same QA product when a second distinct QA URL is unavailable.
	await page.goto(QA.simpleProductPath, { waitUntil: 'domcontentloaded' });
	if (await option.isVisible().catch(() => false)) {
		await page.locator('input[type="radio"][name*="delivery"], .cetech-de-delivery-selector input').first().check({ force: true }).catch(() => undefined);
	}
	if (await add.isVisible().catch(() => false)) {
		await page.getByRole('button', { name: /add to cart/i }).first().click().catch(() => undefined);
		await teachPause(page, 1800);
	}
	await page.goto('/cart/', { waitUntil: 'domcontentloaded' });
	await teachPause(page, 3000);
	await page.goto('/checkout/', { waitUntil: 'domcontentloaded' });
	await teachPause(page, 3000);
});
