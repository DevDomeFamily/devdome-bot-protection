/**
 * DevDome Bot Protection admin (DESIGN.md sections 14 to 21). No inline <script> anywhere in the markup.
 * The Known Bots tab has its own script (bp-knownbots.js).
 */
(function () {
    'use strict';
    var app = document.querySelector('.dd-app');
    if (!app) { return; }
    // DESIGN.md 24: the "a database query failed" banner is printed after the page; it belongs at its top.
    var guardBanner = document.getElementById('bp-guard-banner');
    if (guardBanner) { app.insertBefore(guardBanner, app.firstChild); }

    /* ------------------------------- tabs -------------------------------- */
    // Every tab is rendered up front, so switching is instant.
    var tabs = app.querySelectorAll('.dd-tab[data-dd-tab]');
    var panels = app.querySelectorAll('.dd-tabpanel[data-dd-panel]');
    // DESIGN.md 22.3: a result banner is a flash. It goes on the next tab switch or form submit, and the URL flag
    // that produced it is stripped so a reload does not show it again.
    function clearFlash() { app.querySelectorAll('.dd-banner[data-bp-flash]').forEach(function (b) { b.parentNode.removeChild(b); }); }
    (function () {
        if (!window.history || !history.replaceState || !window.URL) { return; }
        var u = new URL(location.href), hit = false;
        ['bp_saved', 'bp_cf', 'bp_imported', 'bp_import_err', 'bp_feeds_refreshed', 'bp_dberr'].forEach(function (k) { if (u.searchParams.has(k)) { u.searchParams.delete(k); hit = true; } });
        if (hit) { history.replaceState(null, '', u.toString()); }
    })();
    app.addEventListener('submit', clearFlash, true);
    // Overview: the three choices are real radios shown as buttons. The chosen one is filled, the hint says what it does.
    app.addEventListener('change', function (e) {
        var r = e.target;
        if (!r || r.name !== 'bp_state') { return; }
        app.querySelectorAll('#bp-choices .bp-choice').forEach(function (l) { l.classList.toggle('is-on', l.contains(r)); });
        var h = document.getElementById('bp-state-hint'), lab = r.closest('.bp-choice');
        if (h && lab && lab.getAttribute('data-hint')) { h.textContent = lab.getAttribute('data-hint'); }
        // The status circle follows the choice at once (green = blocking, yellow = recording, red = off), saved or not.
        var st = app.querySelector('#bp-verdict .bp-status');
        if (st) { st.className = 'bp-status ' + ({ block: 'is-ok', watch: 'is-warn', off: 'is-bad' }[r.value] || 'is-warn'); }
    });
    // DESIGN.md 16.1 (Admin Cleaner block, 1:1): every form with a Save footer that owns a .dd-pending slot compares its
    // fields with their values at load. "N changes not saved" shows next to the button while they differ, "Saved." steps
    // aside, an autoshow footer stays hidden until there is something to save, leaving with pending changes asks first.
    Array.prototype.slice.call(app.querySelectorAll('form')).filter(function (f) { return f.querySelector('.dd-footer .dd-pending'); }).forEach(function (form) {
        var slot = form.querySelector('.dd-footer .dd-pending'), saved = form.querySelector('.dd-footer .dd-saved'), footer = form.querySelector('.dd-footer');
        var autoshow = footer.hasAttribute('data-dd-autoshow'), submitting = false, initial = {};
        function fields() { return Array.prototype.slice.call(form.elements).filter(function (el) { return el.name && !/^(hidden|submit|button)$/.test(el.type) && el.name !== '_wp_http_referer'; }); }
        function value(el) { return (el.type === 'checkbox' || el.type === 'radio') ? (el.checked ? '1' : '0') : String(el.value); }
        fields().forEach(function (el, i) { initial[el.name + '#' + i] = value(el); });
        function count() {
            var n = 0, seen = {};
            fields().forEach(function (el, i) {
                if (initial[el.name + '#' + i] === value(el)) { return; }
                if (el.type === 'radio') { if (seen[el.name]) { return; } seen[el.name] = 1; } // one radio group = one change
                n++;
            });
            return n;
        }
        function paint() {
            var n = count();
            if (autoshow) { footer.hidden = (n === 0 && !(saved && !saved.hidden)); }
            if (n > 0) {
                slot.textContent = n === 1 ? slot.getAttribute('data-one') : slot.getAttribute('data-many').replace('%d', String(n));
                slot.hidden = false;
                if (saved) { saved.hidden = true; }
            } else { slot.hidden = true; slot.textContent = ''; }
        }
        form.addEventListener('change', paint);
        form.addEventListener('input', paint);
        form.addEventListener('submit', function () { submitting = true; });
        window.addEventListener('beforeunload', function (e) { if (!submitting && count() > 0) { e.preventDefault(); e.returnValue = ''; } });
    });
    function showTab(name) {
        var found = false;
        panels.forEach(function (p) { var on = p.getAttribute('data-dd-panel') === name; p.classList.toggle('is-active', on); if (on) { found = true; } });
        if (!found) { return; }
        tabs.forEach(function (t) { t.classList.toggle('is-active', t.getAttribute('data-dd-tab') === name); });
        app.setAttribute('data-bp-tab', name); // the header shows the Known Bots buttons for that tab only
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function (e) {
            e.preventDefault();
            var name = t.getAttribute('data-dd-tab');
            clearFlash();
            showTab(name);
            t.blur();
            if (window.history && history.replaceState) { history.replaceState(null, '', '#' + name); }
        });
    });
    window.addEventListener('hashchange', function () { var h = (location.hash || '').replace('#', ''); if (h) { showTab(h); } });
    var hash = (location.hash || '').replace('#', '');
    if (hash === 'protection') { hash = 'overview'; }
    if (hash === 'bots') { hash = 'knownbots'; }
    if (hash === 'threats') { hash = 'activity'; }
    if (hash === 'clickfraud') { hash = 'overview'; }
    if (hash) { showTab(hash); }

    /* ------------------------------ dialog ------------------------------- */
    // DESIGN.md section 20: every confirmation is the in-page dialog, never confirm().
    var modal = document.getElementById('bp-modal');
    var modalText = document.getElementById('bp-modal-text');
    var modalTitle = document.getElementById('bp-modal-title');
    var modalOk = document.getElementById('bp-modal-ok');
    var modalCancel = document.getElementById('bp-modal-cancel');
    var modalPending = null;
    function closeModal() { if (!modal) { return; } modal.setAttribute('hidden', 'hidden'); modalPending = null; }
    function confirmDialog(text, onOk, compact) {
        if (!modal) { onOk(); return; }
        var box = modal.querySelector('.dd-modal-box'); if (box) { box.classList.toggle('is-compact', !!compact); }
        modalTitle.textContent = 'Please confirm';
        modalText.textContent = text;
        modalOk.textContent = 'Confirm';
        modalCancel.style.display = '';
        modal.removeAttribute('hidden');
        modalPending = onOk;
        modalOk.focus();
    }
    window.devdbotpConfirm = confirmDialog; // the Known Bots script asks before Use Recommended too
    if (modal) {
        modal.querySelectorAll('[data-bp-modal-close]').forEach(function (el) { el.addEventListener('click', closeModal); });
        modalOk.addEventListener('click', function () { var fn = modalPending; closeModal(); if (fn) { fn(); } });
        document.addEventListener('keydown', function (e) { if (!modal.hasAttribute('hidden') && e.key === 'Escape') { e.preventDefault(); closeModal(); } });
    }
    // Forms carrying data-bp-confirm ask first; a submit button carrying it names its own question.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.getAttribute('data-bp-ok') === '1') { return; }
        var btn = document.activeElement && document.activeElement.form === form ? document.activeElement : null;
        var text = (btn && btn.getAttribute('data-bp-confirm')) || form.getAttribute('data-bp-confirm');
        if (!text) { return; }
        e.preventDefault();
        var submitter = btn;
        confirmDialog(text, function () {
            form.setAttribute('data-bp-ok', '1');
            if (submitter && submitter.name) {
                var h = document.createElement('input'); h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value || '1'; form.appendChild(h);
            }
            form.submit();
        });
    }, true);

    /* -------------------------- small behaviours -------------------------- */
    function initPanel(root) {
        root = root || document;
        // Segmented controls: every variant carries data-target + a hidden input.
        root.querySelectorAll('.dd-seg[data-target]').forEach(function (seg) {
            if (seg.getAttribute('data-wired')) { return; }
            seg.setAttribute('data-wired', '1');
            var target = seg.getAttribute('data-target');
            seg.querySelectorAll('.dd-seg-opt').forEach(function (opt) {
                opt.addEventListener('click', function () {
                    seg.querySelectorAll('.dd-seg-opt').forEach(function (o) { o.classList.remove('is-active'); });
                    opt.classList.add('is-active');
                    var input = document.getElementById(target);
                    if (input) { input.value = opt.getAttribute('data-value'); }
                });
            });
        });
        // Rules tab autosave (Known Bots style, owner 2026-09-28): every Allow / Log / Block pick, threshold or switch posts
        // the whole form after 300 ms; the pill on the range bar says Saving / Saved / Not saved. No Save button.
        var rulesForm = document.getElementById('bp-rules-form');
        if (rulesForm && !rulesForm.getAttribute('data-wired')) {
            rulesForm.setAttribute('data-wired', '1');
            var pill = document.querySelector('[data-bp-rules-save]'), rTimer = null, rFade = null, rSaving = false, rQueued = false;
            function rShow(cls, html) { if (pill) { pill.className = 'bp-autosave' + (cls ? ' ' + cls : ''); pill.innerHTML = html; } }
            function rQueue() { clearTimeout(rTimer); clearTimeout(rFade); rShow('', '<span class="bp-spin"></span>Saving…'); rTimer = setTimeout(rSave, 300); }
            function rSave() {
                if (rSaving) { rQueued = true; return; }
                rSaving = true;
                var body = new FormData(rulesForm); body.set('devdbotp_rules_save', '1'); body.set('bp_ajax', '1');
                fetch(window.location.href, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        rSaving = false;
                        if (!res || !res.success) { throw new Error('not saved'); }
                        if (rQueued) { rQueued = false; rSave(); return; }
                        rShow('saved', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Saved');
                        rFade = setTimeout(function () { rShow('', ''); }, 2200);
                    })
                    .catch(function () { rSaving = false; rQueued = false; rShow('error', 'Not saved, pick again to retry'); });
            }
            rulesForm.addEventListener('click', function (e) { if (e.target.closest('.dd-seg-opt')) { rQueue(); } }); // runs after the segment set its hidden input
            var recBtn = document.getElementById('bp-rules-rec');
            if (recBtn) {
                recBtn.addEventListener('click', function () {
                    var rec; try { rec = JSON.parse(rulesForm.getAttribute('data-bp-rec') || '{}'); } catch (err) { return; }
                    confirmDialog('Set every rule to its recommended action? Thresholds and switches stay as they are.', function () {
                        Object.keys(rec).forEach(function (key) {
                            var opt = rulesForm.querySelector('.dd-seg[data-target="' + key + '"] .dd-seg-opt[data-value="' + rec[key] + '"]');
                            if (opt && !opt.classList.contains('is-active')) { opt.click(); }
                        });
                        rQueue(); // one save even when nothing differed
                    }, true);
                });
            }
            rulesForm.addEventListener('change', function (e) { var t = e.target; if (t.matches('input[type="number"], input[type="checkbox"], select')) { rQueue(); } });
        }
        // Overview autosave (owner 2026-09-28): the Turned Off / Record Bots / Block Bad Bots choice posts itself the moment it is clicked.
        var ovForm = document.getElementById('bp-overview-form');
        if (ovForm && !ovForm.getAttribute('data-wired')) {
            ovForm.setAttribute('data-wired', '1');
            var ovPill = ovForm.querySelector('[data-bp-ov-save]'), ovFade = null;
            function ovShow(cls, html) { if (ovPill) { ovPill.className = 'bp-autosave' + (cls ? ' ' + cls : ''); ovPill.innerHTML = html; } }
            var ovSaving = false;
            ovForm.addEventListener('change', function (e) {
                if (!e.target || e.target.name !== 'bp_state' || ovSaving) { return; }
                ovSaving = true;
                var body = new FormData(ovForm); body.set('devdbotp_save', '1'); body.set('bp_ajax', '1'); // read the form FIRST: a disabled radio is left out of FormData
                ovForm.querySelectorAll('input[name="bp_state"]').forEach(function (r) { r.disabled = true; }); // one choice at a time
                clearTimeout(ovFade); ovShow('', '<span class="bp-spin"></span>Saving…');
                fetch(window.location.href, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.success) { throw new Error('not saved'); }
                        ovShow('saved', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Saved');
                        ovFade = setTimeout(function () { window.location.reload(); }, 900); // the verdict block, pills and header follow the new state
                    })
                    .catch(function () { ovSaving = false; ovForm.querySelectorAll('input[name="bp_state"]').forEach(function (r) { r.disabled = false; }); ovShow('error', 'Not saved, pick again to retry'); });
            });
        }
        // Typed-word confirmation (DESIGN.md 23, Country Blocker dc-typed 1:1): the submit unlocks only when the word matches.
        Array.prototype.slice.call(root.querySelectorAll('form[data-bp-typed]')).forEach(function (form) {
            if (form.getAttribute('data-wired')) { return; }
            form.setAttribute('data-wired', '1');
            var word = String(form.getAttribute('data-bp-typed') || '').toUpperCase();
            var open = form.querySelector('[data-bp-typed-open]'), panel = form.querySelector('.bp-typed-confirm'), input = form.querySelector('.bp-typed-input'), submit = form.querySelector('button[type="submit"]'), cancel = form.querySelector('[data-bp-typed-cancel]');
            if (!word || !open || !panel || !input || !submit) { return; }
            function ok() { return input.value.trim().toUpperCase() === word; }
            function reset() { panel.setAttribute('hidden', 'hidden'); open.style.display = ''; input.value = ''; submit.disabled = true; }
            open.addEventListener('click', function () { panel.removeAttribute('hidden'); open.style.display = 'none'; input.value = ''; submit.disabled = true; setTimeout(function () { input.focus(); }, 0); });
            input.addEventListener('input', function () { submit.disabled = !ok(); });
            if (cancel) { cancel.addEventListener('click', function () { reset(); open.focus(); }); }
            form.addEventListener('submit', function (e) {
                if (!ok()) { e.preventDefault(); return; }
                var h = form.querySelector('input[name="bp_word"]');
                if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'bp_word'; form.appendChild(h); }
                h.value = input.value.trim();
                submit.disabled = true;
            });
            form.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hasAttribute('hidden')) { e.preventDefault(); reset(); open.focus(); } });
        });
        // Settings header: Export / Import open their dialogs (owner 2026-09-28, the card on the tab is gone).
        [['bp-set-export', 'bp-export-modal'], ['bp-set-import', 'bp-import-modal']].forEach(function (pair) {
            var btn = document.getElementById(pair[0]), dlg = document.getElementById(pair[1]);
            if (!btn || !dlg || btn.getAttribute('data-wired')) { return; }
            btn.setAttribute('data-wired', '1');
            btn.addEventListener('click', function () { dlg.removeAttribute('hidden'); var t = dlg.querySelector('textarea'); if (t) { t.focus(); } });
            dlg.querySelectorAll('[data-bp-dialog-close]').forEach(function (x) { x.addEventListener('click', function () { dlg.setAttribute('hidden', 'hidden'); }); });
        });
        // Preset tiles (site type, the wizard grids).
        root.querySelectorAll('.dd-preset-grid[data-target]').forEach(function (grid) {
            if (grid.getAttribute('data-wired')) { return; }
            grid.setAttribute('data-wired', '1');
            var target = grid.getAttribute('data-target');
            grid.querySelectorAll('.dd-preset').forEach(function (tile) {
                tile.addEventListener('click', function () {
                    grid.querySelectorAll('.dd-preset').forEach(function (t) { t.classList.remove('is-active'); });
                    tile.classList.add('is-active');
                    var input = document.getElementById(target);
                    if (input) { input.value = tile.getAttribute('data-value'); }
                });
            });
        });
        // Copy buttons and click-to-select fields.
        root.querySelectorAll('[data-bp-copy]').forEach(function (btn) {
            if (btn.getAttribute('data-wired')) { return; }
            btn.setAttribute('data-wired', '1');
            btn.addEventListener('click', function () {
                var el = document.getElementById(btn.getAttribute('data-bp-copy'));
                if (!el) { return; }
                var done = function () { var t = btn.textContent; btn.textContent = 'Copied'; window.setTimeout(function () { btn.textContent = t; }, 1500); };
                if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(el.value).then(done, function () { el.select(); try { document.execCommand('copy'); done(); } catch (e2) {} }); }
                else { el.select(); try { document.execCommand('copy'); done(); } catch (e3) {} }
            });
        });
        root.querySelectorAll('[data-bp-select]').forEach(function (el) {
            if (el.getAttribute('data-wired')) { return; }
            el.setAttribute('data-wired', '1');
            el.addEventListener('click', function () { el.select(); });
        });
        root.querySelectorAll('[data-bp-reload]').forEach(function (b) {
            if (b.getAttribute('data-wired')) { return; }
            b.setAttribute('data-wired', '1');
            b.addEventListener('click', function () { location.reload(); });
        });
        // Custom rule builder: operators follow the chosen field.
        var fieldOps = { user_agent: ['contains', 'equals', 'starts_with', 'regex'], path: ['contains', 'equals', 'starts_with', 'regex'], ip: ['equals', 'cidr'], country: ['equals'], asn: ['equals'] };
        var opLabels = { contains: 'contains', equals: 'equals', starts_with: 'starts with', regex: 'matches regex', cidr: 'is in CIDR' };
        root.querySelectorAll('.dd-cr-field').forEach(function (f) {
            if (f.getAttribute('data-wired')) { return; }
            f.setAttribute('data-wired', '1');
            var o = f.closest('form') ? f.closest('form').querySelector('.dd-cr-op') : null;
            if (!o) { return; }
            function sync() {
                var ops = fieldOps[f.value] || ['equals'];
                o.innerHTML = '';
                ops.forEach(function (op) { var e = document.createElement('option'); e.value = op; e.textContent = opLabels[op]; o.appendChild(e); });
            }
            f.addEventListener('change', sync);
            sync();
        });
        root.querySelectorAll('table[data-bp-paged]').forEach(pagedList);
        root.querySelectorAll('[data-bp-chart]').forEach(activityChart);
        // Country codes -> English names, as the dashboard does it (Intl.DisplayNames); the code stays when the browser has no names.
        try {
            var names = (typeof Intl !== 'undefined' && Intl.DisplayNames) ? new Intl.DisplayNames(['en'], { type: 'region' }) : null;
            if (names) { root.querySelectorAll('.country-name[data-code]').forEach(function (el) { var n = names.of(el.getAttribute('data-code')); if (n && n !== el.getAttribute('data-code')) { el.textContent = n; } }); }
        } catch (e) {}
        wireFilters(root);
    }

    /* ---------------------- lists: filter, search, pages ---------------------- */
    // DESIGN.md 21.8: the pager is always complete, one page shows "1-3 of 3" and the disabled arrows.
    function pagedList(table) {
        if (table.bpList) { return; }
        var per = parseInt(table.getAttribute('data-bp-paged'), 10) || 20;
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody > tr'));
        var wrap = table.closest('.dd-list-wrap');
        var pager = wrap && wrap.nextElementSibling && wrap.nextElementSibling.classList.contains('bp-pagination') ? wrap.nextElementSibling : null;
        var state = { page: 1, q: '', filter: null, per: per };
        function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
        function matches(tr) {
            if (state.filter && !state.filter(tr)) { return false; }
            return !state.q || (tr.textContent || '').toLowerCase().indexOf(state.q) !== -1;
        }
        function render() {
            var vis = rows.filter(matches);
            var n = state.per;
            var pages = Math.max(1, Math.ceil(vis.length / n));
            if (state.page > pages) { state.page = pages; }
            var onPage = vis.slice((state.page - 1) * n, state.page * n);
            rows.forEach(function (tr) { tr.hidden = onPage.indexOf(tr) === -1; });
            if (!pager) { return; }
            pager.textContent = '';
            if (!vis.length) { pager.appendChild(el('span', 'bp-pg-count', 'No matching rows')); return; }
            pager.appendChild(el('span', 'bp-pg-count', ((state.page - 1) * n + 1) + '-' + Math.min(vis.length, state.page * n) + ' of ' + vis.length));
            var right = el('div', 'bp-pg-right');
            var perWrap = el('div', 'bp-pg-per');
            perWrap.appendChild(el('span', '', 'Show per page:'));
            var jump = el('div', 'page-jump-wrap');
            var jb = el('button', 'page-btn page-jump-btn', n + ' ▾');
            jb.type = 'button'; jb.setAttribute('aria-haspopup', 'true'); jb.setAttribute('aria-expanded', 'false');
            var menu = el('div', 'page-jump-menu hidden');
            [10, 20, 50, 100, 200].forEach(function (k) {
                var it = el('button', 'pjm-item' + (k === n ? ' active' : ''), String(k));
                it.type = 'button';
                it.addEventListener('click', function () { menu.classList.add('hidden'); state.per = k; state.page = 1; render(); });
                menu.appendChild(it);
            });
            jb.addEventListener('click', function (e) { e.stopPropagation(); var open = menu.classList.contains('hidden'); menu.classList.toggle('hidden', !open); jb.setAttribute('aria-expanded', open ? 'true' : 'false'); });
            document.addEventListener('click', function () { menu.classList.add('hidden'); jb.setAttribute('aria-expanded', 'false'); });
            jump.appendChild(jb); jump.appendChild(menu); perWrap.appendChild(jump); right.appendChild(perWrap);
            var btns = el('div', 'bp-pg-btns');
            var mk = function (label, target, disabled, active) {
                var b = el('button', 'page-btn' + (active ? ' active' : ''), label);
                b.type = 'button'; b.disabled = !!disabled;
                if (!disabled && !active) { b.addEventListener('click', function () { state.page = target; render(); }); }
                return b;
            };
            btns.appendChild(mk('‹', state.page - 1, state.page <= 1));
            var prev = 0;
            for (var p = 1; p <= pages; p++) {
                if (p !== 1 && p !== pages && Math.abs(p - state.page) > 1) { continue; }
                if (p - prev > 1) { btns.appendChild(el('span', 'page-gap', '…')); }
                btns.appendChild(mk(String(p), p, false, p === state.page));
                prev = p;
            }
            btns.appendChild(mk('›', state.page + 1, state.page >= pages));
            right.appendChild(btns);
            pager.appendChild(right);
        }
        table.bpList = {
            render: render,
            setQuery: function (q) { state.q = String(q || '').trim().toLowerCase(); state.page = 1; render(); },
            setFilter: function (fn) { state.filter = fn; state.page = 1; render(); }
        };
        render();
    }
    function wireFilters(root) {
        var filter = root.querySelector ? root.querySelector('#bp-evt-filter') : null;
        var table = document.getElementById('bp-evt-table');
        if (filter && table && !filter.getAttribute('data-wired')) {
            filter.setAttribute('data-wired', '1');
            filter.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('.bp-pill') : null;
                if (!btn) { return; }
                var want = btn.getAttribute('data-filter') || '';
                filter.querySelectorAll('.bp-pill').forEach(function (b) { b.classList.toggle('is-on', b === btn); });
                if (table.bpList) { table.bpList.setFilter(want ? function (tr) { return tr.getAttribute('data-cat') === want; } : null); }
            });
        }
        (root.querySelectorAll ? root.querySelectorAll('[data-bp-search]') : []).forEach(function (input) {
            if (input.getAttribute('data-wired')) { return; }
            input.setAttribute('data-wired', '1');
            var timer = null;
            input.addEventListener('input', function () {
                if (timer) { clearTimeout(timer); }
                timer = setTimeout(function () { var t = document.getElementById(input.getAttribute('data-bp-search')); if (t && t.bpList) { t.bpList.setQuery(input.value); } }, 150);
            });
        });
    }

    /* ------------------------- activity chart ------------------------- */
    // The DevDome dashboard, 1:1 (analytics app/main.js initChart + updateDualAxisChart + customTooltipHandler + crosshairPlugin):
    // two dots per KPI card pick the left and right line; counts share ONE y axis; the picked cards get the ring + tint.
    var METRICS = {
        people: { label: 'Visits From People',   main: '#1967d2', bg: '#e8f0fe' },
        bots:   { label: 'Visits From Bots',     main: '#be123c', bg: '#ffe4e6' },
        caught: { label: 'Caught By Your Rules', main: '#ef4444', bg: '#fee2e2' }
    };
    var crosshair = {
        id: 'bpCrosshair',
        afterDraw: function (chart) {
            var active = chart.tooltip ? chart.tooltip.getActiveElements() : [];
            if (active.length) {
                var ctx = chart.ctx, x = active[0].element.x;
                ctx.save(); ctx.beginPath(); ctx.moveTo(x, chart.scales.y.top); ctx.lineTo(x, chart.scales.y.bottom);
                ctx.lineWidth = 1; ctx.strokeStyle = 'rgba(15, 23, 42, 0.28)'; ctx.setLineDash([4, 3]); ctx.stroke(); ctx.restore();
            }
        }
    };
    function axisCount(v) { return v >= 1000000 ? (v / 1000000).toFixed(1).replace(/\.0$/, '') + 'M' : v >= 1000 ? (v / 1000).toFixed(1).replace(/\.0$/, '') + 'K' : v; }
    function activityChart(root) {
        if (root.getAttribute('data-wired') || typeof window.Chart === 'undefined') { return; }
        root.setAttribute('data-wired', '1');
        var data; try { data = JSON.parse(root.getAttribute('data-bp-chart') || '{}'); } catch (e) { return; }
        var box = root.querySelector('.chart-box'), canvas = root.querySelector('.bp-chart-canvas'), tip = root.querySelector('.bp-chart-tip');
        var one = (data.labels || []).length === 1; // Today: one day bucket, drawn as a point
        var picked = function () {
            var r1 = root.querySelector('input[name="bp_axis1"]:checked'), r2 = root.querySelector('input[name="bp_axis2"]:checked');
            return [r1 ? r1.value : 'people', r2 ? r2.value : 'bots'];
        };
        function paintCards() {
            var sel = picked();
            root.querySelectorAll('.metric-card').forEach(function (card) {
                var m = card.getAttribute('data-metric');
                card.style.boxShadow = ''; card.style.backgroundColor = '';
                if (m === sel[0] || m === sel[1]) { card.style.boxShadow = '0 0 0 2px ' + METRICS[m].main; card.style.backgroundColor = METRICS[m].bg; }
            });
        }
        var chart = null, tipKey = '';
        function tooltip(context) {
            var tt = context.tooltip, c = context.chart;
            if (tt.opacity === 0) { tipKey = ''; tip.style.opacity = 0; tip.setAttribute('aria-hidden', 'true'); return; }
            var idx = tt.dataPoints && tt.dataPoints[0] ? tt.dataPoints[0].dataIndex : -1;
            var key = idx + '|' + tt.caretX + '|' + tt.caretY;
            if (key === tipKey) { return; }
            tipKey = key;
            tip.textContent = '';
            var d = document.createElement('span'); d.className = 'bp-tip-date'; d.textContent = data.full[idx] || ''; tip.appendChild(d);
            picked().filter(function (m, i, a) { return a.indexOf(m) === i; }).forEach(function (m) { // both dots on one metric = one row
                var r = document.createElement('div'); r.className = 'bp-tip-row';
                var lab = document.createElement('div'); lab.className = 'bp-tip-label';
                var sw = document.createElement('span'); sw.className = 'bp-tip-swatch'; sw.style.background = METRICS[m].main;
                lab.appendChild(sw); lab.appendChild(document.createTextNode(METRICS[m].label));
                var v = document.createElement('span'); v.className = 'bp-tip-value'; v.textContent = String((data[m] || [])[idx] || 0);
                r.appendChild(lab); r.appendChild(v); tip.appendChild(r);
            });
            var px = c.canvas.offsetLeft, py = c.canvas.offsetTop;
            var half = tip.offsetWidth / 2, minCx = half + 4, maxCx = Math.max(minCx, box.clientWidth - half - 4);
            var cx = Math.min(Math.max(px + tt.caretX, minCx), maxCx);
            tip.style.opacity = 1; tip.setAttribute('aria-hidden', 'false');
            tip.style.left = cx + 'px';
            var th = tip.offsetHeight, ty = py + tt.caretY - th - 14;
            if (ty < py + 2) { ty = py + tt.caretY + 16; }
            tip.style.top = ty + 'px';
        }
        function dataset(m, order) {
            return { label: METRICS[m].label, data: data[m] || [], borderColor: METRICS[m].main, backgroundColor: METRICS[m].main, tension: 0.1, yAxisID: 'y', pointRadius: one ? 4 : 0, borderWidth: 2, fill: false, order: order };
        }
        function update() {
            paintCards();
            if (!chart) { return; }
            var sel = picked();
            var ds = [dataset(sel[0], 1), dataset(sel[1], 0)]; // the second line draws ON TOP (Chart.js draws lowest order last)
            var cur = chart.data.datasets;
            if (cur.length === ds.length) { ds.forEach(function (d, i) { Object.assign(cur[i], d); }); } else { chart.data.datasets = ds; }
            chart.update();
        }
        root.querySelectorAll('.axis-radio').forEach(function (r) { r.addEventListener('change', update); });
        if (canvas && data.labels) {
            var phone = window.matchMedia('(max-width: 640px)').matches;
            var options = {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false }, tooltip: { enabled: false, external: tooltip } },
                scales: {
                    x: { grid: { display: false } },
                    y: { display: true, position: 'left', grid: { color: '#e5e7eb' }, beginAtZero: true, suggestedMax: 4, ticks: { color: '#666', maxTicksLimit: 5, padding: 2, precision: 0, callback: axisCount }, border: { display: true, width: 2 } }
                }
            };
            if (phone) { options.events = ['touchstart', 'click']; options.animation = false; options.layout = { padding: { left: 0, right: 0, top: 4, bottom: 0 } }; }
            var sel = picked();
            chart = new window.Chart(canvas.getContext('2d'), { type: 'line', data: { labels: data.labels, datasets: [dataset(sel[0], 1), dataset(sel[1], 0)] }, options: options, plugins: [crosshair] });
        }
        paintCards();
    }

    /* ------------------------------- misc -------------------------------- */
    // Safe Mode countdown pill (top bar, when paused).
    (function () {
        var el = document.getElementById('bp-safe-countdown');
        if (!el) { return; }
        var left = parseInt(el.getAttribute('data-remaining'), 10) || 0;
        function tick() {
            if (left <= 0) { location.reload(); return; }
            var m = Math.floor(left / 60), s = left % 60;
            el.textContent = m + ':' + (s < 10 ? '0' : '') + s + ' left';
            left--;
        }
        tick();
        setInterval(tick, 1000);
    })();
    // Overview buttons set the hidden mode/level before the form posts.
    (function () {
        var on = document.getElementById('bp-enable-btn');
        if (on) { on.addEventListener('click', function () { var lvl = document.getElementById('bp_protection_level'); if (lvl) { lvl.value = 'recommended'; } }); }
        var live = document.getElementById('bp-golive-btn');
        if (live) { live.addEventListener('click', function () { var m = document.getElementById('bp_mode'); if (m) { m.value = 'live'; } }); }
        var watch = document.getElementById('bp-watch-btn');
        if (watch) { watch.addEventListener('click', function () { var m = document.getElementById('bp_mode'); if (m) { m.value = 'watching'; } }); }
    })();
    initPanel(document);
})();
