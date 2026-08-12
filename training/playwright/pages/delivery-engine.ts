import type { Page, Locator } from '@playwright/test';
import { ADMIN_PAGES, adminPath } from '../helpers/env';

/** Semantic locators for Delivery Engine admin surfaces (RC.2 labels). */
export class DeliveryEngineAdmin {
	constructor(private readonly page: Page) {}

	async openDeliverySettingsHome(): Promise<void> {
		await adminPath(this.page, ADMIN_PAGES.deliverySettingsDefault);
	}

	async openProductSettings(): Promise<void> {
		await adminPath(this.page, ADMIN_PAGES.deliverySettingsProduct);
	}

	async openVariationSettings(): Promise<void> {
		await adminPath(this.page, ADMIN_PAGES.deliverySettingsVariation);
	}

	async openPreview(): Promise<void> {
		await adminPath(this.page, ADMIN_PAGES.preview);
	}

	async openLegacyRules(): Promise<void> {
		await adminPath(this.page, ADMIN_PAGES.legacyRules);
	}

	async openDashboard(): Promise<void> {
		await adminPath(this.page, ADMIN_PAGES.dashboard);
	}

	heading(name: string | RegExp): Locator {
		return this.page.getByRole('heading', { name });
	}

	tabDefault(): Locator {
		return this.page.getByRole('link', { name: /Default Settings/i });
	}

	tabProduct(): Locator {
		return this.page.getByRole('link', { name: /Product-Specific Settings/i });
	}

	tabVariation(): Locator {
		return this.page.getByRole('link', { name: /Variation-Specific Settings/i });
	}

	menuDeliveryEngine(): Locator {
		return this.page.locator('#adminmenu').getByRole('link', { name: /^Delivery Engine$/i });
	}

	mainContent(): Locator {
		return this.page.locator('#wpbody-content .wrap, #wpbody-content .cetech-de-admin, #wpbody-content').first();
	}
}

export class StorefrontDelivery {
	constructor(private readonly page: Page) {}

	deliveryOptions(): Locator {
		return this.page.getByRole('group', { name: /Delivery options/i }).or(
			this.page.locator('.cetech-de-delivery-selector')
		);
	}

	deliveryOptionsTitle(): Locator {
		return this.page.getByText(/Delivery options/i).first();
	}
}
