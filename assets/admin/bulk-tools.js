(function () {
	var root = document.querySelector('.cetech-de-bulk-form, .cetech-de-admin-page');
	if (!root || typeof cetechDeBulk === 'undefined') {
		return;
	}
	var status = document.querySelector('[data-cetech-de-job-id]');
	if (!status) {
		return;
	}
	var jobId = status.getAttribute('data-cetech-de-job-id');
	var interval = window.setInterval(function () {
		var body = new URLSearchParams();
		body.set('action', cetechDeBulk.action);
		body.set('nonce', cetechDeBulk.nonce);
		body.set('job_id', jobId);
		fetch(cetechDeBulk.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data) {
				return;
			}
			status.textContent = payload.data.code + ' · ' + payload.data.status + ' · ' + payload.data.processed + ' / ' + payload.data.total;
			if (payload.data.terminal) {
				window.clearInterval(interval);
			}
		}).catch(function () {
			window.clearInterval(interval);
		});
	}, 5000);
})();
