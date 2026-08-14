import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const htmlDir = path.resolve(__dirname, '../../docs/review/rc3-admin-ui/harness/html');

function readHtml(name: string): string {
	const file = path.join(htmlDir, `${name}.html`);
	test.skip(!fs.existsSync(file), `Fixture ${name}.html not generated yet`);
	return fs.readFileSync(file, 'utf8');
}

test.describe('Stage 13B-R2 fixture HTML @smoke', () => {
	test('wizard fulfilment cards and footer stay separate', () => {
		const html = readHtml('02-wizard-fulfilment-types');
		expect(html).toContain('Select all that apply.');
		expect(html).toContain('cetech-de-wizard-actions');
		expect(html).toMatch(/Save &amp; finish later|Save & finish later/);
		expect(html).toContain('cetech-de-wizard-actions-left');
		expect(html).toContain('cetech-de-wizard-actions-right');
	});

	test('site-wide defaults hide incompatible options for In Warehouse', () => {
		const html = readHtml('10-site-wide-defaults');
		expect(html).toContain('Standard Delivery');
		expect(html).not.toContain('Store Pickup');
		expect(html).not.toContain('Air Shipping');
		expect(html).not.toContain('Sea Shipping');
		expect(html).toContain('Delivery charges are determined by the customer');
	});

	test('wizard charges use staff summaries not internal codes', () => {
		const warehouse = readHtml('03-wizard-warehouse-defaults');
		const areas = readHtml('06-wizard-areas-charges');
		for (const html of [warehouse, areas]) {
			expect(html).not.toContain('accra-standard-flat');
			expect(html).not.toContain('air-shipping-flat');
			expect(html).not.toContain('sea-shipping-per-item');
		}
		expect(warehouse).toContain('Standard Delivery');
		expect(areas).toContain('Actions');
	});

	test('area editor is a condition builder', () => {
		const html = readHtml('14-delivery-area-editor');
		expect(html).toContain('Where should this delivery area apply?');
		expect(html).toContain('+ Add another location condition');
		expect(html).toContain('Advanced matching');
		expect(html).not.toContain('Rule type');
	});

	test('charge editor preloads saved area and option', () => {
		const html = readHtml('16-delivery-charge-editor');
		expect(html).toMatch(/name="destination_zone_id"[\s\S]*selected[\s\S]*Greater Accra/);
		expect(html).toMatch(/name="delivery_offer_id"[\s\S]*selected[\s\S]*Standard Delivery/);
	});

	test('product customize uses business language', () => {
		const html = readHtml('24-product-customized');
		expect(html).toContain('Customize delivery for');
		expect(html).toContain('Use Site-wide Default');
		expect(html).toContain('Save Product Delivery Settings');
		expect(html).not.toContain('INHERIT');
		expect(html).not.toContain('These settings are stored, but customers still use the previous rules');
	});

	test('variation customize inherits from product settings', () => {
		const html = readHtml('26-variation-customized');
		expect(html).toContain('Use Product Setting');
		expect(html).toContain('Save Variation Delivery Settings');
		expect(html).toContain('Reset to Product Settings');
		expect(html).not.toContain('These settings are stored, but customers still use the previous rules');
	});

	test('preview hides private fields and IDs from ordinary UI', () => {
		const ready = readHtml('27-delivery-preview-ready');
		const attention = readHtml('28-delivery-preview-needs-attention');
		expect(ready).toContain('Search/select product');
		expect(ready).not.toContain('Product ID');
		expect(ready).toContain('Technical details');
		expect(ready).toContain('Site-wide Default');
		expect(attention).toContain('Needs Attention');
		expect(attention).toContain('Fix Product Delivery Settings');
		expect(attention).toContain('No usable delivery option is configured.');
	});

	test('legacy page does not teach new legacy configuration', () => {
		const html = readHtml('21-legacy-delivery-rules');
		expect(html).toContain('Moving away from Legacy Delivery Rules');
		expect(html).not.toContain('What is a product rule?');
		expect(html).not.toContain('Manage logistics profiles');
	});
});
