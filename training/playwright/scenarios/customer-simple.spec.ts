import { test, expect } from '@playwright/test';
import { StorefrontDelivery } from '../pages/delivery-engine';
import { captureTeachingShot, redactSensitiveUi, hideAdminBar, QA, isAccessBlocked } from '../helpers/env';

test.describe('Customer simple product @smoke @capture @validate', () => {
	test('QA simple product shows Delivery options', async ({ page }) => {
		await page.goto(QA.simpleProductPath, { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Storefront blocked by Cloudflare — cannot live-capture Delivery options');
		const notFound = page.getByText(/Oops!!! Something Went Wrong|Error 404|Page not found/i).first();
		test.skip(await notFound.isVisible().catch(() => false), 'QA simple product URL returned 404 under /intl/');

		await redactSensitiveUi(page);
		await hideAdminBar(page);
		const store = new StorefrontDelivery(page);
		await expect(store.deliveryOptionsTitle()).toBeVisible({ timeout: 45_000 });
		await captureTeachingShot(page, '07-simple-product-customer-delivery.png');
	});
});
