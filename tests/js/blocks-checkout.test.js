/**
 * @vitest-environment jsdom
 */
import { describe, expect, it, vi } from 'vitest';
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
	function editableItem(key, name, locality, address = {}, extras = {}) {
		return {
			key,
			name,
			quantity: extras.quantity || 2,
			extensions: {
				'cetech-delivery-engine': {
					can_edit_context: true,
					needs_reselection: false,
					cart_item_key: key,
					product_name: name,
					quantity: extras.quantity || 2,
					locality,
					can_split: extras.can_split !== undefined ? extras.can_split : (extras.quantity || 2) > 1,
					is_pickup: !!extras.is_pickup,
					address_complete: !!address.address_1,
					address_needed: extras.is_pickup ? false : !address.address_1,
					address_action_label: extras.address_action_label || (extras.is_pickup ? 'Edit pickup details' : (address.address_1 ? 'Edit delivery details' : 'Add delivery address')),
					ui_anchor: extras.ui_anchor || ('cetech-de-delivery-' + String(key).replace(/[^a-f0-9]/g, '').padEnd(16, '0').slice(0, 16)),
					has_matching_location: extras.has_matching_location !== undefined ? extras.has_matching_location : true,
					destination_summary: extras.destination_summary || (locality ? locality + ', Greater Accra' : ''),
					matching_location: extras.matching_location || { country: 'GH', state: 'AA', city: locality, postcode: 'GA-123' },
					delivery_address: {
						address_1: address.address_1 || '',
						address_2: address.address_2 || '',
						first_name: address.first_name || '',
						last_name: address.last_name || '',
						company: address.company || '',
						phone: address.phone || ''
					},
					available_options: extras.available_options || [
						{ display_key: 'in_warehouse:delivery:1', label: 'QA Local Standard', estimate_text: '3-5 days', selected: true, fulfilment_choice: 'delivery' }
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
		const fieldIds = ids.filter((id) => !id.startsWith('cetech-de-delivery-'));
		expect(fieldIds.every((id) => id.startsWith('cetech-de-b-'))).toBe(true);
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
			i18n: { applyCheckoutAddress: 'Use my checkout address', addDeliveryAddress: 'Add delivery address', cartUrl: '/cart/' }
		};
		installCart({
			items: [],
			extensions: {
				'cetech-delivery-engine': {
					can_apply_checkout_address: false,
					incomplete_delivery: 2,
					first_incomplete_anchor: 'cetech-de-delivery-aaaaaaaaaaaaaaaa',
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
		expect(mount.querySelector('.cetech-de-blocks-add-delivery-address')).toBeTruthy();
		expect(mount.querySelector('.cetech-de-blocks-add-delivery-address').textContent).toContain('Add delivery address');
	});

	it('shows Use my checkout address only when the bulk action is available', () => {
		window.cetechDeBlocks = {
			namespace: 'cetech-delivery-engine',
			i18n: { applyCheckoutAddress: 'Use my checkout address', addDeliveryAddress: 'Add delivery address', cartUrl: '/cart/' }
		};
		installCart({
			items: [],
			extensions: {
				'cetech-delivery-engine': {
					can_apply_checkout_address: true,
					incomplete_delivery: 1,
					first_incomplete_anchor: 'cetech-de-delivery-bbbbbbbbbbbbbbbb',
					notices: []
				}
			}
		});
		const api = loadScript();
		api.renderDomUi();
		const button = document.querySelector('.cetech-de-blocks-apply-checkout-address');
		expect(button).toBeTruthy();
		expect(button.textContent).toContain('Use my checkout address');
		expect(button.className).toContain('cetech-de-blocks-apply-checkout-address--secondary');
		const add = document.querySelector('.cetech-de-blocks-add-delivery-address');
		expect(add).toBeTruthy();
		expect(add.getAttribute('href')).toBe('/cart/#cetech-de-delivery-bbbbbbbbbbbbbbbb');
	});

	it('still renders Add delivery address when can_apply is false', () => {
		window.cetechDeBlocks = {
			namespace: 'cetech-delivery-engine',
			i18n: { addDeliveryAddress: 'Add delivery address', cartUrl: '/cart/' }
		};
		installCart({
			items: [],
			extensions: {
				'cetech-delivery-engine': {
					can_apply_checkout_address: false,
					incomplete_delivery: 1,
					first_incomplete_anchor: 'cetech-de-delivery-cccccccccccccccc',
					notices: []
				}
			}
		});
		const api = loadScript();
		api.renderDomUi();
		const add = document.querySelector('.cetech-de-blocks-add-delivery-address');
		expect(add).toBeTruthy();
		expect(add.getAttribute('href')).toBe('/cart/#cetech-de-delivery-cccccccccccccccc');
		expect(document.querySelector('.cetech-de-blocks-apply-checkout-address')).toBeNull();
	});

	it('uses the item ui_anchor as the editor id and compact labels', () => {
		window.cetechDeBlocks = {
			namespace: 'cetech-delivery-engine',
			i18n: {
				addressNeeded: 'Address needed',
				addDeliveryAddress: 'Add delivery address',
				editDeliveryDetails: 'Edit delivery details',
				company: 'Company',
				recipientOptional: 'Recipient details (optional)',
				applyToQuantity: 'Apply to quantity'
			}
		};
		const incomplete = editableItem('aaa111bbbb2222cccc3333', 'Chair', 'Accra', {}, {
			ui_anchor: 'cetech-de-delivery-1111111111111111',
			address_action_label: 'Add delivery address'
		});
		const complete = editableItem('dddd4444eeee5555ffff6666', 'Lamp', 'Tema', { address_1: '4 Harbour', company: 'CETECH' }, {
			ui_anchor: 'cetech-de-delivery-2222222222222222',
			address_action_label: 'Edit delivery details',
			quantity: 1,
			can_split: false
		});
		installCart({
			items: [incomplete, complete],
			extensions: { 'cetech-delivery-engine': { notices: [] } }
		});
		const api = loadScript();
		api.renderDomUi();
		const incompleteEditor = document.getElementById('cetech-de-delivery-1111111111111111');
		const completeEditor = document.getElementById('cetech-de-delivery-2222222222222222');
		expect(incompleteEditor).toBeTruthy();
		expect(completeEditor).toBeTruthy();
		expect(incompleteEditor.tagName).toBe('DETAILS');
		expect(document.querySelector('[data-cetech-de-address-needed]').textContent).toContain('Address needed');
		expect(incompleteEditor.querySelector('summary').textContent).toContain('Add delivery address');
		expect(completeEditor.querySelector('summary').textContent).toContain('Edit delivery details');
		expect(completeEditor.querySelector('[name="cetech_de_company"]').value).toBe('CETECH');
		expect(completeEditor.querySelector('.cetech-de-blocks-editor__recipient').open).toBe(true);
		expect(incompleteEditor.querySelector('.cetech-de-blocks-editor__recipient').open).toBe(false);
		expect(incompleteEditor.querySelector('[data-cetech-de-qty-split]')).toBeTruthy();
		expect(completeEditor.querySelector('[data-cetech-de-qty-split]')).toBeNull();
		const read = api.readEditor(completeEditor);
		expect(read.delivery_address.company).toBe('CETECH');
	});

	it('opens the matching editor once for a deep-link hash and preserves open state', () => {
		window.cetechDeBlocks = { namespace: 'cetech-delivery-engine', i18n: { address1: 'Address line 1' } };
		window.history.replaceState(null, '', '/cart/#cetech-de-delivery-1111111111111111');
		installCart({
			items: [
				editableItem('aaa111bbbb2222cccc3333', 'Chair', 'Accra', {}, { ui_anchor: 'cetech-de-delivery-1111111111111111' }),
				editableItem('dddd4444eeee5555ffff6666', 'Lamp', 'Tema', {}, { ui_anchor: 'cetech-de-delivery-2222222222222222' })
			],
			extensions: { 'cetech-delivery-engine': { notices: [] } }
		});
		const api = loadScript();
		api.resetDeepLink();
		api.renderDomUi();
		const first = document.getElementById('cetech-de-delivery-1111111111111111');
		const second = document.getElementById('cetech-de-delivery-2222222222222222');
		expect(first.open).toBe(true);
		expect(second.open).toBe(false);
		const required = first.querySelector('[data-cetech-de-required-address]');
		expect(document.activeElement).toBe(required);
		first.querySelector('[name="cetech_de_address_1"]').focus();
		expect(document.activeElement).toBe(first.querySelector('[name="cetech_de_address_1"]'));
		api.renderDomUi();
		expect(document.getElementById('cetech-de-delivery-1111111111111111').open).toBe(true);
		expect(document.activeElement).toBe(document.getElementById('cetech-de-delivery-1111111111111111').querySelector('[name="cetech_de_address_1"]'));
	});

	it('focuses the first missing destination field when matching location is absent', () => {
		window.cetechDeBlocks = { namespace: 'cetech-delivery-engine', i18n: { address1: 'Address line 1' } };
		window.history.replaceState(null, '', '/cart/#cetech-de-delivery-1111111111111111');
		installCart({
			items: [
				editableItem('aaa111bbbb2222cccc3333', 'Chair', '', {}, {
					ui_anchor: 'cetech-de-delivery-1111111111111111',
					has_matching_location: false,
					matching_location: { country: '', state: '', city: '', postcode: '' }
				})
			],
			extensions: { 'cetech-delivery-engine': { notices: [] } }
		});
		const api = loadScript();
		api.resetDeepLink();
		api.renderDomUi();
		const editor = document.getElementById('cetech-de-delivery-1111111111111111');
		expect(editor.open).toBe(true);
		expect(editor.querySelector('[data-cetech-de-editor-location] details').open).toBe(true);
		expect(document.activeElement).toBe(editor.querySelector('[data-cetech-de-destination-control="country"]'));
		expect(document.activeElement).not.toBe(editor.querySelector('[data-cetech-de-required-address]'));
	});

	it('restores Delivery sections after unsaved Pickup then Cancel without Store API mutation', () => {
		window.cetechDeBlocks = { namespace: 'cetech-delivery-engine', i18n: {} };
		window.wc = { blocksCheckout: { extensionCartUpdate: vi.fn() } };
		installCart({
			items: [
				editableItem('aaa111bbbb2222cccc3333', 'Chair', 'Accra', {}, {
					ui_anchor: 'cetech-de-delivery-1111111111111111',
					available_options: [
						{ display_key: 'in_warehouse:delivery:1', label: 'QA Local Standard', selected: true, fulfilment_choice: 'delivery' },
						{ display_key: 'in_store:store_pickup:4', label: 'Store pickup', selected: false, fulfilment_choice: 'store_pickup' }
					]
				})
			],
			extensions: { 'cetech-delivery-engine': { notices: [] } }
		});
		const api = loadScript();
		api.renderDomUi();
		const editor = document.getElementById('cetech-de-delivery-1111111111111111');
		const location = editor.querySelector('[data-cetech-de-editor-location]');
		const address = editor.querySelector('[data-cetech-de-editor-address]');
		const recipient = editor.querySelector('[data-cetech-de-editor-recipient]');
		const select = editor.querySelector('select[name="cetech_de_delivery_option_key"]');
		editor.open = true;
		expect(location.hidden).toBe(false);
		select.value = 'in_store:store_pickup:4';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		expect(location.hidden).toBe(true);
		expect(address.hidden).toBe(true);
		expect(recipient.hidden).toBe(true);
		editor.querySelector('.cetech-de-blocks-editor__cancel').click();
		expect(select.value).toBe('in_warehouse:delivery:1');
		expect(location.hidden).toBe(false);
		expect(address.hidden).toBe(false);
		expect(recipient.hidden).toBe(false);
		expect(editor.open).toBe(false);
		expect(document.activeElement).toBe(editor.querySelector('summary'));
		expect(window.wc.blocksCheckout.extensionCartUpdate).not.toHaveBeenCalled();
		expect(typeof api.resetEditor).toBe('function');
		expect(typeof api.syncMethodState).toBe('function');
	});

	it('restores Pickup visibility after unsaved Delivery then Cancel', () => {
		window.cetechDeBlocks = { namespace: 'cetech-delivery-engine', i18n: {} };
		window.wc = { blocksCheckout: { extensionCartUpdate: vi.fn() } };
		installCart({
			items: [
				editableItem('aaa111bbbb2222cccc3333', 'Chair', 'Accra', {}, {
					ui_anchor: 'cetech-de-delivery-1111111111111111',
					is_pickup: true,
					address_action_label: 'Edit pickup details',
					available_options: [
						{ display_key: 'in_store:store_pickup:4', label: 'Store pickup', selected: true, fulfilment_choice: 'store_pickup' },
						{ display_key: 'in_warehouse:delivery:1', label: 'QA Local Standard', selected: false, fulfilment_choice: 'delivery' }
					]
				})
			],
			extensions: { 'cetech-delivery-engine': { notices: [] } }
		});
		loadScript().renderDomUi();
		const editor = document.getElementById('cetech-de-delivery-1111111111111111');
		const location = editor.querySelector('[data-cetech-de-editor-location]');
		const address = editor.querySelector('[data-cetech-de-editor-address]');
		const select = editor.querySelector('select[name="cetech_de_delivery_option_key"]');
		editor.open = true;
		expect(location.hidden).toBe(true);
		select.value = 'in_warehouse:delivery:1';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		expect(location.hidden).toBe(false);
		expect(address.hidden).toBe(false);
		editor.querySelector('.cetech-de-blocks-editor__cancel').click();
		expect(select.value).toBe('in_store:store_pickup:4');
		expect(location.hidden).toBe(true);
		expect(address.hidden).toBe(true);
		expect(editor.open).toBe(false);
		expect(window.wc.blocksCheckout.extensionCartUpdate).not.toHaveBeenCalled();
	});
});
