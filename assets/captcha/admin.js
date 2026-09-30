/**
 * Authlify CAPTCHA settings: show the fields for the chosen provider, and a
 * live preview of the widget with the typed site key.
 *
 * @since 3.0.0
 */
(function () {
    'use strict';

    var t = window.authlifyCaptchaAdmin || {};

    function provider() {
        var checked = document.querySelector('input[name="authlify[captcha_provider]"]:checked');
        return checked ? checked.value : 'none';
    }

    function sync() {
        var current = provider();
        var list = document.querySelectorAll('[data-authlify-captcha-show]');
        for (var i = 0; i < list.length; i++) {
            var show = (' ' + list[i].getAttribute('data-authlify-captcha-show') + ' ').indexOf(' ' + current + ' ') > -1;
            list[i].hidden = !show;
        }
    }

    function message(box, text) {
        var p = document.createElement('p');
        p.className = 'description';
        p.textContent = text;
        box.appendChild(p);
    }

    function preview() {
        var box = document.getElementById('authlify-captcha-preview');
        var keyInput = document.getElementById('authlify-captcha_site_key');
        if (!box) {
            return;
        }
        var p = provider();
        var key = keyInput ? keyInput.value.trim() : '';
        box.innerHTML = '';

        if ('none' === p) {
            message(box, t.none || '');
            return;
        }
        if ('altcha' !== p && !key) {
            message(box, t.needKey || '');
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'authlify-captcha authlify-captcha--' + p;
        var el = document.createElement('div');
        el.className = 'authlify-captcha__widget';
        el.setAttribute('data-provider', p);
        el.setAttribute('data-sitekey', key);
        el.setAttribute('data-action', 'preview');
        el.setAttribute('data-preview', '1');

        if ('altcha' === p) {
            el.className += ' authlify-altcha';
            el.setAttribute('data-state', 'idle');
            el.innerHTML = '<span class="authlify-altcha__icon" aria-hidden="true"></span><span class="authlify-altcha__text" role="status" aria-live="polite"></span><input type="hidden" name="altcha" form="authlify-captcha-none" value="">';
        } else if ('recaptcha_v3' === p) {
            el.innerHTML = '<input type="hidden" name="g-recaptcha-response" value="">';
            message(box, t.v3 || '');
        }

        wrap.appendChild(el);
        box.appendChild(wrap);

        if (window.AuthlifyCaptcha) {
            window.AuthlifyCaptcha.loadApi(p, key);
            window.AuthlifyCaptcha.render(box);
        }
    }

    document.addEventListener('change', function (event) {
        if (event.target && 'authlify[captcha_provider]' === event.target.name) {
            sync();
            var box = document.getElementById('authlify-captcha-preview');
            if (box) {
                box.innerHTML = '';
            }
        }
    });

    document.addEventListener('click', function (event) {
        if (event.target && 'authlify-captcha-preview-button' === event.target.id) {
            event.preventDefault();
            preview();
        }
    });

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', sync);
    } else {
        sync();
    }
})();
