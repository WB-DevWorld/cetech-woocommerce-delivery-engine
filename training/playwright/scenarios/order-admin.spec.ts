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
		const orderId = process.env.CETECH_DE_QA_ORDER_ID || '39724';
		await adminPath(page, `admin.php?page=wc-orders&action=edit&id=${orderId}`);
		await redactSensitiveUi(page);

		const panel = page.locator('#cetech-de-order-delivery, .cetech-de-order-delivery').first();
		if (!(await panel.isVisible().catch(() => false))) {
			await adminPath(page, `post.php?post=${orderId}&action=edit`);
			await redactSensitiveUi(page);
		}

		// Prefer the real meta box; avoid matching the hidden screen-options label.
		if (await panel.isVisible().catch(() => false)) {
			await expect(panel).toBeVisible({ timeout: 30_000 });
			await expect(panel.getByText(/Fulfilment|Delivery option|Delivery method|Estimated delivery/i).first()).toBeVisible();
			await captureTeachingShot(page, '13-order-delivery-information.png', { target: panel });
		} else {
			const heading = page.getByRole('heading', { name: /Delivery information/i }).first();
			await expect(heading).toBeVisible({ timeout: 30_000 });
			await expect(page.getByText(/Fulfilment|Delivery option|Delivery method|Estimated delivery/i).first()).toBeVisible();
			// Crop tightly around heading + following content without billing columns when possible.
			const wrap = page.locator('.postbox').filter({ hasText: /Delivery information/i }).first();
			await captureTeachingShot(page, '13-order-delivery-information.png', {
				target: (await wrap.isVisible().catch(() => false)) ? wrap : heading,
			});
		}

		const shippingArea = page.locator('#order_shipping_line_items, .woocommerce_order_items').first();
		if (await shippingArea.isVisible().catch(() => false)) {
			await redactSensitiveUi(page);
			await captureTeachingShot(page, '14-order-shipping-clean.png', { target: shippingArea });
		}
	});
});
