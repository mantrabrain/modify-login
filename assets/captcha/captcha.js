/**
 * Authlify CAPTCHA widgets (no dependencies).
 *
 * Renders Turnstile, hCaptcha and reCAPTCHA explicitly (so several forms on
 * one page each get a widget, with a theme that follows the visitor's colour
 * scheme), keeps reCAPTCHA v3 tokens fresh, and runs the self-hosted ALTCHA
 * proof of work in a web worker. Widgets reset after a failed WooCommerce
 * checkout, because tokens are single-use.
 *
 * @since 3.0.0
 */
(function () {
    'use strict';

    var cfg = window.authlifyCaptchaConfig || {};
    var i18n = cfg.i18n || {};
    var loaded = {};

    function theme() {
        var t = cfg.theme || 'auto';
        if ('auto' !== t) {
            return t;
        }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function fail(el, text) {
        el.setAttribute('data-rendered', 'error');
        var p = document.createElement('p');
        p.className = 'authlify-captcha__error';
        p.textContent = text || i18n.error || '';
        el.appendChild(p);
    }

    /**
     * Render one widget if its provider's API is ready.
     *
     * @param {HTMLElement} el Widget element.
     */
    function render(el) {
        if (el.getAttribute('data-rendered')) {
            return;
        }
        var provider = el.getAttribute('data-provider');
        var key = el.getAttribute('data-sitekey') || '';
        var action = el.getAttribute('data-action') || '';
        var id = null;

        try {
            if ('turnstile' === provider && window.turnstile) {
                id = window.turnstile.render(el, {
                    sitekey: key,
                    theme: 'auto' === (cfg.theme || 'auto') ? 'auto' : cfg.theme,
                    size: 'flexible',
                    action: action.replace(/[^a-z0-9_-]/gi, '').slice(0, 32)
                });
            } else if ('hcaptcha' === provider && window.hcaptcha) {
                id = window.hcaptcha.render(el, { sitekey: key, theme: theme() });
            } else if ('recaptcha_v2' === provider && window.grecaptcha && window.grecaptcha.render) {
                id = window.grecaptcha.render(el, { sitekey: key, theme: theme() });
            } else if ('recaptcha_v3' === provider && window.grecaptcha && window.grecaptcha.execute) {
                v3(el, key, action);
                id = 'v3';
            } else if ('altcha' === provider) {
                altcha(el);
                id = 'altcha';
            } else {
                return;
            }
        } catch (e) {
            fail(el);
            return;
        }

        el.setAttribute('data-rendered', '1');
        el.authlifyId = id;
    }

    function renderAll(scope) {
        var list = (scope || document).querySelectorAll('.authlify-captcha__widget[data-provider]');
        for (var i = 0; i < list.length; i++) {
            render(list[i]);
        }
    }

    /* reCAPTCHA v3: fetch a token now and every 100 s (tokens expire after 2 minutes). */
    function v3(el, key, action) {
        var input = el.querySelector('input[name="g-recaptcha-response"]');
        function run() {
            window.grecaptcha.ready(function () {
                window.grecaptcha.execute(key, { action: action }).then(function (token) {
                    if (input) {
                        input.value = token;
                    }
                });
            });
        }
        el.authlifyRefresh = run;
        run();
        if (!el.authlifyTimer) {
            el.authlifyTimer = setInterval(run, 100000);
        }
    }

    /* ALTCHA ------------------------------------------------------------ */

    function getChallenge(el, fresh) {
        var embedded = el.getAttribute('data-challenge');
        if (!fresh && embedded) {
            el.removeAttribute('data-challenge');
            try {
                return Promise.resolve(JSON.parse(embedded));
            } catch (e) { /* fetch instead */ }
        }
        var url = (cfg.ajax || '/wp-admin/admin-ajax.php') + (String(cfg.ajax).indexOf('?') > -1 ? '&' : '?') + 'action=authlify_altcha&_=' + Date.now();
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
            if (!r.ok) {
                throw new Error('challenge');
            }
            return r.json();
        });
    }

    function solve(ch) {
        return new Promise(function (resolve) {
            var worker = null;
            try {
                worker = new Worker(cfg.worker);
            } catch (e) {
                worker = null;
            }
            function fallback() {
                if (typeof window.authlifyAltchaSolve === 'function') {
                    window.authlifyAltchaSolve(ch, resolve);
                } else {
                    resolve(-1);
                }
            }
            if (!worker) {
                fallback();
                return;
            }
            worker.onmessage = function (event) {
                worker.terminate();
                resolve(event.data && typeof event.data.number === 'number' ? event.data.number : -1);
            };
            worker.onerror = function () {
                worker.terminate();
                fallback();
            };
            worker.postMessage({ challenge: ch.challenge, salt: ch.salt, max: ch.maxnumber });
        });
    }

    function altcha(el) {
        var input = el.querySelector('input[name="altcha"]');
        var text = el.querySelector('.authlify-altcha__text');
        var form = el.closest && !el.hasAttribute('data-preview') ? el.closest('form') : null;
        var state = { busy: null, solvedAt: 0 };
        el.authlifyAltcha = state;

        function set(name, message, retry) {
            el.setAttribute('data-state', name);
            if (text) {
                text.textContent = message || '';
                if (retry) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'authlify-altcha__retry';
                    b.textContent = i18n.retry || 'Try again';
                    b.addEventListener('click', function () {
                        start(true);
                    });
                    text.appendChild(document.createTextNode(' '));
                    text.appendChild(b);
                }
            }
        }

        function start(fresh) {
            if (state.busy) {
                return state.busy;
            }
            input.value = '';
            state.solvedAt = 0;
            set('verifying', i18n.verifying);
            var began = Date.now();
            state.busy = getChallenge(el, fresh).then(function (ch) {
                return solve(ch).then(function (n) {
                    if (n < 0) {
                        throw new Error('unsolved');
                    }
                    input.value = btoa(JSON.stringify({
                        algorithm: ch.algorithm,
                        challenge: ch.challenge,
                        number: n,
                        salt: ch.salt,
                        signature: ch.signature,
                        took: Date.now() - began
                    }));
                    state.solvedAt = Date.now();
                    set('verified', i18n.verified);
                });
            }).catch(function () {
                set('error', i18n.failed, true);
            }).then(function () {
                state.busy = null;
            });
            return state.busy;
        }

        el.authlifyRefresh = function () {
            return start(true);
        };

        // Solutions expire with the challenge (20 minutes): refresh idle forms.
        setInterval(function () {
            if (state.solvedAt && Date.now() - state.solvedAt > 15 * 60 * 1000) {
                start(true);
            }
        }, 60000);

        // If someone submits before the check finished, wait for it, then submit.
        if (form) {
            form.addEventListener('submit', function (event) {
                if (input.value) {
                    return;
                }
                event.preventDefault();
                event.stopImmediatePropagation();
                var submitter = event.submitter || null;
                start(false).then(function () {
                    if (!input.value) {
                        return;
                    }
                    if (form.requestSubmit) {
                        form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                    } else {
                        form.submit();
                    }
                });
            }, true);
        }

        start(false);
    }

    /* Reset after a failed AJAX submission (tokens are single-use). */
    function resetAll() {
        var list = document.querySelectorAll('.authlify-captcha__widget[data-rendered="1"]');
        for (var i = 0; i < list.length; i++) {
            var el = list[i], id = el.authlifyId, p = el.getAttribute('data-provider');
            try {
                if ('turnstile' === p && window.turnstile) {
                    window.turnstile.reset(id);
                } else if ('hcaptcha' === p && window.hcaptcha) {
                    window.hcaptcha.reset(id);
                } else if ('recaptcha_v2' === p && window.grecaptcha) {
                    window.grecaptcha.reset(id);
                } else if (el.authlifyRefresh) {
                    el.authlifyRefresh();
                }
            } catch (e) { /* ignore */ }
        }
    }

    /**
     * Load a provider script on demand (settings preview).
     *
     * @param {string} provider Provider key.
     * @param {string} key      Site key (reCAPTCHA v3 needs it in the URL).
     */
    function loadApi(provider, key) {
        var url = cfg.apis && cfg.apis[provider];
        if (!url) {
            renderAll();
            return;
        }
        if ('recaptcha_v3' === provider) {
            url += (url.indexOf('?') > -1 ? '&' : '?') + 'render=' + encodeURIComponent(key);
        }
        if (loaded[url]) {
            renderAll();
            return;
        }
        loaded[url] = true;
        var s = document.createElement('script');
        s.src = url;
        s.async = true;
        s.defer = true;
        document.head.appendChild(s);
    }

    window.authlifyCaptchaOnload = function () {
        renderAll();
    };

    window.AuthlifyCaptcha = { render: renderAll, reset: resetAll, loadApi: loadApi };

    /* Boxes printed by a hook after the submit button move in front of it. */
    function place() {
        var list = document.querySelectorAll('.authlify-captcha[data-before]');
        for (var i = 0; i < list.length; i++) {
            var box = list[i], form = box.closest ? box.closest('form') : null;
            var target = form ? form.querySelector(box.getAttribute('data-before')) : null;
            if (target && target.parentNode && target !== box) {
                target.parentNode.insertBefore(box, target);
            }
            box.removeAttribute('data-before');
        }
    }

    function ready() {
        try {
            place();
        } catch (e) { /* keep the box where it was printed */ }
        renderAll();
        if (window.jQuery) {
            window.jQuery(document.body).on('checkout_error', resetAll);
        }
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
