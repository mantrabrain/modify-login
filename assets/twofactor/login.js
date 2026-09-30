/**
 * Authlify: passkeys on the login page.
 *
 * - "Sign in with a passkey" on the login form (discoverable credentials).
 * - "Use my passkey" on the two-factor step.
 *
 * @since 3.0.0
 */
(function (window, document) {
	'use strict';

	var cfg = window.authlifyLogin || {};
	var i18n = cfg.i18n || {};
	var passkey = window.authlifyPasskey;

	function say(status, text, isError) {
		if (!status) {
			return;
		}
		status.textContent = text || '';
		status.classList.toggle('is-error', !!isError);
	}

	function field(form, name, value) {
		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		form.appendChild(input);
	}

	/* Sign in with a passkey (no password). */
	function initSignIn() {
		var box = document.querySelector('.authlify-passkey-login');
		var loginForm = document.getElementById('loginform');
		if (!box || !cfg.button || !passkey || !passkey.supported()) {
			return;
		}

		// Show it below the form's submit button.
		if (loginForm && loginForm.contains(box)) {
			loginForm.appendChild(box);
		}
		box.hidden = false;

		var button = box.querySelector('.authlify-passkey-signin');
		var status = box.querySelector('.authlify-passkey-status');
		var busy = false;

		button.addEventListener('click', function () {
			if (busy) {
				return;
			}
			busy = true;
			button.disabled = true;
			say(status, i18n.waiting);

			window.fetch(cfg.optionsUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'authlify=1'
			}).then(function (response) {
				return response.json();
			}).then(function (json) {
				if (!json || !json.success) {
					throw new Error(json && json.data && json.data.message ? json.data.message : i18n.failed);
				}
				return passkey.get(json.data.options).then(function (assertion) {
					say(status, i18n.signing);

					var form = document.createElement('form');
					form.method = 'post';
					form.action = cfg.loginUrl;
					form.hidden = true;
					field(form, 'authlify_pk_token', json.data.token);
					Object.keys(assertion).forEach(function (key) {
						field(form, 'authlify_pk_' + key, assertion[key]);
					});

					if (loginForm) {
						var redirect = loginForm.querySelector('input[name="redirect_to"]');
						var remember = loginForm.querySelector('input[name="rememberme"]');
						var interim = loginForm.querySelector('input[name="interim-login"]');
						if (redirect) {
							field(form, 'redirect_to', redirect.value);
						}
						if (remember && remember.checked) {
							field(form, 'rememberme', 'forever');
						}
						if (interim) {
							field(form, 'interim-login', '1');
						}
					}

					document.body.appendChild(form);
					form.submit();
				});
			}).catch(function (error) {
				busy = false;
				button.disabled = false;
				say(status, passkey.cancelled(error) ? i18n.cancelled : (error && error.message && error.name === 'Error' ? error.message : i18n.failed), !passkey.cancelled(error));
				button.focus();
			});
		});
	}

	/* Second step: use a registered passkey. */
	function initStep() {
		var button = document.querySelector('.authlify-passkey-verify');
		if (!button) {
			return;
		}

		var form = button.form || document.getElementById('loginform');
		var status = form ? form.querySelector('.authlify-passkey-status') : null;

		if (!passkey || !passkey.supported()) {
			button.disabled = true;
			say(status, window.isSecureContext ? i18n.failed : i18n.insecure, true);
			return;
		}

		button.focus();
		button.addEventListener('click', function () {
			button.disabled = true;
			say(status, i18n.waiting);

			passkey.get(JSON.parse(button.getAttribute('data-options'))).then(function (assertion) {
				Object.keys(assertion).forEach(function (key) {
					var input = form.querySelector('input[name="authlify_pk_' + key + '"]');
					if (input) {
						input.value = assertion[key];
					}
				});
				say(status, i18n.signing);
				form.submit();
			}).catch(function (error) {
				button.disabled = false;
				say(status, passkey.cancelled(error) ? i18n.cancelled : i18n.failed, !passkey.cancelled(error));
				button.focus();
			});
		});
	}

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	ready(function () {
		initSignIn();
		initStep();
	});
})(window, document);
