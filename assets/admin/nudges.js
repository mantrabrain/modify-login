/**
 * Authlify: dismissing the inline cards, and showing the Designer's card after a save.
 */
(function () {
    'use strict';

    var config = window.authlifyNudges;
    if (!config) {
        return;
    }

    function send(id, later) {
        var body = new FormData();
        body.append('action', 'authlify_dismiss_nudge');
        body.append('nonce', config.nonce);
        body.append('id', id);
        if (later) {
            body.append('later', later);
        }
        return fetch(config.url, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
    }

    function announce(text) {
        var region = document.getElementById('authlify-nudge-status');
        if (!region) {
            region = document.createElement('div');
            region.id = 'authlify-nudge-status';
            region.className = 'screen-reader-text';
            region.setAttribute('role', 'status');
            document.body.appendChild(region);
        }
        region.textContent = text;
    }

    document.addEventListener('click', function (event) {
        var target = event.target.closest && event.target.closest('[data-authlify-nudge-done], [data-authlify-nudge-later]');
        var card = target && target.closest('[data-authlify-nudge]');
        if (!card) {
            return;
        }

        var later = target.getAttribute('data-authlify-nudge-later') || '';
        var isLink = target.tagName === 'A';
        if (!isLink) {
            event.preventDefault();
        }
        var id = card.getAttribute('data-authlify-nudge');
        send(id, later);

        // An answer to the review card also ends the review line in the footer.
        if (id === 'review') {
            document.querySelectorAll('.authlify-foot').forEach(function (line) {
                line.remove();
            });
        }

        // Keep keyboard users in place: the button they used is about to go.
        var page = card.closest('.wrap') || document.body;
        card.remove();
        if (!isLink) {
            page.setAttribute('tabindex', '-1');
            page.focus({ preventScroll: true });
        }
        announce(later ? config.later : config.hidden);
    });

    // The Designer's card: revealed by a successful save, then not back for 30 days.
    var designer = document.querySelector('[data-authlify-nudge="moment-designer"][hidden]');
    if (designer && window.wp && window.wp.apiFetch && window.wp.apiFetch.use) {
        window.wp.apiFetch.use(function (options, next) {
            var result = next(options);
            var path = String(options.path || options.url || '');
            if (String(options.method || 'GET').toUpperCase() === 'POST' && /\/designer\/?(\?|$)/.test(path)) {
                result.then(function () {
                    if (designer.isConnected && designer.hidden) {
                        designer.hidden = false;
                        send('moment-designer', '30');
                    }
                }).catch(function () {});
            }
            return result;
        });
    }
})();
