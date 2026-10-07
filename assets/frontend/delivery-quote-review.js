(function (window, document) {
	'use strict';
	var namespace = 'cetech-delivery-quote-review';
	var config = window.cetechDeQuoteReview || {};
	var statuses = ['no_quote', 'review_required', 'confirmed', 'expired', 'changed', 'unconfirmed', 'unavailable'];
	var busy = false;
	var pendingTransport = null;
	var lastSignature = null;
	var messages = {
		no_quote: 'Refresh delivery to review the current price.',
		review_required: 'Review the delivery price, then confirm it.',
		confirmed: 'Delivery price confirmed for these delivery details.',
		expired: 'This delivery quote has expired. Refresh delivery and review the new price.',
		changed: 'Your delivery details changed. Review delivery again.',
		unconfirmed: 'We could not confirm this quote. Retry the same request.',
		unavailable: 'Delivery quoting is temporarily unavailable. Try again.'
	};

	function exact(value, keys) {
		return value && typeof value === 'object' && !Array.isArray(value)
			&& Object.keys(value).length === keys.length && keys.every(function (key) { return Object.prototype.hasOwnProperty.call(value, key); });
	}
	function text(value, max) {
		return typeof value === 'string' && value.length > 0 && value.length <= max && !/[\u0000-\u001f\u007f<>]/.test(value);
	}
	function uuid(value) { return typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value); }
	function money(value) {
		return exact(value, ['amount', 'currency', 'precision']) && typeof value.amount === 'string'
			&& /^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,6}))?$/.test(value.amount)
			&& typeof value.currency === 'string' && /^[A-Z]{3}$/.test(value.currency)
			&& Number.isInteger(value.precision) && value.precision >= 0 && value.precision <= 6
			&& (value.amount.split('.')[1] || '').length <= value.precision;
	}
	function component(value) {
		return exact(value, ['customer_label', 'list_price', 'promotion', 'final_price', 'tax', 'rounded_tax', 'total', 'display_total'])
			&& text(value.customer_label, 120) && money(value.list_price) && money(value.final_price) && money(value.tax) && money(value.total)
			&& (value.rounded_tax === null || money(value.rounded_tax)) && (value.display_total === null || money(value.display_total))
			&& exact(value.promotion, ['state', 'amount']) && ['none', 'applied', 'unavailable'].indexOf(value.promotion.state) !== -1
			&& (value.promotion.state === 'unavailable' ? value.promotion.amount === null : money(value.promotion.amount));
	}
	function quote(value) {
		return exact(value, ['contract_version', 'decision_kind', 'quote_id', 'status', 'currently_applicable', 'expires_at', 'customer_label', 'money', 'reason_code', 'recovery_action', 'correlation_id'])
			&& value.contract_version === 1 && ['delivery_quote', 'delivery_estimate'].indexOf(value.decision_kind) !== -1 && uuid(value.quote_id)
			&& ['issued', 'accepted', 'invalidated', 'expired', 'stripped'].indexOf(value.status) !== -1
			&& typeof value.currently_applicable === 'boolean' && text(value.customer_label, 120)
			&& typeof value.expires_at === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/.test(value.expires_at) && Number.isFinite(Date.parse(value.expires_at))
			&& Array.isArray(value.money) && value.money.length <= 200 && value.money.every(component)
			&& [null, 'quote_expired', 'quote_invalidated', 'quote_unavailable'].indexOf(value.reason_code) !== -1
			&& [null, 'refresh_and_review', 'retry_later'].indexOf(value.recovery_action) !== -1 && uuid(value.correlation_id);
	}
	function validFacts(value) {
		return exact(value, ['contract_version', 'status', 'generation', 'quote', 'can_refresh', 'can_confirm', 'can_retry', 'message_code', 'correlation_id'])
			&& value.contract_version === 1 && statuses.indexOf(value.status) !== -1 && value.message_code === value.status
			&& Number.isSafeInteger(value.generation) && value.generation >= 0 && (value.quote === null || quote(value.quote))
			&& typeof value.can_refresh === 'boolean' && typeof value.can_confirm === 'boolean' && typeof value.can_retry === 'boolean' && uuid(value.correlation_id);
	}
	function button(action, label, disabled) {
		var el = document.createElement('button'); el.type = 'button'; el.dataset.quoteReviewAction = action; el.textContent = label; el.disabled = disabled; return el;
	}
	function expired(facts) { return facts.quote !== null && Date.now() >= Date.parse(facts.quote.expires_at); }
	function render(mount, facts) {
		if (!mount || !validFacts(facts)) { return false; }
		mount.replaceChildren(); mount.dataset.quoteReviewGeneration = String(facts.generation);
		var message = document.createElement('p'); message.className = 'cetech-de-quote-review-message'; message.setAttribute('role', 'status'); message.setAttribute('aria-live', 'polite');
		message.textContent = pendingTransport ? messages.unconfirmed : messages[facts.message_code]; mount.appendChild(message);
		if (facts.quote) {
			var prices = document.createElement('ul'); prices.className = 'cetech-de-quote-review-money';
			facts.quote.money.forEach(function (part) {
				var item = document.createElement('li'); var amount = part.display_total || part.total; var bold = document.createElement('strong');
				item.appendChild(document.createTextNode(part.customer_label + ': ')); bold.textContent = amount.currency + ' ' + amount.amount; item.appendChild(bold); prices.appendChild(item);
			}); mount.appendChild(prices);
			var hold = document.createElement('p'); hold.className = 'cetech-de-quote-review-expiry';
			hold.appendChild(document.createTextNode('Delivery price is held until ')); var time = document.createElement('time'); time.dateTime = facts.quote.expires_at;
			time.textContent = new Date(facts.quote.expires_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }); hold.appendChild(time);
			hold.appendChild(document.createTextNode('. Delivery details and price rules must stay unchanged.')); mount.appendChild(hold);
		}
		var actions = document.createElement('div'); actions.className = 'cetech-de-quote-review-actions';
		if (pendingTransport) { actions.appendChild(button('transport_retry', 'Retry same request', busy)); }
		else {
			if (facts.can_refresh) { actions.appendChild(button('refresh', 'Refresh delivery', busy)); }
			if (facts.can_confirm && facts.status === 'review_required' && facts.quote && facts.quote.currently_applicable) { actions.appendChild(button('confirm', 'Confirm delivery price', busy || expired(facts))); }
			if (facts.can_retry) { actions.appendChild(button('retry', 'Retry same request', busy)); }
		}
		mount.appendChild(actions); mount._cetechQuoteFacts = facts; return true;
	}
	function storeFacts() {
		try {
			var store = window.wp && window.wp.data && window.wp.data.select('wc/store/cart');
			var cart = store && store.getCartData(); var facts = cart && cart.extensions && cart.extensions[namespace];
			return validFacts(facts) ? facts : null;
		} catch (error) { return null; }
	}
	function renderBlocks() {
		var facts = storeFacts(); if (!facts) { return; }
		var host = document.querySelector('.wp-block-woocommerce-checkout-order-summary-block, .wp-block-woocommerce-cart-order-summary-block, .wc-block-components-sidebar');
		if (!host) { return; }
		var mount = document.getElementById('cetech-de-quote-review-blocks');
		if (!mount) { mount = document.createElement('section'); mount.id = 'cetech-de-quote-review-blocks'; mount.className = 'cetech-de-quote-review'; mount.dataset.quoteReviewTransport = 'blocks'; mount.setAttribute('aria-label', 'Delivery price review'); host.appendChild(mount); }
		var signature = JSON.stringify(facts) + String(busy) + String(!!pendingTransport);
		if (signature !== lastSignature) { render(mount, facts); lastSignature = signature; }
	}
	function hydrateClassic() {
		document.querySelectorAll('[data-quote-review-transport="classic"]').forEach(function (mount) {
			try { var facts = JSON.parse(mount.getAttribute('data-quote-review-facts') || 'null'); render(mount, facts); } catch (error) { /* Keep the fixed server view. */ }
		});
	}
	function reviewToken() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') { return window.crypto.randomUUID(); }
		if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') { throw new Error('Quote review unavailable.'); }
		var bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
		var hex = Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
		return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
	}
	function command(action, facts) {
		if (!validFacts(facts) || ['refresh', 'confirm', 'retry'].indexOf(action) === -1 || !facts['can_' + action]) { throw new Error('Quote review unavailable.'); }
		if (action === 'confirm' && (facts.status !== 'review_required' || !facts.quote || !facts.quote.currently_applicable || expired(facts))) { throw new Error('Quote review unavailable.'); }
		var data = { action: action, generation: facts.generation }; if (action === 'refresh') { data.review_token = reviewToken(); } return data;
	}
	function transport(kind, data) {
		if (kind === 'blocks') {
			var native = window.wc && window.wc.blocksCheckout;
			if (!native || typeof native.extensionCartUpdate !== 'function') { return Promise.reject(new Error('Quote review unavailable.')); }
			return Promise.resolve(native.extensionCartUpdate({ namespace: namespace, data: data }));
		}
		if (kind !== 'classic' || typeof config.ajaxUrl !== 'string' || !config.ajaxUrl || typeof config.nonce !== 'string' || !config.nonce || typeof window.fetch !== 'function') { return Promise.reject(new Error('Quote review unavailable.')); }
		var body = new URLSearchParams(); Object.keys(data).forEach(function (key) { body.set(key, String(data[key])); }); body.set('_wpnonce', config.nonce);
		return window.fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body.toString() })
			.then(function (response) { if (!response.ok) { throw new Error('Quote review unavailable.'); } return response.json(); })
			.then(function (response) { if (!response || response.success !== true || !validFacts(response.data)) { throw new Error('Quote review unavailable.'); } return response.data; });
	}
	function submit(mount, action) {
		if (busy || !mount || !validFacts(mount._cetechQuoteFacts)) { return Promise.resolve(false); }
		var data; var kind = mount.dataset.quoteReviewTransport;
		try { data = action === 'transport_retry' && pendingTransport ? pendingTransport.data : command(action, mount._cetechQuoteFacts); }
		catch (error) { return Promise.resolve(false); }
		if (pendingTransport && (kind !== pendingTransport.kind || action !== 'transport_retry')) { return Promise.resolve(false); }
		busy = true; render(mount, mount._cetechQuoteFacts);
		return transport(kind, data).then(function (facts) {
			busy = false; pendingTransport = null;
			if (kind === 'classic') { render(mount, facts); mount.dataset.quoteReviewFacts = JSON.stringify(facts); }
			else { lastSignature = null; renderBlocks(); }
			return true;
		}, function () { busy = false; pendingTransport = { kind: kind, data: data }; render(mount, mount._cetechQuoteFacts); return false; });
	}
	function boot() {
		hydrateClassic(); renderBlocks();
		document.addEventListener('click', function (event) {
			var target = event.target instanceof window.Element ? event.target.closest('[data-quote-review-action]') : null;
			if (!target) { return; } var mount = target.closest('.cetech-de-quote-review'); if (!mount) { return; }
			event.preventDefault(); submit(mount, target.dataset.quoteReviewAction);
		});
		if (window.wp && window.wp.data && typeof window.wp.data.subscribe === 'function') { window.wp.data.subscribe(renderBlocks); }
		if (window.jQuery) { window.jQuery(document.body).on('updated_checkout updated_cart_totals', hydrateClassic); }
		// This timer disables a visibly expired confirmation only. Server DB time remains authoritative.
		window.setInterval(function () {
			document.querySelectorAll('.cetech-de-quote-review').forEach(function (mount) {
				var facts = mount._cetechQuoteFacts; if (validFacts(facts) && expired(facts)) { var confirm = mount.querySelector('[data-quote-review-action="confirm"]'); if (confirm) { confirm.disabled = true; } }
			});
		}, 1000);
	}
	window.CetechDeQuoteReview = { namespace: namespace, validFacts: validFacts, render: render, renderBlocks: renderBlocks, hydrateClassic: hydrateClassic, command: command, submit: submit };
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot, { once: true }); } else { boot(); }
})(window, document);
