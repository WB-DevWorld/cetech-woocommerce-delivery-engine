/**
 * Product-page fulfilment switcher (Delivery vs Store Pickup) plus matching-location refresh.
 *
 * Does not calculate prices or invent options. Toggles visible display_key radios
 * so only the active fulfilment choice is submitted. Location AJAX asks the server
 * for valid options.
 */
(function (window, document) {
	'use strict';

	function panelRadios(panel) {
		return panel ? Array.prototype.slice.call(panel.querySelectorAll('input[type="radio"][name]')) : [];
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
				if (radios.length === 1) {
					radios[0].checked = true;
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
	}

	function bindAll(scope) {
		var root = scope || document;
		var nodes = root.querySelectorAll ? root.querySelectorAll('[data-cetech-de-selector]') : [];
		Array.prototype.forEach.call(nodes, bind);
		if (root.getAttribute && root.getAttribute('data-cetech-de-selector')) {
			bind(root);
		}
	}

	function fieldValue(root, name) {
		var field = root.querySelector('[name="' + name + '"]');
		return field ? String(field.value || '') : '';
	}

	function bindLocation(root) {
		if (!root || root.getAttribute('data-cetech-de-location-bound') === '1') {
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
		function schedule() {
			window.clearTimeout(timer);
			timer = window.setTimeout(fetchOptions, 280);
		}

		locationRoot.addEventListener('change', fetchOptions);
		locationRoot.addEventListener('input', schedule);
		root.setAttribute('data-cetech-de-location-bound', '1');

		function fetchOptions() {
			var status = root.querySelector('[data-cetech-de-status]');
			var optionsEl = root.querySelector('[data-cetech-de-options]');
			if (status) {
				status.textContent = (config.i18n && config.i18n.loading) || '';
			}

			var body = new window.URLSearchParams();
			body.set('action', config.action);
			body.set('nonce', config.nonce);
			body.set('product_id', String(config.productId || root.getAttribute('data-product-id') || '0'));
			body.set('variation_id', fieldValue(root, 'cetech_de_delivery_variation_id') || '0');
			body.set('country', fieldValue(root, 'cetech_de_matching_country'));
			body.set('state', fieldValue(root, 'cetech_de_matching_state'));
			body.set('city', fieldValue(root, 'cetech_de_matching_city'));
			body.set('postcode', fieldValue(root, 'cetech_de_matching_postcode'));

			window.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (response) {
				return response.json();
			}).then(function (payload) {
				var data = payload && payload.data ? payload.data : payload;
				if (!data) {
					throw new Error('empty');
				}
				if (status) {
					status.textContent = data.message || '';
				}
				if (optionsEl) {
					optionsEl.innerHTML = renderOptionsHtml(data.options || [], data.default_key || '', config);
					root.removeAttribute('data-cetech-de-switch-bound');
					bind(root);
				}
			}).catch(function () {
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

	function renderOptionsHtml(options, defaultKey, config) {
		var delivery = [];
		var pickup = [];
		var i18n = (config && config.i18n) || {};
		options.forEach(function (option) {
			if (!option || !option.is_available) {
				return;
			}
			if (option.fulfilment_choice === 'store_pickup') {
				pickup.push(option);
			} else {
				delivery.push(option);
			}
		});

		var html = '';
		var hasSwitch = delivery.length && pickup.length;
		if (hasSwitch) {
			html += '<div class="cetech-de-fulfilment-choice" role="radiogroup">';
			html += optionSwitch('delivery', i18n.delivery || 'Delivery', true);
			html += optionSwitch('store_pickup', i18n.storePickup || 'Store pickup', false);
			html += '</div>';
		}

		if (delivery.length) {
			html += '<div class="cetech-de-delivery-option-group" data-cetech-de-choice-panel="delivery">';
			delivery.forEach(function (option) {
				html += radioOption(option, defaultKey, config);
			});
			html += '</div>';
		}

		if (pickup.length) {
			html += '<div class="cetech-de-delivery-option-group cetech-de-delivery-option-group--pickup" data-cetech-de-choice-panel="store_pickup"' + (hasSwitch ? ' hidden' : '') + '>';
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
		var estimate = option.estimate_text && option.fulfilment_choice !== 'store_pickup'
			? '<span class="cetech-de-delivery-option__estimate">' + escapeHtml((config.i18n && config.i18n.estimated ? config.i18n.estimated + ': ' : '') + option.estimate_text) + '</span>'
			: '';
		return '<p class="cetech-de-delivery-option cetech-de-delivery-option--radio"><label>' +
			'<input type="radio" name="' + escapeHtml((config && config.postField) || 'cetech_de_delivery_option_key') + '" value="' + escapeHtml(option.display_key) + '"' + (checked ? ' checked="checked"' : '') + ' required="required" />' +
			'<span class="cetech-de-delivery-option__body"><span class="cetech-de-delivery-option__label">' + escapeHtml(option.delivery_offer_public_label || option.pickup_location_label || '') + '</span>' + estimate + '</span></label></p>';
	}

	window.CetechDeProductDeliverySelector = {
		bind: bind,
		bindAll: bindAll,
		setPanelActive: setPanelActive,
	};

	function boot() {
		bindAll(document);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(window, document);
