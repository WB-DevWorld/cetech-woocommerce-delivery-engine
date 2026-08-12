import { test, expect } from '@playwright/test';
import { captureTeachingShot, redactSensitiveUi, QA, isAccessBlocked } from '../helpers/env';

test.describe('Customer variable product @smoke @capture @validate', () => {
	test('QA variable product probe shows delivery selector shell', async ({ page }) => {
		await page.goto(QA.variableProductProbe, { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Storefront blocked by Cloudflare — cannot live-capture variable selector');

		await redactSensitiveUi(page);
		const title = page.getByText(/Delivery options|Select your product options/i).first();
		await expect(title).toBeVisible({ timeout: 30_000 });
		await captureTeachingShot(page, '06b-variable-product-customer-view.png');
	});
});
