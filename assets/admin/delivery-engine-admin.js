(function () {

	'use strict';



	document.addEventListener('click', function (event) {

		var trigger = event.target.closest('[data-cetech-de-open]');

		if (!trigger) {

			return;

		}



		var targetId = trigger.getAttribute('data-cetech-de-open');

		var panel = targetId ? document.getElementById(targetId) : null;

		if (!panel || !panel.matches('details')) {

			return;

		}



		panel.open = true;

		var firstField = panel.querySelector('input, select, textarea');

		if (firstField) {

			firstField.focus();

		}

	});



	function syncDeliveryTabLayout() {

		var panel = document.getElementById('cetech_de_delivery_product_data');

		var wrap = document.getElementById('woocommerce-product-data');

		if (!panel || !wrap) {

			return;

		}

		var active = !panel.classList.contains('hidden') && window.getComputedStyle(panel).display !== 'none';

		wrap.classList.toggle('cetech-de-delivery-tab-active', active);

	}



	document.addEventListener('click', function (event) {

		if (event.target.closest('.product_data_tabs a, .wc-tabs a, .product-data-tabs a')) {

			window.setTimeout(syncDeliveryTabLayout, 0);

		}

	});



	document.addEventListener('click', function (event) {

		var add = event.target.closest('[data-cetech-de-add-condition]');

		if (!add) {

			return;

		}

		var builder = add.closest('[data-cetech-de-condition-builder]');

		var template = builder ? builder.querySelector('[data-cetech-de-condition-template]') : null;

		var tbody = builder ? builder.querySelector('.cetech-de-condition-table tbody') : null;

		if (!template || !tbody) {

			return;

		}

		var index = tbody.querySelectorAll('tr').length;

		var html = template.innerHTML.replace(/\{\{index\}\}/g, String(index));

		var row = document.createElement('tr');

		row.className = 'cetech-de-condition-row';

		row.innerHTML = html;

		tbody.appendChild(row);

	});



	function resetVariationSelect(select, placeholder) {

		if (!select) {

			return;

		}

		select.innerHTML = '';

		var empty = document.createElement('option');

		empty.value = '';

		empty.textContent = placeholder || 'Select variation';

		select.appendChild(empty);

	}



	function populateVariationSelect(select, variations, selectedId) {

		resetVariationSelect(select);

		(variations || []).forEach(function (item) {

			var option = document.createElement('option');

			option.value = String(item.id);

			option.textContent = item.label || ('Variation #' + item.id);

			if (selectedId && String(selectedId) === String(item.id)) {

				option.selected = true;

			}

			select.appendChild(option);

		});

	}



	function loadPreviewVariations(productSelect) {

		var form = productSelect.closest('[data-cetech-de-preview-form]');

		if (!form) {

			return;

		}

		var row = form.querySelector('.cetech-de-preview-variation-row');

		var variationSelect = form.querySelector('[data-cetech-de-preview-variation]');

		var selected = productSelect.options[productSelect.selectedIndex];

		var isVariable = !!(selected && selected.getAttribute('data-variable') === '1');

		var productId = productSelect.value;



		if (row) {

			row.hidden = !isVariable;

		}

		if (!variationSelect) {

			return;

		}



		resetVariationSelect(variationSelect);

		if (!isVariable || !productId) {

			return;

		}



		var cfg = window.cetechDePreview || null;

		if (!cfg || !cfg.ajaxUrl || !cfg.nonce) {

			return;

		}



		variationSelect.disabled = true;

		var body = new window.FormData();

		body.append('action', cfg.action || 'cetech_de_preview_variations');

		body.append('nonce', cfg.nonce);

		body.append('product_id', productId);



		window.fetch(cfg.ajaxUrl, {

			method: 'POST',

			credentials: 'same-origin',

			body: body

		}).then(function (response) {

			return response.json();

		}).then(function (payload) {

			variationSelect.disabled = false;

			if (!payload || !payload.success || !payload.data) {

				return;

			}

			populateVariationSelect(variationSelect, payload.data.variations || [], '');

		}).catch(function () {

			variationSelect.disabled = false;

		});

	}



	document.addEventListener('change', function (event) {

		var fulfilment = event.target.closest('[data-cetech-de-fulfilment-select]');

		if (fulfilment) {

			var form = fulfilment.closest('[data-cetech-de-customize]');

			var options = form ? form.querySelectorAll('.cetech-de-compatible-option') : [];

			options.forEach(function (row) {

				var profiles = (row.getAttribute('data-profiles') || '').split(',');

				row.hidden = profiles.indexOf(fulfilment.value) === -1;

			});

		}

		var product = event.target.closest('[data-cetech-de-preview-product]');

		if (product) {

			loadPreviewVariations(product);

		}

	});



	document.addEventListener('DOMContentLoaded', function () {

		syncDeliveryTabLayout();

		var params = new URLSearchParams(window.location.search);

		var focus = params.get('focus');

		if (focus === 'option-list') {

			var options = document.getElementById('cetech-de-option-list');

			if (options) {

				options.focus({ preventScroll: false });

				var checked = options.querySelector('input:checked') || options.querySelector('input');

				if (checked) {

					checked.focus();

				}

			}

		}

		if (focus === 'pickup') {

			var pickup = document.getElementById('pickup_location_id');

			if (pickup) {

				pickup.focus();

			}

		}



		var previewProduct = document.querySelector('[data-cetech-de-preview-product]');

		var previewVariation = document.querySelector('[data-cetech-de-preview-variation]');

		if (previewProduct && previewVariation && previewVariation.options.length <= 1) {

			var selectedProduct = previewProduct.options[previewProduct.selectedIndex];

			if (selectedProduct && selectedProduct.getAttribute('data-variable') === '1' && previewProduct.value) {

				loadPreviewVariations(previewProduct);

			}

		}



		var accessTable = document.querySelector('[data-cetech-de-access-table]');
		if (accessTable) {
			accessTable.addEventListener('change', function (event) {
				var box = event.target;
				if (!box || !box.getAttribute || !box.getAttribute('data-cetech-de-access-cap')) {
					return;
				}
				var row = box.closest('tr');
				if (!row) {
					return;
				}
				var view = row.querySelector('[data-cetech-de-access-view]');
				var manageChecked = row.querySelectorAll('[data-cetech-de-implies-view]:checked');
				if (view && !view.disabled && manageChecked.length) {
					view.checked = true;
				}
				if (box.getAttribute('data-cetech-de-access-view') && !box.checked && manageChecked.length) {
					box.checked = true;
				}
			});
		}

		var copyButton = document.getElementById('cetech-de-copy-diagnostic');

		var report = document.getElementById('cetech-de-diagnostic-report');

		if (copyButton && report) {

			copyButton.addEventListener('click', function () {

				var text = report.value || report.textContent || '';

				if (navigator.clipboard && navigator.clipboard.writeText) {

					navigator.clipboard.writeText(text).then(function () {

						copyButton.textContent = copyButton.getAttribute('data-copied') || 'Copied';

					}).catch(function () {

						report.removeAttribute('hidden');

						report.select();

					});

					return;

				}

				report.removeAttribute('hidden');

				report.select();

			});

		}

	});

})();

