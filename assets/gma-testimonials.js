/* GMA Testimonials - front-end behaviour (vanilla, no jQuery dependency) */
(function () {
    'use strict';

    function eachWrap(fn) {
        var wraps = document.querySelectorAll('.gma-t-wrap');
        for (var i = 0; i < wraps.length; i++) fn(wraps[i]);
    }

    /* ── Read more / Show less: expands in place ─────────────────────────── */

    function bindToggles(scope) {
        var buttons = scope.querySelectorAll('.gma-t-toggle');
        for (var i = 0; i < buttons.length; i++) {
            (function (btn) {
                if (btn.dataset.bound === '1') return;
                btn.dataset.bound = '1';
                btn.addEventListener('click', function () {
                    var card = btn.closest('.gma-t-card');
                    if (!card) return;
                    var more = card.querySelector('.gma-t-more');
                    if (!more) return;
                    var open = !more.hidden;
                    more.hidden = open;
                    btn.textContent = open ? btn.dataset.more : btn.dataset.less;
                });
            })(buttons[i]);
        }
    }

    /* ── AJAX fetch ──────────────────────────────────────────────────────── */

    function buildBody(wrap, paged, mode) {
        var mentorSel  = wrap.querySelector('.gma-t-select[data-tax="mentor"]');
        var serviceSel = wrap.querySelector('.gma-t-select[data-tax="service"]');

        var body = new FormData();
        body.append('action', 'gma_t_filter');
        body.append('nonce', GMAT.nonce);
        body.append('config', wrap.getAttribute('data-config') || '{}');
        var searchBox = wrap.querySelector('.gma-t-search');

        body.append('mentor', mentorSel ? mentorSel.value : '');
        body.append('service', serviceSel ? serviceSel.value : '');
        body.append('search', searchBox ? searchBox.value : '');
        body.append('paged', paged || 1);
        body.append('mode', mode || 'full');
        return body;
    }

    /* Replace the whole results block: initial filter change, numbered/prev-next paging */
    function fetchResults(wrap, paged) {
        var results = wrap.querySelector('.gma-t-results');
        if (!results) return;

        results.classList.add('is-loading');

        fetch(GMAT.ajax_url, { method: 'POST', credentials: 'same-origin', body: buildBody(wrap, paged, 'full') })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string') {
                    results.innerHTML = json.data.html;
                    bindToggles(results);
                    bindPagination(wrap);
                    bindLoadMore(wrap);
                }
            })
            .catch(function () {
                /* leave the current results in place on failure */
            })
            .then(function () {
                results.classList.remove('is-loading');
            });
    }

    /* Append the next page of cards into the existing grid */
    function loadMore(wrap, btn) {
        var grid = wrap.querySelector('.gma-t-grid');
        if (!grid) return;

        var page = parseInt(btn.getAttribute('data-page'), 10) || 2;
        var original = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Loading...';

        fetch(GMAT.ajax_url, { method: 'POST', credentials: 'same-origin', body: buildBody(wrap, page, 'cards') })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success || !json.data) return;

                if (typeof json.data.html === 'string' && json.data.html !== '') {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = json.data.html;
                    while (tmp.firstChild) grid.appendChild(tmp.firstChild);
                    bindToggles(grid);
                }

                var count = wrap.querySelector('.gma-t-count');
                if (count && json.data.total) {
                    count.textContent = count.textContent
                        .replace(/\d+(?=\s*of)/, json.data.shown);
                }

                if (json.data.has_more) {
                    btn.setAttribute('data-page', json.data.next_page);
                    btn.disabled = false;
                    btn.textContent = original;
                } else {
                    var holder = btn.closest('.gma-t-loadmore-wrap');
                    if (holder) { holder.parentNode.removeChild(holder); } else { btn.remove(); }
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = original;
            });
    }

    function bindLoadMore(wrap) {
        var btns = wrap.querySelectorAll('.gma-t-loadmore');
        for (var i = 0; i < btns.length; i++) {
            (function (btn) {
                if (btn.dataset.bound === '1') return;
                btn.dataset.bound = '1';
                btn.addEventListener('click', function () { loadMore(wrap, btn); });
            })(btns[i]);
        }
    }

    /* ── Pagination ──────────────────────────────────────────────────────── */

    function bindPagination(wrap) {
        var buttons = wrap.querySelectorAll('.gma-t-pagination button');
        for (var i = 0; i < buttons.length; i++) {
            (function (btn) {
                if (btn.dataset.bound === '1') return;
                btn.dataset.bound = '1';
                btn.addEventListener('click', function () {
                    if (btn.disabled || btn.classList.contains('is-current')) return;
                    var page = parseInt(btn.getAttribute('data-page'), 10) || 1;
                    fetchResults(wrap, page);
                    var results = wrap.querySelector('.gma-t-results');
                    if (results && results.getBoundingClientRect().top < 0) {
                        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            })(buttons[i]);
        }
    }

    /* ── Filters ─────────────────────────────────────────────────────────── */

    /*
     * Reflect the current filters in the address bar, so a filtered view can
     * be copied, bookmarked or linked to. replaceState rather than pushState:
     * filtering shouldn't fill up the back button.
     */
    function syncUrl(wrap) {
        if (!window.history || !window.history.replaceState || !window.URL) return;

        var mentorSel  = wrap.querySelector('.gma-t-select[data-tax="mentor"]');
        var serviceSel = wrap.querySelector('.gma-t-select[data-tax="service"]');

        try {
            var url = new URL(window.location.href);


            if (mentorSel && mentorSel.value) {
                url.searchParams.set('mentor', mentorSel.value);
            } else {
                url.searchParams.delete('mentor');
            }

            if (serviceSel && serviceSel.value) {
                url.searchParams.set('service', serviceSel.value);
            } else {
                url.searchParams.delete('service');
            }

            var searchBox = wrap.querySelector('.gma-t-search');
            if (searchBox && searchBox.value.trim()) {
                url.searchParams.set('q', searchBox.value.trim());
            } else {
                url.searchParams.delete('q');
            }

            window.history.replaceState({}, '', url.toString());
        } catch (e) {
            /* older browser - the filtering still works, the URL just won't update */
        }
    }

    function bindFilters(wrap) {
        var selects   = wrap.querySelectorAll('.gma-t-select');
        var searchBox = wrap.querySelector('.gma-t-search');
        var clearBtn  = wrap.querySelector('.gma-t-search-clear');

        for (var i = 0; i < selects.length; i++) {
            selects[i].addEventListener('change', function () {
                syncUrl(wrap);
                fetchResults(wrap, 1);
            });
        }

        /* Show the clear affordance only when there is something to clear. */
        function updateClear() {
            if (!clearBtn || !searchBox) return;
            clearBtn.hidden = (searchBox.value === '');
        }

        function runSearch() {
            updateClear();
            syncUrl(wrap);
            fetchResults(wrap, 1);
        }

        if (searchBox) {
            var timer = null;
            var last  = searchBox.value;

            /* Debounced, so typing a name does not fire a request per letter. */
            searchBox.addEventListener('input', function () {
                updateClear();
                if (timer) { clearTimeout(timer); }
                timer = setTimeout(function () {
                    if (searchBox.value === last) return;
                    last = searchBox.value;
                    runSearch();
                }, 350);
            });

            /* Enter searches straight away rather than waiting out the debounce,
               and never submits a surrounding form. */
            searchBox.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                if (timer) { clearTimeout(timer); }
                last = searchBox.value;
                runSearch();
            });

            /* The native clear "x" in a search input fires search, not input,
               in some browsers. */
            searchBox.addEventListener('search', function () {
                if (searchBox.value === last) return;
                last = searchBox.value;
                runSearch();
            });
        }

        if (clearBtn && searchBox) {
            clearBtn.addEventListener('click', function () {
                if (searchBox.value === '') return;
                searchBox.value = '';
                searchBox.focus();
                runSearch();
            });
        }

        var reset = wrap.querySelector('.gma-t-reset');
        if (reset) {
            reset.addEventListener('click', function () {
                for (var j = 0; j < selects.length; j++) selects[j].value = '';
                if (searchBox) { searchBox.value = ''; }
                updateClear();
                syncUrl(wrap);
                fetchResults(wrap, 1);
            });
        }
    }

    /* ── Init ────────────────────────────────────────────────────────────── */

    function init() {
        eachWrap(function (wrap) {
            bindToggles(wrap);
            bindPagination(wrap);
            bindLoadMore(wrap);
            bindFilters(wrap);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
