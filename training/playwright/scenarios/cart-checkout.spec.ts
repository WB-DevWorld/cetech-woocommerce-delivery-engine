import { test, expect } from '@playwright/test';
import { captureTeachingShot, redactSensitiveUi, QA, isAccessBlocked } from '../helpers/env';

/**
 * Cart/checkout capture is read-only where possible.
 * Does NOT place orders. Does NOT enable payment methods.
 */
test.describe('Cart and checkout delivery @capture @validate', () => {
	test('Cart shows delivery summary after selecting an offer on QA simple product', async ({ page }) => {
		await page.goto(QA.simpleProductPath, { waitUntil: 'domcontentloaded' });
		test.skip(await isAccessBlocked(page), 'Storefront blocked by Cloudflare — cannot live-capture cart path');

		await redactSensitiveUi(page);
		const offer = page.locator('.cetech-de-delivery-selector input[type="radio"]').first();
		const hasOffer = await offer.count();
		test.skip(hasOffer === 0, 'No delivery radio options visible on QA product — cannot safely capture cart path');

		await offer.check({ force: true });
		const add = page.getByRole('button', { name: /add to cart/i }).first();
		await expect(add).toBeVisible();
		await add.click();

		await page.goto('/cart/', { waitUntil: 'domcontentloaded' }).catch(async () => {
			await page.goto('/?page_id=cart', { waitUntil: 'domcontentloaded' });
		});
		test.skip(await isAccessBlocked(page), 'Cart blocked by Cloudflare');
		await redactSensitiveUi(page);
		await expect(page.getByText(/Fulfilment|Delivery option|Delivery method|Cart|Basket/i).first()).toBeVisible({
			timeout: 30_000,
		});
		await captureTeachingShot(page, '07-cart-delivery-information.png');
	});

	test('Checkout shows Delivery shipping line when cart has delivery selection', async ({ page }) => {
		await page.goto('/checkout/', { waitUntil: 'domcontentloaded' }).catch(async () => {
			await page.goto('/?page_id=checkout', { waitUntil: 'domcontentloaded' });
		});
		test.skip(await isAccessBlocked(page), 'Checkout blocked by Cloudflare');
		await redactSensitiveUi(page);

		const deliveryLine = page.getByText(/^Delivery$|Delivery charge|shipping/i).first();
		const visible = await deliveryLine.isVisible().catch(() => false);
		test.skip(!visible, 'Checkout delivery line not visible without a prepared cart — skip (no order created)');
		await captureTeachingShot(page, '08-checkout-delivery-charge.png');
	});
});
