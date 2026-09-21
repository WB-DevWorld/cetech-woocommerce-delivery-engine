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

	function bindChoice(root) {
		var editor = root.closest ? root.closest('.cetech-de-cart-context') : null;
		if (!editor) {
			editor = root;
		}
		var location = editor.querySelector('[data-cetech-de-editor-location]');
		var address = editor.querySelector('[data-cetech-de-editor-address]');
		var recipient = editor.querySelector('[data-cetech-de-editor-recipient]');
		var select = editor.querySelector('select[name="cetech_de_delivery_option_key"]');
		var radios = editor.querySelectorAll('input[name="cetech_de_delivery_option_key"]');
		if (!select && !radios.length) {
			return;
		}

		function sync() {
			var pickup = selectedChoice(editor) === 'store_pickup';
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

		if (select) {
			select.addEventListener('change', sync);
		}
		Array.prototype.forEach.call(radios, function (input) {
			input.addEventListener('change', sync);
		});
		sync();
	}

	function snapshotForm(form) {
		if (!form || !form.elements) {
			return;
		}
		Array.prototype.forEach.call(form.elements, function (el) {
			if (!el || !el.name) {
				return;
			}
			if (el.type === 'checkbox' || el.type === 'radio') {
				el.setAttribute('data-cetech-de-initial', el.checked ? '1' : '0');
			} else {
				el.setAttribute('data-cetech-de-initial', el.value);
			}
		});
	}

	function resetForm(form) {
		if (!form || !form.elements) {
			return;
		}
		Array.prototype.forEach.call(form.elements, function (el) {
			if (!el || !el.hasAttribute('data-cetech-de-initial')) {
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

	function bindCancel(root) {
		var details = root.querySelector('details.cetech-de-cart-context__editor');
		var form = root.querySelector('form.cetech-de-cart-context__form');
		var cancel = root.querySelector('[data-cetech-de-cancel]');
		if (!details || !form || !cancel) {
			return;
		}
		snapshotForm(form);
		cancel.addEventListener('click', function (event) {
			event.preventDefault();
			resetForm(form);
			details.open = false;
			var summary = details.querySelector('summary');
			if (summary && typeof summary.focus === 'function') {
				summary.focus();
			}
		});
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
		var required = details.querySelector('[data-cetech-de-required-address]');
		if (required && !required.closest('[hidden]') && typeof required.focus === 'function') {
			required.focus();
		}
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
