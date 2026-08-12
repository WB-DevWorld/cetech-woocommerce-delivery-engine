import { test, expect } from '@playwright/test';
import { DeliveryEngineAdmin } from '../pages/delivery-engine';
import { captureTeachingShot, hasAdminStorageState, redactSensitiveUi, QA, adminPath, ADMIN_PAGES } from '../helpers/env';

test.describe('Variation-specific settings @smoke @capture @validate', () => {
	test.beforeEach(() => {
		test.skip(!hasAdminStorageState(), 'Admin storage state missing');
	});

	test('Variation tab and QA variation A #39718', async ({ page }) => {
		const admin = new DeliveryEngineAdmin(page);
		await admin.openVariationSettings();
		await redactSensitiveUi(page);
		await expect(page.getByText(/Variation-Specific Settings/i).first()).toBeVisible();
		await captureTeachingShot(page, '04-variation-specific-settings.png', { target: admin.mainContent() });

		await adminPath(
			page,
			`${ADMIN_PAGES.deliverySettingsVariation}&scope_id=${QA.variationAId}&parent_product_id=${QA.variableProductId}`
		);
		await expect(
			page.getByText(/Fulfilment availability|Use inherited setting|Currently using|Ready|Needs configuration/i).first()
		).toBeVisible();
		await captureTeachingShot(page, '04b-variation-39718-editor.png', { target: admin.mainContent() });
	});
});
