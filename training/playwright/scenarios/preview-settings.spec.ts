import { test, expect } from '@playwright/test';
import { DeliveryEngineAdmin } from '../pages/delivery-engine';
import { captureTeachingShot, hasAdminStorageState, redactSensitiveUi, QA, adminPath, ADMIN_PAGES } from '../helpers/env';

test.describe('Delivery Settings Preview @smoke @capture @validate', () => {
	test.beforeEach(() => {
		test.skip(!hasAdminStorageState(), 'Admin storage state missing');
	});

	test('Preview page loads and can target QA product', async ({ page }) => {
		const admin = new DeliveryEngineAdmin(page);
		await admin.openPreview();
		await redactSensitiveUi(page);
		await expect(page.getByText(/Delivery Settings Preview/i).first()).toBeVisible();
		await captureTeachingShot(page, '05-delivery-preview-ready.png', { target: admin.mainContent() });
	});
});
