/**
 * Authlify: "Two-factor login" section on the profile screen.
 *
 * Talks to the authlify/v1/twofactor REST routes. Inputs have no name
 * attribute, so nothing is submitted with the profile form, and Enter inside
 * them runs the matching action instead of saving the profile.
 *
 * @since 3.0.0
 */
(function (window, document) {
	'use strict';

	var cfg = window.authlifyTwoFactor;
	if (!cfg) {
		return;
	}

	var i18n = cfg.i18n || {};
	var root;
	var live;
	var status = cfg.status;
	var ui = { setup: null, codes: null };
	var uid = 0;

	/* Helpers ------------------------------------------------------------ */

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (value === null || value === undefined || value === false) {
				return;
			}
			if (key === 'text') {
				node.textContent = value;
			} else if (key === 'className') {
				// On the front end (account pages) buttons also take the theme's classes.
				if (cfg.front && /(^|\s)button(\s|$)/.test(value)) {
					value += ' wp-element-button' + (/button-primary/.test(value) ? ' alt' : '');
				}
				node.className = value;
			} else if (key.indexOf('on') === 0 && typeof value === 'function') {
				node.addEventListener(key.slice(2), value);
			} else if (value === true) {
				node.setAttribute(key, '');
			} else {
				node.setAttribute(key, value);
			}
		});
		(children || []).forEach(function (child) {
			if (child) {
				node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
			}
		});
		return node;
	}

	function id(prefix) {
		uid += 1;
		return 'authlify-2fa-' + prefix + '-' + uid;
	}

	function announce(text, isError, link) {
		live.textContent = '';
		var tone = isError === 'warning' ? 'warning' : (isError ? 'error' : 'success');
		live.className = 'authlify-2fa-profile__live' + (text ? ' notice notice-' + tone + ' inline is-' + tone : '');
		if (text) {
			var p = el('p', { text: text });
			if (link && link.url) {
				p.appendChild(document.createTextNode(' '));
				p.appendChild(el('a', { href: link.url, text: link.label }));
			}
			live.appendChild(p);
		}
	}

	function api(path, method, body) {
		var headers = { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' };
		var verb = method || 'GET';
		if (verb === 'DELETE') {
			headers['X-HTTP-Method-Override'] = 'DELETE';
			verb = 'POST';
		}

		return window.fetch(cfg.root + path, {
			method: verb,
			credentials: 'same-origin',
			headers: headers,
			body: body ? JSON.stringify(body) : undefined
		}).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (json) {
				if (!response.ok) {
					var error = new Error(json && json.message ? json.message : i18n.error);
					error.code = json && json.code ? json.code : '';
					error.data = json && json.data ? json.data : {};
					throw error;
				}
				return json;
			});
		});
	}

	function busy(button, on) {
		if (!button) {
			return;
		}
		button.disabled = !!on;
		button.setAttribute('aria-busy', on ? 'true' : 'false');
		// Some steps take a second or two (hashing backup codes): say so.
		if (on && button.tagName === 'BUTTON' && !button.getAttribute('data-label')) {
			button.setAttribute('data-label', button.textContent);
			button.textContent = i18n.working;
		} else if (!on && button.getAttribute('data-label')) {
			button.textContent = button.getAttribute('data-label');
			button.removeAttribute('data-label');
		}
	}

	function onEnter(input, fn) {
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				fn();
			}
		});
	}

	/* Sudo mode (Authlify Pro): the change needs a fresh confirmation. */
	function sudoLink(error) {
		if (error && error.code === 'authlify_sudo_required' && error.data && error.data.sudoUrl) {
			return { url: error.data.sudoUrl, label: i18n.confirmFirst };
		}
		return null;
	}

	function fail(button) {
		return function (error) {
			busy(button, false);
			announce(error && error.message ? error.message : i18n.error, true, sudoLink(error));
		};
	}

	function plural(count, one, many) {
		return sprintf(count === 1 && one ? one : many, count);
	}

	function offered(method) {
		return status && status.offered && status.offered.indexOf(method) !== -1;
	}

	function pill(on, text) {
		return el('span', { className: 'authlify-2fa-pill ' + (on ? 'is-on' : 'is-off'), text: text || (on ? i18n.on : i18n.off) });
	}

	function sprintf(text, value) {
		return String(text).replace('%s', value).replace('%d', value);
	}

	/* Icons (static markup, no user data). */
	var ICONS = {
		shield: '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm0 2.2 6 2.2V11c0 4-2.6 7.8-6 8.9-3.4-1.1-6-4.9-6-8.9V6.4l6-2.2Z"/></svg>',
		check: '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm-1.2 13.6-3.5-3.5 1.4-1.4 2.1 2.1 4.9-4.9 1.4 1.4-6.3 6.3Z"/></svg>',
		phone: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16 1H8a3 3 0 0 0-3 3v16a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3V4a3 3 0 0 0-3-3Zm1 19a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v16Zm-5-3.5a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5Z"/></svg>',
		codes: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 2v12h16V6H4Zm2 2h5v2H6V8Zm7 0h5v2h-5V8Zm-7 4h5v2H6v-2Zm7 0h5v2h-5v-2Z"/></svg>',
		key: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-7 1.6-7 4v2h11.1a6 6 0 0 1-.1-1c0-1.9.9-3.6 2.2-4.7-1.8-.2-4-.3-6.2-.3Zm12.6 3.3a3.5 3.5 0 1 0-4.1 3.4V22l1.5 1 1.5-1-1-1 1-1-1-.7v-.6a3.5 3.5 0 0 0 2.1-3.4Zm-3.5-.8a.9.9 0 1 1 0-1.8.9.9 0 0 1 0 1.8Z"/></svg>'
	};

	function icon(name, className) {
		var span = el('span', { className: className || 'authlify-2fa-method__icon', 'aria-hidden': 'true' });
		span.innerHTML = ICONS[name] || '';
		return span;
	}

	/* Rendering ---------------------------------------------------------- */

	var card;

	function render(focusSelector) {
		root.textContent = '';

		if (!status) {
			return;
		}

		card = el('div', { className: 'authlify-2fa-card' });
		root.appendChild(card);

		if (!cfg.self) {
			renderOtherUser();
		} else {
			var noMethods = !status.active && (!status.offered || !status.offered.length);
			card.appendChild(summary(status.active, status.active ? i18n.summaryOn : i18n.summaryOff, status.active ? i18n.summaryOnText : (noMethods ? i18n.noMethods : i18n.summaryOffText)));
			if (status.unavailable && status.unavailable.length) {
				card.appendChild(note(sprintf(i18n.unavailable, status.unavailable.join(', ')), 'warn'));
			}
			renderTotp();
			renderPasskeys();
			renderBackup();
			renderTurnOff();
		}

		if (focusSelector) {
			var target = root.querySelector(focusSelector);
			if (target) {
				target.focus();
			}
		}
	}

	function summary(on, title, text) {
		return el('div', { className: 'authlify-2fa-summary' + (on ? ' is-on' : ''), tabindex: '-1' }, [
			icon(on ? 'check' : 'shield', 'authlify-2fa-summary__icon'),
			el('div', { className: 'authlify-2fa-summary__text' }, [
				el('strong', { text: title }),
				el('span', { text: text })
			]),
			pill(on)
		]);
	}

	/**
	 * One method row: icon, title with a state pill, help text, action on the
	 * right, and an optional full-width body underneath.
	 */
	function section(key, title, help, state, iconName) {
		var heading = el('h3', { id: 'authlify-2fa-' + key + '-title', tabindex: '-1' }, [
			el('span', { text: title }),
			state ? pill(state.on, state.text) : null
		]);
		var action = el('div', { className: 'authlify-2fa-method__action' });
		var body = el('div', { className: 'authlify-2fa-method__body' });
		var main = el('div', { className: 'authlify-2fa-method__main' }, [
			heading,
			help ? el('p', { className: 'authlify-2fa-method__help', text: help }) : null
		]);
		var box = el('div', { className: 'authlify-2fa-method authlify-2fa-card--' + key, role: 'group', 'aria-labelledby': heading.id }, [
			icon(iconName || 'shield'),
			main,
			action,
			body
		]);
		box.main = main;
		box.action = action;
		box.body = body;
		card.appendChild(box);
		return box;
	}

	function note(text, tone) {
		return el('p', { className: 'authlify-2fa-note' + (tone ? ' is-' + tone : ''), text: text });
	}

	function renderOtherUser() {
		card.appendChild(summary(status.active, status.active ? i18n.summaryOn : i18n.summaryOff, status.active ? i18n.userOn : i18n.userOff));
		if (status.unavailable && status.unavailable.length) {
			card.appendChild(note(sprintf(i18n.unavailableOther, status.unavailable.join(', ')), 'warn'));
		}

		var hasAnything = status.totp || status.backup > 0 || (status.passkeys && status.passkeys.length);
		if (!hasAnything) {
			return;
		}

		var list = el('ul', { className: 'authlify-2fa-methods' });
		if (status.totp) {
			list.appendChild(el('li', {}, [icon('phone', 'authlify-2fa-methods__icon'), el('span', { text: i18n.totpTitle })]));
		}
		if (status.passkeys && status.passkeys.length) {
			list.appendChild(el('li', {}, [icon('key', 'authlify-2fa-methods__icon'), el('span', { text: i18n.passkeyTitle + ': ' + status.passkeys.map(function (p) { return p.name; }).join(', ') })]));
		}
		if (status.backup > 0) {
			list.appendChild(el('li', {}, [icon('codes', 'authlify-2fa-methods__icon'), el('span', { text: i18n.backupTitle + ': ' + plural(status.backup, i18n.backupLeftOne, i18n.backupLeft) })]));
		}
		card.appendChild(el('div', { className: 'authlify-2fa-method authlify-2fa-method--plain' }, [list]));

		var button = el('button', { type: 'button', className: 'button', text: i18n.resetButton });
		button.addEventListener('click', function () {
			if (!window.confirm(i18n.resetConfirm)) {
				return;
			}
			busy(button, true);
			api('reset', 'POST', { user_id: cfg.userId }).then(function (json) {
				status = json.status;
				render('.authlify-2fa-summary');
				announce(i18n.resetDone);
			}).catch(fail(button));
		});

		card.appendChild(el('div', { className: 'authlify-2fa-footer' }, [
			el('div', { className: 'authlify-2fa-footer__text' }, [
				el('strong', { text: i18n.resetTitle }),
				el('span', { text: i18n.resetHelp })
			]),
			button
		]));
	}

	/* Authenticator app ---------------------------------------------------- */

	function renderTotp() {
		if (!status.totp && !offered('totp')) {
			return;
		}

		var box = section('totp', i18n.totpTitle, i18n.totpHelp, status.totp ? { on: true, text: i18n.on } : null, 'phone');

		if (status.totp) {
			if (status.required && status.primary <= 1) {
				box.main.appendChild(note(i18n.lastRequired));
				return;
			}
			var remove = el('button', { type: 'button', className: 'button', text: i18n.remove });
			remove.setAttribute('aria-label', i18n.totpRemove);
			remove.addEventListener('click', function () {
				if (!window.confirm(i18n.totpRemoveConfirm)) {
					return;
				}
				busy(remove, true);
				api('totp', 'DELETE').then(function (json) {
					status = json.status;
					render('#authlify-2fa-totp-title');
				}).catch(fail(remove));
			});
			box.action.appendChild(remove);
			return;
		}

		if (ui.setup) {
			box.classList.add('is-open');
			box.body.appendChild(setupPanel(ui.setup));
			return;
		}

		var start = el('button', { type: 'button', className: 'button button-primary', text: i18n.totpSetup });
		start.addEventListener('click', function () {
			busy(start, true);
			api('totp/setup', 'POST').then(function (json) {
				ui.setup = json;
				render('.authlify-2fa-setup input');
			}).catch(fail(start));
		});
		box.action.appendChild(start);
	}

	function step(number, title, children) {
		return el('li', { className: 'authlify-2fa-step' }, [
			el('span', { className: 'authlify-2fa-step__num', 'aria-hidden': 'true', text: String(number) }),
			el('div', { className: 'authlify-2fa-step__main' }, [el('strong', { text: title })].concat(children))
		]);
	}

	function setupPanel(setup) {
		var qr = el('div', { className: 'authlify-2fa-qr' });
		if (typeof window.qrcode === 'function') {
			var code = window.qrcode(0, 'M');
			code.addData(setup.uri);
			code.make();
			qr.innerHTML = code.createSvgTag({ cellSize: 4, margin: 2, scalable: true, alt: i18n.totpScan });
		}

		var inputId = id('code');
		var input = el('input', {
			type: 'text',
			id: inputId,
			className: 'authlify-2fa-code',
			inputmode: 'numeric',
			autocomplete: 'one-time-code',
			maxlength: '7',
			pattern: '[0-9 ]*',
			placeholder: '000000',
			'aria-describedby': inputId + '-help'
		});
		var verify = el('button', { type: 'button', className: 'button button-primary', text: i18n.totpVerify });
		var cancel = el('button', { type: 'button', className: 'button-link', text: i18n.cancel });

		// A wrong code marks the field invalid and points it at the message.
		function invalid(on) {
			if (on) {
				input.setAttribute('aria-invalid', 'true');
				input.setAttribute('aria-describedby', inputId + '-help authlify-2fa-live');
			} else {
				input.removeAttribute('aria-invalid');
				input.setAttribute('aria-describedby', inputId + '-help');
			}
		}
		input.addEventListener('input', function () {
			invalid(false);
		});

		function submit() {
			var value = input.value.replace(/\s+/g, '');
			if (!/^\d{6}$/.test(value)) {
				invalid(true);
				input.focus();
				announce(i18n.totpInvalid, true);
				return;
			}
			busy(verify, true);
			api('totp/verify', 'POST', { code: value }).then(function (json) {
				ui.setup = null;
				status = json.status;
				if (json.codes && json.codes.length) {
					ui.codes = json.codes;
				}
				render(ui.codes ? '.authlify-2fa-codes' : '#authlify-2fa-totp-title');
				announce(i18n.totpEnabled);
			}).catch(function (error) {
				fail(verify)(error);
				invalid(true);
				input.select();
				input.focus();
			});
		}

		onEnter(input, submit);
		verify.addEventListener('click', submit);
		cancel.addEventListener('click', function () {
			ui.setup = null;
			render('#authlify-2fa-totp-title');
		});

		var key = setup.secret.replace(/(.{4})/g, '$1 ').trim();
		var keyEl = el('code', { className: 'authlify-2fa-key', text: key });
		var copyKey = el('button', { type: 'button', className: 'button button-small', text: i18n.copy });
		copyKey.addEventListener('click', function () {
			if (window.navigator.clipboard) {
				window.navigator.clipboard.writeText(setup.secret).then(function () {
					copyKey.textContent = i18n.copied;
					window.setTimeout(function () {
						copyKey.textContent = i18n.copy;
					}, 1500);
				});
			}
		});

		return el('div', { className: 'authlify-2fa-setup' }, [
			qr,
			el('ol', { className: 'authlify-2fa-steps' }, [
				step(1, i18n.stepScan, [
					el('p', { text: i18n.totpScan }),
					el('p', { className: 'authlify-2fa-keyline' }, [el('span', { className: 'authlify-2fa-keyline__label', text: i18n.totpKey }), keyEl, copyKey])
				]),
				step(2, i18n.stepCode, [
					el('p', { className: 'authlify-2fa-verify' }, [
						el('label', { 'for': inputId, className: 'screen-reader-text', text: i18n.totpCode }),
						input,
						verify,
						cancel
					]),
					el('p', { id: inputId + '-help', className: 'authlify-2fa-method__help', text: i18n.totpCodeHint })
				])
			])
		]);
	}

	/* Backup codes --------------------------------------------------------- */

	function renderBackup() {
		if (!status.backup && !offered('backup') && !ui.codes) {
			return;
		}

		var state = status.backup > 0 ? { on: status.backup > status.backupWarn, text: sprintf(i18n.backupCount, status.backup) } : null;
		var box = section('backup', i18n.backupTitle, i18n.backupHelp, state, 'codes');

		if (ui.codes) {
			box.classList.add('is-open');
			box.body.appendChild(codesPanel(ui.codes));
			return;
		}

		if (status.backup > 0 && status.backup <= status.backupWarn) {
			box.main.appendChild(note(i18n.backupLow, 'warn'));
		}

		if (!offered('backup')) {
			return;
		}

		var generate = el('button', { type: 'button', className: 'button', text: status.backup > 0 ? i18n.backupRegenerate : i18n.backupGenerate });
		generate.addEventListener('click', function () {
			if (status.backup > 0 && !window.confirm(i18n.backupRegenerateConfirm)) {
				return;
			}
			busy(generate, true);
			api('backup-codes', 'POST').then(function (json) {
				status = json.status;
				ui.codes = json.codes;
				render('.authlify-2fa-codes');
			}).catch(fail(generate));
		});
		box.action.appendChild(generate);
	}

	function codesPanel(codes) {
		var text = codes.join('\n');
		var list = el('ol', { className: 'authlify-2fa-codes', tabindex: '-1', 'aria-label': i18n.backupTitle });
		codes.forEach(function (code) {
			list.appendChild(el('li', {}, [el('code', { text: code })]));
		});

		var copy = el('button', { type: 'button', className: 'button', text: i18n.backupCopy });
		copy.addEventListener('click', function () {
			if (window.navigator.clipboard) {
				window.navigator.clipboard.writeText(text).then(function () {
					announce(i18n.backupCopied);
				});
			}
		});

		var host = window.location.hostname;
		var download = el('a', {
			className: 'button',
			href: 'data:text/plain;charset=utf-8,' + encodeURIComponent(host + '\n\n' + text + '\n'),
			download: host + '-backup-codes.txt',
			text: i18n.backupDownload
		});

		var done = el('button', { type: 'button', className: 'button button-primary', text: i18n.backupDone });
		done.addEventListener('click', function () {
			ui.codes = null;
			render('#authlify-2fa-backup-title');
		});

		return el('div', { className: 'authlify-2fa-codes-box' }, [
			note(i18n.backupShowOnce, 'warn'),
			list,
			el('p', { className: 'authlify-2fa-actions' }, [copy, download, el('span', { className: 'authlify-2fa-actions__spacer' }), done])
		]);
	}

	/* Passkeys ------------------------------------------------------------- */

	function renderPasskeys() {
		var has = status.passkeys && status.passkeys.length;
		if (!has && !offered('passkey') && cfg.passkeysSupported) {
			return;
		}

		var state = has ? { on: true, text: sprintf(status.passkeys.length === 1 ? i18n.passkeyCountOne : i18n.passkeyCount, status.passkeys.length) } : null;
		var box = section('passkeys', i18n.passkeyTitle, i18n.passkeyHelp, state, 'key');

		if (!cfg.passkeysSupported) {
			box.main.appendChild(note(i18n.passkeyPhp, 'warn'));
			return;
		}

		if (has) {
			var list = el('ul', { className: 'authlify-2fa-passkeys' });
			var lastRequired = status.required && status.primary <= 1;
			status.passkeys.forEach(function (item) {
				var rename = el('button', { type: 'button', className: 'button-link', 'aria-label': i18n.passkeyRename + ': ' + item.name, text: i18n.passkeyRename });
				rename.addEventListener('click', function () {
					var value = window.prompt(i18n.passkeyName, item.name);
					if (value === null || !value.trim() || value.trim() === item.name) {
						return;
					}
					busy(rename, true);
					api('passkeys/' + item.id, 'POST', { name: value.trim() }).then(function (json) {
						status = json.status;
						render('#authlify-2fa-passkeys-title');
						announce(i18n.passkeyRenamed);
					}).catch(fail(rename));
				});

				var remove = el('button', { type: 'button', className: 'button-link button-link-delete', 'aria-label': i18n.passkeyRemove + ': ' + item.name, text: i18n.passkeyRemove, hidden: lastRequired, title: lastRequired ? i18n.lastRequired : null });
				remove.addEventListener('click', function () {
					if (!window.confirm(sprintf(i18n.passkeyRemoveConfirm, item.name))) {
						return;
					}
					busy(remove, true);
					api('passkeys/' + item.id, 'DELETE').then(function (json) {
						status = json.status;
						render('#authlify-2fa-passkeys-title');
						announce(i18n.passkeyRemoved);
					}).catch(fail(remove));
				});

				list.appendChild(el('li', {}, [
					el('span', { className: 'authlify-2fa-passkeys__main' }, [
						el('strong', { text: item.name }),
						el('span', { className: 'authlify-2fa-passkeys__meta', text: sprintf(i18n.passkeyCreated, item.created) + ' · ' + (item.lastUsed ? sprintf(i18n.passkeyUsed, item.lastUsed) : i18n.passkeyNeverUsed) })
					]),
					el('span', { className: 'authlify-2fa-passkeys__actions' }, [rename, lastRequired ? null : remove])
				]));
			});
			box.body.appendChild(list);
			if (lastRequired) {
				box.body.appendChild(note(i18n.lastRequired));
			}
			box.classList.add('is-open');
		}

		if (!offered('passkey')) {
			return;
		}

		var api2 = window.authlifyPasskey;
		if (!api2 || !api2.supported()) {
			box.main.appendChild(note(window.isSecureContext ? i18n.passkeyBrowser : i18n.passkeyInsecure, 'warn'));
			return;
		}

		var nameId = id('passkey-name');
		var name = el('input', { type: 'text', id: nameId, className: 'regular-text', maxlength: '100', 'aria-describedby': nameId + '-hint', autocomplete: 'off', placeholder: i18n.passkeyNameHint });
		var add = el('button', { type: 'button', className: has ? 'button' : 'button button-primary', text: i18n.passkeyCreate });

		function create() {
			busy(add, true);
			announce(i18n.working);
			api('passkeys/options', 'POST').then(function (json) {
				return api2.create(json.options);
			}).then(function (response) {
				response.name = name.value.trim();
				return api('passkeys', 'POST', response);
			}).then(function (json) {
				status = json.status;
				if (json.codes && json.codes.length) {
					ui.codes = json.codes;
				}
				render(ui.codes ? '.authlify-2fa-codes' : '#authlify-2fa-passkeys-title');
				announce(ui.codes ? i18n.passkeyAddedCodes : i18n.passkeyAdded);
			}).catch(function (error) {
				busy(add, false);
				announce(api2.cancelled(error) ? i18n.passkeyCancelled : (error && error.message ? error.message : i18n.error), !api2.cancelled(error), sudoLink(error));
				add.focus();
			});
		}

		onEnter(name, create);
		add.addEventListener('click', create);

		box.classList.add('is-open');
		box.body.appendChild(el('div', { className: 'authlify-2fa-add' }, [
			el('label', { 'for': nameId, text: i18n.passkeyName }),
			el('div', { className: 'authlify-2fa-add__row' }, [name, add]),
			el('p', { id: nameId + '-hint', className: 'screen-reader-text', text: i18n.passkeyNameHint })
		]));
	}

	/* Turn everything off --------------------------------------------------- */

	function renderTurnOff() {
		if (!status.active || status.required) {
			return;
		}

		var button = el('button', { type: 'button', className: 'button-link button-link-delete', text: i18n.turnOff });
		button.addEventListener('click', function () {
			if (!window.confirm(i18n.turnOffConfirm)) {
				return;
			}
			busy(button, true);
			api('reset', 'POST', { user_id: cfg.userId }).then(function (json) {
				status = json.status;
				ui.codes = null;
				render('.authlify-2fa-summary');
				announce(i18n.statusOff);
			}).catch(fail(button));
		});

		card.appendChild(el('div', { className: 'authlify-2fa-footer authlify-2fa-off' }, [button]));
	}

	/* Boot ------------------------------------------------------------------ */

	function boot() {
		root = document.querySelector('.authlify-2fa-profile__app');
		live = document.querySelector('.authlify-2fa-profile__live');
		if (!root || !live) {
			return;
		}

		render();

		if (cfg.recovered && cfg.self) {
			announce(i18n.recovered, 'warning');
		}

		// Other sections (Authlify Pro email codes) change the same state:
		// they fire this event so the cards here never go stale.
		document.addEventListener('authlify:twofactor-changed', function () {
			api('status' + (cfg.self ? '' : '?user_id=' + cfg.userId), 'GET').then(function (json) {
				status = json;
				if (!ui.codes) {
					render();
				}
			}).catch(function () {});
		});
	}

	if (document.readyState !== 'loading') {
		boot();
	} else {
		document.addEventListener('DOMContentLoaded', boot);
	}
})(window, document);
