/**
 * Authlify admin helpers (no dependencies).
 */
(function () {
    'use strict';

    // Copy buttons: <button data-authlify-copy="#selector">.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-authlify-copy]');
        if (!button) {
            return;
        }
        var source = document.querySelector(button.getAttribute('data-authlify-copy'));
        if (!source || !navigator.clipboard) {
            return;
        }
        navigator.clipboard.writeText(source.textContent.trim()).then(function () {
            var label = button.textContent;
            button.textContent = (window.authlifyAdmin && window.authlifyAdmin.copied) || 'Copied';
            setTimeout(function () {
                button.textContent = label;
            }, 1500);
        });
    });
})();

/**
 * Rows with data-authlify-show-if="name=value" only show while that control has that value.
 */
(function () {
    'use strict';

    function sync() {
        document.querySelectorAll('[data-authlify-show-if]').forEach(function (row) {
            var rule = row.getAttribute('data-authlify-show-if');
            var i = rule.lastIndexOf('=');
            var name = rule.slice(0, i);
            var want = rule.slice(i + 1);
            var inputs = document.querySelectorAll('[name="' + name + '"]');
            var value = '';
            inputs.forEach(function (input) {
                if ((input.type === 'radio' || input.type === 'checkbox') ? input.checked : true) {
                    value = input.type === 'checkbox' ? '1' : input.value;
                }
            });
            row.hidden = value !== want;
        });
    }

    document.addEventListener('change', sync);
    document.addEventListener('DOMContentLoaded', sync);
})();

/**
 * "Search settings…": filters the rows and cards of the current section, and
 * lists other sections whose name or keywords match (they load on click).
 */
(function () {
    'use strict';

    var cfg = window.authlifyAdmin || {};

    function text(el) {
        return (el.textContent || '').replace(/\s+/g, ' ').toLowerCase();
    }

    function init() {
        var input = document.getElementById('authlify-settings-search');
        if (!input) {
            return;
        }
        var page = input.closest('.authlify-page');
        var empty = document.getElementById('authlify-settings-empty');
        var status = document.getElementById('authlify-settings-status');
        var none = empty.querySelector('[data-authlify-search-none]');
        var other = empty.querySelector('[data-authlify-search-other]');
        var list = other.querySelector('ul');
        var links = page.querySelectorAll('.authlify-side__link');

        // Cards and loose blocks of the section (not the page head, nav or search).
        function blocks() {
            var out = [];
            Array.prototype.forEach.call(page.children, function (child) {
                if (child.matches('.authlify-page-head, .authlify-side, .authlify-setsearch, .authlify-setsearch__empty, .authlify-section-head, #authlify-settings-status, hr, script, style, .notice')) {
                    return;
                }
                out.push(child);
            });

            return out;
        }

        function reset() {
            page.querySelectorAll('.is-filtered').forEach(function (el) {
                el.classList.remove('is-filtered');
            });
            links.forEach(function (a) {
                a.classList.remove('is-match');
            });
            empty.hidden = true;
            status.textContent = '';
        }

        function run() {
            var q = input.value.trim().toLowerCase();
            reset();
            if (!q) {
                return;
            }

            var hits = 0;
            blocks().forEach(function (block) {
                var panels = block.matches('.authlify-panel') ? [block] : Array.prototype.slice.call(block.querySelectorAll('.authlify-panel'));
                if (!panels.length) {
                    if (block.matches('form') || text(block).indexOf(q) === -1) {
                        if (!block.matches('form')) {
                            block.classList.add('is-filtered');
                        }
                    } else {
                        hits++;
                    }
                    return;
                }
                panels.forEach(function (panel) {
                    var head = panel.querySelector('.authlify-panel__head');
                    var headHit = head && text(head).indexOf(q) > -1;
                    var rows = panel.querySelectorAll('.authlify-panel__body > .authlify-field, .authlify-panel__body > div > .authlify-field');
                    var found = 0;
                    rows.forEach(function (row) {
                        var hit = headHit || text(row).indexOf(q) > -1;
                        row.classList.toggle('is-filtered', !hit);
                        found += hit ? 1 : 0;
                    });
                    var panelHit = found > 0 || (!rows.length && (headHit || text(panel).indexOf(q) > -1));
                    panel.classList.toggle('is-filtered', !panelHit);
                    hits += panelHit ? Math.max(found, 1) : 0;
                });
            });

            // Hide the save card of a form whose cards are all filtered out.
            page.querySelectorAll('form[data-authlify-form]').forEach(function (form) {
                var visible = form.querySelector('.authlify-panel:not(.is-filtered)');
                var bar = form.querySelector('.authlify-savebar');
                if (bar) {
                    bar.classList.toggle('is-filtered', !visible);
                }
            });

            list.innerHTML = '';
            var matches = 0;
            links.forEach(function (a) {
                if (a.classList.contains('is-active')) {
                    return;
                }
                var hay = (text(a) + ' ' + (a.getAttribute('data-keywords') || '')).toLowerCase();
                if (hay.indexOf(q) > -1) {
                    a.classList.add('is-match');
                    var li = document.createElement('li');
                    var link = document.createElement('a');
                    link.href = a.href;
                    link.textContent = a.querySelector('.authlify-side__label').textContent;
                    li.appendChild(link);
                    list.appendChild(li);
                    matches++;
                }
            });

            none.hidden = hits > 0;
            other.hidden = matches === 0;
            empty.hidden = hits > 0 && matches === 0;
            if (hits > 0 && matches > 0) {
                none.hidden = true;
            }
            status.textContent = (cfg.searchCount || '%1$d matches here, %2$d other sections').replace('%1$d', hits).replace('%2$d', matches);
        }

        input.addEventListener('input', run);
        input.addEventListener('keydown', function (event) {
            if ('Escape' === event.key && input.value) {
                input.value = '';
                run();
            }
        });
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/**
 * Save card: show "Unsaved changes" once a settings form was edited.
 */
(function () {
    'use strict';

    function mark(event) {
        var form = event.target.closest && event.target.closest('form[data-authlify-form]');
        if (!form || event.target.id === 'authlify-settings-search') {
            return;
        }
        var hint = form.querySelector('[data-authlify-dirty]');
        if (hint) {
            hint.hidden = false;
        }
    }

    document.addEventListener('input', mark);
    document.addEventListener('change', mark);
})();

/**
 * Header navigation: keep the current item in view when the bar scrolls
 * sideways (narrow screens), and fade the edges that continue off-screen.
 */
(function () {
    'use strict';

    function init() {
        var nav = document.querySelector('.authlify-top__nav');
        if (!nav) {
            return;
        }
        var active = nav.querySelector('[aria-current="page"]');
        function edges() {
            var clipped = nav.scrollWidth > nav.clientWidth + 1;
            nav.classList.toggle('is-clipped', clipped);
            // Right-to-left pages scroll with negative values (UX2-03).
            var left = Math.abs(nav.scrollLeft);
            nav.classList.toggle('is-scrolled', clipped && left > 2);
            nav.classList.toggle('is-end', clipped && left + nav.clientWidth >= nav.scrollWidth - 2);
        }
        if (active && nav.scrollWidth > nav.clientWidth) {
            if ('rtl' === document.documentElement.dir || document.body.classList.contains('rtl')) {
                nav.scrollLeft = -Math.max(0, (nav.scrollWidth - active.offsetLeft - active.offsetWidth) - (nav.clientWidth - active.offsetWidth) / 2);
            } else {
                nav.scrollLeft = Math.max(0, active.offsetLeft - (nav.clientWidth - active.offsetWidth) / 2);
            }
        }
        edges();
        nav.addEventListener('scroll', edges, { passive: true });
        window.addEventListener('resize', edges);
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
