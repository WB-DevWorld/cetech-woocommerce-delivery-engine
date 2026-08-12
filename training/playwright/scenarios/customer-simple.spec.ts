import { test, expect } from '@playwright/test';
import { StorefrontDelivery } from '../pages/delivery-engine';
import { captureTeachingShot, redactSensitiveUi, QA, isAccessBlocked } from '../helpers/env';

test.describe('Customer simple product @smoke @capture @validate', () => {
	test('QA simple product shows Delivery options', async ({ page }) => {
		await page.goto(QA.simpleProductPath, { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Storefront blocked by Cloudflare — cannot live-capture Delivery options');

		await redactSensitiveUi(page);
		const store = new StorefrontDelivery(page);
		await expect(store.deliveryOptionsTitle()).toBeVisible({ timeout: 30_000 });
		await captureTeachingShot(page, '06-simple-product-customer-view.png');
	});
});
