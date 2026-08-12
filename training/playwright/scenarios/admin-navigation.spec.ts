import { test, expect } from '@playwright/test';
import { DeliveryEngineAdmin } from '../pages/delivery-engine';
import { captureTeachingShot, hasAdminStorageState, redactSensitiveUi, ADMIN_PAGES, adminPath } from '../helpers/env';

test.describe('Admin navigation @smoke @capture @validate', () => {
	test.beforeEach(({ }, testInfo) => {
		test.skip(!hasAdminStorageState(), 'Admin storage state missing — run auth:save or provide auth/storage-state.json');
	});

	test('Delivery Engine menu and Delivery Settings home', async ({ page }) => {
		const admin = new DeliveryEngineAdmin(page);
		await admin.openDashboard();
		await redactSensitiveUi(page);
		await expect(page.getByText(/Delivery Engine|Delivery readiness|CETECH/i).first()).toBeVisible();
		await captureTeachingShot(page, '00-delivery-engine-dashboard.png', { target: admin.mainContent() });

		await admin.openDeliverySettingsHome();
		await expect(page.getByText(/Delivery Settings/i).first()).toBeVisible();
		await expect(page.getByText(/Default Settings/i).first()).toBeVisible();
		await captureTeachingShot(page, '01-delivery-settings-home.png', { target: admin.mainContent() });
	});

	test('Supporting config pages are reachable', async ({ page }) => {
		for (const [label, path] of [
			['Delivery Offers', ADMIN_PAGES.offers],
			['Destination Zones', ADMIN_PAGES.zones],
			['Rate Cards', ADMIN_PAGES.rateCards],
		] as const) {
			await adminPath(page, path);
			await expect(page.getByText(new RegExp(label, 'i')).first()).toBeVisible();
		}
	});

	test('Technical diagnostic tools capture for admin/support docs only', async ({ page }) => {
		const admin = new DeliveryEngineAdmin(page);
		await admin.openDashboard();
		await redactSensitiveUi(page);
		// Prefer an expanded diagnostics / system area when present; otherwise dashboard wrap.
		const diag = page.getByText(/Technical diagnostic|System status|Advanced|Debug/i).first();
		await expect(page.getByText(/Delivery Engine|Delivery readiness|CETECH/i).first()).toBeVisible();
		if (await diag.isVisible().catch(() => false)) {
			await diag.click().catch(() => undefined);
		}
		await captureTeachingShot(page, '16-technical-diagnostic-tools.png', { target: admin.mainContent() });
	});
});
