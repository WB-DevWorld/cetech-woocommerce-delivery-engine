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

	var lastDomUiSignature = '';
	var applyTimer = null;

	function fieldId(cartItemKey, suffix) {
		var safe = String(cartItemKey == null ? '' : cartItemKey).replace(/[^a-zA-Z0-9]/g, '').slice(0, 20);
		if (!safe) {
			safe = 'x';
		}
		return 'cetech-de-b-' + safe + '-' + suffix;
	}

	function fieldInput(id, name, label, value) {
		return '<label class="cetech-de-blocks-editor__field" for="' + escapeHtml(id) + '">' + escapeHtml(label) +
			'<input type="text" id="' + escapeHtml(id) + '" name="' + escapeHtml(name) + '" value="' + escapeHtml(value || '') + '" /></label>';
	}

	function findDomUiHost() {
		return document.querySelector('.wp-block-woocommerce-checkout-order-summary-block')
			|| document.querySelector('.wp-block-woocommerce-cart-order-summary-block')
			|| document.querySelector('.wc-block-components-sidebar')
			|| document.querySelector('.wp-block-woocommerce-filled-cart-block')
			|| document.querySelector('.wp-block-woocommerce-checkout')
			|| document.querySelector('.wc-block-cart')
			|| document.querySelector('.wc-block-checkout')
			|| document.querySelector('.wp-block-woocommerce-checkout-fields-block');
	}

	function uiSignature(cart) {
		var extensions = getExtensions(cart);
		var items = editableItems(cart);
		return JSON.stringify({
			items: items.map(function (item) {
				var ext = item.ext || {};
				return [
					item.key,
					item.quantity,
					ext.locality,
					ext.can_split,
					ext.address_complete,
					ext.needs_reselection,
					ext.is_pickup,
					ext.pickup_location_label,
					(ext.available_options || []).map(function (option) {
						return String(option.display_key || '') + (option.selected ? '*' : '');
					})
				];
			}),
			notices: extensions.notices || [],
			apply: !!extensions.can_apply_checkout_address,
			skipped: ((extensions.mutation_result || {}).skipped) || [],
			failed: ((extensions.mutation_result || {}).failed) || []
		});
	}

	function bindDomUiOnce(mount) {
		if (mount.getAttribute('data-cetech-de-bound') === '1') {
			return;
		}
		mount.setAttribute('data-cetech-de-bound', '1');
		mount.addEventListener('click', function (event) {
			var target = event.target;
			if (!target || !target.closest) {
				return;
			}
			if (target.closest('.cetech-de-blocks-editor__update')) {
				var editor = target.closest('.cetech-de-blocks-editor');
				if (editor) {
					submitCommand(readEditor(editor));
				}
				return;
			}
			if (target.closest('.cetech-de-blocks-editor__use-for-all')) {
				var allEditor = target.closest('.cetech-de-blocks-editor');
				if (allEditor) {
					var data = readEditor(allEditor);
					data.action = 'use_for_all';
					submitCommand(data);
				}
				return;
			}
			if (target.closest('.cetech-de-blocks-apply-checkout-address')) {
				submitCommand({ action: 'apply_checkout_address' });
			}
		});
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
		var host = findDomUiHost();
		if (!host) {
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
			lastDomUiSignature = '';
			return;
		}
		var signature = uiSignature(cart);
		var mount = document.getElementById('cetech-de-blocks-dom-ui');
		if (!mount) {
			mount = document.createElement('div');
			mount.id = 'cetech-de-blocks-dom-ui';
			mount.className = 'cetech-de-blocks-customer-context';
			host.insertBefore(mount, host.firstChild);
		}
		bindDomUiOnce(mount);
		if (signature === lastDomUiSignature && mount.childNodes.length) {
			return;
		}
		if (mount.contains(document.activeElement) && document.activeElement && /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName)) {
			return;
		}
		var openKeys = [];
		mount.querySelectorAll('details[open]').forEach(function (node) {
			openKeys.push(node.getAttribute('data-cart-item-key') || '');
		});
		var html = '<div class="cetech-de-blocks-notices" aria-live="polite">';
		notices.forEach(function (notice) {
			html += '<p class="cetech-de-blocks-notice cetech-de-blocks-notice--' + escapeHtml(notice.code || 'info') + '" role="' + (notice.code === 'incomplete_delivery' ? 'alert' : 'status') + '">' + escapeHtml(notice.message || '') + '</p>';
		});
		skipped.concat(failed).forEach(function (row) {
			html += '<p class="cetech-de-blocks-notice cetech-de-blocks-notice--failure" role="alert">' + escapeHtml((row.name ? row.name + ' — ' : '') + (row.reason || '')) + '</p>';
		});
		html += '</div>';
		var onCheckout = !!(document.body && (
			document.body.classList.contains('woocommerce-checkout') ||
			document.querySelector('.wc-block-checkout')
		));
		if (onCheckout && extensions.delivery_plan && extensions.delivery_plan.length) {
			html += '<section class="cetech-de-delivery-plan"><h2 class="cetech-de-delivery-plan__title">' +
				escapeHtml(i18n.yourDeliveries || 'Your deliveries') + '</h2>';
			extensions.delivery_plan.forEach(function (group) {
				html += '<div class="cetech-de-delivery-plan__group"><h3 class="cetech-de-delivery-plan__heading">' +
					escapeHtml(group.heading || '') + '</h3>';
				(group.lines || []).forEach(function (line) {
					html += '<p class="cetech-de-delivery-plan__line">';
					if (line.product) {
						html += '<span class="cetech-de-delivery-plan__product">' + escapeHtml(line.product) + '</span> ';
					}
					html += '<span class="cetech-de-delivery-plan__offer">' + escapeHtml(line.estimate || line.offer || '') + '</span></p>';
				});
				html += '</div>';
			});
			html += '</section>';
		}
		if (extensions.keep_address_note) {
			html += '<p class="cetech-de-checkout-keep-address">' + escapeHtml(extensions.keep_address_note) + '</p>';
		}
		if (extensions.can_apply_checkout_address) {
			html += '<p class="cetech-de-blocks-incomplete-actions">';
			if (i18n.cartUrl) {
				html += '<a class="cetech-de-blocks-add-delivery-address" href="' + escapeHtml(i18n.cartUrl) + '">' +
					escapeHtml(i18n.addDeliveryAddress || 'Add delivery address') + '</a> ';
			}
			html += '<button type="button" class="cetech-de-blocks-apply-checkout-address cetech-de-blocks-apply-checkout-address--secondary">' +
				escapeHtml(i18n.applyCheckoutAddress || 'Use my checkout address') + '</button></p>';
		}
		if (!onCheckout) {
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
			var optionId = fieldId(item.key, 'option');
			var options = (ext.available_options || []).map(function (option) {
				return '<option value="' + escapeHtml(option.display_key) + '"' + (option.display_key === selected ? ' selected' : '') + '>' +
					escapeHtml(option.estimate_text ? option.label + ' — ' + option.estimate_text : option.label) + '</option>';
			}).join('');
			var isPickup = !!ext.is_pickup;
			html += '<div class="cetech-de-blocks-line">';
			html += '<div class="cetech-de-blocks-line__summary">';
			if (ext.summary_kicker) {
				html += '<p class="cetech-de-blocks-line__kicker">' + escapeHtml(ext.summary_kicker) + '</p>';
			}
			if (ext.summary_title) {
				html += '<p class="cetech-de-blocks-line__title">' + escapeHtml(ext.summary_title) + '</p>';
			}
			if (ext.summary_meta) {
				html += '<p class="cetech-de-blocks-line__meta">' + escapeHtml(ext.summary_meta) + '</p>';
			}
			html += '</div>';
			html += '<details class="cetech-de-blocks-editor" data-cart-item-key="' + escapeHtml(item.key) + '">';
			html += '<summary>' + escapeHtml(i18n.change || i18n.changeDelivery || 'Change') + '</summary>';
			html += '<div class="cetech-de-blocks-editor__body">';
			html += '<fieldset class="cetech-de-blocks-editor__options"><legend>' +
				escapeHtml(i18n.optionLegend || 'Delivery') + '</legend>';
			html += '<label class="cetech-de-blocks-editor__field" for="' + escapeHtml(optionId) + '">' +
				escapeHtml(i18n.choose || 'Choose a delivery option') +
				'<select id="' + escapeHtml(optionId) + '" name="cetech_de_delivery_option_key">' + options + '</select></label></fieldset>';
			if (!isPickup) {
				html += fieldInput(fieldId(item.key, 'country'), 'cetech_de_matching_country', i18n.country || 'Country', matching.country);
				html += fieldInput(fieldId(item.key, 'state'), 'cetech_de_matching_state', i18n.state || 'State / Region', matching.state);
				html += fieldInput(fieldId(item.key, 'city'), 'cetech_de_matching_city', i18n.city || 'City', matching.city);
				html += fieldInput(fieldId(item.key, 'postcode'), 'cetech_de_matching_postcode', i18n.postcode || 'Postcode', matching.postcode);
				html += fieldInput(fieldId(item.key, 'address-1'), 'cetech_de_address_1', i18n.address1 || 'Address line 1', address.address_1);
				html += fieldInput(fieldId(item.key, 'address-2'), 'cetech_de_address_2', i18n.address2 || 'Address line 2', address.address_2);
				html += fieldInput(fieldId(item.key, 'first-name'), 'cetech_de_first_name', i18n.firstName || 'First name', address.first_name);
				html += fieldInput(fieldId(item.key, 'last-name'), 'cetech_de_last_name', i18n.lastName || 'Last name', address.last_name);
				html += fieldInput(fieldId(item.key, 'phone'), 'cetech_de_phone', i18n.phone || 'Phone', address.phone);
			}
			if (ext.can_split) {
				var splitId = fieldId(item.key, 'split');
				html += '<label class="cetech-de-blocks-editor__field" for="' + escapeHtml(splitId) + '"><input type="checkbox" id="' + escapeHtml(splitId) + '" name="cetech_de_apply_mode" /> ' +
					escapeHtml(i18n.split || 'Move some quantity') + '</label>';
				html += fieldInput(fieldId(item.key, 'split-qty'), 'cetech_de_split_qty', i18n.splitQty || 'Quantity to move', '1');
			}
			html += '<button type="button" class="wc-block-components-button cetech-de-blocks-editor__update">' +
				escapeHtml(i18n.updateDetails || 'Update') + '</button>';
			if (!isPickup) {
				html += '<button type="button" class="wc-block-components-button cetech-de-blocks-editor__use-for-all">' +
					escapeHtml(i18n.useForAll || 'Use this address for all delivery items') + '</button>';
			}
			html += '</div></details></div>';
		});
		}
		mount.innerHTML = html;
		openKeys.forEach(function (key) {
			if (!key) {
				return;
			}
			var details = mount.querySelector('details[data-cart-item-key="' + String(key).replace(/"/g, '') + '"]');
			if (details) {
				details.open = true;
			}
		});
		lastDomUiSignature = signature;
	}

	function scheduleApply() {
		if (applyTimer) {
			return;
		}
		applyTimer = window.setTimeout(function () {
			applyTimer = null;
			apply();
		}, 120);
	}

	function subscribe() {
		if (window.wp && window.wp.data && typeof window.wp.data.subscribe === 'function') {
			window.wp.data.subscribe(function () {
				scheduleApply();
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
			return null;
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
		// Store API DOM editor (#cetech-de-blocks-dom-ui) is the qualified mount.
		// PluginArea slot-fills are not required and must not duplicate Change delivery controls.
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
		uiSignature: uiSignature,
		fieldId: fieldId,
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
		});
	} else {
		registerSlotFill();
		registerReselectionPanel();
		registerContextEditors();
		subscribe();
		apply();
		setTimeout(apply, 400);
	}
})(window, document);
