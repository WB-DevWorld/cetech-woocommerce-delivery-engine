import { test, expect } from '@playwright/test';
import {
	ADMIN_PAGES,
	QA,
	adminPath,
	canonicalNameFromTitle,
	hasAdminStorageState,
	isAccessBlocked,
	promoteVideoFromTestInfo,
	redactSensitiveUi,
	teachPause,
} from '../helpers/video-env';

/**
 * Paced training videos 01–06, 09–12 (admin).
 * Requires human auth storage state from training/playwright/auth/.
 * Base URL: https://flairoc.com/intl/
 */

test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page }, testInfo) => {
	test.skip(!hasAdminStorageState(), 'Run npm run training:auth first (human Cloudflare/login).');
	testInfo.setTimeout(10 * 60_000);
});

test.afterEach(async ({}, testInfo) => {
	const name = canonicalNameFromTitle(testInfo.title);
	if (!name) return;
	// Promote even on soft failures when a video attachment exists (training draft).
	await promoteVideoFromTestInfo(testInfo, name);
});

test('01 getting started overview @video01', async ({ page }, testInfo) => {
	await adminPath(page, ADMIN_PAGES.dashboard);
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked — use HUMAN recording shot list');
	await redactSensitiveUi(page);
	await teachPause(page, 2500);
	await expect(page.getByText(/Delivery Engine|Delivery readiness|CETECH/i).first()).toBeVisible();

	await adminPath(page, ADMIN_PAGES.deliverySettingsDefault);
	await teachPause(page, 2200);
	await expect(page.getByText(/Delivery Settings/i).first()).toBeVisible();

	await page.getByText(/Product-Specific Settings/i).first().click({ trial: true }).catch(() => undefined);
	await adminPath(page, ADMIN_PAGES.deliverySettingsProduct);
	await teachPause(page, 1800);
	await adminPath(page, ADMIN_PAGES.deliverySettingsVariation);
	await teachPause(page, 1800);

	await adminPath(page, ADMIN_PAGES.preview);
	await teachPause(page, 2200);

	for (const p of [ADMIN_PAGES.offers, ADMIN_PAGES.zones, ADMIN_PAGES.rateCards]) {
		await adminPath(page, p);
		await teachPause(page, 1600);
	}

	const orderId = process.env.CETECH_DE_QA_ORDER_ID || QA.orderVariable;
	await adminPath(page, `admin.php?page=wc-orders&action=edit&id=${orderId}`);
	await redactSensitiveUi(page);
	await teachPause(page, 2000);
	let deliveryPanel = page.locator('#cetech-de-order-delivery, .cetech-de-order-delivery, #cetech_de_order_delivery').first();
	if (!(await deliveryPanel.isVisible().catch(() => false))) {
		await adminPath(page, `post.php?post=${orderId}&action=edit`);
		await redactSensitiveUi(page);
		await teachPause(page, 2000);
		deliveryPanel = page.locator('#cetech-de-order-delivery, .cetech-de-order-delivery, #cetech_de_order_delivery').first();
	}
	// Prefer the panel/heading — avoid the hidden metabox "hide" label matching getByText.
	const deliveryHeading = page.getByRole('heading', { name: /Delivery information/i }).first();
	if (await deliveryHeading.isVisible().catch(() => false)) {
		await teachPause(page, 2800);
	} else if (await deliveryPanel.isVisible().catch(() => false)) {
		await teachPause(page, 2800);
	} else {
		// Still show the order screen for teaching; do not fail the whole overview on panel chrome.
		await expect(page.locator('#wpadminbar, #wpbody-content').first()).toBeVisible();
		await teachPause(page, 2500);
	}

	await adminPath(page, ADMIN_PAGES.deliverySettingsDefault);
	await teachPause(page, 2000);
});

test('02 default delivery settings @video02', async ({ page }, testInfo) => {
	await adminPath(page, ADMIN_PAGES.deliverySettingsDefault);
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked — use HUMAN recording');
	await redactSensitiveUi(page);
	await teachPause(page, 2500);
	await expect(page.getByText(/Delivery Settings|Default/i).first()).toBeVisible();
	await page.mouse.wheel(0, 400);
	await teachPause(page, 2200);
	await page.mouse.wheel(0, 400);
	await teachPause(page, 2200);
	await adminPath(page, ADMIN_PAGES.preview);
	await teachPause(page, 2500);
});

test('03 configure simple product @video03', async ({ page }, testInfo) => {
	await adminPath(
		page,
		`${ADMIN_PAGES.deliverySettingsProduct}&scope_id=${QA.simpleProductId}`
	);
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked — use HUMAN recording');
	await redactSensitiveUi(page);
	await teachPause(page, 2800);
	await expect(page.getByText(/Delivery Settings|Product/i).first()).toBeVisible();
	await page.mouse.wheel(0, 350);
	await teachPause(page, 2000);
	await adminPath(page, `${ADMIN_PAGES.preview}&product_id=${QA.simpleProductId}`);
	await teachPause(page, 2500);
	await page.goto(QA.simpleProductPath, { waitUntil: 'domcontentloaded' });
	if (await isAccessBlocked(page)) {
		await teachPause(page, 1500);
	} else {
		await teachPause(page, 2800);
		await expect(page.getByText(/Delivery options/i).first()).toBeVisible({ timeout: 20_000 }).catch(() => undefined);
		await teachPause(page, 2000);
	}
});

test('04 configure variable product @video04', async ({ page }, testInfo) => {
	await adminPath(
		page,
		`${ADMIN_PAGES.deliverySettingsProduct}&scope_id=${QA.variableProductId}`
	);
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked — use HUMAN recording');
	await redactSensitiveUi(page);
	await teachPause(page, 2400);
	await adminPath(
		page,
		`${ADMIN_PAGES.deliverySettingsVariation}&scope_id=${QA.variationAId}`
	);
	await teachPause(page, 2400);
	await adminPath(
		page,
		`${ADMIN_PAGES.deliverySettingsVariation}&scope_id=${QA.variationBId}`
	);
	await teachPause(page, 2400);
	await adminPath(page, ADMIN_PAGES.preview);
	await teachPause(page, 2200);
	await page.goto(QA.variableProductProbe, { waitUntil: 'domcontentloaded' });
	await teachPause(page, 2800);
});

test('05 variation inheritance overrides @video05', async ({ page }, testInfo) => {
	await adminPath(page, ADMIN_PAGES.deliverySettingsDefault);
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked — use HUMAN recording');
	await teachPause(page, 2000);
	await adminPath(
		page,
		`${ADMIN_PAGES.deliverySettingsProduct}&scope_id=${QA.simpleProductId}`
	);
	await teachPause(page, 2400);
	await page.mouse.wheel(0, 300);
	await teachPause(page, 2000);
	await adminPath(
		page,
		`${ADMIN_PAGES.deliverySettingsVariation}&scope_id=${QA.variationAId}`
	);
	await teachPause(page, 2800);
});

test('06 delivery settings preview @video06', async ({ page }, testInfo) => {
	await adminPath(page, ADMIN_PAGES.preview);
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked — use HUMAN recording');
	await redactSensitiveUi(page);
	await teachPause(page, 3000);
	await expect(page.getByText(/Preview|Ready|Needs configuration|Currently using|Delivery/i).first()).toBeVisible();
	await teachPause(page, 2800);
});

test('09 order delivery information @video09', async ({ page }, testInfo) => {
	const orderId = process.env.CETECH_DE_QA_ORDER_ID || QA.orderVariable;
	await adminPath(page, `admin.php?page=wc-orders&action=edit&id=${orderId}`);
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked — use HUMAN recording');
	await redactSensitiveUi(page);
	await teachPause(page, 2000);
	let panel = page.locator('#cetech-de-order-delivery, .cetech-de-order-delivery, #cetech_de_order_delivery').first();
	if (!(await panel.isVisible().catch(() => false))) {
		await adminPath(page, `post.php?post=${orderId}&action=edit`);
		await redactSensitiveUi(page);
		panel = page.locator('#cetech-de-order-delivery, .cetech-de-order-delivery, #cetech_de_order_delivery').first();
	}
	const heading = page.getByRole('heading', { name: /Delivery information/i }).first();
	await expect(heading).toBeVisible({ timeout: 30_000 });
	await expect(page.getByRole('rowheader', { name: /Fulfilment/i }).first()).toBeVisible();
	await teachPause(page, 3500);
	await teachPause(page, 2500);
});

test('10 staff troubleshooting @video10', async ({ page }, testInfo) => {
	await adminPath(page, ADMIN_PAGES.preview);
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked — use HUMAN recording');
	await teachPause(page, 2500);
	await adminPath(
		page,
		`${ADMIN_PAGES.deliverySettingsProduct}&scope_id=${QA.simpleProductId}`
	);
	await teachPause(page, 2500);
	await adminPath(page, ADMIN_PAGES.deliverySettingsDefault);
	await teachPause(page, 2500);
});

test('11 legacy rules explained @video11', async ({ page }, testInfo) => {
	await adminPath(page, ADMIN_PAGES.deliverySettingsDefault);
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked — use HUMAN recording');
	await teachPause(page, 2200);
	await adminPath(page, ADMIN_PAGES.legacyRules);
	await teachPause(page, 3500);
	await expect(page.getByText(/Legacy|Product|Delivery|Rule/i).first()).toBeVisible();
	await teachPause(page, 2000);
	await adminPath(page, ADMIN_PAGES.deliverySettingsDefault);
	await teachPause(page, 2000);
});

test('12 complete staff walkthrough @video12', async ({ page }, testInfo) => {
	if (await isAccessBlocked(page)) test.skip(true, 'Cloudflare blocked — use HUMAN recording');
	await adminPath(page, ADMIN_PAGES.dashboard);
	await redactSensitiveUi(page);
	await teachPause(page, 2000);
	await adminPath(page, ADMIN_PAGES.deliverySettingsDefault);
	await teachPause(page, 2000);
	await adminPath(
		page,
		`${ADMIN_PAGES.deliverySettingsProduct}&scope_id=${QA.simpleProductId}`
	);
	await teachPause(page, 2000);
	await adminPath(
		page,
		`${ADMIN_PAGES.deliverySettingsProduct}&scope_id=${QA.variableProductId}`
	);
	await teachPause(page, 1800);
	await adminPath(
		page,
		`${ADMIN_PAGES.deliverySettingsVariation}&scope_id=${QA.variationAId}`
	);
	await teachPause(page, 1800);
	await adminPath(page, ADMIN_PAGES.preview);
	await teachPause(page, 2000);
	await page.goto(QA.simpleProductPath, { waitUntil: 'domcontentloaded' }).catch(() => undefined);
	await teachPause(page, 2200);
	const orderId = QA.orderMulti;
	await adminPath(page, `admin.php?page=wc-orders&action=edit&id=${orderId}`);
	await redactSensitiveUi(page);
	await teachPause(page, 2200);
	await adminPath(page, ADMIN_PAGES.legacyRules);
	await teachPause(page, 2000);
	await adminPath(page, ADMIN_PAGES.deliverySettingsDefault);
	await teachPause(page, 2000);
});
