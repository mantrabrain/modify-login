/**
 * Authlify CAPTCHA on the WooCommerce Checkout block.
 *
 * The box is printed after the checkout block. This script moves it in
 * front of the "Place order" row once the block has rendered (and back in if
 * the block re-renders), sends the token and honeypot fields to the Store
 * API as extension data ("authlify"), and resets the widget after a failed
 * order, because tokens are single-use.
 *
 * @since 3.1.0
 */
(function () {
    'use strict';

    var cfg = window.authlifyWcBlocks || {};
    var ns = cfg.ns || 'authlify';
    var fields = cfg.fields || {};
    var box = document.querySelector('.authlify-captcha[data-wc-blocks]');
    var STORE = 'wc/store/checkout';

    if (!box || !window.wp || !window.wp.data) {
        return;
    }

    function target() {
        return document.querySelector('.wp-block-woocommerce-checkout .wc-block-checkout__actions')
            || document.querySelector('.wp-block-woocommerce-checkout-actions-block');
    }

    function place() {
        var t = target();
        if (t && t.parentNode && box.nextSibling !== t) {
            t.parentNode.insertBefore(box, t);
            box.classList.add('authlify-captcha--wc-blocks');
            if (window.AuthlifyCaptcha) {
                window.AuthlifyCaptcha.render(box);
            }
        }
    }

    function value(name) {
        var el = box.querySelector('[name="' + name + '"]');
        return el && 'string' === typeof el.value ? el.value : '';
    }

    function collect() {
        var token = '';
        var names = fields.token || [];
        for (var i = 0; i < names.length && !token; i++) {
            token = value(names[i]);
        }
        return { token: token, hp: value(fields.hp || 'authlify_website'), ts: value(fields.ts || 'authlify_ts') };
    }

    var last = '';
    function send() {
        var dispatch = window.wp.data.dispatch(STORE);
        if (!dispatch) {
            return;
        }
        var data = collect();
        var key = JSON.stringify(data);
        if (key === last) {
            return;
        }
        last = key;
        if ('function' === typeof dispatch.setExtensionData) {
            dispatch.setExtensionData(ns, data);
        } else if ('function' === typeof dispatch.__internalSetExtensionData) {
            dispatch.__internalSetExtensionData(ns, data);
        }
    }

    // Keep the box in place and the data current.
    if (window.MutationObserver) {
        new MutationObserver(place).observe(document.body, { childList: true, subtree: true });
    }
    place();
    setInterval(send, 400);
    send();

    // A failed order used the token: get a new one.
    var wasBusy = false;
    window.wp.data.subscribe(function () {
        var select = window.wp.data.select(STORE);
        if (!select || 'function' !== typeof select.isProcessing) {
            return;
        }
        var busy = select.isProcessing() || ('function' === typeof select.isBeforeProcessing && select.isBeforeProcessing());
        if (wasBusy && !busy && 'function' === typeof select.hasError && select.hasError() && window.AuthlifyCaptcha) {
            last = '';
            window.AuthlifyCaptcha.reset();
        }
        wasBusy = busy;
    });
})();
