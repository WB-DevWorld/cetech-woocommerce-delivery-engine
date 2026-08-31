/**
 * Product-page fulfilment switcher (Delivery vs Store Pickup).
 *
 * Does not calculate prices or invent options. Toggles visible display_key radios
 * so only the active fulfilment choice is submitted.
 */
(function (window, document) {
	'use strict';

	function panelRadios(panel) {
		return panel ? Array.prototype.slice.call(panel.querySelectorAll('input[type="radio"][name]')) : [];
	}

	function setPanelActive(root, choice) {
		var panels = root.querySelectorAll('[data-cetech-de-choice-panel]');
		Array.prototype.forEach.call(panels, function (panel) {
			var active = panel.getAttribute('data-cetech-de-choice-panel') === choice;
			var radios = panelRadios(panel).filter(function (input) {
				return input.getAttribute('data-cetech-de-choice-switch') !== '1';
			});

			if (active) {
				panel.hidden = false;
				radios.forEach(function (input) {
					input.disabled = false;
					input.required = true;
				});
				if (radios.length === 1) {
					radios[0].checked = true;
				}
			} else {
				panel.hidden = true;
				radios.forEach(function (input) {
					input.checked = false;
					input.disabled = true;
					input.required = false;
				});
			}
		});
	}

	function bind(root) {
		if (!root || root.getAttribute('data-cetech-de-switch-bound') === '1') {
			return;
		}

		var switches = root.querySelectorAll('[data-cetech-de-choice-switch]');
		if (!switches.length) {
			root.setAttribute('data-cetech-de-switch-bound', '1');
			return;
		}

		Array.prototype.forEach.call(switches, function (input) {
			input.addEventListener('change', function () {
				if (!input.checked) {
					return;
				}
				setPanelActive(root, input.value);
			});
		});

		var current = root.querySelector('[data-cetech-de-choice-switch]:checked');
		setPanelActive(root, current ? current.value : 'delivery');
		root.setAttribute('data-cetech-de-switch-bound', '1');
	}

	function bindAll(scope) {
		var root = scope || document;
		var nodes = root.querySelectorAll ? root.querySelectorAll('[data-cetech-de-selector]') : [];
		Array.prototype.forEach.call(nodes, bind);
		if (root.getAttribute && root.getAttribute('data-cetech-de-selector')) {
			bind(root);
		}
	}

	window.CetechDeProductDeliverySelector = {
		bind: bind,
		bindAll: bindAll,
		setPanelActive: setPanelActive,
	};

	function boot() {
		bindAll(document);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(window, document);
