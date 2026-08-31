/**
 * @vitest-environment jsdom
 */
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const scriptSource = readFileSync(resolve(root, 'assets/frontend/blocks-checkout.js'), 'utf8');

function loadScript() {
	delete window.CetechDeBlocksCheckout;
	eval(scriptSource);
	return window.CetechDeBlocksCheckout;
}

describe('Blocks checkout pickup presentation', () => {
	it('exposes the Store API namespace', () => {
		const api = loadScript();
		expect(api.namespace).toBe('cetech-delivery-engine');
	});

	it('selects only pickup packages from extensions', () => {
		const api = loadScript();
		const pickup = api.pickupPackages({
			packages: [
				{ is_pickup: false, heading: 'Delivery' },
				{
					is_pickup: true,
					heading: 'Pickup at Main showroom',
					pickup_address: '12 Harbour Street',
					pickup_location_label: 'Main showroom'
				}
			]
		});

		expect(pickup).toHaveLength(1);
		expect(pickup[0].pickup_address).toBe('12 Harbour Street');
	});

	it('keeps mixed delivery packages out of pickup notes', () => {
		const api = loadScript();
		const pickup = api.pickupPackages({
			packages: [
				{ is_pickup: false, heading: 'Standard Delivery', offer_label: 'Standard Delivery' },
				{
					is_pickup: true,
					heading: 'Pickup at Main showroom',
					pickup_address: '12 Harbour Street',
					pickup_location_label: 'Main showroom',
					estimate_text: 'Ready in 2 hours',
					charge_is_zero: true
				}
			]
		});

		expect(pickup).toHaveLength(1);
		expect(pickup[0].charge_is_zero).toBe(true);
		expect(pickup[0].pickup_location_label).toBe('Main showroom');
	});

	it('reads namespaced cart extensions', () => {
		const api = loadScript();
		const extensions = api.getExtensions({
			extensions: {
				'cetech-delivery-engine': { has_managed_packages: true }
			}
		});
		expect(extensions.has_managed_packages).toBe(true);
	});
});
