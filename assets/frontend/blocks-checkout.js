/**
 * Cart / Checkout Blocks presentation for CETECH Delivery Engine.
 *
 * Reads customer-safe Store API extensions and keeps Store Pickup from looking
 * like a shipment to the customer's address. Does not calculate prices.
 */
(function (window, document) {
	'use strict';

	var NAMESPACE = (window.cetechDeBlocks && window.cetechDeBlocks.namespace) || 'cetech-delivery-engine';

	function getCartData() {
		try {
			if (window.wp && window.wp.data && typeof window.wp.data.select === 'function') {
				var store = window.wp.data.select('wc/store/cart');
				if (store && typeof store.getCartData === 'function') {
					return store.getCartData();
				}
			}
		} catch (e) {
			return null;
		}
		return null;
	}

	function getExtensions(cart) {
		if (!cart || !cart.extensions) {
			return {};
		}
		return cart.extensions[NAMESPACE] || {};
	}

	function pickupPackages(extensions) {
		var packages = extensions.packages || [];
		return packages.filter(function (pkg) {
			return pkg && pkg.is_pickup;
		});
	}

	function textOf(el) {
		return (el && el.textContent ? el.textContent : '').replace(/\s+/g, ' ').trim();
	}

	function replaceShippingToCopy(pkg) {
		if (!pkg || !pkg.is_pickup) {
			return;
		}

		var address = pkg.pickup_address || pkg.destination_label || '';
		var heading = pkg.heading || '';
		var nodes = document.querySelectorAll(
			'.wc-block-components-shipping-rates-control__package, .wc-block-components-shipping-rates-control'
		);

		nodes.forEach(function (node) {
			var content = textOf(node);
			if (heading && content.indexOf(heading) === -1 && pkg.offer_label && content.indexOf(pkg.offer_label) === -1) {
				return;
			}

			node.querySelectorAll('a, button').forEach(function (control) {
				var label = textOf(control).toLowerCase();
				if (label.indexOf('change address') !== -1) {
					control.style.display = 'none';
				}
			});

			var destination = node.querySelector(
				'.wc-block-components-shipping-rates-control__package-destination, .wc-block-components-address-card, .wc-block-components-shipping-address'
			);

			if (destination && address) {
				destination.textContent = address;
			}

			node.querySelectorAll('p, span, div').forEach(function (el) {
				var copy = textOf(el);
				if (/^shipping to\b/i.test(copy) && address) {
					el.textContent = 'Pickup address: ' + address;
				}
			});
		});
	}

	function apply() {
		var cart = getCartData();
		var extensions = getExtensions(cart);
		pickupPackages(extensions).forEach(replaceShippingToCopy);
		renderDomUi();
	}

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function fieldInput(name, label, value) {
		return '<label class="cetech-de-blocks-editor__field">' + escapeHtml(label) +
			'<input type="text" name="' + escapeHtml(name) + '" value="' + escapeHtml(value || '') + '" /></label>';
	}

	function readEditor(editor) {
		function val(name) {
			var el = editor.querySelector('[name="' + name + '"]');
			return el ? String(el.value || '') : '';
		}
		var selected = (editor.querySelector('select[name="cetech_de_delivery_option_key"]') || {}).value || '';
		var split = !!(editor.querySelector('input[name="cetech_de_apply_mode"]') || {}).checked;
		return {
			action: split ? 'split_item_context' : 'set_item_context',
			cart_item_key: editor.getAttribute('data-cart-item-key') || '',
			display_key: selected,
			matching_location: {
				country: val('cetech_de_matching_country'),
				state: val('cetech_de_matching_state'),
				city: val('cetech_de_matching_city'),
				postcode: val('cetech_de_matching_postcode')
			},
			delivery_address: {
				address_1: val('cetech_de_address_1'),
				address_2: val('cetech_de_address_2'),
				first_name: val('cetech_de_first_name'),
				last_name: val('cetech_de_last_name'),
				phone: val('cetech_de_phone')
			},
			quantity: parseInt(val('cetech_de_split_qty'), 10) || 1
		};
	}

	function renderDomUi() {
		var cart = getCartData();
		if (!cart) {
			return;
		}
		var host = document.querySelector(
			'.wp-block-woocommerce-filled-cart-block, .wp-block-woocommerce-checkout, .wc-block-cart, .wc-block-checkout, .wp-block-woocommerce-checkout-fields-block'
		);
		if (!host) {
			return;
		}
		if (document.querySelector('.cetech-de-blocks-customer-context:not(#cetech-de-blocks-dom-ui)')) {
			return;
		}
		var i18n = (window.cetechDeBlocks && window.cetechDeBlocks.i18n) || {};
		var extensions = getExtensions(cart);
		var items = editableItems(cart);
		var notices = extensions.notices || [];
		var skipped = ((extensions.mutation_result || {}).skipped) || [];
		var failed = ((extensions.mutation_result || {}).failed) || [];
		if (!items.length && !notices.length && !skipped.length && !failed.length && !extensions.can_apply_checkout_address) {
			var existingEmpty = document.getElementById('cetech-de-blocks-dom-ui');
			if (existingEmpty) {
				existingEmpty.remove();
			}
			return;
		}
		var mount = document.getElementById('cetech-de-blocks-dom-ui');
		if (!mount) {
			mount = document.createElement('div');
			mount.id = 'cetech-de-blocks-dom-ui';
			mount.className = 'cetech-de-blocks-customer-context';
			host.insertBefore(mount, host.firstChild);
		}
		if (mount.contains(document.activeElement) && document.activeElement && /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName)) {
			return;
		}
		var html = '';
		notices.forEach(function (notice) {
			html += '<p class="cetech-de-blocks-notice cetech-de-blocks-notice--' + escapeHtml(notice.code || 'info') + '" role="' + (notice.code === 'incomplete_delivery' ? 'alert' : 'status') + '">' + escapeHtml(notice.message || '') + '</p>';
		});
		skipped.concat(failed).forEach(function (row) {
			html += '<p class="cetech-de-blocks-notice cetech-de-blocks-notice--failure" role="status">' + escapeHtml((row.name ? row.name + ' — ' : '') + (row.reason || '')) + '</p>';
		});
		if (extensions.can_apply_checkout_address) {
			html += '<button type="button" class="wc-block-components-button cetech-de-blocks-apply-checkout-address">' +
				escapeHtml(i18n.applyCheckoutAddress || 'Use checkout shipping address for incomplete delivery items') + '</button>';
		}
		items.forEach(function (item) {
			var ext = item.ext || {};
			var matching = ext.matching_location || {};
			var address = ext.delivery_address || {};
			var selected = '';
			(ext.available_options || []).forEach(function (option) {
				if (option.selected) {
					selected = option.display_key;
				}
			});
			var options = (ext.available_options || []).map(function (option) {
				return '<option value="' + escapeHtml(option.display_key) + '"' + (option.display_key === selected ? ' selected' : '') + '>' +
					escapeHtml(option.estimate_text ? option.label + ' — ' + option.estimate_text : option.label) + '</option>';
			}).join('');
			var isPickup = !!ext.is_pickup;
			var summary = (isPickup ? (i18n.changePickup || 'Change pickup') : (i18n.changeDelivery || 'Change delivery'));
			if (ext.locality) {
				summary += ' — ' + (isPickup ? (ext.pickup_location_label || ext.locality) : ((i18n.deliveringTo || 'Delivering to') + ': ' + ext.locality));
			}
			html += '<details class="cetech-de-blocks-editor" data-cart-item-key="' + escapeHtml(item.key) + '">';
			html += '<summary>' + escapeHtml(summary) + '</summary>';
			html += '<div class="cetech-de-blocks-editor__body">';
			html += '<label class="cetech-de-blocks-editor__field">' + escapeHtml(i18n.choose || 'Choose a delivery option') +
				'<select name="cetech_de_delivery_option_key">' + options + '</select></label>';
			if (!isPickup) {
				html += fieldInput('cetech_de_matching_country', i18n.country || 'Country', matching.country);
				html += fieldInput('cetech_de_matching_state', i18n.state || 'State / Region', matching.state);
				html += fieldInput('cetech_de_matching_city', i18n.city || 'City', matching.city);
				html += fieldInput('cetech_de_matching_postcode', i18n.postcode || 'Postcode', matching.postcode);
				html += fieldInput('cetech_de_address_1', i18n.address1 || 'Address', address.address_1);
				html += fieldInput('cetech_de_address_2', i18n.address2 || 'Apartment, suite, etc.', address.address_2);
				html += fieldInput('cetech_de_first_name', i18n.firstName || 'First name', address.first_name);
				html += fieldInput('cetech_de_last_name', i18n.lastName || 'Last name', address.last_name);
				html += fieldInput('cetech_de_phone', i18n.phone || 'Phone', address.phone);
			}
			if (ext.can_split) {
				html += '<label class="cetech-de-blocks-editor__field"><input type="checkbox" name="cetech_de_apply_mode" /> ' +
					escapeHtml(i18n.split || 'Move some quantity') + '</label>';
				html += fieldInput('cetech_de_split_qty', i18n.split || 'Move some quantity', '1');
			}
			html += '<button type="button" class="wc-block-components-button cetech-de-blocks-editor__update">' +
				escapeHtml(i18n.updateDetails || 'Update') + '</button>';
			if (!isPickup) {
				html += '<button type="button" class="wc-block-components-button cetech-de-blocks-editor__use-for-all">' +
					escapeHtml(i18n.useForAll || 'Use this address for all eligible delivery items') + '</button>';
			}
			html += '</div></details>';
		});
		mount.innerHTML = html;
		mount.querySelectorAll('.cetech-de-blocks-editor__update').forEach(function (button) {
			button.addEventListener('click', function () {
				var editor = button.closest('.cetech-de-blocks-editor');
				if (editor) {
					submitCommand(readEditor(editor));
				}
			});
		});
		mount.querySelectorAll('.cetech-de-blocks-editor__use-for-all').forEach(function (button) {
			button.addEventListener('click', function () {
				var editor = button.closest('.cetech-de-blocks-editor');
				if (editor) {
					var data = readEditor(editor);
					data.action = 'use_for_all';
					submitCommand(data);
				}
			});
		});
		mount.querySelectorAll('.cetech-de-blocks-apply-checkout-address').forEach(function (button) {
			button.addEventListener('click', function () {
				submitCommand({ action: 'apply_checkout_address' });
			});
		});
	}

	function subscribe() {
		if (window.wp && window.wp.data && typeof window.wp.data.subscribe === 'function') {
			window.wp.data.subscribe(function () {
				apply();
			});
		}
	}

	function registerSlotFill() {
		var wp = window.wp || {};
		var wc = window.wc || {};
		var plugins = wp.plugins || {};
		var element = wp.element || {};
		var blocksCheckout = (wc.blocksCheckout || {});
		var ExperimentalOrderShippingPackages = blocksCheckout.ExperimentalOrderShippingPackages;
		var createElement = element.createElement;
		var registerPlugin = plugins.registerPlugin;

		if (!registerPlugin || !createElement || !ExperimentalOrderShippingPackages) {
			return;
		}

		function PickupNotes() {
			var cart = getCartData();
			var notes = pickupPackages(getExtensions(cart)).map(function (pkg, index) {
				var lines = [];
				if (pkg.pickup_location_label) {
					lines.push(pkg.pickup_location_label);
				}
				if (pkg.pickup_address) {
					lines.push(pkg.pickup_address);
				}
				if (pkg.estimate_text) {
					lines.push(pkg.estimate_text);
				}
				if (!lines.length) {
					return null;
				}
				return createElement(
					'p',
					{
						key: 'cetech-de-pickup-' + index,
						className: 'cetech-de-blocks-pickup-note'
					},
					lines.join(' — ')
				);
			});

			return createElement('div', { className: 'cetech-de-blocks-pickup-notes' }, notes);
		}

		['woocommerce-checkout', 'woocommerce-cart'].forEach(function (scope) {
			registerPlugin('cetech-de-blocks-pickup-' + scope, {
				render: function () {
					return createElement(ExperimentalOrderShippingPackages, {}, createElement(PickupNotes));
				},
				scope: scope
			});
		});
	}

	function itemExtension(item) {
		if (!item || !item.extensions) {
			return {};
		}
		return item.extensions[NAMESPACE] || {};
	}

	function itemsNeedingReselection(cart) {
		var items = (cart && cart.items) || [];
		return items
			.filter(function (item) {
				return itemExtension(item).needs_reselection;
			})
			.map(function (item) {
				var ext = itemExtension(item);
				return {
					key: ext.cart_item_key || item.key,
					name: ext.product_name || item.name || '',
					message: ext.reselection_message || '',
					options: ext.reselection_options || []
				};
			});
	}

	function submitReselection(cartItemKey, displayKey) {
		var blocksCheckout = (window.wc && window.wc.blocksCheckout) || {};
		if (typeof blocksCheckout.extensionCartUpdate !== 'function' || !cartItemKey || !displayKey) {
			return;
		}
		blocksCheckout.extensionCartUpdate({
			namespace: NAMESPACE,
			data: {
				action: 'reselect_option',
				cart_item_key: cartItemKey,
				display_key: displayKey
			}
		});
	}

	function registerReselectionPanel() {
		var wp = window.wp || {};
		var plugins = wp.plugins || {};
		var element = wp.element || {};
		var createElement = element.createElement;
		var registerPlugin = plugins.registerPlugin;
		var i18n = (window.cetechDeBlocks && window.cetechDeBlocks.i18n) || {};

		if (!registerPlugin || !createElement) {
			return;
		}

		function ReselectionPanel() {
			var needing = itemsNeedingReselection(getCartData());
			if (!needing.length) {
				return null;
			}

			return createElement(
				'div',
				{ className: 'cetech-de-blocks-reselection' },
				needing.map(function (item) {
					return createElement(
						'div',
						{ key: item.key, className: 'cetech-de-blocks-reselection__item' },
						createElement('p', { className: 'cetech-de-blocks-reselection__message' }, item.message),
						createElement(
							'label',
							null,
							i18n.choose || 'Choose a delivery option',
							createElement(
								'select',
								{
									defaultValue: '',
									onChange: function (event) {
										item._selected = event.target.value;
									}
								},
								createElement('option', { value: '' }, i18n.choose || 'Choose a delivery option'),
								item.options.map(function (option) {
									return createElement(
										'option',
										{ key: option.display_key, value: option.display_key },
										option.estimate_text
											? option.label + ' — ' + option.estimate_text
											: option.label
									);
								})
							)
						),
						createElement(
							'button',
							{
								type: 'button',
								className: 'wc-block-components-button',
								onClick: function () {
									submitReselection(item.key, item._selected || '');
								}
							},
							i18n.update || 'Update delivery option'
						)
					);
				})
			);
		}

		['woocommerce-checkout', 'woocommerce-cart'].forEach(function (scope) {
			registerPlugin('cetech-de-blocks-reselection-' + scope, {
				render: ReselectionPanel,
				scope: scope
			});
		});
	}

	function submitCommand(data) {
		var blocksCheckout = (window.wc && window.wc.blocksCheckout) || {};
		if (typeof blocksCheckout.extensionCartUpdate !== 'function') {
			return;
		}
		return blocksCheckout.extensionCartUpdate({
			namespace: NAMESPACE,
			data: data
		});
	}

	function editableItems(cart) {
		var items = (cart && cart.items) || [];
		return items
			.filter(function (item) {
				var ext = itemExtension(item);
				return ext.can_edit_context && !ext.needs_reselection;
			})
			.map(function (item) {
				var ext = itemExtension(item);
				return {
					key: ext.cart_item_key || item.key,
					name: ext.product_name || item.name || '',
					ext: ext,
					quantity: ext.quantity || item.quantity || 1
				};
			});
	}

		function registerContextEditors() {
		var wp = window.wp || {};
		var plugins = wp.plugins || {};
		var element = wp.element || {};
		var createElement = element.createElement;
		var useState = element.useState;
		var registerPlugin = plugins.registerPlugin;
		var i18n = (window.cetechDeBlocks && window.cetechDeBlocks.i18n) || {};
		var blocksCheckout = (window.wc && window.wc.blocksCheckout) || {};

		if (!registerPlugin || !createElement) {
			return;
		}

		function field(label, value, onChange) {
			return createElement(
				'label',
				{ className: 'cetech-de-blocks-editor__field' },
				label,
				createElement('input', {
					type: 'text',
					value: value || '',
					onChange: function (event) {
						onChange(event.target.value);
					}
				})
			);
		}

		function ItemEditor(props) {
			var ext = props.ext || {};
			var matching = ext.matching_location || {};
			var address = ext.delivery_address || {};
			var selected = (ext.available_options || []).find(function (option) { return option.selected; });
			var state = useState ? useState({
				display_key: selected ? selected.display_key : '',
				country: matching.country || '',
				state: matching.state || '',
				city: matching.city || '',
				postcode: matching.postcode || '',
				address_1: address.address_1 || '',
				address_2: address.address_2 || '',
				first_name: address.first_name || '',
				last_name: address.last_name || '',
				phone: address.phone || '',
				split: false,
				quantity: 1
			}) : [{}, function () {}];
			var values = state[0];
			var setValues = state[1];
			function patch(key, value) {
				var next = Object.assign({}, values);
				next[key] = value;
				setValues(next);
			}
			function payload(action) {
				return {
					action: action,
					cart_item_key: props.itemKey,
					display_key: values.display_key,
					matching_location: {
						country: values.country,
						state: values.state,
						city: values.city,
						postcode: values.postcode
					},
					delivery_address: {
						address_1: values.address_1,
						address_2: values.address_2,
						first_name: values.first_name,
						last_name: values.last_name,
						phone: values.phone
					},
					quantity: parseInt(values.quantity, 10) || 1
				};
			}
			var options = (ext.available_options || []).map(function (option) {
				return createElement(
					'option',
					{ key: option.display_key, value: option.display_key },
					option.estimate_text ? option.label + ' — ' + option.estimate_text : option.label
				);
			});
			var isPickup = !!ext.is_pickup;
			var localityCopy = ext.locality
				? ' — ' + (isPickup
					? (ext.pickup_location_label || ext.locality)
					: ((i18n.deliveringTo || 'Delivering to') + ': ' + ext.locality))
				: (isPickup && ext.pickup_location_label ? ' — ' + ext.pickup_location_label : '');
			return createElement(
				'details',
				{ className: 'cetech-de-blocks-editor', open: false },
				createElement(
					'summary',
					null,
					isPickup ? (i18n.changePickup || 'Change pickup') : (i18n.changeDelivery || 'Change delivery'),
					localityCopy
				),
				createElement(
					'div',
					{ className: 'cetech-de-blocks-editor__body' },
					createElement(
						'label',
						{ className: 'cetech-de-blocks-editor__field' },
						i18n.choose || 'Choose a delivery option',
						createElement(
							'select',
							{
								value: values.display_key,
								onChange: function (event) {
									patch('display_key', event.target.value);
								}
							},
							options
						)
					),
					isPickup
						? null
						: [
								field(i18n.country || 'Country', values.country, function (v) { patch('country', v); }),
								field(i18n.state || 'State / Region', values.state, function (v) { patch('state', v); }),
								field(i18n.city || 'City', values.city, function (v) { patch('city', v); }),
								field(i18n.postcode || 'Postcode', values.postcode, function (v) { patch('postcode', v); }),
								field(i18n.address1 || 'Address', values.address_1, function (v) { patch('address_1', v); }),
								field(i18n.address2 || 'Apartment, suite, etc.', values.address_2, function (v) { patch('address_2', v); }),
								field(i18n.firstName || 'First name', values.first_name, function (v) { patch('first_name', v); }),
								field(i18n.lastName || 'Last name', values.last_name, function (v) { patch('last_name', v); }),
								field(i18n.phone || 'Phone', values.phone, function (v) { patch('phone', v); })
							],
					!isPickup && !values.split && (props.quantity || ext.quantity || 1) > 1
						? createElement('p', { className: 'cetech-de-blocks-editor__apply-all' }, i18n.applyAllN || 'Apply to all items')
						: null,
					ext.can_split
						? createElement(
								'label',
								{ className: 'cetech-de-blocks-editor__field' },
								createElement('input', {
									type: 'checkbox',
									checked: !!values.split,
									onChange: function (event) {
										patch('split', event.target.checked);
									}
								}),
								' ',
								i18n.split || 'Move some quantity'
							)
						: null,
					values.split
						? field(i18n.split || 'Move some quantity', String(values.quantity), function (v) { patch('quantity', v); })
						: null,
					createElement(
						'button',
						{
							type: 'button',
							className: 'wc-block-components-button cetech-de-blocks-editor__update',
							onClick: function () {
								submitCommand(payload(values.split ? 'split_item_context' : 'set_item_context'));
							}
						},
						i18n.updateDetails || 'Update'
					),
					isPickup
						? null
						: createElement(
								'button',
								{
									type: 'button',
									className: 'wc-block-components-button cetech-de-blocks-editor__use-for-all',
									onClick: function () {
										submitCommand(payload('use_for_all'));
									}
								},
								i18n.useForAll || 'Use this address for all eligible delivery items'
							)
				)
			);
		}

		function ContextEditors() {
			var cart = getCartData();
			var extensions = getExtensions(cart);
			var items = editableItems(cart);
			var notices = extensions.notices || [];
			var skipped = ((extensions.mutation_result || {}).skipped) || [];
			var failed = ((extensions.mutation_result || {}).failed) || [];
			if (!items.length && !notices.length && !skipped.length && !failed.length) {
				return null;
			}
			return createElement(
				'div',
				{ className: 'cetech-de-blocks-customer-context' },
				notices.map(function (notice, index) {
					return createElement(
						'p',
						{
							key: notice.code || index,
							className: 'cetech-de-blocks-notice cetech-de-blocks-notice--' + (notice.code || 'info'),
							role: notice.code === 'incomplete_delivery' ? 'alert' : 'status'
						},
						notice.message
					);
				}),
				skipped.map(function (row, index) {
					return createElement(
						'p',
						{ key: 'skip-' + index, className: 'cetech-de-blocks-notice cetech-de-blocks-notice--failure', role: 'status' },
						(row.name ? row.name + ' — ' : '') + (row.reason || '')
					);
				}),
				extensions.can_apply_checkout_address
					? createElement(
							'button',
							{
								type: 'button',
								className: 'wc-block-components-button cetech-de-blocks-apply-checkout-address',
								onClick: function () {
									submitCommand({ action: 'apply_checkout_address' });
								}
							},
							i18n.applyCheckoutAddress || 'Use checkout shipping address for incomplete delivery items'
						)
					: null,
				items.map(function (item) {
					return createElement(ItemEditor, {
						key: item.key,
						itemKey: item.key,
						ext: item.ext,
						quantity: item.quantity
					});
				})
			);
		}

		['woocommerce-checkout', 'woocommerce-cart'].forEach(function (scope) {
			registerPlugin('cetech-de-blocks-context-' + scope, {
				render: ContextEditors,
				scope: scope
			});
		});
	}

	window.CetechDeBlocksCheckout = {
		apply: apply,
		getExtensions: getExtensions,
		pickupPackages: pickupPackages,
		itemsNeedingReselection: itemsNeedingReselection,
		submitReselection: submitReselection,
		submitCommand: submitCommand,
		editableItems: editableItems,
		renderDomUi: renderDomUi,
		namespace: NAMESPACE
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			registerSlotFill();
			registerReselectionPanel();
			registerContextEditors();
			subscribe();
			apply();
			setTimeout(apply, 400);
			setTimeout(apply, 1500);
			setTimeout(apply, 3000);
		});
	} else {
		registerSlotFill();
		registerReselectionPanel();
		registerContextEditors();
		subscribe();
		apply();
		setTimeout(apply, 400);
		setTimeout(apply, 1500);
		setTimeout(apply, 3000);
	}
})(window, document);
