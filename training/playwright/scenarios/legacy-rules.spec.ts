import { test, expect } from '@playwright/test';
import { DeliveryEngineAdmin } from '../pages/delivery-engine';
import { captureTeachingShot, hasAdminStorageState, redactSensitiveUi } from '../helpers/env';

test.describe('Legacy Delivery Rules warning @smoke @capture @validate', () => {
	test.beforeEach(() => {
		test.skip(!hasAdminStorageState(), 'Admin storage state missing');
	});

	test('Legacy page is present and points staff to Delivery Settings', async ({ page }) => {
		const admin = new DeliveryEngineAdmin(page);
		await admin.openLegacyRules();
		await redactSensitiveUi(page);
		await expect(page.getByText(/Legacy Delivery Rules/i).first()).toBeVisible();
		await expect(page.getByText(/Delivery Settings/i).first()).toBeVisible();
		await captureTeachingShot(page, '10-legacy-delivery-rules.png', { target: admin.mainContent() });
	});
});
