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

describe('Blocks cart reselection', () => {
	it('selects cart items that need a new delivery option', () => {
		const api = loadScript();
		const needing = api.itemsNeedingReselection({
			items: [
				{
					key: 'keep',
					name: 'Valid item',
					extensions: {
						'cetech-delivery-engine': { needs_reselection: false }
					}
				},
				{
					key: 'stale',
					name: 'Changed item',
					extensions: {
						'cetech-delivery-engine': {
							needs_reselection: true,
							cart_item_key: 'stale',
							product_name: 'Cable',
							reselection_message: 'Delivery options for “Cable” have changed.',
							reselection_options: [
								{ display_key: 'in_warehouse:delivery:20', label: 'Option B', estimate_text: '2 days' }
							]
						}
					}
				}
			]
		});

		expect(needing).toHaveLength(1);
		expect(needing[0].key).toBe('stale');
		expect(needing[0].name).toBe('Cable');
		expect(needing[0].options[0].display_key).toBe('in_warehouse:delivery:20');
	});

	it('selects editable managed lines and submits named commands', () => {
		const api = loadScript();
		const items = api.editableItems({
			items: [
				{
					key: 'accra',
					name: 'Chair',
					quantity: 2,
					extensions: {
						'cetech-delivery-engine': {
							can_edit_context: true,
							needs_reselection: false,
							cart_item_key: 'accra',
							product_name: 'Chair',
							quantity: 2,
							locality: 'Accra'
						}
					}
				},
				{
					key: 'stale',
					extensions: {
						'cetech-delivery-engine': { can_edit_context: false, needs_reselection: true }
					}
				}
			]
		});
		expect(items).toHaveLength(1);
		expect(items[0].key).toBe('accra');
		expect(typeof api.submitCommand).toBe('function');
		expect(typeof api.renderDomUi).toBe('function');
	});
});

describe('Blocks DOM customer editor hardening', () => {
	function editableItem(key, name, locality, address = {}) {
		return {
			key,
			name,
			quantity: 2,
			extensions: {
				'cetech-delivery-engine': {
					can_edit_context: true,
					needs_reselection: false,
					cart_item_key: key,
					product_name: name,
					quantity: 2,
					locality,
					can_split: true,
					is_pickup: false,
					matching_location: { country: 'GH', state: 'AA', city: locality, postcode: 'GA-123' },
					delivery_address: {
						address_1: address.address_1 || '',
						address_2: '',
						first_name: address.first_name || 'Ama',
						last_name: '',
						phone: address.phone || '0244000000'
					},
					available_options: [
						{ display_key: 'in_warehouse:delivery:1', label: 'QA Local Standard', estimate_text: '3-5 days', selected: true }
					]
				}
			}
		};
	}

	function installCart(cart) {
		window.wp = {
			data: {
				select: () => ({ getCartData: () => cart }),
				subscribe: () => {}
			},
			plugins: {
				registerPlugin: () => {}
			}
		};
		document.body.innerHTML = '<div class="wp-block-woocommerce-filled-cart-block"></div>';
	}

	it('renders unique hashed field ids without embedding street, name, or phone', () => {
		window.cetechDeBlocks = {
			namespace: 'cetech-delivery-engine',
			i18n: { address1: 'Address line 1', splitQty: 'Quantity to move' }
		};
		installCart({
			items: [
				editableItem('aaa111bbbb2222cccc3333', 'Chair', 'Accra', { address_1: '12 Independence Avenue', phone: '0244000000', first_name: 'Ama' }),
				editableItem('dddd4444eeee5555ffff6666', 'Lamp', 'Kumasi', { address_1: '4 Kejetia Road' })
			],
			extensions: { 'cetech-delivery-engine': { notices: [] } }
		});
		const api = loadScript();
		api.renderDomUi();
		const mount = document.getElementById('cetech-de-blocks-dom-ui');
		expect(mount).toBeTruthy();
		expect(mount.querySelectorAll('.cetech-de-blocks-editor')).toHaveLength(2);
		const ids = [...mount.querySelectorAll('[id]')].map((el) => el.id);
		expect(ids.length).toBeGreaterThan(4);
		expect(new Set(ids).size).toBe(ids.length);
		expect(ids.every((id) => id.startsWith('cetech-de-b-'))).toBe(true);
		const blob = mount.innerHTML;
		expect(blob).toContain('Address line 1');
		expect(blob).toContain('Quantity to move');
		expect(ids.join('|')).not.toMatch(/Independence|Kejetia|0244000000|Ama/i);
		expect(api.fieldId('aaa111bbbb2222cccc3333', 'phone')).toBe('cetech-de-b-aaa111bbbb2222cccc33-phone');
	});

	it('skips innerHTML rebuild when the cart signature is unchanged', () => {
		window.cetechDeBlocks = { namespace: 'cetech-delivery-engine', i18n: {} };
		const cart = {
			items: [editableItem('aaa111bbbb2222cccc3333', 'Chair', 'Accra')],
			extensions: { 'cetech-delivery-engine': { notices: [] } }
		};
		installCart(cart);
		const api = loadScript();
		api.renderDomUi();
		const first = document.getElementById('cetech-de-blocks-dom-ui').innerHTML;
		document.getElementById('cetech-de-blocks-dom-ui').setAttribute('data-rebuild-probe', '1');
		api.renderDomUi();
		expect(document.getElementById('cetech-de-blocks-dom-ui').innerHTML).toBe(first);
		expect(document.getElementById('cetech-de-blocks-dom-ui').getAttribute('data-rebuild-probe')).toBe('1');
		expect(api.uiSignature(cart)).toContain('aaa111bbbb2222cccc3333');
	});

	it('does not register PluginArea context editors', () => {
		const calls = [];
		window.wp = {
			plugins: { registerPlugin: (name) => calls.push(name) },
			data: { select: () => ({ getCartData: () => null }), subscribe: () => {} },
			element: { createElement: () => null }
		};
		window.wc = { blocksCheckout: {} };
		loadScript();
		expect(calls.filter((name) => String(name).includes('context'))).toHaveLength(0);
	});

	it('hides Use my checkout address when destinations are heterogeneous', () => {
		window.cetechDeBlocks = {
			namespace: 'cetech-delivery-engine',
			i18n: { applyCheckoutAddress: 'Use my checkout address', addDeliveryAddress: 'Add delivery address' }
		};
		installCart({
			items: [],
			extensions: {
				'cetech-delivery-engine': {
					can_apply_checkout_address: false,
					incomplete_delivery: 2,
					notices: [
						{
							code: 'heterogeneous_destinations',
							message: 'These items are going to different destinations. Add a delivery address for each item. Your selected destinations will be kept.'
						}
					]
				}
			}
		});
		const api = loadScript();
		api.renderDomUi();
		const mount = document.getElementById('cetech-de-blocks-dom-ui');
		expect(mount).toBeTruthy();
		expect(mount.textContent).toContain('These items are going to different destinations.');
		expect(mount.querySelector('.cetech-de-blocks-apply-checkout-address')).toBeNull();
	});

	it('shows Use my checkout address only when the bulk action is available', () => {
		window.cetechDeBlocks = {
			namespace: 'cetech-delivery-engine',
			i18n: { applyCheckoutAddress: 'Use my checkout address' }
		};
		installCart({
			items: [],
			extensions: {
				'cetech-delivery-engine': {
					can_apply_checkout_address: true,
					incomplete_delivery: 1,
					notices: []
				}
			}
		});
		const api = loadScript();
		api.renderDomUi();
		const button = document.querySelector('.cetech-de-blocks-apply-checkout-address');
		expect(button).toBeTruthy();
		expect(button.textContent).toContain('Use my checkout address');
	});
});
