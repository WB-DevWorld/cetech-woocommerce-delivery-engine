/**
 * Classic cart quantity-split editor and pickup/delivery field disclosure.
 */
(function (document) {
	'use strict';

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

	function bindChoice(root) {
		var editor = root.closest ? root.closest('.cetech-de-cart-context') : null;
		if (!editor) {
			editor = root;
		}
		var location = editor.querySelector('[data-cetech-de-editor-location]');
		var address = editor.querySelector('[data-cetech-de-editor-address]');
		var radios = editor.querySelectorAll('input[name="cetech_de_delivery_option_key"]');
		if (!radios.length) {
			return;
		}

		function sync() {
			var selected = editor.querySelector('input[name="cetech_de_delivery_option_key"]:checked');
			var pickup = selected && selected.getAttribute('data-cetech-de-choice') === 'store_pickup';
			if (location) {
				location.hidden = !!pickup;
			}
			if (address) {
				address.hidden = !!pickup;
			}
		}

		Array.prototype.forEach.call(radios, function (input) {
			input.addEventListener('change', sync);
		});
		sync();
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-cetech-de-qty-split]'), bindSplit);
		Array.prototype.forEach.call(document.querySelectorAll('.cetech-de-cart-context'), bindChoice);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(document);
