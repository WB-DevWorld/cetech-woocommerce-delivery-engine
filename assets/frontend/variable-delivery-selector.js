/**
 * Stage 6A — Variable product delivery selector (standard WooCommerce variation events).
 *
 * Theme-independent: listens to found_variation / reset_data on .variations_form.
 * Does not resolve delivery rules client-side. Server remains authoritative.
 * Compact product selector: public label + estimated delivery; no option descriptions.
 */
(function (window, document, $) {
	'use strict';

	var config = window.cetechDeVariableDelivery || {};
	var Controller = {
		requestToken: 0,
		currentVariationId: 0,
		xhr: null,
		root: null,
		statusEl: null,
		optionsEl: null,
		variationInput: null,
		cache: Object.create(null),

		init: function () {
			this.root = document.querySelector('[data-cetech-de-variable-selector]');
			if (!this.root) {
				return;
			}

			this.statusEl = this.root.querySelector('[data-cetech-de-status]');
			this.optionsEl = this.root.querySelector('[data-cetech-de-options]');
			this.variationInput = this.root.querySelector('[data-cetech-de-variation-id]');

			this.bindWooCommerceEvents();
			this.bindMatchingLocation();
			this.bindQuantity();
			this.showSelectOptions();
		},

		bindWooCommerceEvents: function () {
			if (!$ || typeof $.fn === 'undefined') {
				return;
			}

			var self = this;
			var $form = $(this.root).closest('form.cart, .variations_form').filter('.variations_form');
			if (!$form.length) {
				$form = $('form.variations_form').first();
			}

			$form.on('found_variation.cetechDe', function (_event, variation) {
				self.onFoundVariation(variation);
			});

			$form.on('reset_data.cetechDe hide_variation.cetechDe', function () {
				self.onReset();
			});
		},

		onFoundVariation: function (variation) {
			var variationId = this.extractVariationId(variation);
			if (!variationId) {
				this.onReset();
				return;
			}

			this.invalidateSelection();
			this.currentVariationId = variationId;
			this.fetchOptions(variationId);
		},

		onReset: function () {
			this.abortRequest();
			this.requestToken += 1;
			this.currentVariationId = 0;
			this.invalidateSelection();
			this.showSelectOptions();
		},

		bindMatchingLocation: function () {
			if (!this.root) {
				return;
			}

			var self = this;
			var locationRoot = this.root.querySelector('[data-cetech-de-matching-location]');
			if (!locationRoot || locationRoot.getAttribute('data-cetech-de-variable-location-bound') === '1') {
				return;
			}
			locationRoot.setAttribute('data-cetech-de-variable-location-bound', '1');
			var timer = null;
			function schedule() {
				window.clearTimeout(timer);
				timer = window.setTimeout(function () {
					if (self.currentVariationId) {
						self.fetchOptions(self.currentVariationId);
					}
				}, 280);
			}
			locationRoot.addEventListener('change', function () {
				self.cache = Object.create(null);
				self.invalidateSelection();
				if (self.currentVariationId) {
					self.fetchOptions(self.currentVariationId);
				}
			});
			locationRoot.addEventListener('input', schedule);
		},

		bindQuantity: function () {
			if (!this.root) {
				return;
			}
			var self = this;
			var form = this.root.closest ? this.root.closest('form.cart, .variations_form') : null;
			if (!form || form.getAttribute('data-cetech-de-variable-qty-bound') === '1') {
				return;
			}
			form.setAttribute('data-cetech-de-variable-qty-bound', '1');
			function maybeRefresh(event) {
				var target = event.target;
				if (!target || (target.name !== 'quantity' && !(target.classList && target.classList.contains('qty')))) {
					return;
				}
				if (self.currentVariationId) {
					self.fetchOptions(self.currentVariationId);
				}
			}
			form.addEventListener('change', maybeRefresh);
			form.addEventListener('input', maybeRefresh);
		},

		readMatchingLocation: function () {
			var loc = this.root ? this.root.querySelector('[data-cetech-de-matching-location]') : null;
			function value(name) {
				var field = loc ? loc.querySelector('[name="' + name + '"]') : null;
				return field ? String(field.value || '') : '';
			}
			return {
				country: value('cetech_de_matching_country'),
				state: value('cetech_de_matching_state'),
				city: value('cetech_de_matching_city'),
				postcode: value('cetech_de_matching_postcode'),
				location_key: value('cetech_de_matching_location_key')
			};
		},

		extractVariationId: function (variation) {
			if (!variation || typeof variation !== 'object') {
				return 0;
			}
			var id = parseInt(variation.variation_id, 10);
			return id > 0 ? id : 0;
		},

		fetchOptions: function (variationId) {
			this.abortRequest();
			var token = ++this.requestToken;
			var productId = parseInt(config.productId || (this.root && this.root.getAttribute('data-product-id')) || 0, 10);
			var location = this.readMatchingLocation();
			var quantity = (window.CetechDeProductDeliverySelector && window.CetechDeProductDeliverySelector.productQuantity)
				? window.CetechDeProductDeliverySelector.productQuantity(this.root)
				: 1;

			this.showLoading();

			var cacheKey = productId + ':' + variationId + ':' + location.country + ':' + location.state + ':' + location.city + ':' + location.postcode + ':' + (location.location_key || '') + ':' + quantity;
			if (this.cache[cacheKey]) {
				if (token === this.requestToken && variationId === this.currentVariationId) {
					this.renderResponse(this.cache[cacheKey], variationId);
				}
				return;
			}

			if (!$ || !$.ajax) {
				this.showError();
				return;
			}

			var self = this;
			this.xhr = $.ajax({
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: config.action,
					nonce: config.nonce,
					product_id: productId,
					variation_id: variationId,
					country: location.country,
					state: location.state,
					city: location.city,
					postcode: location.postcode,
					location_key: location.location_key,
					quantity: quantity
				}
			});

			this.xhr
				.done(function (response) {
					if (token !== self.requestToken || variationId !== self.currentVariationId) {
						return;
					}

					var payload = self.normalizePayload(response);
					if (payload && payload.status === 'ok') {
						self.cache[cacheKey] = payload;
					}
					self.renderResponse(payload, variationId);
				})
				.fail(function (_xhr, textStatus) {
					if (token !== self.requestToken || variationId !== self.currentVariationId) {
						return;
					}
					if (textStatus === 'abort') {
						return;
					}
					self.showError();
				})
				.always(function () {
					if (token === self.requestToken) {
						self.xhr = null;
					}
				});
		},

		normalizePayload: function (response) {
			if (!response || typeof response !== 'object') {
				return null;
			}
			if (response.success && response.data) {
				return response.data;
			}
			if (response.data && response.data.message) {
				return {
					status: 'error',
					message: response.data.message,
					options: []
				};
			}
			return {
				status: 'error',
				message: (config.i18n && config.i18n.error) || '',
				options: []
			};
		},

		renderResponse: function (payload, variationId) {
			if (!payload) {
				this.showError();
				return;
			}

			if (payload.status === 'need_location' || payload.status === 'need_precision') {
				this.setStatus(payload.message || ((config.i18n && (payload.status === 'need_precision' ? config.i18n.needPrecision : config.i18n.selectOptions)) || ''), payload.status === 'need_precision' ? 'need-precision' : 'need-location');
				this.renderOptions(Array.isArray(payload.options) ? payload.options : [], variationId, payload);
				this.setVariationBinding(variationId);
				return;
			}

			if (payload.status !== 'ok') {
				if (payload.status === 'unavailable') {
					this.showUnavailable(payload.message);
				} else {
					this.showError(payload.message);
				}
				return;
			}

			var options = Array.isArray(payload.options) ? payload.options : [];
			var available = options.filter(function (option) {
				return option && option.is_available && option.delivery_offer_public_label;
			});

			if (!available.length) {
				this.showUnavailable(payload.message);
				return;
			}

			this.setStatus('', '');
			this.renderOptions(available, variationId, payload);
			this.setVariationBinding(variationId);
		},

		renderOptions: function (options, variationId, payload) {
			if (!this.optionsEl) {
				return;
			}

			this.optionsEl.innerHTML = '';
			if (this.root) {
				this.root.removeAttribute('data-cetech-de-switch-bound');
			}
			var fieldName = config.postField || 'cetech_de_delivery_option_key';
			var fragment = document.createDocumentFragment();
			var estimatePrefix = (config.i18n && config.i18n.estimatedDelivery) || 'Estimated delivery';
			var i18n = config.i18n || {};
			var groups = { delivery: [], store_pickup: [] };

			options.forEach(function (option) {
				if (!option || !option.display_key) {
					return;
				}
				if (String(option.fulfilment_choice || '') === 'store_pickup') {
					groups.store_pickup.push(option);
				} else if (option.estimate_text || option.estimate_line) {
					groups.delivery.push(option);
				}
			});

			var caps = window.CetechDeProductDeliverySelector && window.CetechDeProductDeliverySelector.fulfilmentCapabilities
				? window.CetechDeProductDeliverySelector.fulfilmentCapabilities(this.root, payload || {})
				: { hasDelivery: groups.delivery.length > 0, hasPickup: groups.store_pickup.length > 0 };
			var hasSwitch = (caps.hasDelivery && caps.hasPickup) || (groups.delivery.length > 0 && groups.store_pickup.length > 0);
			var defaultKey = '';
			options.forEach(function (option) {
				if (option && option.is_default && option.display_key) {
					defaultKey = String(option.display_key);
				}
			});
			if (!defaultKey && options.length === 1) {
				defaultKey = String(options[0].display_key || '');
			}
			var activeChoice = 'delivery';
			if (hasSwitch) {
				options.forEach(function (option) {
					if (option && String(option.display_key || '') === defaultKey) {
						activeChoice = String(option.fulfilment_choice || 'delivery');
					}
				});
				if (activeChoice !== 'store_pickup') {
					activeChoice = 'delivery';
				}
			} else if (groups.delivery.length === 0) {
				activeChoice = 'store_pickup';
			}

			if (hasSwitch) {
				fragment.appendChild(this.renderChoiceSwitch(activeChoice, i18n));
			}

			if (caps.hasDelivery || groups.delivery.length) {
				fragment.appendChild(
					this.renderChoicePanel('delivery', groups.delivery, variationId, fieldName, defaultKey, hasSwitch && activeChoice !== 'delivery', estimatePrefix, i18n)
				);
			}
			if (caps.hasPickup || groups.store_pickup.length) {
				fragment.appendChild(
					this.renderChoicePanel('store_pickup', groups.store_pickup, variationId, fieldName, defaultKey, hasSwitch && activeChoice !== 'store_pickup', estimatePrefix, i18n)
				);
			}

			this.optionsEl.appendChild(fragment);
			if (window.CetechDeProductDeliverySelector && this.root) {
				window.CetechDeProductDeliverySelector.bind(this.root);
			}
		},

		renderChoiceSwitch: function (activeChoice, i18n) {
			var wrap = document.createElement('div');
			wrap.className = 'cetech-de-fulfilment-choice';
			wrap.setAttribute('role', 'radiogroup');
			wrap.setAttribute('aria-label', i18n.fulfilment || 'Fulfilment');
			[
				{ value: 'delivery', label: i18n.delivery || 'Delivery' },
				{ value: 'store_pickup', label: i18n.storePickup || 'Store pickup' },
			].forEach(function (choice) {
				var p = document.createElement('p');
				p.className = 'cetech-de-fulfilment-choice__option';
				var label = document.createElement('label');
				var input = document.createElement('input');
				input.type = 'radio';
				input.name = 'cetech_de_fulfilment_ui';
				input.value = choice.value;
				input.setAttribute('data-cetech-de-choice-switch', '1');
				if (activeChoice === choice.value) {
					input.checked = true;
				}
				label.appendChild(input);
				label.appendChild(document.createTextNode(' ' + choice.label));
				p.appendChild(label);
				wrap.appendChild(p);
			});
			return wrap;
		},

		renderChoicePanel: function (choice, options, variationId, fieldName, defaultKey, hidden, estimatePrefix, i18n) {
			var panel = document.createElement('div');
			panel.className = 'cetech-de-delivery-option-group';
			if (choice === 'store_pickup') {
				panel.className += ' cetech-de-delivery-option-group--pickup';
			}
			panel.setAttribute('data-cetech-de-choice-panel', choice);
			if (hidden) {
				panel.hidden = true;
			}

			var self = this;
			options.forEach(function (option) {
				panel.appendChild(self.renderOptionRadio(option, variationId, fieldName, defaultKey, hidden, estimatePrefix, i18n));
				if (choice === 'store_pickup') {
					var details = self.renderPickupDetails(option, i18n);
					if (details) {
						panel.appendChild(details);
					}
				}
			});
			return panel;
		},

		renderOptionRadio: function (option, variationId, fieldName, defaultKey, hidden, estimatePrefix, i18n) {
			var displayKey = String(option.display_key || '');
			var p = document.createElement('p');
			p.className = 'cetech-de-delivery-option cetech-de-delivery-option--radio';
			p.setAttribute('data-cetech-de-choice', String(option.fulfilment_choice || ''));

			var inputId = 'cetech-de-delivery-option-' + displayKey.replace(/[^a-zA-Z0-9_-]/g, '-');
			var label = document.createElement('label');
			label.className = 'cetech-de-delivery-option__label-wrap';
			label.setAttribute('for', inputId);

			var input = document.createElement('input');
			input.type = 'radio';
			input.name = fieldName;
			input.id = inputId;
			input.value = displayKey;
			input.required = !hidden;
			input.disabled = !!hidden;
			input.checked = defaultKey !== '' && defaultKey === displayKey;
			input.setAttribute('data-cetech-de-variation-bound', String(variationId));

			var body = document.createElement('span');
			body.className = 'cetech-de-delivery-option__body';

			var labelText = document.createElement('span');
			labelText.className = 'cetech-de-delivery-option__label';
			labelText.textContent = String(option.delivery_offer_public_label || '');
			body.appendChild(labelText);

			if (option.estimate_text && String(option.fulfilment_choice || '') !== 'store_pickup') {
				var estimateLine = option.estimate_line
					? String(option.estimate_line)
					: (window.CetechDeProductDeliverySelector && window.CetechDeProductDeliverySelector.formatEstimateLine
						? window.CetechDeProductDeliverySelector.formatEstimateLine(option, { i18n: { estimated: estimatePrefix } })
						: estimatePrefix + ': ' + String(option.estimate_text).replace(/^Estimated(?:\s+delivery)?\s*:?\s+/i, '').trim());
				if (estimateLine) {
					var eta = document.createElement('span');
					eta.className = 'cetech-de-delivery-option__estimate';
					eta.textContent = estimateLine;
					body.appendChild(eta);
				}
			}

			var priceText = window.CetechDeProductDeliverySelector && window.CetechDeProductDeliverySelector.formatPriceText
				? window.CetechDeProductDeliverySelector.formatPriceText(option, { i18n: i18n })
				: String(option.price_text || '');
			if (priceText) {
				var price = document.createElement('span');
				price.className = 'cetech-de-delivery-option__price';
				price.textContent = priceText;
				body.appendChild(price);
			}

			label.appendChild(input);
			label.appendChild(body);
			p.appendChild(label);
			return p;
		},

		renderPickupDetails: function (option, i18n) {
			var rows = [];
			if (option.pickup_location_label) {
				rows.push([i18n.pickupLocation || 'Pickup location', option.pickup_location_label]);
			}
			if (option.pickup_address) {
				rows.push([i18n.pickupAddress || 'Pickup address', option.pickup_address]);
			}
			if (option.estimate_text) {
				rows.push([i18n.readyForPickup || 'Ready for pickup', String(option.estimate_text).replace(/^Estimated\s+/i, '').trim()]);
			}
			if (option.pickup_instructions) {
				rows.push([i18n.pickupInstructions || 'Pickup instructions', option.pickup_instructions]);
			}
			if (!rows.length) {
				return null;
			}
			var wrap = document.createElement('div');
			wrap.className = 'cetech-de-pickup-details';
			rows.forEach(function (row) {
				if (!row[1]) {
					return;
				}
				var p = document.createElement('p');
				p.className = 'cetech-de-pickup-details__row';
				var label = document.createElement('span');
				label.className = 'cetech-de-pickup-details__label';
				label.textContent = row[0];
				var value = document.createElement('span');
				value.className = 'cetech-de-pickup-details__value';
				value.textContent = ' ' + row[1];
				p.appendChild(label);
				p.appendChild(value);
				wrap.appendChild(p);
			});
			return wrap;
		},

		setVariationBinding: function (variationId) {
			if (!this.variationInput) {
				return;
			}
			this.variationInput.disabled = false;
			this.variationInput.value = String(variationId);
		},

		invalidateSelection: function () {
			if (this.optionsEl) {
				this.optionsEl.innerHTML = '';
			}
			if (this.variationInput) {
				this.variationInput.value = '';
				this.variationInput.disabled = true;
			}
		},

		abortRequest: function () {
			if (this.xhr && typeof this.xhr.abort === 'function') {
				this.xhr.abort();
			}
			this.xhr = null;
		},

		showSelectOptions: function () {
			this.setStatus((config.i18n && config.i18n.selectOptions) || '', 'select');
			if (this.optionsEl) {
				this.optionsEl.innerHTML = '';
			}
		},

		showLoading: function () {
			this.setStatus((config.i18n && config.i18n.loading) || '', 'loading');
			if (this.optionsEl) {
				this.optionsEl.innerHTML = '';
			}
		},

		showUnavailable: function (message) {
			this.setStatus(message || ((config.i18n && config.i18n.unavailable) || ''), 'unavailable');
			if (this.optionsEl) {
				this.optionsEl.innerHTML = '';
			}
			if (this.variationInput) {
				this.variationInput.value = '';
				this.variationInput.disabled = true;
			}
		},

		showError: function (message) {
			this.setStatus(message || ((config.i18n && config.i18n.error) || ''), 'error');
			if (this.optionsEl) {
				this.optionsEl.innerHTML = '';
			}
			if (this.variationInput) {
				this.variationInput.value = '';
				this.variationInput.disabled = true;
			}
		},

		setStatus: function (message, state) {
			if (!this.statusEl) {
				return;
			}
			this.statusEl.textContent = message || '';
			if (state) {
				this.statusEl.setAttribute('data-state', state);
			} else {
				this.statusEl.removeAttribute('data-state');
			}
		}
	};

	window.CetechDeVariableDeliveryController = Controller;

	function boot() {
		Controller.init();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(window, document, window.jQuery);
