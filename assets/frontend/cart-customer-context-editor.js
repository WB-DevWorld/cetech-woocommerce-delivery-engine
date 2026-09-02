/**
 * Classic cart quantity-split editor.
 */
(function (document) {
	'use strict';

	function bind(root) {
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

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-cetech-de-qty-split]'), bind);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(document);
