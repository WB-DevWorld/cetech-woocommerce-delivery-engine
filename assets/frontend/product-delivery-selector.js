/**
 * Product-page fulfilment switcher (Delivery vs Store Pickup) plus matching-location refresh.
 *
 * Does not calculate prices or invent options. Toggles visible display_key radios
 * so only the active fulfilment choice is submitted. Location AJAX asks the server
 * for valid options. One hidden JSON payload is the authoritative customer context.
 */
(function (window, document) {
	'use strict';

	function panelRadios(panel) {
		return panel ? Array.prototype.slice.call(panel.querySelectorAll('input[type="radio"][name]')) : [];
	}

	function closestSelector(node) {
		return node && node.closest ? node.closest('[data-cetech-de-selector]') : null;
	}

	function fieldValue(scope, name) {
		var field = scope ? scope.querySelector('[name="' + name + '"]') : null;
		return field ? String(field.value || '') : '';
	}

	function formatEstimateLine(option, config) {
		if (!option || option.fulfilment_choice === 'store_pickup') {
			return '';
		}
		if (option.estimate_line) {
			return String(option.estimate_line).replace(/^Estimated(?:\s+delivery)?\s*:?\s+/i, '').trim();
		}
		return String(option.estimate_text || '').replace(/^Estimated(?:\s+delivery)?\s*:?\s+/i, '').trim();
	}

	function formatPriceText(option, config) {
		if (!option) {
			return '';
		}
		if (option.price_text) {
			return String(option.price_text);
		}
		if (option.fulfilment_choice === 'store_pickup') {
			return (config && config.i18n && config.i18n.free) || 'Free';
		}
		return '';
	}

	function productQuantity(root) {
		var form = root && root.closest ? root.closest('form.cart, .variations_form, form.variations_form') : null;
		var field = form ? form.querySelector('input.qty, input[name="quantity"]') : null;
		if (!field && document.querySelector) {
			field = document.querySelector('form.cart input.qty, form.cart input[name="quantity"]');
		}
		var n = parseInt(field && field.value ? field.value : '1', 10);
		return n > 0 ? n : 1;
	}

	function bindQuantityRefresh(root, schedule) {
		var form = root && root.closest ? root.closest('form.cart, .variations_form') : null;
		if (!form || form.getAttribute('data-cetech-de-qty-bound') === '1') {
			return;
		}
		form.setAttribute('data-cetech-de-qty-bound', '1');
		form.addEventListener('change', function (event) {
			var target = event.target;
			if (!target || !target.name) {
				return;
			}
			if (target.name === 'quantity' || (target.classList && target.classList.contains('qty'))) {
				schedule();
			}
		});
		form.addEventListener('input', function (event) {
			var target = event.target;
			if (!target || !target.name) {
				return;
			}
			if (target.name === 'quantity' || (target.classList && target.classList.contains('qty'))) {
				schedule();
			}
		});
	}

	function currentPayload(root) {
		var loc = root.querySelector('[data-cetech-de-matching-location]') || root;
		var checked = root.querySelector('input[name="cetech_de_delivery_option_key"]:checked:not([disabled])');
		var choiceSwitch = root.querySelector('[data-cetech-de-choice-switch]:checked');
		return {
			matching_location: {
				country: fieldValue(loc, 'cetech_de_matching_country'),
				state: fieldValue(loc, 'cetech_de_matching_state'),
				city: fieldValue(loc, 'cetech_de_matching_city'),
				postcode: fieldValue(loc, 'cetech_de_matching_postcode')
			},
			display_key: checked ? String(checked.value || '') : '',
			fulfilment_choice: choiceSwitch
				? String(choiceSwitch.value || '')
				: (checked && checked.closest('[data-cetech-de-choice-panel]')
					? String(checked.closest('[data-cetech-de-choice-panel]').getAttribute('data-cetech-de-choice-panel') || '')
					: '')
		};
	}

	function ensurePayloadInput(root) {
		var input = root.querySelector('[data-cetech-de-pdp-context]');
		if (input) {
			return input;
		}
		var config = window.cetechDeMatchingLocation || {};
		input = document.createElement('input');
		input.type = 'hidden';
		input.name = config.contextField || 'cetech_de_pdp_context';
		input.setAttribute('data-cetech-de-pdp-context', '1');
		input.autocomplete = 'off';
		root.appendChild(input);
		return input;
	}

	function writePayload(root) {
		if (!root) {
			return;
		}
		ensurePayloadInput(root).value = JSON.stringify(currentPayload(root));
	}

	function prepareSubmit(root) {
		var checked = root.querySelector('input[name="cetech_de_delivery_option_key"]:checked');
		if (checked) {
			checked.disabled = false;
		}
		writePayload(root);
	}

	function setPanelActive(root, choice) {
		var panels = root.querySelectorAll('[data-cetech-de-choice-panel]');
		Array.prototype.forEach.call(panels, function (panel) {
			var active = panel.getAttribute('data-cetech-de-choice-panel') === choice;
			var radios = panelRadios(panel).filter(function (input) {
				return input.getAttribute('data-cetech-de-choice-switch') !== '1';
			});

			if (active) {
				panel.hidden = false;
				radios.forEach(function (input) {
					input.disabled = false;
					input.required = true;
				});
				if (!radios.some(function (input) { return input.checked; })) {
					if (radios.length === 1) {
						radios[0].checked = true;
					}
				}
			} else {
				panel.hidden = true;
				radios.forEach(function (input) {
					input.checked = false;
					input.disabled = true;
					input.required = false;
				});
			}
		});
		writePayload(root);
		toggleLocationPanel(root, choice);
		dismissStaleSelectionNotices(root);
	}

	function toggleLocationPanel(root, choice) {
		root.setAttribute('data-cetech-de-active-choice', choice || 'delivery');
		var locationPanel = root.querySelector('[data-cetech-de-location-panel]');
		if (locationPanel) {
			locationPanel.hidden = choice === 'store_pickup';
		}
	}

	function dismissStaleSelectionNotices(root) {
		var checked = root.querySelector('input[name="cetech_de_delivery_option_key"]:checked:not([disabled])');
		if (!checked) {
			return;
		}
		var notices = document.querySelectorAll('.woocommerce-error, .woocommerce-NoticeGroup .wc-block-components-notice-banner.is-error');
		Array.prototype.forEach.call(notices, function (notice) {
			var text = (notice.textContent || '').toLowerCase();
			if (
				text.indexOf('please select a delivery option') !== -1 ||
				text.indexOf('please enter a delivery location') !== -1
			) {
				notice.hidden = true;
			}
		});
	}

	function bind(root) {
		if (!root) {
			return;
		}

		var switches = root.querySelectorAll('[data-cetech-de-choice-switch]');
		if (switches.length && root.getAttribute('data-cetech-de-switch-bound') !== '1') {
			Array.prototype.forEach.call(switches, function (input) {
				input.addEventListener('change', function () {
					if (!input.checked) {
						return;
					}
					setPanelActive(root, input.value);
				});
			});

			var current = root.querySelector('[data-cetech-de-choice-switch]:checked');
			setPanelActive(root, current ? current.value : 'delivery');
			root.setAttribute('data-cetech-de-switch-bound', '1');
		}

		bindLocation(root);
		bindFormSubmit(root);
		writePayload(root);
		dismissStaleSelectionNotices(root);
	}

	function bindAll(scope) {
		var root = scope || document;
		var nodes = root.querySelectorAll ? root.querySelectorAll('[data-cetech-de-selector]') : [];
		Array.prototype.forEach.call(nodes, bind);
		if (root.getAttribute && root.getAttribute('data-cetech-de-selector')) {
			bind(root);
		}
	}

	function bindFormSubmit(root) {
		var form = root.closest ? root.closest('form.cart, form.variations_form, form') : null;
		if (!form || form.getAttribute('data-cetech-de-pdp-submit') === '1') {
			return;
		}
		form.addEventListener('submit', function () {
			var selector = closestSelector(root) || root;
			prepareSubmit(selector);
		});
		form.setAttribute('data-cetech-de-pdp-submit', '1');
	}

	function bindLocation(root) {
		if (!root || root.getAttribute('data-cetech-de-location-bound') === '1') {
			return;
		}

		if (root.getAttribute('data-cetech-de-variable-selector')) {
			root.setAttribute('data-cetech-de-location-bound', '1');
			return;
		}

		var locationRoot = root.querySelector('[data-cetech-de-matching-location]');
		var config = window.cetechDeMatchingLocation;
		if (!locationRoot || !config || !config.ajaxUrl) {
			if (locationRoot) {
				root.setAttribute('data-cetech-de-location-bound', '1');
			}
			return;
		}

		var timer = null;
		var requestToken = 0;
		root.setAttribute('data-cetech-de-options-loading', '0');

		function schedule() {
			window.clearTimeout(timer);
			timer = window.setTimeout(fetchOptions, 280);
		}

		locationRoot.addEventListener('change', fetchOptions);
		locationRoot.addEventListener('input', schedule);
		bindQuantityRefresh(root, schedule);
		root.setAttribute('data-cetech-de-location-bound', '1');

		function fetchOptions() {
			var status = root.querySelector('[data-cetech-de-status]');
			var optionsEl = root.querySelector('[data-cetech-de-options]');
			var token = ++requestToken;
			root.setAttribute('data-cetech-de-options-loading', '1');
			if (status) {
				status.textContent = (config.i18n && config.i18n.loading) || '';
			}

			var body = new window.URLSearchParams();
			body.set('action', config.action);
			body.set('nonce', config.nonce);
			body.set('product_id', String(config.productId || root.getAttribute('data-product-id') || '0'));
			body.set('variation_id', fieldValue(root, 'cetech_de_delivery_variation_id') || '0');
			body.set('country', fieldValue(locationRoot, 'cetech_de_matching_country'));
			body.set('state', fieldValue(locationRoot, 'cetech_de_matching_state'));
			body.set('city', fieldValue(locationRoot, 'cetech_de_matching_city'));
			body.set('postcode', fieldValue(locationRoot, 'cetech_de_matching_postcode'));
			body.set('quantity', String(productQuantity(root)));

			window.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (response) {
				return response.json();
			}).then(function (payload) {
				if (token !== requestToken) {
					return;
				}
				root.setAttribute('data-cetech-de-options-loading', '0');
				var data = payload && payload.data ? payload.data : payload;
				if (!data) {
					throw new Error('empty');
				}
				if (status) {
					if (data.status === 'need_location') {
						status.textContent = '';
					} else {
						status.textContent = data.message || '';
					}
				}
				if (optionsEl) {
					var previousChoice = '';
					var choiceSwitch = root.querySelector('[data-cetech-de-choice-switch]:checked');
					if (choiceSwitch) {
						previousChoice = String(choiceSwitch.value || '');
					}
					optionsEl.innerHTML = renderOptionsHtml(data.options || [], data.default_key || '', config, data.locality || '', previousChoice, data);
					root.removeAttribute('data-cetech-de-switch-bound');
					bind(root);
					var radios = optionsEl.querySelectorAll('input[name="cetech_de_delivery_option_key"]:not([disabled])');
					if (radios.length && !Array.prototype.some.call(radios, function (input) { return input.checked; })) {
						radios[0].checked = true;
					}
					writePayload(root);
				}
			}).catch(function () {
				if (token !== requestToken) {
					return;
				}
				root.setAttribute('data-cetech-de-options-loading', '0');
				if (status) {
					status.textContent = (config.i18n && config.i18n.error) || '';
				}
			});
		}
	}

	function escapeHtml(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function fulfilmentCapabilities(root, data) {
		var hasDelivery = data && typeof data.has_delivery === 'boolean'
			? !!data.has_delivery
			: (root && root.getAttribute('data-cetech-de-has-delivery') === '1');
		var hasPickup = data && typeof data.has_pickup === 'boolean'
			? !!data.has_pickup
			: (root && root.getAttribute('data-cetech-de-has-pickup') === '1');
		if (root && data) {
			root.setAttribute('data-cetech-de-has-delivery', hasDelivery ? '1' : '0');
			root.setAttribute('data-cetech-de-has-pickup', hasPickup ? '1' : '0');
		}
		return { hasDelivery: !!hasDelivery, hasPickup: !!hasPickup };
	}

	function renderOptionsHtml(options, defaultKey, config, locality, preferredChoice, payload) {
		var delivery = [];
		var pickup = [];
		var i18n = (config && config.i18n) || {};
		var root = document.querySelector('[data-cetech-de-selector]');
		options.forEach(function (option) {
			if (!option || !option.is_available) {
				return;
			}
			if (option.fulfilment_choice === 'store_pickup') {
				pickup.push(option);
			} else if (option.estimate_text || option.estimate_line) {
				delivery.push(option);
			}
		});

		var caps = fulfilmentCapabilities(root, payload || {});
		var html = '';
		var hasSwitch = (caps.hasDelivery && caps.hasPickup) || (delivery.length && pickup.length);
		var pickupActive = preferredChoice === 'store_pickup' && (pickup.length > 0 || caps.hasPickup);
		if (hasSwitch) {
			html += '<div class="cetech-de-fulfilment-choice" role="radiogroup">';
			html += optionSwitch('delivery', i18n.delivery || 'Delivery', !pickupActive);
			html += optionSwitch('store_pickup', i18n.storePickup || 'Store Pickup', pickupActive);
			html += '</div>';
		}

		if (caps.hasDelivery) {
			html += '<div class="cetech-de-delivery-option-group" data-cetech-de-choice-panel="delivery"' + (pickupActive ? ' hidden' : '') + '>';
			html += '<p class="cetech-de-delivery-option-group__heading">' + escapeHtml(locality ? ('Delivery to ' + locality) : (i18n.delivery || 'Delivery')) + '</p>';
			delivery.forEach(function (option) {
				html += radioOption(option, defaultKey, config);
			});
			html += '</div>';
		}

		if (caps.hasPickup || pickup.length) {
			html += '<div class="cetech-de-delivery-option-group cetech-de-delivery-option-group--pickup" data-cetech-de-choice-panel="store_pickup"' + (hasSwitch && !pickupActive ? ' hidden' : '') + '>';
			html += '<p class="cetech-de-delivery-option-group__heading">' + escapeHtml(i18n.storePickup || 'Store Pickup') + '</p>';
			pickup.forEach(function (option) {
				html += radioOption(option, defaultKey, config);
			});
			html += '</div>';
		}

		return html;
	}

	function optionSwitch(value, label, checked) {
		return '<p class="cetech-de-fulfilment-choice__option"><label>' +
			'<input type="radio" name="cetech_de_fulfilment_ui" value="' + escapeHtml(value) + '" data-cetech-de-choice-switch="1"' + (checked ? ' checked="checked"' : '') + ' /> ' +
			escapeHtml(label) + '</label></p>';
	}

	function radioOption(option, defaultKey, config) {
		var checked = option.display_key === defaultKey || option.is_default;
		var isPickup = option.fulfilment_choice === 'store_pickup';
		var title = option.delivery_offer_public_label || option.pickup_location_label || '';
		var price = formatPriceText(option, config);
		var headline = '<span class="cetech-de-delivery-option__headline"><span class="cetech-de-delivery-option__label">' + escapeHtml(title) + '</span>';
		if (price) {
			headline += '<span class="cetech-de-delivery-option__price">' + escapeHtml(price) + '</span>';
		}
		headline += '</span>';
		var meta = '';
		if (isPickup) {
			if (option.pickup_location_label && option.pickup_location_label !== title) {
				meta += '<span class="cetech-de-delivery-option__meta">' + escapeHtml(option.pickup_location_label) + '</span>';
			}
			if (option.pickup_address) {
				meta += '<span class="cetech-de-delivery-option__meta">' + escapeHtml(option.pickup_address) + '</span>';
			}
			var ready = String(option.estimate_text || '').replace(/^Estimated(?:\s+delivery)?\s*:?\s+/i, '').trim();
			if (ready) {
				meta += '<span class="cetech-de-delivery-option__estimate">' + escapeHtml(ready) + '</span>';
			}
		} else {
			var estimateLine = formatEstimateLine(option, config);
			if (estimateLine) {
				meta += '<span class="cetech-de-delivery-option__estimate">' + escapeHtml(estimateLine) + '</span>';
			}
		}
		return '<p class="cetech-de-delivery-option cetech-de-delivery-option--radio cetech-de-delivery-option--card" data-cetech-de-choice="' + escapeHtml(option.fulfilment_choice || '') + '"><label>' +
			'<input type="radio" name="' + escapeHtml((config && config.postField) || 'cetech_de_delivery_option_key') + '" value="' + escapeHtml(option.display_key) + '"' + (checked ? ' checked="checked"' : '') + ' required="required" />' +
			'<span class="cetech-de-delivery-option__body">' + headline + meta + '</span></label></p>';
	}

	window.CetechDeProductDeliverySelector = {
		bind: bind,
		bindAll: bindAll,
		setPanelActive: setPanelActive,
		writePayload: writePayload,
		currentPayload: currentPayload,
		formatEstimateLine: formatEstimateLine,
		formatPriceText: formatPriceText,
		productQuantity: productQuantity,
		dismissStaleSelectionNotices: dismissStaleSelectionNotices,
		storeApiExtensions: storeApiExtensions,
		renderOptionsHtml: renderOptionsHtml,
		fulfilmentCapabilities: fulfilmentCapabilities
	};

	function storeApiExtensions() {
		var root = document.querySelector('[data-cetech-de-selector]');
		if (root && typeof writePayload === 'function') {
			writePayload(root);
		}
		var payload = root ? currentPayload(root) : null;
		if (!payload) {
			return {};
		}
		return {
			delivery_option_key: payload.display_key || '',
			matching_location: payload.matching_location || {},
			pdp_context: payload
		};
	}

	function attachStoreApiAddItem() {
		var namespace = (window.cetechDeMatchingLocation && window.cetechDeMatchingLocation.storeNamespace) || 'cetech-delivery-engine';
		function merge(data) {
			data = data || {};
			data.extensions = data.extensions || {};
			data.extensions[namespace] = Object.assign({}, data.extensions[namespace] || {}, storeApiExtensions());
			return data;
		}
		if (window.wp && window.wp.apiFetch && typeof window.wp.apiFetch.use === 'function') {
			window.wp.apiFetch.use(function (options, next) {
				var path = String(options.path || options.url || '');
				if (options.data && /\/wc\/store(?:\/v1)?\/cart\/add-item/.test(path)) {
					options.data = merge(options.data);
				}
				return next(options);
			});
		}
		if (typeof window.fetch === 'function') {
			var original = window.fetch;
			window.fetch = function (input, init) {
				var url = typeof input === 'string' ? input : (input && input.url) || '';
				if (/\/wc\/store(?:\/v1)?\/cart\/add-item/.test(String(url))) {
					init = init || {};
					if (init.body && typeof init.body === 'string') {
						try {
							var parsed = JSON.parse(init.body);
							init = Object.assign({}, init, { body: JSON.stringify(merge(parsed)) });
						} catch (e) {
							/* keep original body */
						}
					}
				}
				return original.call(this, input, init);
			};
		}
	}

	function boot() {
		bindAll(document);
		attachStoreApiAddItem();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(window, document);
