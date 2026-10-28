/**
 * Progressive enhancement for scoped configuration and focused delivery editors.
 * Server-side validation remains authoritative; forms remain usable without JS.
 */
(function () {
	'use strict';

	// Only restore controls that were available before this enhancement hid them.
	var originallyDisabled = new WeakMap();

	function syncModePanels(editor) {
		var focused = editor.classList.contains('cetech-de-customize-field');
		var checked = editor.querySelector(focused
			? 'input[type="radio"]:checked'
			: 'input[type="radio"][data-mode]:checked');
		var mode = checked ? checked.value : '';
		var panels = editor.querySelectorAll(focused
			? '.cetech-de-customize-override'
			: '.cetech-de-mode-panel');

		panels.forEach(function (panel) {
			var showFor = (panel.getAttribute('data-show-for') || 'override').split(',');
			var visible = showFor.indexOf(mode) !== -1;
			panel.hidden = !visible;

			panel.querySelectorAll('input, select, textarea, button').forEach(function (control) {
				if (!originallyDisabled.has(control)) {
					originallyDisabled.set(control, control.disabled);
				}
				control.disabled = !visible || originallyDisabled.get(control);
			});
		});
	}

	function bindEditor(editor) {
		if (editor.getAttribute('data-cetech-de-mode-bound') === '1') {
			return;
		}
		editor.setAttribute('data-cetech-de-mode-bound', '1');
		editor.querySelectorAll('input[type="radio"]').forEach(function (input) {
			input.addEventListener('change', function () {
				syncModePanels(editor);
			});
		});
		syncModePanels(editor);
	}

	function bindOptionSearch(list) {
		var search = list.querySelector('[data-cetech-de-option-search]');
		var toolbar = list.querySelector('.cetech-de-option-search-controls');
		if (!search || !toolbar || list.getAttribute('data-cetech-de-search-bound') === '1') {
			return;
		}
		list.setAttribute('data-cetech-de-search-bound', '1');
		var rows = Array.from(list.querySelectorAll('.cetech-de-option-search-row'));
		var empty = list.querySelector('[data-cetech-de-search-empty]');
		toolbar.hidden = false;

		function filter() {
			var term = search.value.trim().toLocaleLowerCase();
			var anyMatches = false;
			rows.forEach(function (row) {
				var selected = row.querySelector('input[type="checkbox"]:checked');
				var matches = !term || row.textContent.toLocaleLowerCase().indexOf(term) !== -1;
				var compatibleRow = row.querySelector('.cetech-de-compatible-option');
				var compatible = !compatibleRow || !compatibleRow.hidden;
				// The wrapper owns search visibility; the inner row still owns compatibility.
				row.hidden = !matches && !selected;
				anyMatches = anyMatches || (compatible && (matches || !!selected));
			});
			if (empty) {
				empty.hidden = !term || anyMatches;
			}
		}

		search.addEventListener('input', filter);
		list.addEventListener('change', filter);
		var form = list.closest('form[data-cetech-de-customize]');
		if (form) {
			form.addEventListener('change', function () {
				// Compatibility is applied by the shared admin listener at document level.
				Promise.resolve().then(filter);
			});
		}
		filter();
	}

	function bindUnsavedStatus(form) {
		var status = form.querySelector('.cetech-de-unsaved-status');
		var label = form.getAttribute('data-unsaved-label');
		if (!status || !label || form.getAttribute('data-cetech-de-status-bound') === '1') {
			return;
		}
		form.setAttribute('data-cetech-de-status-bound', '1');
		function snapshot() {
			return JSON.stringify(Array.from(new FormData(form).entries()));
		}
		var initial = snapshot();
		function update() {
			var changed = snapshot() !== initial;
			status.textContent = changed ? label : '';
			status.hidden = !changed;
		}
		form.addEventListener('input', update);
		form.addEventListener('change', update);
		// A submit attempt does not confirm saving. Keep the marker until the server
		// navigates to its response, including slow connections and cancelled submits.
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.cetech-de-field-editor, .cetech-de-customize-field').forEach(bindEditor);
		document.querySelectorAll('.cetech-de-option-search').forEach(bindOptionSearch);
		document.querySelectorAll('form.cetech-de-customize-form[data-cetech-de-customize]').forEach(bindUnsavedStatus);
	});
})();
