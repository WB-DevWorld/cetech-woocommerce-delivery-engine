import { test, expect } from '@playwright/test';
import { DeliveryEngineAdmin } from '../pages/delivery-engine';
import { captureTeachingShot, hasAdminStorageState, redactSensitiveUi, QA, adminPath, ADMIN_PAGES } from '../helpers/env';

test.describe('Product-specific settings @smoke @capture @validate', () => {
	test.beforeEach(() => {
		test.skip(!hasAdminStorageState(), 'Admin storage state missing');
	});

	test('Product tab and QA product #39705 picker/editor', async ({ page }) => {
		const admin = new DeliveryEngineAdmin(page);
		await admin.openProductSettings();
		await redactSensitiveUi(page);
		await expect(page.getByText(/Product-Specific Settings/i).first()).toBeVisible();
		await captureTeachingShot(page, '03-product-specific-settings.png', { target: admin.mainContent() });

		// Read-only load of dedicated QA product — do not save changes.
		await adminPath(
			page,
			`${ADMIN_PAGES.deliverySettingsProduct}&scope_id=${QA.simpleProductId}`
		);
		await expect(page.getByText(/Fulfilment availability|Delivery offers|Currently using|Ready|Needs configuration/i).first()).toBeVisible();
		await captureTeachingShot(page, '03b-product-39705-editor.png', { target: admin.mainContent() });
	});
});
