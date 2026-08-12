import { test, expect } from '@playwright/test';
import { captureTeachingShot, hasAdminStorageState, redactSensitiveUi, adminPath } from '../helpers/env';

/**
 * Read-only view of an existing QA order Delivery information panel.
 * Does not create orders. Prefer existing #39721 / #39724 when available.
 */
test.describe('Admin order delivery information @smoke @capture @validate', () => {
	test.beforeEach(() => {
		test.skip(!hasAdminStorageState(), 'Admin storage state missing');
	});

	test('Existing QA order shows Delivery information meta box', async ({ page }) => {
		const orderId = process.env.CETECH_DE_QA_ORDER_ID || '39721';
		await adminPath(page, `admin.php?page=wc-orders&action=edit&id=${orderId}`);
		await redactSensitiveUi(page);

		const panel = page.getByText(/Delivery information/i).first();
		const visible = await panel.isVisible().catch(() => false);
		if (!visible) {
			// Legacy edit.php fallback for non-HPOS.
			await adminPath(page, `post.php?post=${orderId}&action=edit`);
			await redactSensitiveUi(page);
		}

		await expect(page.getByText(/Delivery information/i).first()).toBeVisible({ timeout: 30_000 });
		await expect(page.getByText(/Fulfilment|Delivery option|Delivery method|Estimated delivery/i).first()).toBeVisible();
		await captureTeachingShot(page, '09-order-delivery-information.png', {
			target: page.locator('#cetech-de-order-delivery, .cetech-de-order-delivery, #woocommerce-order-data').first(),
		});
	});
});
