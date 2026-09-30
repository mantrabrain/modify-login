/**
 * Authlify documentation screen: one article at a time, deep links
 * (#article-id with back/forward), instant search and copy buttons.
 * No dependencies. Without JavaScript every article is readable in order.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-authlify-docs]');
    if (!root) {
        return;
    }

    var i18n = window.authlifyDocs || {};
    var input = document.getElementById('authlify-docs-q');
    var count = document.getElementById('authlify-docs-count');
    var jump = document.getElementById('authlify-docs-jump');
    var results = document.getElementById('authlify-docs-results');
    var resultsTitle = results.querySelector('.authlify-docs__results-title');
    var resultsList = results.querySelector('.authlify-docs__results-list');
    var overview = root.querySelector('[data-doc-overview]');
    var articles = Array.prototype.slice.call(root.querySelectorAll('.authlify-docs__article'));
    var navLinks = Array.prototype.slice.call(root.querySelectorAll('[data-doc-link]'));
    var byId = {};

    function norm(text) {
        text = String(text || '').toLowerCase();
        return text.normalize ? text.normalize('NFD').replace(/[̀-ͯ]/g, '') : text;
    }

    // Search index, built once from the rendered articles.
    var index = articles.map(function (el) {
        var title = el.querySelector('.authlify-docs__title');
        var lead = el.querySelector('.authlify-docs__lead');
        var body = el.querySelector('.authlify-docs__body');
        var item = {
            id: el.id,
            el: el,
            title: title ? title.textContent.replace(/\s+Pro\s*$/, '').trim() : el.id,
            summary: lead ? lead.textContent.trim() : '',
            category: el.getAttribute('data-category') || '',
            pro: !!(title && title.querySelector('.authlify-pro-tag'))
        };
        item.t = norm(item.title);
        item.s = norm(item.summary);
        item.k = norm(el.getAttribute('data-keywords') + ' ' + item.category);
        item.b = norm(body ? body.textContent : '');
        byId[item.id] = item;
        return item;
    });

    /* ------------------------------------------------------------------
     * Views
     * --------------------------------------------------------------- */

    function current() {
        var id = decodeURIComponent((window.location.hash || '').replace(/^#/, ''));
        return byId[id] ? id : '';
    }

    function show(id, focus) {
        overview.classList.toggle('is-active', !id);
        articles.forEach(function (el) {
            el.classList.toggle('is-active', el.id === id);
        });
        navLinks.forEach(function (link) {
            if (link.getAttribute('data-doc-link') === id) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
        if (jump) {
            jump.value = id;
        }

        // Leaving search results for an article keeps the query (and the filtered nav).
        root.classList.remove('is-searching');
        results.hidden = true;

        if (id) {
            var active = byId[id].el;
            var side = root.querySelector('.authlify-docs__side');
            var link = side.querySelector('[data-doc-link="' + id + '"]');
            if (link && side.scrollHeight > side.clientHeight) {
                var top = link.offsetTop - side.clientHeight / 2;
                side.scrollTop = Math.max(0, top);
            }
            if (focus) {
                active.focus({ preventScroll: true });
            }
        }
        // Bring the top of the docs (search and article start) into view when scrolled past it.
        var layout = root.querySelector('.authlify-docs__layout');
        var y = layout.getBoundingClientRect().top + window.pageYOffset - 56;
        if (window.pageYOffset > y) {
            window.scrollTo(0, Math.max(0, y));
        }
    }

    window.addEventListener('hashchange', function () {
        show(current(), true);
    });

    if (jump) {
        jump.addEventListener('change', function () {
            window.location.hash = jump.value ? jump.value : '';
            if (!jump.value) {
                show('', false);
            }
        });
    }

    root.addEventListener('click', function (event) {
        // "Overview" link clears the hash without jumping to the top of the page.
        var home = event.target.closest('.authlify-docs__home');
        if (home) {
            event.preventDefault();
            if (window.history && window.history.pushState) {
                window.history.pushState(null, '', window.location.pathname + window.location.search);
            } else {
                window.location.hash = '';
            }
            show('', false);
        }
    });

    /* ------------------------------------------------------------------
     * Search
     * --------------------------------------------------------------- */

    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function quote(text) {
        return String(text).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // Words match at the start of a word: "lock" finds "lockout" and "locked", "out" does not find "about".
    function wordStart(term) {
        return new RegExp('(^|[^a-z0-9])' + quote(term));
    }

    function highlight(text, terms) {
        var html = escapeHtml(text);
        terms.forEach(function (term) {
            if (term.length < 2) {
                return;
            }
            html = html.replace(new RegExp('(^|[^A-Za-z0-9&#;])(' + quote(escapeHtml(term)) + ')', 'gi'), '$1<mark>$2</mark>');
        });
        return html;
    }

    function score(item, terms, phrase) {
        var total = 0;
        for (var i = 0; i < terms.length; i++) {
            var re = wordStart(terms[i]);
            var s = 0;
            if (re.test(item.t)) {
                s += item.t.indexOf(terms[i]) === 0 ? 14 : 10;
            }
            if (re.test(item.k)) {
                s += 6;
            }
            if (re.test(item.s)) {
                s += 4;
            }
            if (re.test(item.b)) {
                s += 1;
            }
            if (!s) {
                return 0; // Every word must match somewhere.
            }
            total += s;
        }
        if (terms.length > 1 && (item.t.indexOf(phrase) !== -1 || item.k.indexOf(phrase) !== -1)) {
            total += 20;
        }
        return total;
    }

    function format(template, value) {
        return String(template || '').replace('%d', value).replace('%s', value);
    }

    var timer = null;

    function search() {
        var query = input.value.trim();
        var terms = norm(query).split(/\s+/).filter(Boolean);
        var groups = root.querySelectorAll('[data-doc-group]');

        if (!terms.length) {
            root.querySelectorAll('[data-doc-item]').forEach(function (li) {
                li.hidden = false;
            });
            groups.forEach(function (group) {
                group.hidden = false;
            });
            count.textContent = '';
            results.hidden = true;
            root.classList.remove('is-searching');
            show(current(), false);
            return;
        }

        var phrase = terms.join(' ');
        var matches = index.map(function (item) {
            return { item: item, score: score(item, terms, phrase) };
        }).filter(function (m) {
            return m.score > 0;
        }).sort(function (a, b) {
            return b.score - a.score;
        });

        var matched = {};
        matches.forEach(function (m) {
            matched[m.item.id] = true;
        });
        root.querySelectorAll('[data-doc-item]').forEach(function (li) {
            li.hidden = !matched[li.getAttribute('data-doc-item')];
        });
        groups.forEach(function (group) {
            group.hidden = !group.querySelector('[data-doc-item]:not([hidden])');
        });

        count.textContent = !matches.length ? i18n.countNone : format(matches.length === 1 ? i18n.countOne : i18n.countMany, matches.length);
        resultsTitle.textContent = format(i18n.resultsFor, query);
        resultsList.innerHTML = matches.map(function (m) {
            var item = m.item;
            return '<li><a href="#' + encodeURIComponent(item.id) + '"><strong>' + highlight(item.title, terms) +
                (item.pro ? ' <span class="authlify-pro-tag">Pro</span>' : '') + '</strong>' +
                '<span class="authlify-docs__result-meta">' + escapeHtml(item.category) + ' · ' + highlight(item.summary, terms) + '</span></a></li>';
        }).join('') || '<li><p class="authlify-empty">' + escapeHtml(i18n.countNone) + '</p></li>';

        root.classList.add('is-searching');
        results.hidden = false;
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(search, 60);
    });

    // Picking a result shows the article even when the hash is already that article.
    results.addEventListener('click', function (event) {
        var link = event.target.closest('a[href^="#"]');
        if (link && link.getAttribute('href') === '#' + encodeURIComponent(current())) {
            event.preventDefault();
            show(current(), true);
        }
    });

    function resultLinks() {
        return Array.prototype.slice.call(resultsList.querySelectorAll('a'));
    }

    input.addEventListener('keydown', function (event) {
        var links = resultLinks();
        if (event.key === 'ArrowDown' && !results.hidden && links.length) {
            event.preventDefault();
            links[0].focus();
        } else if (event.key === 'Enter' && !results.hidden && links.length) {
            event.preventDefault();
            links[0].click();
        } else if (event.key === 'Escape' && input.value) {
            event.preventDefault();
            input.value = '';
            search();
        }
    });

    resultsList.addEventListener('keydown', function (event) {
        var links = resultLinks();
        var i = links.indexOf(document.activeElement);
        if (i === -1) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            (links[i + 1] || links[i]).focus();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            (i === 0 ? input : links[i - 1]).focus();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            input.focus();
        }
    });

    // "/" focuses the search box, unless the person is typing somewhere.
    document.addEventListener('keydown', function (event) {
        if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }
        var tag = (document.activeElement && document.activeElement.tagName) || '';
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (document.activeElement && document.activeElement.isContentEditable)) {
            return;
        }
        event.preventDefault();
        input.focus();
        input.select();
    });

    /* ------------------------------------------------------------------
     * Copy buttons on code blocks
     * --------------------------------------------------------------- */

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try {
                document.execCommand('copy') ? resolve() : reject();
            } catch (e) {
                reject(e);
            }
            document.body.removeChild(area);
        });
    }

    root.querySelectorAll('.authlify-docs__body pre').forEach(function (pre) {
        var wrap = document.createElement('div');
        wrap.className = 'authlify-docs__code';
        pre.parentNode.insertBefore(wrap, pre);
        wrap.appendChild(pre);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button button-small authlify-docs__copy';
        button.textContent = i18n.copy || 'Copy';
        button.setAttribute('aria-label', i18n.copyCode || 'Copy code to clipboard');
        wrap.appendChild(button);

        button.addEventListener('click', function () {
            copyText(pre.textContent.replace(/\n$/, '')).then(function () {
                button.textContent = i18n.copied || 'Copied';
                button.setAttribute('aria-label', i18n.copied || 'Copied');
                setTimeout(function () {
                    button.textContent = i18n.copy || 'Copy';
                    button.setAttribute('aria-label', i18n.copyCode || 'Copy code to clipboard');
                }, 1600);
            });
        });
    });

    /* ------------------------------------------------------------------
     * Start
     * --------------------------------------------------------------- */

    root.classList.add('is-ready');
    show(current(), false);

    // A deep link opens the article at the top of the screen, not scrolled to its anchor.
    if (current()) {
        window.scrollTo(0, 0);
        window.addEventListener('load', function () {
            window.scrollTo(0, 0);
        });
    }
})();
