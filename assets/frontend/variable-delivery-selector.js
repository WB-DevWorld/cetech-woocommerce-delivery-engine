/**
 * Stage 6A — Variable product delivery selector (standard WooCommerce variation events).
 *
 * Theme-independent: listens to found_variation / reset_data on .variations_form.
 * Does not resolve delivery rules client-side. Server remains authoritative.
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

			this.showLoading();

			var cacheKey = productId + ':' + variationId;
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
					variation_id: variationId
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
			if (!payload || payload.status !== 'ok') {
				if (payload && payload.status === 'unavailable') {
					this.showUnavailable(payload.message);
				} else {
					this.showError(payload && payload.message);
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
			this.renderOptions(available, variationId);
			this.setVariationBinding(variationId);
		},

			renderOptions: function (options, variationId) {
			if (!this.optionsEl) {
				return;
			}

			this.optionsEl.innerHTML = '';
			var fieldName = config.postField || 'cetech_de_delivery_option_key';
			var fragment = document.createDocumentFragment();
			var estimatePrefix = (config.i18n && config.i18n.estimatedDelivery) || 'Estimated delivery';
			var pickupPrefix = (config.i18n && config.i18n.readyForPickup) || 'Ready for pickup';

			options.forEach(function (option) {
				var displayKey = String(option.display_key || '');
				if (!displayKey) {
					return;
				}

				var p = document.createElement('p');
				p.className = 'cetech-de-delivery-option cetech-de-delivery-option--radio';

				var inputId = 'cetech-de-delivery-option-' + displayKey.replace(/[^a-zA-Z0-9_-]/g, '-');
				var label = document.createElement('label');
				label.className = 'cetech-de-delivery-option__label-wrap';
				label.setAttribute('for', inputId);

				var input = document.createElement('input');
				input.type = 'radio';
				input.name = fieldName;
				input.id = inputId;
				input.value = displayKey;
				input.required = true;
				input.setAttribute('data-cetech-de-variation-bound', String(variationId));

				var body = document.createElement('span');
				body.className = 'cetech-de-delivery-option__body';

				var labelText = document.createElement('span');
				labelText.className = 'cetech-de-delivery-option__label';
				labelText.textContent = String(option.delivery_offer_public_label || '');
				body.appendChild(labelText);

				// Compact product selector: public label + estimate only.
				// Public description remains in the data contract but is omitted here.
				if (option.estimate_text) {
					var rawEstimate = String(option.estimate_text).replace(/^Estimated\s+/i, '').trim();
					if (rawEstimate) {
						var isPickup = String(option.fulfilment_choice || '').toLowerCase() === 'store_pickup'
							|| String(option.fulfilment_choice_label || '').toLowerCase().indexOf('pickup') !== -1;
						var eta = document.createElement('span');
						eta.className = 'cetech-de-delivery-option__estimate';
						eta.textContent = (isPickup ? pickupPrefix : estimatePrefix) + ': ' + rawEstimate;
						body.appendChild(eta);
					}
				}

				label.appendChild(input);
				label.appendChild(body);
				p.appendChild(label);
				fragment.appendChild(p);
			});

			this.optionsEl.appendChild(fragment);
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
