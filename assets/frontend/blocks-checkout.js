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

	window.CetechDeBlocksCheckout = {
		apply: apply,
		getExtensions: getExtensions,
		pickupPackages: pickupPackages,
		itemsNeedingReselection: itemsNeedingReselection,
		submitReselection: submitReselection,
		namespace: NAMESPACE
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			registerSlotFill();
			registerReselectionPanel();
			subscribe();
			apply();
		});
	} else {
		registerSlotFill();
		registerReselectionPanel();
		subscribe();
		apply();
	}
})(window, document);
