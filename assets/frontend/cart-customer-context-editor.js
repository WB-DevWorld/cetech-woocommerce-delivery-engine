/**
 * Classic cart quantity-split editor, pickup/delivery disclosure, destination cascade, cancel, and deep-link.
 */
(function (window, document) {
	'use strict';

	var deepLinkApplied = false;

	function bindSplit(root) {
		var radios = root.querySelectorAll('input[name="cetech_de_apply_mode"]');
		var qtyWrap = root.querySelector('.cetech-de-cart-context__split-qty');
		if (!radios.length || !qtyWrap) {
			return;
		}

		function sync() {
			var split = root.querySelector('input[name="cetech_de_apply_mode"][value="split"]');
			qtyWrap.hidden = !(split && split.checked);
		}

		Array.prototype.forEach.call(radios, function (input) {
			input.addEventListener('change', sync);
		});
		sync();
	}

	function selectedChoice(editor) {
		var select = editor.querySelector('select[name="cetech_de_delivery_option_key"]');
		if (select && select.options && select.selectedIndex >= 0) {
			var opt = select.options[select.selectedIndex];
			return opt ? String(opt.getAttribute('data-cetech-de-choice') || '') : '';
		}
		var checked = editor.querySelector('input[name="cetech_de_delivery_option_key"]:checked');
		if (checked) {
			return String(checked.getAttribute('data-cetech-de-choice') || '');
		}
		var hidden = editor.querySelector('input[name="cetech_de_delivery_option_key"][type="hidden"]');
		return hidden ? String(hidden.getAttribute('data-cetech-de-choice') || '') : '';
	}

	function syncMethodState(editor) {
		var pickup = selectedChoice(editor) === 'store_pickup';
		var location = editor.querySelector('[data-cetech-de-editor-location]');
		var address = editor.querySelector('[data-cetech-de-editor-address]');
		var recipient = editor.querySelector('[data-cetech-de-editor-recipient]');
		var useForAll = editor.querySelector('.cetech-de-cart-context__use-for-all');
		var qtySplit = editor.querySelector('[data-cetech-de-qty-split]');
		if (location) {
			location.hidden = !!pickup;
		}
		if (address) {
			address.hidden = !!pickup;
		}
		if (recipient) {
			recipient.hidden = !!pickup;
		}
		if (useForAll) {
			useForAll.hidden = !!pickup;
			useForAll.disabled = !!pickup;
		}
		if (qtySplit) {
			qtySplit.hidden = !!pickup;
		}
	}

	function bindChoice(root) {
		var editor = root.closest ? root.closest('.cetech-de-cart-context') : null;
		if (!editor) {
			editor = root;
		}
		var select = editor.querySelector('select[name="cetech_de_delivery_option_key"]');
		var radios = editor.querySelectorAll('input[name="cetech_de_delivery_option_key"]');
		if (!select && !radios.length) {
			syncMethodState(editor);
			return;
		}

		if (select) {
			select.addEventListener('change', function () {
				syncMethodState(editor);
			});
		}
		Array.prototype.forEach.call(radios, function (input) {
			input.addEventListener('change', function () {
				syncMethodState(editor);
			});
		});
		syncMethodState(editor);
	}

	function collectControls(form, root) {
		var list = [];
		function add(el) {
			if (!el || !el.name || list.indexOf(el) !== -1) {
				return;
			}
			list.push(el);
		}
		if (form && form.elements) {
			Array.prototype.forEach.call(form.elements, add);
		}
		if (root && root.querySelectorAll) {
			Array.prototype.forEach.call(root.querySelectorAll('[form]'), add);
		}
		return list;
	}

	function snapshotForm(form, root) {
		collectControls(form, root).forEach(function (el) {
			if (el.type === 'checkbox' || el.type === 'radio') {
				el.setAttribute('data-cetech-de-initial', el.checked ? '1' : '0');
			} else {
				el.setAttribute('data-cetech-de-initial', el.value);
			}
		});
	}

	function resetForm(form, root) {
		collectControls(form, root).forEach(function (el) {
			if (!el.hasAttribute('data-cetech-de-initial')) {
				return;
			}
			var initial = el.getAttribute('data-cetech-de-initial');
			if (el.type === 'checkbox' || el.type === 'radio') {
				el.checked = initial === '1';
			} else {
				el.value = initial;
			}
			if (typeof el.dispatchEvent === 'function') {
				el.dispatchEvent(new Event('change', { bubbles: true }));
			}
		});
	}

	function associatedForm(root) {
		var owned = root.querySelector('[form]');
		if (owned && owned.form) {
			return owned.form;
		}
		var formId = root.getAttribute('data-cetech-de-form-id') || (owned && owned.getAttribute('form')) || '';
		if (formId) {
			return document.getElementById(formId);
		}
		return root.querySelector('form.cetech-de-cart-context__form');
	}

	function bindCancel(root) {
		var details = root.querySelector('details.cetech-de-cart-context__editor');
		var form = associatedForm(root);
		var cancel = root.querySelector('[data-cetech-de-cancel]');
		if (!details || !form || !cancel) {
			return;
		}
		snapshotForm(form, root);
		cancel.addEventListener('click', function (event) {
			event.preventDefault();
			resetForm(form, root);
			syncMethodState(root);
			details.open = false;
			var summary = details.querySelector('summary');
			if (summary && typeof summary.focus === 'function') {
				summary.focus();
			}
		});
	}

	function isVisuallyHidden(el) {
		if (!el) {
			return true;
		}
		if (el.hidden) {
			return true;
		}
		if (el.closest && el.closest('[hidden]')) {
			return true;
		}
		return false;
	}

	function firstMissingDestinationControl(root) {
		var order = ['country', 'region', 'locality', 'postcode'];
		var i;
		for (i = 0; i < order.length; i += 1) {
			var key = order[i];
			var control = root.querySelector('[data-cetech-de-destination-control="' + key + '"]');
			if (!control || isVisuallyHidden(control)) {
				continue;
			}
			var wrap = control.closest('[data-cetech-de-field="' + key + '"], [data-cetech-de-reveal="' + key + '"]');
			if (wrap && isVisuallyHidden(wrap)) {
				continue;
			}
			if (String(control.value || '').trim() === '') {
				return control;
			}
		}
		return null;
	}

	function focusDeepLinkField(details, wrapper) {
		var host = wrapper || details;
		var hasMatching = host.getAttribute('data-cetech-de-has-matching-location') === '1';
		var destDisclosure = details.querySelector('.cetech-de-cart-context__location details.cetech-de-cart-context__disclosure');
		if (!hasMatching && destDisclosure) {
			destDisclosure.open = true;
		}
		if (!hasMatching) {
			var dest = firstMissingDestinationControl(details);
			if (dest && typeof dest.focus === 'function') {
				dest.focus();
				return;
			}
		}
		var required = details.querySelector('[data-cetech-de-required-address]');
		if (required && !isVisuallyHidden(required) && typeof required.focus === 'function') {
			required.focus();
		}
	}

	function fragmentAnchor() {
		var hash = String(window.location.hash || '').replace(/^#/, '');
		if (hash.indexOf('cetech-de-delivery-') !== 0) {
			return '';
		}
		return hash;
	}

	function prefersReducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function applyDeepLink() {
		var anchor = fragmentAnchor();
		if (!anchor) {
			return false;
		}
		if (deepLinkApplied) {
			return false;
		}
		var target = document.getElementById(anchor);
		if (!target) {
			return false;
		}
		var details = target.matches && target.matches('details')
			? target
			: target.querySelector('details.cetech-de-cart-context__editor');
		if (!details) {
			return false;
		}
		details.open = true;
		deepLinkApplied = true;
		if (typeof details.scrollIntoView === 'function') {
			details.scrollIntoView({
				behavior: prefersReducedMotion() ? 'auto' : 'smooth',
				block: 'center'
			});
		}
		focusDeepLinkField(details, target);
		return true;
	}

	/**
	 * Destination cascade inside the cart editor (C12): Country reveals Region,
	 * Region reveals City / Town, and the City field offers the same locality
	 * suggestions as the product page. The product-page script is not loaded on
	 * the cart, so the small cascade lives here against the shared markup
	 * (`data-cetech-de-reveal` wrappers, `.cetech-de-locality-results`).
	 */
	function editorConfig() {
		return window.cetechDeCartContextEditor || {};
	}

	function setReveal(locationRoot, field, visible) {
		Array.prototype.forEach.call(locationRoot.querySelectorAll('[data-cetech-de-reveal="' + field + '"]'), function (node) {
			node.hidden = !visible;
		});
	}

	function destinationControl(locationRoot, field) {
		return locationRoot.querySelector('[data-cetech-de-destination-control="' + field + '"]');
	}

	function clearControl(control) {
		if (control && control.value !== '') {
			control.value = '';
		}
	}

	function postForm(body) {
		var config = editorConfig();
		return window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) { return response.json(); }).then(function (payload) {
			return payload && payload.data ? payload.data : payload;
		});
	}

	function bindDestination(root) {
		var locationRoot = root.querySelector('[data-cetech-de-matching-location]');
		if (!locationRoot || locationRoot.getAttribute('data-cetech-de-cart-cascade') === '1') {
			return;
		}
		locationRoot.setAttribute('data-cetech-de-cart-cascade', '1');

		var country = destinationControl(locationRoot, 'country');
		var region = destinationControl(locationRoot, 'region');
		var city = destinationControl(locationRoot, 'locality');
		var postcode = destinationControl(locationRoot, 'postcode');
		var key = locationRoot.querySelector('[data-cetech-de-location-key]') || locationRoot.querySelector('input[name="cetech_de_matching_location_key"]');
		var list = locationRoot.querySelector('.cetech-de-locality-results');
		var childrenToken = 0;
		var searchToken = 0;
		var searchTimer = null;
		var regionKeysToken = 0;
		var regionKeysLoaded = '';

		function i18n(name, fallback) {
			var config = editorConfig();
			return (config.i18n && config.i18n[name]) || fallback;
		}

		function geography() {
			return editorConfig().geography || {};
		}

		function hideList() {
			if (list) {
				list.hidden = true;
				list.innerHTML = '';
			}
			if (city) {
				city.setAttribute('aria-expanded', 'false');
			}
		}

		function regionKey() {
			if (!region) {
				return '';
			}
			var selected = region.options && region.selectedIndex >= 0 ? region.options[region.selectedIndex] : null;
			var fromOption = selected ? String(selected.getAttribute('data-location-key') || '') : '';
			if (fromOption) {
				return fromOption;
			}
			var value = String(region.value || '');
			return value.indexOf('-') !== -1 && value.length > 8 ? value : '';
		}

		function loadRegions() {
			var geo = geography();
			if (!editorConfig().ajaxUrl || !geo.childrenAction || !region || region.tagName !== 'SELECT' || !country) {
				return;
			}
			var token = ++childrenToken;
			var body = new window.URLSearchParams();
			body.set('action', geo.childrenAction);
			body.set('nonce', geo.childrenNonce || '');
			body.set('country', country.value || '');
			body.set('parent_key', '');
			body.set('type', 'administrative');
			body.set('page', '1');
			body.set('request_token', String(token));
			postForm(body).then(function (data) {
				if (token !== childrenToken) {
					return;
				}
				var items = data && data.items ? data.items : [];
				if (!items.length || (data && data.skip_admin)) {
					// No administrative level for this country: go straight to the locality.
					setReveal(locationRoot, 'region', false);
					setReveal(locationRoot, 'locality', true);
					return;
				}
				region.innerHTML = '';
				var placeholder = document.createElement('option');
				placeholder.value = '';
				placeholder.textContent = i18n('select', 'Select…');
				region.appendChild(placeholder);
				items.forEach(function (item) {
					var option = document.createElement('option');
					option.value = item.code || item.key || item.name || '';
					option.setAttribute('data-location-key', item.key || '');
					option.textContent = item.name || '';
					region.appendChild(option);
				});
				region.value = '';
				regionKeysLoaded = country.value;
				setReveal(locationRoot, 'region', true);
				setReveal(locationRoot, 'locality', false);
			}).catch(function () { /* keep the rendered options */ });
		}

		function annotateRegionKeys() {
			// The server renders the Region select from WooCommerce state codes, which carry no
			// canonical location key. Fetch the country's administrative areas once and mark the
			// options by code (or name) so searches and saves can use the canonical key.
			var geo = geography();
			if (!editorConfig().ajaxUrl || !geo.childrenAction || !region || region.tagName !== 'SELECT' || !country || !country.value) {
				return Promise.resolve();
			}
			if (regionKeysLoaded === country.value) {
				return Promise.resolve();
			}
			var token = ++regionKeysToken;
			var body = new window.URLSearchParams();
			body.set('action', geo.childrenAction);
			body.set('nonce', geo.childrenNonce || '');
			body.set('country', country.value || '');
			body.set('parent_key', '');
			body.set('type', 'administrative');
			body.set('page', '1');
			body.set('request_token', String(token));
			return postForm(body).then(function (data) {
				if (token !== regionKeysToken) {
					return;
				}
				var items = data && data.items ? data.items : [];
				var byCode = {};
				var byName = {};
				items.forEach(function (item) {
					if (item.code) {
						byCode[String(item.code).toUpperCase()] = item.key || '';
					}
					if (item.name) {
						byName[String(item.name).trim().toLowerCase()] = item.key || '';
					}
				});
				Array.prototype.forEach.call(region.options, function (option) {
					if (!option.value || option.getAttribute('data-location-key')) {
						return;
					}
					var key = byCode[String(option.value).toUpperCase()] || byName[String(option.textContent || '').trim().toLowerCase()] || '';
					if (key) {
						option.setAttribute('data-location-key', key);
					}
				});
				regionKeysLoaded = country.value;
			}).catch(function () { /* the search stays country-wide */ });
		}

		function selectLocality(item) {
			if (city) {
				city.value = item.name || item.label || '';
			}
			if (key) {
				key.value = item.key || '';
			}
			hideList();
			if (city && typeof city.focus === 'function') {
				city.focus();
			}
		}

		function renderResults(items, hasMore, page, query) {
			if (!list) {
				return;
			}
			list.innerHTML = '';
			items.forEach(function (item, index) {
				var li = document.createElement('li');
				li.setAttribute('role', 'option');
				li.setAttribute('data-key', item.key || '');
				li.setAttribute('aria-selected', 'false');
				li.tabIndex = 0;
				li.textContent = item.label || item.name || '';
				li.addEventListener('click', function () { selectLocality(item); });
				li.addEventListener('keydown', function (event) {
					if (event.key === 'Enter' || event.key === ' ') {
						event.preventDefault();
						selectLocality(item);
					} else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
						event.preventDefault();
						var options = list.querySelectorAll('[role="option"]');
						var next = event.key === 'ArrowDown' ? index + 1 : index - 1;
						if (next < 0) {
							next = options.length - 1;
						}
						if (next >= options.length) {
							next = 0;
						}
						options[next].focus();
					} else if (event.key === 'Escape') {
						hideList();
						if (city) {
							city.focus();
						}
					}
				});
				list.appendChild(li);
			});
			if (hasMore) {
				var more = document.createElement('button');
				more.type = 'button';
				more.className = 'button-link';
				more.setAttribute('data-cetech-de-locality-more', '1');
				more.textContent = i18n('loadMore', 'Load more');
				more.addEventListener('click', function (event) {
					event.preventDefault();
					searchLocalities(query, page + 1, items);
				});
				list.appendChild(more);
			}
			list.hidden = items.length === 0;
			if (city) {
				city.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
			}
		}

		function searchLocalities(query, page, previous) {
			var geo = geography();
			if (!editorConfig().ajaxUrl || !geo.searchAction || !list) {
				return;
			}
			page = page || 1;
			var token = ++searchToken;
			function run() {
				if (token !== searchToken) {
					return;
				}
				var body = new window.URLSearchParams();
				body.set('action', geo.searchAction);
				body.set('nonce', geo.searchNonce || '');
				body.set('country', country ? country.value || '' : '');
				body.set('parent_key', regionKey());
				body.set('q', query || '');
				body.set('page', String(page));
				body.set('request_token', String(token));
				postForm(body).then(function (data) {
					if (token !== searchToken) {
						return;
					}
					var items = (previous || []).concat(data && data.items ? data.items : []);
					renderResults(items, !!(data && data.has_more), page, query);
				}).catch(function () { /* keep */ });
			}
			// The server renders WooCommerce state codes without canonical keys; resolve the
			// chosen Region once so the locality search is narrowed to it instead of the country.
			if (regionKey() === '' && region && region.value) {
				annotateRegionKeys().then(run, run);
			} else {
				run();
			}
		}

		if (country) {
			country.addEventListener('change', function () {
				clearControl(region);
				clearControl(city);
				clearControl(key);
				clearControl(postcode);
				hideList();
				var has = !!country.value;
				setReveal(locationRoot, 'region', has);
				setReveal(locationRoot, 'locality', false);
				setReveal(locationRoot, 'postcode', false);
				if (has) {
					loadRegions();
				}
			});
		}

		if (region) {
			region.addEventListener('change', function () {
				clearControl(city);
				clearControl(key);
				hideList();
				setReveal(locationRoot, 'locality', !!region.value);
			});
		}

		if (city) {
			city.addEventListener('input', function () {
				clearControl(key);
				window.clearTimeout(searchTimer);
				var query = String(city.value || '').trim();
				if (query === '') {
					hideList();
					return;
				}
				searchTimer = window.setTimeout(function () {
					searchLocalities(query, 1, []);
				}, 280);
			});
			city.addEventListener('keydown', function (event) {
				if (!list || list.hidden) {
					return;
				}
				var first = list.querySelector('[role="option"]');
				if (event.key === 'ArrowDown' && first) {
					event.preventDefault();
					first.focus();
				} else if (event.key === 'Escape') {
					hideList();
				}
			});
		}

		// Mirror the server's initial state so a stored destination keeps its fields visible.
		if (country && country.value) {
			setReveal(locationRoot, 'region', true);
			if (region && region.value) {
				setReveal(locationRoot, 'locality', true);
			}
		}
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-cetech-de-qty-split]'), bindSplit);
		Array.prototype.forEach.call(document.querySelectorAll('.cetech-de-cart-context'), function (root) {
			bindChoice(root);
			bindCancel(root);
			bindDestination(root);
		});
		applyDeepLink();
	}

	window.addEventListener('hashchange', function () {
		deepLinkApplied = false;
		applyDeepLink();
	});

	window.CetechDeCartContextEditor = {
		bindSplit: bindSplit,
		bindChoice: bindChoice,
		bindCancel: bindCancel,
		syncMethodState: syncMethodState,
		bindDestination: bindDestination,
		setReveal: setReveal,
		applyDeepLink: applyDeepLink,
		fragmentAnchor: fragmentAnchor,
		resetDeepLink: function () {
			deepLinkApplied = false;
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(window, document);
