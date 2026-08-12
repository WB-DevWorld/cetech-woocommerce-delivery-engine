import { test, expect } from '@playwright/test';
import { captureTeachingShot, redactSensitiveUi, hideAdminBar, QA, isAccessBlocked } from '../helpers/env';

test.describe('Customer variable product @smoke @capture @validate', () => {
	test('QA variable product Variation A shows delivery choices', async ({ page }) => {
		await page.goto(QA.variableProductProbe, { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Storefront blocked by Cloudflare — cannot live-capture variable selector');
		test.skip(
			await page.getByText(/Oops!!! Something Went Wrong|Error 404/i).first().isVisible().catch(() => false),
			'Variable QA product 404 under /intl/'
		);

		await redactSensitiveUi(page);
		await hideAdminBar(page);
		const firstAttr = page.locator('select[name^="attribute_"], .variations select').first();
		if ((await firstAttr.count()) > 0) {
			await firstAttr.selectOption({ index: 1 }).catch(() => undefined);
			await page.waitForTimeout(1200);
		}
		const title = page.getByText(/Delivery options|Select your product options/i).first();
		await expect(title).toBeVisible({ timeout: 45_000 });
		await captureTeachingShot(page, '08-variable-product-customer-delivery.png');
	});

	test('QA variable product Variation B shows delivery change when available', async ({ page }) => {
		await page.goto(QA.variableProductProbe, { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Storefront blocked by Cloudflare — cannot live-capture Variation B');
		test.skip(
			await page.getByText(/Oops!!! Something Went Wrong|Error 404/i).first().isVisible().catch(() => false),
			'Variable QA product 404 under /intl/'
		);

		await redactSensitiveUi(page);
		await hideAdminBar(page);
		const firstAttr = page.locator('select[name^="attribute_"], .variations select').first();
		test.skip((await firstAttr.count()) === 0, 'No variation attribute control — skip Variation B capture');
		const options = await firstAttr.locator('option').count();
		test.skip(options < 3, 'Fewer than two real variation options — skip Variation B capture');
		await firstAttr.selectOption({ index: 2 }).catch(() => undefined);
		await page.waitForTimeout(1200);
		await expect(page.getByText(/Delivery options|Select your product options/i).first()).toBeVisible({
			timeout: 45_000,
		});
		await captureTeachingShot(page, '09-variable-product-second-variation.png');
	});
});
