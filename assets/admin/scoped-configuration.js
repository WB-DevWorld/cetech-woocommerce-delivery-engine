/**
 * Progressive enhancement for Stage 4 scoped configuration admin.
 * Server-side validation remains authoritative; forms remain usable without JS.
 */
(function () {
	'use strict';

	function syncModePanels(editor) {
		var checked = editor.querySelector('input[type="radio"][data-mode]:checked');
		var mode = checked ? checked.getAttribute('data-mode') : '';
		var panels = editor.querySelectorAll('.cetech-de-mode-panel');

		panels.forEach(function (panel) {
			var showFor = (panel.getAttribute('data-show-for') || '').split(',');
			var visible = showFor.indexOf(mode) !== -1;
			if (visible) {
				panel.removeAttribute('hidden');
			} else {
				panel.setAttribute('hidden', 'hidden');
			}

			panel.querySelectorAll('input, select, textarea').forEach(function (control) {
				control.disabled = !visible;
			});
		});
	}

	function bindEditor(editor) {
		editor.querySelectorAll('input[type="radio"][data-mode]').forEach(function (input) {
			input.addEventListener('change', function () {
				syncModePanels(editor);
			});
		});
		syncModePanels(editor);
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.cetech-de-field-editor').forEach(bindEditor);
	});
})();
