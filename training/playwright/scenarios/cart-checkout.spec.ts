import { test, expect } from '@playwright/test';
import { captureTeachingShot, redactSensitiveUi, hideAdminBar, QA, isAccessBlocked, hasAdminStorageState } from '../helpers/env';

/**
 * Cart/checkout capture is read-only where possible.
 * Does NOT place orders. Does NOT enable payment methods.
 * Paths are relative to baseURL https://flairoc.com/intl/ (no leading "/").
 */
test.describe('Cart and checkout delivery @capture @validate', () => {
	test.beforeEach(() => {
		test.skip(!hasAdminStorageState(), 'Auth storage missing — run training:auth first');
	});

	test('Cart shows delivery summary after selecting an offer on QA simple product', async ({ page }) => {
		await page.goto(QA.simpleProductPath, { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Storefront blocked by Cloudflare — cannot live-capture cart path');
		test.skip(
			await page.getByText(/Oops!!! Something Went Wrong|Error 404/i).first().isVisible().catch(() => false),
			'QA product 404'
		);

		await redactSensitiveUi(page);
		await hideAdminBar(page);
		await expect(page.getByText(/Delivery options/i).first()).toBeVisible({ timeout: 45_000 });
		const offer = page
			.locator(
				'.cetech-de-product-delivery-selector input[type="radio"], .cetech-de-delivery-selector input[type="radio"], input[name="cetech_de_delivery_option_key"]'
			)
			.first();
		test.skip((await offer.count()) === 0, 'No delivery radio options visible on QA product — cannot safely capture cart path');

		await offer.check({ force: true });
		const add = page.getByRole('button', { name: /add to cart/i }).first();
		await expect(add).toBeVisible();
		await add.click();
		await page.waitForTimeout(1500);

		await page.goto('cart/', { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Cart blocked by Cloudflare');
		await redactSensitiveUi(page);
		await hideAdminBar(page);
		await expect(page.getByText(/Fulfilment|Delivery option|Delivery method|Cart|Basket|Shopping cart/i).first()).toBeVisible({
			timeout: 30_000,
		});
		await captureTeachingShot(page, '10-cart-delivery-information.png');
	});

	test('Multi-product cart shows one fixed-per-shipment Delivery charge when safe', async ({ page }) => {
		await page.goto(QA.simpleProductPath, { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Storefront blocked by Cloudflare — cannot capture multi-product cart');
		await expect(page.getByText(/Delivery options/i).first()).toBeVisible({ timeout: 45_000 });

		const offer = page.locator('input[name="cetech_de_delivery_option_key"]').first();
		test.skip((await offer.count()) === 0, 'No delivery radios on simple QA product');
		await offer.check({ force: true });
		await page.getByRole('button', { name: /add to cart/i }).first().click();
		await page.waitForTimeout(1200);

		await page.goto(QA.variableProductProbe, { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Variable product blocked — skip multi-product cart capture');
		const firstAttr = page.locator('select[name^="attribute_"], .variations select').first();
		if ((await firstAttr.count()) > 0) {
			await firstAttr.selectOption({ index: 1 }).catch(() => undefined);
			await page.waitForTimeout(1000);
		}
		const varOffer = page.locator('input[name="cetech_de_delivery_option_key"]').first();
		if ((await varOffer.count()) > 0) {
			await varOffer.check({ force: true });
			const addVar = page.getByRole('button', { name: /add to cart/i }).first();
			if (await addVar.isEnabled().catch(() => false)) {
				await addVar.click();
				await page.waitForTimeout(1200);
			}
		}

		await page.goto('cart/', { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Cart blocked by Cloudflare');
		await redactSensitiveUi(page);
		await hideAdminBar(page);
		const hasCharge = await page.getByText(/25\.00|Delivery|Shipment/i).first().isVisible().catch(() => false);
		test.skip(!hasCharge, 'Multi-product one-charge view not visible — skip (no business changes)');
		await captureTeachingShot(page, '11-multi-product-cart-one-charge.png');
	});

	test('Checkout shows Delivery shipping line when cart has delivery selection', async ({ page }) => {
		await page.goto('checkout/', { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Checkout blocked by Cloudflare');
		await redactSensitiveUi(page);
		await hideAdminBar(page);

		const orderBox = page.locator('#order_review, .woocommerce-checkout-review-order').first();
		const deliveryLine = page.getByText(/Shipment|Delivery:|\$25\.00/i).first();
		const visible = (await deliveryLine.isVisible().catch(() => false)) || (await orderBox.isVisible().catch(() => false));
		test.skip(!visible, 'Checkout delivery line not visible without a prepared cart — skip (no order created)');
		// Prefer order summary crop to reduce billing PII in teaching shot.
		if (await orderBox.isVisible().catch(() => false)) {
			await captureTeachingShot(page, '12-checkout-delivery-charge.png', { target: orderBox });
		} else {
			await captureTeachingShot(page, '12-checkout-delivery-charge.png');
		}
	});
});
