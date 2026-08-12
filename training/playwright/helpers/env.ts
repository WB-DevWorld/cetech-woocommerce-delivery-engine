import path from 'node:path';
import fs from 'node:fs';
import type { Page, Locator } from '@playwright/test';

export const SCREENSHOT_DIR = path.resolve(
	__dirname,
	'../../../docs/training/assets/screenshots'
);

export const QA = {
	simpleProductId: 39705,
	/**
	 * Paths must be relative to baseURL https://flairoc.com/intl/
	 * Leading "/" jumps to site root and drops /intl/ (404).
	 */
	simpleProductPath: '?p=39705',
	variableProductId: 39717,
	variationAId: 39718,
	variationBId: 39719,
	variableProductProbe: '?p=39717',
} as const;

export const ADMIN_PAGES = {
	dashboard: 'admin.php?page=cetech-delivery-engine-system-status',
	settings: 'admin.php?page=cetech-delivery-engine-settings',
	deliverySettings: 'admin.php?page=cetech-delivery-engine-scoped-config',
	deliverySettingsDefault: 'admin.php?page=cetech-delivery-engine-scoped-config&scope_type=global',
	deliverySettingsProduct: 'admin.php?page=cetech-delivery-engine-scoped-config&scope_type=product',
	deliverySettingsVariation: 'admin.php?page=cetech-delivery-engine-scoped-config&scope_type=variation',
	preview: 'admin.php?page=cetech-delivery-engine-effective-preview',
	offers: 'admin.php?page=cetech-delivery-engine-delivery-offers',
	zones: 'admin.php?page=cetech-delivery-engine-destination-zones',
	rateCards: 'admin.php?page=cetech-delivery-engine-rate-cards',
	logistics: 'admin.php?page=cetech-delivery-engine-logistics-profiles',
	pickup: 'admin.php?page=cetech-delivery-engine-pickup-locations',
	suppliers: 'admin.php?page=cetech-delivery-engine-suppliers-origins',
	legacyRules: 'admin.php?page=cetech-delivery-engine-product-rules',
} as const;

export function ensureScreenshotDir(): void {
	fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

/**
 * Capture a teaching screenshot. Avoids credential chrome; crops to main content when possible.
 */
export async function captureTeachingShot(
	page: Page,
	filename: string,
	options?: { fullPage?: boolean; target?: Locator }
): Promise<string> {
	ensureScreenshotDir();
	const dest = path.join(SCREENSHOT_DIR, filename);
	const target = options?.target;
	if (target) {
		await target.screenshot({ path: dest });
	} else {
		await page.screenshot({
			path: dest,
			fullPage: options?.fullPage ?? false,
		});
	}
	return dest;
}

export async function adminPath(page: Page, relativeAdminQuery: string): Promise<void> {
	const adminBase =
		process.env.FLAIROC_WP_ADMIN_URL?.replace(/\/?$/, '/') ||
		(process.env.FLAIROC_BASE_URL || 'https://flairoc.com/intl/').replace(/\/?$/, '/') + 'wp-admin/';

	await page.goto(adminBase + relativeAdminQuery.replace(/^\//, ''), {
		waitUntil: 'domcontentloaded',
	});
}

export function hasAdminStorageState(): boolean {
	return fs.existsSync(path.resolve(__dirname, '../auth/storage-state.json'));
}

/** Redact common personal fields from page before screenshot when present. */
export async function redactSensitiveUi(page: Page): Promise<void> {
	await page.addStyleTag({
		content: `
			#wpadminbar .display-name,
			#wpadminbar .avatar,
			#wpadminbar .ab-item .display-name,
			.avatar,
			input[type="password"],
			.woocommerce-customer-details,
			.woocommerce-order-overview__email,
			.woocommerce-billing-fields,
			.woocommerce-shipping-fields,
			.woocommerce-billing-fields__field-wrapper,
			.woocommerce-shipping-fields__field-wrapper,
			#order_data .order_data_column:nth-child(2),
			#order_data .order_data_column:nth-child(3),
			#order_data .order_data_column p.form-field-wide,
			.order_data_column .address,
			.order_data_column .form-field,
			#customer_user,
			.select2-selection__rendered,
			.wc-customer-search,
			.wc-order-preview-address,
			#billing_first_name, #billing_last_name, #billing_address_1, #billing_address_2,
			#billing_city, #billing_postcode, #billing_phone, #billing_email,
			#shipping_first_name, #shipping_last_name, #shipping_address_1, #shipping_address_2,
			#shipping_city, #shipping_postcode, #shipping_phone,
			p.woocommerce-customer-details--email,
			p.woocommerce-customer-details--phone {
				filter: blur(8px) !important;
			}
			/* Hide IP / payment meta lines that can leak identifiers */
			.woocommerce-order-data__meta,
			.order_number + .order_data_column p:first-of-type {
				filter: blur(6px) !important;
			}
		`,
	});
}

/** Hide WordPress admin bar for customer-facing teaching screenshots. */
export async function hideAdminBar(page: Page): Promise<void> {
	await page.addStyleTag({
		content: `#wpadminbar { display: none !important; } html.admin-bar { margin-top: 0 !important; padding-top: 0 !important; }`,
	});
}
export async function isAccessBlocked(page: Page): Promise<boolean> {
	const title = await page.title().catch(() => '');
	if (/just a moment|attention required|cloudflare/i.test(title)) {
		return true;
	}
	const blocked = page.getByRole('heading', { name: /sorry, you have been blocked|unable to access/i });
	return (await blocked.count()) > 0;
}
