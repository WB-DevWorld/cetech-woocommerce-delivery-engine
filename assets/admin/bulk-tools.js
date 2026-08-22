(function (root) {
	function scopeMatches(allowed, current) {
		if (!allowed) {
			return true;
		}
		return allowed.split(',').indexOf(current) !== -1;
	}

	function valueMatches(allowed, current) {
		if (!allowed) {
			return true;
		}
		return allowed.split(',').indexOf(current) !== -1;
	}

	function setHidden(el, hidden) {
		if (!el) {
			return;
		}
		el.hidden = !!hidden;
		var disable = !!hidden;
		if (el.matches && el.matches('input, select, textarea')) {
			el.disabled = disable;
		}
		el.querySelectorAll('input, select, textarea').forEach(function (field) {
			field.disabled = disable;
		});
	}

	function syncBulkCatalogForm(form) {
		if (!form) {
			return;
		}
		var scopeEl = form.querySelector('#cetech-de-target-scope');
		var fulfilEl = form.querySelector('#cetech-de-fulfilment-action');
		var offersEl = form.querySelector('#cetech-de-offers-action');
		var resetEl = form.querySelector('#cetech-de-reset-scope');
		var scope = scopeEl ? scopeEl.value : 'selected_ids';
		var fulfil = fulfilEl ? fulfilEl.value : 'no_change';
		var offers = offersEl ? offersEl.value : 'no_change';
		var resetOn = !!(resetEl && resetEl.checked);

		form.querySelectorAll('[data-reveal-scope]').forEach(function (el) {
			setHidden(el, !scopeMatches(el.getAttribute('data-reveal-scope'), scope));
		});
		form.querySelectorAll('[data-reveal-fulfilment]').forEach(function (el) {
			setHidden(el, !valueMatches(el.getAttribute('data-reveal-fulfilment'), fulfil));
		});
		form.querySelectorAll('[data-reveal-offers]').forEach(function (el) {
			setHidden(el, !valueMatches(el.getAttribute('data-reveal-offers'), offers));
		});
		form.querySelectorAll('[data-reveal-reset]').forEach(function (el) {
			setHidden(el, !resetOn);
		});
	}

	function initSearchableSelects(form) {
		if (!form || typeof root.jQuery === 'undefined' || !root.jQuery.fn || !root.jQuery.fn.selectWoo) {
			return;
		}
		var $ = root.jQuery;
		var ajaxUrl = root.cetechDeBulk && root.cetechDeBulk.ajaxUrl ? root.cetechDeBulk.ajaxUrl : '';
		var nonce = root.cetechDeBulk && root.cetechDeBulk.searchProductsNonce ? root.cetechDeBulk.searchProductsNonce : '';

		$(form).find('.cetech-de-enhanced-select').each(function () {
			var $el = $(this);
			if ($el.hasClass('select2-hidden-accessible')) {
				return;
			}
			$el.selectWoo({
				allowClear: true,
				width: '100%',
				placeholder: $el.attr('data-placeholder') || ''
			});
		});

		$(form).find('.cetech-de-product-search').each(function () {
			var $el = $(this);
			if ($el.hasClass('select2-hidden-accessible')) {
				return;
			}
			var config = {
				allowClear: true,
				multiple: true,
				width: '100%',
				placeholder: $el.attr('data-placeholder') || '',
				minimumInputLength: 2
			};
			if (ajaxUrl && nonce) {
				config.ajax = {
					url: ajaxUrl,
					dataType: 'json',
					delay: 250,
					data: function (params) {
						return {
							term: params.term,
							action: 'woocommerce_json_search_products',
							security: nonce
						};
					},
					processResults: function (data) {
						var terms = [];
						if (data) {
							$.each(data, function (id, text) {
								terms.push({ id: id, text: text });
							});
						}
						return { results: terms };
					}
				};
			}
			$el.selectWoo(config);
		});
	}

	function initBulkCatalogForm(form) {
		if (!form) {
			return;
		}
		syncBulkCatalogForm(form);
		initSearchableSelects(form);
		form.addEventListener('change', function () {
			syncBulkCatalogForm(form);
		});
	}

	function initJobPolling() {
		if (typeof root.cetechDeBulk === 'undefined') {
			return;
		}
		var status = document.querySelector('[data-cetech-de-job-id]');
		if (!status) {
			return;
		}
		var jobId = status.getAttribute('data-cetech-de-job-id');
		var interval = root.setInterval(function () {
			var body = new URLSearchParams();
			body.set('action', root.cetechDeBulk.action);
			body.set('nonce', root.cetechDeBulk.nonce);
			body.set('job_id', jobId);
			root.fetch(root.cetechDeBulk.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (response) {
				return response.json();
			}).then(function (payload) {
				if (!payload || !payload.success || !payload.data) {
					return;
				}
				status.textContent = payload.data.code + ' · ' + payload.data.status + ' · ' + payload.data.processed + ' / ' + payload.data.total;
				if (payload.data.terminal) {
					root.clearInterval(interval);
				}
			}).catch(function () {
				root.clearInterval(interval);
			});
		}, 5000);
	}

	root.cetechDeBulkCatalog = {
		sync: syncBulkCatalogForm,
		init: initBulkCatalogForm
	};

	function boot() {
		document.querySelectorAll('[data-cetech-de-bulk-catalog]').forEach(initBulkCatalogForm);
		initJobPolling();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(typeof window !== 'undefined' ? window : globalThis);
