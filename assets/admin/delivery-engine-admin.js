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

		row.querySelectorAll('[disabled]').forEach(function (el) {

			el.removeAttribute('disabled');

		});

		tbody.appendChild(row);

		syncConditionValueControls(row);

	});



	function syncConditionValueControls(row) {

		if (!row) {

			return;

		}

		var typeSelect = row.querySelector('[data-cetech-de-rule-type]');

		var cell = row.querySelector('[data-cetech-de-condition-value]');

		if (!typeSelect || !cell) {

			return;

		}

		var name = cell.getAttribute('data-cetech-de-value-name') || '';

		var country = cell.querySelector('[data-cetech-de-country-select]');

		var text = cell.querySelector('[data-cetech-de-rule-text]');

		var isCountry = typeSelect.value === 'country';

		if (!country || !text || !name) {

			return;

		}

		if (isCountry) {

			country.hidden = false;

			text.hidden = true;

			if (text.value && !country.value) {

				var posted = String(text.value).trim();

				country.value = posted.length === 2 ? posted.toUpperCase() : country.value;

			}

			return;

		}

		country.hidden = true;

		text.hidden = false;

	}



	function syncAllConditionValueControls(root) {

		(root || document).querySelectorAll('.cetech-de-condition-row').forEach(syncConditionValueControls);

	}



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

		var ruleType = event.target.closest('[data-cetech-de-rule-type]');

		if (ruleType) {

			syncConditionValueControls(ruleType.closest('.cetech-de-condition-row') || ruleType.closest('tr'));

		}

	});



	document.addEventListener('DOMContentLoaded', function () {

		syncDeliveryTabLayout();

		syncAllConditionValueControls(document);

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

	function coverageBuilder() {
		var root = document.querySelector('[data-cetech-de-coverage-builder]');
		if (!root || root.getAttribute('data-cetech-de-coverage-bound') === '1') {
			return;
		}
		root.setAttribute('data-cetech-de-coverage-bound', '1');
		var geo = window.cetechDeGeography || {};
		var nextIndex = root.querySelectorAll('.cetech-de-coverage-group').length;
		var countries = {};
		try {
			countries = JSON.parse(root.getAttribute('data-countries') || '{}') || {};
		} catch (e) {
			countries = {};
		}
		var searchTimers = Object.create(null);

		function searchTimerKey(group, target) {
			return groupIndex(group) + ':' + (target || 'include');
		}

		function nextSearchToken(group, target) {
			var attr = 'data-search-token-' + target;
			var current = parseInt(group.getAttribute(attr) || '0', 10) || 0;
			current += 1;
			group.setAttribute(attr, String(current));
			return current;
		}

		function currentSearchToken(group, target) {
			return parseInt(group.getAttribute('data-search-token-' + target) || '0', 10) || 0;
		}

		function nextGroupToken(group, kind) {
			var attr = 'data-' + kind + '-token';
			var current = parseInt(group.getAttribute(attr) || '0', 10) || 0;
			current += 1;
			group.setAttribute(attr, String(current));
			return current;
		}

		function currentGroupToken(group, kind) {
			return parseInt(group.getAttribute('data-' + kind + '-token') || '0', 10) || 0;
		}

		function groupIndex(group) {
			return Array.prototype.indexOf.call(root.querySelectorAll('.cetech-de-coverage-group'), group);
		}

		function applyAdminLabel(group, label) {
			if (!group || !label) {
				return;
			}
			var wrap = group.querySelector('[data-cetech-de-admin-browser] > p');
			var select = wrap ? wrap.querySelector('select') : null;
			var lab = wrap ? wrap.querySelector('label') : null;
			if (!lab || !select) {
				return;
			}
			lab.textContent = '';
			lab.appendChild(document.createTextNode(label));
			lab.appendChild(document.createElement('br'));
			lab.appendChild(select);
		}

		function reindexCoverageGroups() {
			Array.prototype.forEach.call(root.querySelectorAll('.cetech-de-coverage-group'), function (group, index) {
				var legend = group.querySelector('legend');
				if (legend) {
					legend.textContent = 'Coverage group ' + (index + 1);
				}
				Array.prototype.forEach.call(group.querySelectorAll('[name]'), function (field) {
					var name = field.getAttribute('name') || '';
					if (name.indexOf('coverage_groups[') !== 0) {
						return;
					}
					field.setAttribute('name', name.replace(/^coverage_groups\[\d+]/, 'coverage_groups[' + index + ']'));
				});
			});
			nextIndex = root.querySelectorAll('.cetech-de-coverage-group').length;
		}

		function countryOf(group) {
			var field = group.querySelector('[data-cetech-de-coverage-country]');
			return field ? String(field.value || '') : '';
		}

		function rootKeyOf(group) {
			var field = group.querySelector('[data-cetech-de-root-key]');
			return field ? String(field.value || '') : '';
		}

		function setRoot(group, id, key) {
			var idField = group.querySelector('[data-cetech-de-root-id]');
			var keyField = group.querySelector('[data-cetech-de-root-key]');
			if (idField) {
				idField.value = id ? String(id) : '';
			}
			if (keyField) {
				keyField.value = key ? String(key) : '';
			}
		}

		function clearChips(group, target) {
			var chips = group.querySelector('[data-cetech-de-chips="' + target + '"]');
			if (chips) {
				chips.innerHTML = '';
			}
			updateCounts(group);
		}

		function updateCounts(group) {
			var include = group.querySelectorAll('[data-cetech-de-chips="include"] li').length;
			var exclude = group.querySelectorAll('[data-cetech-de-chips="exclude"] li').length;
			var includeEl = group.querySelector('[data-cetech-de-include-count]');
			var excludeEl = group.querySelector('[data-cetech-de-exclude-count]');
			if (includeEl) {
				includeEl.textContent = include + ' location' + (include === 1 ? '' : 's') + ' included';
			}
			if (excludeEl) {
				excludeEl.textContent = exclude + ' location' + (exclude === 1 ? '' : 's') + ' excluded';
			}
		}

		function syncPanels(group) {
			var mode = group.querySelector('[data-cetech-de-coverage-mode]');
			var value = mode ? mode.value : 'entire_area';
			var includePanel = group.querySelector('[data-cetech-de-include-panel]');
			var excludePanel = group.querySelector('[data-cetech-de-exclude-panel]');
			if (includePanel) {
				includePanel.hidden = value !== 'selected_descendants';
			}
			if (excludePanel) {
				excludePanel.hidden = value !== 'entire_except';
			}
		}

		function addChip(group, target, item) {
			var chips = group.querySelector('[data-cetech-de-chips="' + target + '"]');
			if (!chips || !item || !item.id) {
				return;
			}
			if (chips.querySelector('[value="' + String(item.id) + '"]')) {
				return;
			}
			var index = groupIndex(group);
			var field = target === 'exclude' ? 'exclusions' : 'members';
			var li = document.createElement('li');
			li.appendChild(document.createTextNode((item.name || '') + ' '));
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'button-link';
			btn.setAttribute('data-remove-member', String(item.id));
			btn.textContent = '×';
			li.appendChild(btn);
			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = 'coverage_groups[' + index + '][' + field + '][]';
			hidden.value = String(item.id);
			li.appendChild(hidden);
			chips.appendChild(li);
			updateCounts(group);
		}

		function renderResults(group, target, items, token, append, hasMore, page) {
			var list = group.querySelector('[data-cetech-de-search-results="' + target + '"]');
			if (!list || String(list.getAttribute('data-token') || '') !== String(token)) {
				return;
			}
			if (!append) {
				list.innerHTML = '';
			}
			var seen = {};
			Array.prototype.forEach.call(list.querySelectorAll('[role="option"]'), function (option) {
				seen[option.getAttribute('data-id') || option.getAttribute('data-key') || ''] = true;
			});
			items.forEach(function (item) {
				var id = String(item.id || item.key || '');
				if (seen[id]) {
					return;
				}
				seen[id] = true;
				var li = document.createElement('li');
				li.setAttribute('role', 'option');
				li.tabIndex = -1;
				var resultLabel = item.label || item.name || '';
				li.textContent = resultLabel;
				li.setAttribute('aria-label', resultLabel);
				li.setAttribute('data-id', String(item.id || ''));
				li.setAttribute('data-key', item.key || '');
				li.setAttribute('data-name', item.name || '');
				list.appendChild(li);
			});
			var more = list.querySelector('[data-cetech-de-load-more]');
			if (more) {
				more.remove();
			}
			if (hasMore) {
				var button = document.createElement('button');
				button.type = 'button';
				button.className = 'button-link';
				button.setAttribute('data-cetech-de-load-more', target);
				button.setAttribute('data-page', String((page || 1) + 1));
				button.textContent = 'Load more';
				list.appendChild(button);
			}
			list.hidden = list.querySelectorAll('[role="option"]').length === 0;
			if (!append) {
				list.setAttribute('data-active', '0');
			}
		}

		function chooseResult(group, target, item) {
			addChip(group, target, item);
			var list = group.querySelector('[data-cetech-de-search-results="' + target + '"]');
			if (list) {
				list.hidden = true;
				list.innerHTML = '';
			}
			var input = group.querySelector('[data-cetech-de-search-target="' + target + '"]');
			if (input) {
				input.value = '';
			}
		}

		function moveActive(list, delta) {
			var options = Array.prototype.slice.call(list.querySelectorAll('[role="option"]'));
			if (!options.length) {
				return;
			}
			var current = parseInt(list.getAttribute('data-active') || '0', 10);
			if (isNaN(current)) {
				current = 0;
			}
			current = (current + delta + options.length) % options.length;
			options.forEach(function (option, idx) {
				option.setAttribute('aria-selected', idx === current ? 'true' : 'false');
			});
			list.setAttribute('data-active', String(current));
			options[current].focus();
		}

		function fetchJson(body) {
			return window.fetch(geo.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			}).then(function (response) { return response.json(); });
		}

		function loadChildRoots(group, parentKey, level) {
			var browser = group.querySelector('[data-cetech-de-admin-browser]') || group;
			Array.prototype.forEach.call(group.querySelectorAll('[data-cetech-de-nested-root]'), function (node) {
				if (parseInt(node.getAttribute('data-cetech-de-nested-root') || '0', 10) >= level) {
					node.remove();
				}
			});
			if (!parentKey) {
				return;
			}
			var token = nextGroupToken(group, 'child-root');
			var body = new window.URLSearchParams();
			body.set('action', geo.searchAction);
			body.set('nonce', geo.searchNonce || '');
			body.set('op', 'children');
			body.set('country', countryOf(group));
			body.set('parent_key', parentKey);
			body.set('request_token', String(token));
			fetchJson(body).then(function (payload) {
				if (token !== currentGroupToken(group, 'child-root')) {
					return;
				}
				var data = payload && payload.data ? payload.data : payload;
				var items = (data && data.items ? data.items : []).filter(function (item) {
					return !item.entire_country;
				});
				if (!items.length) {
					return;
				}
				var wrap = document.createElement('p');
				wrap.setAttribute('data-cetech-de-nested-root', String(level));
				wrap.innerHTML = '<label>Narrower area<br /><select data-cetech-de-coverage-root data-cetech-de-nested-level="' + level + '"><option value="">Entire selected area</option></select></label>';
				var select = wrap.querySelector('select');
				items.forEach(function (item) {
					var option = document.createElement('option');
					option.value = item.key || '';
					option.textContent = item.name || '';
					option.setAttribute('data-id', String(item.id || ''));
					option.setAttribute('data-has-children', item.has_children ? '1' : '0');
					select.appendChild(option);
				});
				browser.appendChild(wrap);
			}).catch(function () { /* keep */ });
		}

		function loadRoots(group) {
			var country = countryOf(group);
			var select = group.querySelector('[data-cetech-de-coverage-root]:not([data-cetech-de-nested-level])');
			if (!select || !geo.ajaxUrl || !country) {
				return;
			}
			Array.prototype.forEach.call(group.querySelectorAll('[data-cetech-de-nested-root]'), function (node) {
				node.remove();
			});
			var token = nextGroupToken(group, 'root');
			var body = new window.URLSearchParams();
			body.set('action', geo.searchAction);
			body.set('nonce', geo.searchNonce || '');
			body.set('op', 'children');
			body.set('country', country);
			body.set('request_token', String(token));
			fetchJson(body).then(function (payload) {
				if (token !== currentGroupToken(group, 'root')) {
					return;
				}
				var data = payload && payload.data ? payload.data : payload;
				if (data && data.label) {
					applyAdminLabel(group, data.label);
				}
				var items = data && data.items ? data.items : [];
				var current = select.value;
				select.innerHTML = '<option value="">Select…</option>';
				items.forEach(function (item) {
					var option = document.createElement('option');
					option.value = item.key || '';
					option.textContent = item.name || '';
					option.setAttribute('data-id', String(item.id || ''));
					option.setAttribute('data-has-children', item.has_children ? '1' : '0');
					if (current && current === option.value) {
						option.selected = true;
					}
					select.appendChild(option);
				});
				if (data && data.root && !select.value) {
					select.value = data.root.key || '';
					setRoot(group, data.root.id, data.root.key);
				}
				if (select.selectedOptions[0]) {
					var chosen = select.selectedOptions[0];
					setRoot(group, chosen.getAttribute('data-id'), chosen.value);
					if (chosen.getAttribute('data-has-children') === '1') {
						loadChildRoots(group, chosen.value, 2);
					}
				}
			}).catch(function () { /* keep */ });
		}

		function searchLocalities(input, page, append) {
			var group = input.closest('.cetech-de-coverage-group');
			if (!group || !geo.ajaxUrl) {
				return;
			}
			var target = input.getAttribute('data-cetech-de-search-target') || 'include';
			var token = append ? currentSearchToken(group, target) : nextSearchToken(group, target);
			page = page || 1;
			var list = group.querySelector('[data-cetech-de-search-results="' + target + '"]');
			if (list) {
				list.setAttribute('data-token', String(token));
			}
			var body = new window.URLSearchParams();
			body.set('action', geo.searchAction);
			body.set('nonce', geo.searchNonce || '');
			body.set('op', 'search');
			body.set('q', input.value || '');
			body.set('page', String(page));
			body.set('request_token', String(token));
			body.set('country', countryOf(group));
			body.set('parent_key', rootKeyOf(group));
			fetchJson(body).then(function (payload) {
				if (token !== currentSearchToken(group, target)) {
					return;
				}
				var data = payload && payload.data ? payload.data : payload;
				if (data && data.request_token && String(data.request_token) !== String(token)) {
					return;
				}
				renderResults(group, target, (data && data.items) ? data.items : [], token, !!append, !!(data && data.has_more), page);
			}).catch(function () { /* keep */ });
		}

		root.addEventListener('click', function (event) {
			var removeGroup = event.target.closest('[data-cetech-de-remove-coverage-group]');
			if (removeGroup) {
				event.preventDefault();
				var groupToRemove = removeGroup.closest('.cetech-de-coverage-group');
				if (!groupToRemove) {
					return;
				}
				if (!window.confirm('Remove this coverage group? Remaining groups keep their own locations.')) {
					return;
				}
				var remaining = root.querySelectorAll('.cetech-de-coverage-group').length;
				if (remaining <= 1) {
					if (!window.confirm('This is the last canonical coverage group. Removing it stops canonical coverage. Hidden legacy conditions will not be used automatically.')) {
						return;
					}
					var form = root.closest('form') || document;
					var flag = form.querySelector('input[name="confirm_drop_canonical"]');
					if (!flag) {
						flag = document.createElement('input');
						flag.type = 'hidden';
						flag.name = 'confirm_drop_canonical';
						form.appendChild(flag);
					}
					flag.value = '1';
				}
				groupToRemove.remove();
				reindexCoverageGroups();
				return;
			}
			var add = event.target.closest('[data-cetech-de-add-coverage-group]');
			if (add) {
				event.preventDefault();
				var holder = root.querySelector('[data-cetech-de-coverage-groups]');
				if (!holder) {
					return;
				}
				holder.insertAdjacentHTML('beforeend', coverageGroupTemplate(nextIndex, countries));
				nextIndex += 1;
				return;
			}
			var selectAll = event.target.closest('[data-cetech-de-select-all]');
			if (selectAll) {
				event.preventDefault();
				var group = selectAll.closest('.cetech-de-coverage-group');
				if (!group || !geo.ajaxUrl) {
					return;
				}
				var body = new window.URLSearchParams();
				body.set('action', geo.searchAction);
				body.set('nonce', geo.searchNonce || '');
				body.set('op', 'descendants');
				body.set('select_all', '1');
				body.set('country', countryOf(group));
				body.set('parent_key', rootKeyOf(group));
				fetchJson(body).then(function (payload) {
					var data = payload && payload.data ? payload.data : payload;
					if (data && data.recommend_entire_area) {
						var mode = group.querySelector('[data-cetech-de-coverage-mode]');
						if (mode) {
							mode.value = 'entire_area';
							syncPanels(group);
						}
						window.alert('This area is too large to select every location. Switched to Entire selected area.');
						return;
					}
					((data && data.items) ? data.items : []).forEach(function (item) {
						addChip(group, 'include', item);
					});
				}).catch(function () { /* keep */ });
				return;
			}
			var addPost = event.target.closest('[data-cetech-de-add-postcode]');
			if (addPost) {
				event.preventDefault();
				var group = addPost.closest('.cetech-de-coverage-group');
				var table = group ? group.querySelector('[data-cetech-de-postcode-table] tbody') : null;
				if (table) {
					var index = groupIndex(group);
					var rowIndex = table.querySelectorAll('[data-cetech-de-postcode-row]').length;
					table.insertAdjacentHTML('beforeend', postcodeRowTemplate(index, rowIndex));
				}
				return;
			}
			var removePost = event.target.closest('[data-cetech-de-remove-postcode]');
			if (removePost) {
				event.preventDefault();
				var row = removePost.closest('tr');
				if (row) {
					row.remove();
				}
				return;
			}
			var clear = event.target.closest('[data-cetech-de-clear-members]');
			if (clear) {
				event.preventDefault();
				var group = clear.closest('.cetech-de-coverage-group');
				if (group) {
					clearChips(group, 'include');
				}
				return;
			}
			var remove = event.target.closest('[data-remove-member]');
			if (remove) {
				event.preventDefault();
				var item = remove.closest('li');
				var group = remove.closest('.cetech-de-coverage-group');
				if (item) {
					item.remove();
				}
				if (group) {
					updateCounts(group);
				}
				return;
			}
			var more = event.target.closest('[data-cetech-de-load-more]');
			if (more) {
				event.preventDefault();
				var group = more.closest('.cetech-de-coverage-group');
				var target = more.getAttribute('data-cetech-de-load-more') || 'include';
				var input = group ? group.querySelector('[data-cetech-de-search-target="' + target + '"]') : null;
				if (input) {
					searchLocalities(input, parseInt(more.getAttribute('data-page') || '2', 10), true);
				}
				return;
			}
			var option = event.target.closest('[data-cetech-de-search-results] [role="option"]');
			if (option) {
				event.preventDefault();
				var list = option.parentNode;
				var group = option.closest('.cetech-de-coverage-group');
				var target = list ? list.getAttribute('data-cetech-de-search-results') : 'include';
				chooseResult(group, target, {
					id: option.getAttribute('data-id'),
					key: option.getAttribute('data-key'),
					name: option.getAttribute('data-name') || option.textContent
				});
			}
		});

		root.addEventListener('change', function (event) {
			var country = event.target.closest('[data-cetech-de-coverage-country]');
			if (country) {
				var group = country.closest('.cetech-de-coverage-group');
				if (group) {
					setRoot(group, '', '');
					clearChips(group, 'include');
					clearChips(group, 'exclude');
					loadRoots(group);
				}
				return;
			}
			var adminRoot = event.target.closest('[data-cetech-de-coverage-root]');
			if (adminRoot) {
				var group = adminRoot.closest('.cetech-de-coverage-group');
				var chosen = adminRoot.selectedOptions && adminRoot.selectedOptions[0] ? adminRoot.selectedOptions[0] : null;
				if (group) {
					var nestedLevel = parseInt(adminRoot.getAttribute('data-cetech-de-nested-level') || '1', 10);
					if (chosen && chosen.value) {
						setRoot(group, chosen.getAttribute('data-id'), chosen.value);
					} else {
						var parentSelect = nestedLevel > 1
							? group.querySelector('[data-cetech-de-coverage-root][data-cetech-de-nested-level="' + (nestedLevel - 1) + '"]')
							: group.querySelector('[data-cetech-de-coverage-root]:not([data-cetech-de-nested-level])');
						if (!parentSelect || parentSelect === adminRoot) {
							parentSelect = group.querySelector('[data-cetech-de-coverage-root]:not([data-cetech-de-nested-level])');
						}
						var parentChosen = parentSelect && parentSelect.selectedOptions && parentSelect.selectedOptions[0] ? parentSelect.selectedOptions[0] : null;
						setRoot(group, parentChosen ? parentChosen.getAttribute('data-id') : '', parentSelect ? parentSelect.value : '');
					}
					clearChips(group, 'include');
					clearChips(group, 'exclude');
					if (chosen && chosen.value && chosen.getAttribute('data-has-children') === '1') {
						loadChildRoots(group, chosen.value, nestedLevel + 1);
					} else {
						Array.prototype.forEach.call(group.querySelectorAll('[data-cetech-de-nested-root]'), function (node) {
							if (parseInt(node.getAttribute('data-cetech-de-nested-root') || '0', 10) > nestedLevel) {
								node.remove();
							}
						});
					}
				}
				return;
			}
			var mode = event.target.closest('[data-cetech-de-coverage-mode]');
			if (mode) {
				var group = mode.closest('.cetech-de-coverage-group');
				if (group) {
					syncPanels(group);
				}
			}
		});

		root.addEventListener('input', function (event) {
			var input = event.target.closest('[data-cetech-de-locality-search]');
			if (!input) {
				return;
			}
			var group = input.closest('.cetech-de-coverage-group');
			var target = input.getAttribute('data-cetech-de-search-target') || 'include';
			var key = searchTimerKey(group, target);
			window.clearTimeout(searchTimers[key]);
			searchTimers[key] = window.setTimeout(function () {
				searchLocalities(input);
			}, 280);
		});

		root.addEventListener('keydown', function (event) {
			var list = event.target.closest('[data-cetech-de-search-results]');
			var input = event.target.closest('[data-cetech-de-locality-search]');
			if (input && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
				var target = input.getAttribute('data-cetech-de-search-target') || 'include';
				var results = input.closest('.cetech-de-coverage-group').querySelector('[data-cetech-de-search-results="' + target + '"]');
				if (results && !results.hidden) {
					event.preventDefault();
					moveActive(results, event.key === 'ArrowDown' ? 1 : -1);
				}
				return;
			}
			if (list && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
				event.preventDefault();
				moveActive(list, event.key === 'ArrowDown' ? 1 : -1);
				return;
			}
			if ((input || list) && (event.key === 'Enter' || event.key === ' ')) {
				var group = event.target.closest('.cetech-de-coverage-group');
				var results = list || (group ? group.querySelector('[data-cetech-de-search-results="' + (input.getAttribute('data-cetech-de-search-target') || 'include') + '"]') : null);
				if (!results || results.hidden) {
					return;
				}
				var active = parseInt(results.getAttribute('data-active') || '0', 10);
				var options = results.querySelectorAll('[role="option"]');
				var option = options[active] || options[0];
				if (option) {
					event.preventDefault();
					chooseResult(group, results.getAttribute('data-cetech-de-search-results'), {
						id: option.getAttribute('data-id'),
						key: option.getAttribute('data-key'),
						name: option.getAttribute('data-name') || option.textContent
					});
				}
			}
		});

		Array.prototype.forEach.call(root.querySelectorAll('.cetech-de-coverage-group'), function (group) {
			syncPanels(group);
			if (!rootKeyOf(group) && countryOf(group)) {
				loadRoots(group);
			}
		});
	}

	function postcodeRowTemplate(index, rowIndex) {
		return '<tr data-cetech-de-postcode-row><td><input type="text" class="regular-text" name="coverage_groups[' + index + '][postcodes][' + rowIndex + '][postcode_value]" /></td>' +
			'<td><select name="coverage_groups[' + index + '][postcodes][' + rowIndex + '][match_mode]"><option value="exact">Exact</option><option value="prefix">Prefix</option></select></td>' +
			'<td><button type="button" class="button-link" data-cetech-de-remove-postcode>×</button></td></tr>';
	}

	function coverageGroupTemplate(index, countries) {
		var options = '<option value="">Select…</option>';
		Object.keys(countries || {}).forEach(function (code) {
			options += '<option value="' + code + '">' + String(countries[code]).replace(/</g, '') + '</option>';
		});
		return '<fieldset class="cetech-de-coverage-group" data-cetech-de-coverage-group><legend>Coverage group ' + (index + 1) + '</legend>' +
			'<p><button type="button" class="button-link-delete" data-cetech-de-remove-coverage-group>Remove coverage group</button></p>' +
			'<p><label>Country<br /><select name="coverage_groups[' + index + '][country]" data-cetech-de-coverage-country>' + options + '</select></label></p>' +
			'<div data-cetech-de-admin-browser><p><label>Administrative area<br /><select data-cetech-de-coverage-root><option value="">Select…</option></select></label></p></div>' +
			'<p><label>Coverage <select name="coverage_groups[' + index + '][mode]" data-cetech-de-coverage-mode>' +
			'<option value="entire_area">Entire selected area</option>' +
			'<option value="selected_descendants" selected>Selected locations</option>' +
			'<option value="entire_except">Entire selected area except…</option>' +
			'</select></label></p>' +
			'<input type="hidden" name="coverage_groups[' + index + '][root_location_id]" value="" data-cetech-de-root-id />' +
			'<input type="hidden" name="coverage_groups[' + index + '][root_key]" value="" data-cetech-de-root-key />' +
			'<div data-cetech-de-include-panel>' +
			'<p><label>Include locations<br /><input type="search" class="regular-text" data-cetech-de-locality-search data-cetech-de-search-target="include" placeholder="Search…" autocomplete="off" /></label></p>' +
			'<ul class="cetech-de-coverage-results" data-cetech-de-search-results="include" role="listbox" hidden></ul>' +
			'<ul class="cetech-de-coverage-chips" data-cetech-de-chips="include"></ul>' +
			'<p class="description" data-cetech-de-include-count>0 locations included</p>' +
			'<p><button type="button" class="button" data-cetech-de-select-all>Select all</button> ' +
			'<button type="button" class="button" data-cetech-de-clear-members>Clear</button></p></div>' +
			'<div data-cetech-de-exclude-panel hidden>' +
			'<p><label>Excluded locations<br /><input type="search" class="regular-text" data-cetech-de-locality-search data-cetech-de-search-target="exclude" placeholder="Search locations to exclude…" autocomplete="off" /></label></p>' +
			'<ul class="cetech-de-coverage-results" data-cetech-de-search-results="exclude" role="listbox" hidden></ul>' +
			'<ul class="cetech-de-coverage-chips" data-cetech-de-chips="exclude"></ul>' +
			'<p class="description" data-cetech-de-exclude-count>0 locations excluded</p></div>' +
			'<div data-cetech-de-postcode-panel><p><strong>Postcode constraints</strong></p>' +
			'<table class="widefat striped" data-cetech-de-postcode-table><tbody>' + postcodeRowTemplate(index, 0) + '</tbody></table>' +
			'<p><button type="button" class="button" data-cetech-de-add-postcode>Add postcode</button></p></div></fieldset>';
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', coverageBuilder);
	} else {
		coverageBuilder();
	}

})();


