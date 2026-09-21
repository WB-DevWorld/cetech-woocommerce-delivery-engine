/**
 * Classic cart quantity-split editor, pickup/delivery disclosure, cancel, and deep-link.
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
		if (location) {
			location.hidden = !!pickup;
		}
		if (address) {
			address.hidden = !!pickup;
		}
		if (recipient) {
			recipient.hidden = !!pickup;
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

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-cetech-de-qty-split]'), bindSplit);
		Array.prototype.forEach.call(document.querySelectorAll('.cetech-de-cart-context'), function (root) {
			bindChoice(root);
			bindCancel(root);
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
