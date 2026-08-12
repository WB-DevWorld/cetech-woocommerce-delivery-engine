import { test, expect } from '@playwright/test';
import { DeliveryEngineAdmin } from '../pages/delivery-engine';
import { captureTeachingShot, hasAdminStorageState, redactSensitiveUi } from '../helpers/env';

test.describe('Default delivery settings @smoke @capture @validate', () => {
	test.beforeEach(() => {
		test.skip(!hasAdminStorageState(), 'Admin storage state missing');
	});

	test('Default Settings tab shows inheritance editor fields', async ({ page }) => {
		const admin = new DeliveryEngineAdmin(page);
		await admin.openDeliverySettingsHome();
		await redactSensitiveUi(page);

		await expect(page.getByText(/Default Settings/i).first()).toBeVisible();
		await expect(page.getByText(/Fulfilment availability/i).first()).toBeVisible();
		await expect(page.getByText(/Fulfilment choice/i).first()).toBeVisible();
		await expect(page.getByText(/Delivery offers/i).first()).toBeVisible();

		await captureTeachingShot(page, '02-default-settings.png', { target: admin.mainContent() });
	});
});
