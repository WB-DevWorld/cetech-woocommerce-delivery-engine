import path from 'node:path';
import fs from 'node:fs';
import type { Page, TestInfo } from '@playwright/test';

export const VIDEO_DIR = path.resolve(__dirname, '../../../docs/training/assets/videos');
export const OUTPUT_DIR = path.resolve(__dirname, '../output');

export const QA = {
	simpleProductId: 39705,
	simpleProductPath: '/buy/flairoc-delivery-engine-qa-product/',
	variableProductId: 39717,
	variationAId: 39718,
	variationBId: 39719,
	variableProductProbe: '/?p=39717',
	orderVariable: '39721',
	orderMulti: '39724',
} as const;

export const ADMIN_PAGES = {
	dashboard: 'admin.php?page=cetech-delivery-engine-system-status',
	deliverySettingsDefault: 'admin.php?page=cetech-delivery-engine-scoped-config&scope_type=global',
	deliverySettingsProduct: 'admin.php?page=cetech-delivery-engine-scoped-config&scope_type=product',
	deliverySettingsVariation: 'admin.php?page=cetech-delivery-engine-scoped-config&scope_type=variation',
	preview: 'admin.php?page=cetech-delivery-engine-effective-preview',
	offers: 'admin.php?page=cetech-delivery-engine-delivery-offers',
	zones: 'admin.php?page=cetech-delivery-engine-destination-zones',
	rateCards: 'admin.php?page=cetech-delivery-engine-rate-cards',
	legacyRules: 'admin.php?page=cetech-delivery-engine-product-rules',
} as const;

export function hasAdminStorageState(): boolean {
	return fs.existsSync(path.resolve(__dirname, '../../playwright/auth/storage-state.json'));
}

export async function adminPath(page: Page, relativeAdminQuery: string): Promise<void> {
	const adminBase =
		process.env.FLAIROC_WP_ADMIN_URL?.replace(/\/?$/, '/') ||
		(process.env.FLAIROC_BASE_URL || 'https://flairoc.com/intl/').replace(/\/?$/, '/') + 'wp-admin/';
	await page.goto(adminBase + relativeAdminQuery.replace(/^\//, ''), {
		waitUntil: 'domcontentloaded',
	});
}

/** Teaching pause — keeps pacing readable for staff videos. */
export async function teachPause(page: Page, ms = 1800): Promise<void> {
	await page.waitForTimeout(ms);
}

export async function redactSensitiveUi(page: Page): Promise<void> {
	await page.addStyleTag({
		content: `
			#wpadminbar .display-name,
			.avatar,
			input[type="password"],
			.woocommerce-customer-details,
			.woocommerce-order-overview__email,
			.woocommerce-column--billing-address,
			.woocommerce-column--shipping-address {
				filter: blur(8px) !important;
			}
		`,
	});
}

export async function isAccessBlocked(page: Page): Promise<boolean> {
	const title = await page.title().catch(() => '');
	if (/just a moment|attention required|cloudflare|sorry, you have been blocked/i.test(title)) {
		return true;
	}
	const blocked = page.getByRole('heading', {
		name: /sorry, you have been blocked|unable to access/i,
	});
	return (await blocked.count()) > 0;
}

/**
 * Copy Playwright's recorded webm into the canonical training filename.
 * Call from test.afterEach — video attachments are finalized after the test ends.
 */
export async function promoteVideoFromTestInfo(
	testInfo: TestInfo,
	canonicalName: string
): Promise<string | null> {
	fs.mkdirSync(VIDEO_DIR, { recursive: true });
	fs.mkdirSync(OUTPUT_DIR, { recursive: true });
	const candidates: string[] = [];
	for (const a of testInfo.attachments) {
		if (a.path && (a.name === 'video' || a.contentType?.includes('video'))) {
			candidates.push(a.path);
		}
	}
	const outDir = testInfo.outputDir;
	if (outDir && fs.existsSync(outDir)) {
		for (const f of fs.readdirSync(outDir)) {
			if (f.endsWith('.webm')) candidates.push(path.join(outDir, f));
		}
	}
	const source = candidates.find((p) => p && fs.existsSync(p));
	if (!source) return null;
	const dest = path.join(VIDEO_DIR, canonicalName);
	fs.copyFileSync(source, dest);
	fs.copyFileSync(source, path.join(OUTPUT_DIR, canonicalName));
	return dest;
}

/** @deprecated Prefer afterEach + promoteVideoFromTestInfo (attachments finalize post-test). */
export async function promoteVideo(
	testInfo: TestInfo,
	canonicalName: string
): Promise<string | null> {
	return promoteVideoFromTestInfo(testInfo, canonicalName);
}

export async function outlineImportant(page: Page, selector: string): Promise<void> {
	await page.evaluate((sel) => {
		const el = document.querySelector(sel) as HTMLElement | null;
		if (!el) return;
		el.style.outline = '3px solid #2271b1';
		el.style.outlineOffset = '4px';
	}, selector);
}

/** Map @videoNN title tags to canonical filenames. */
export function canonicalNameFromTitle(title: string): string | null {
	const m = title.match(/@video(\d{2})/i);
	if (!m) return null;
	const map: Record<string, string> = {
		'01': '01-getting-started-overview.webm',
		'02': '02-default-delivery-settings.webm',
		'03': '03-configure-simple-product.webm',
		'04': '04-configure-variable-product.webm',
		'05': '05-variation-inheritance-overrides.webm',
		'06': '06-delivery-settings-preview.webm',
		'07': '07-customer-cart-checkout.webm',
		'08': '08-multi-product-shipping.webm',
		'09': '09-order-delivery-information.webm',
		'10': '10-staff-troubleshooting.webm',
		'11': '11-legacy-rules-explained.webm',
		'12': '12-complete-staff-walkthrough.webm',
	};
	return map[m[1]] || null;
}
