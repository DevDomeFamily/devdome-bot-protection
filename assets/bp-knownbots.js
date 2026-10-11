/**
 * Known Bots (Bot Protection 0.4.0): the DevDome dashboard's Known Bots view 1:1 (DEVDOME WEBSITE
 * features/analytics/app/botprotection.js). Switch on = allowed. Every flip saves at once through admin-ajax and
 * syncs with the dashboard when the site is connected. No confirmation dialogs, not even for search engines
 * (owner 2026-09-16: "the user sees the bots").
 */
(function () {
    'use strict';

    function bpNum(n) { n = +n || 0; return n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e4 ? Math.round(n / 1e3) + 'k' : n >= 1e3 ? (n / 1e3).toFixed(1) + 'k' : String(n); }

    // Same drawing as the dashboard's bpSparkFromVals: faint baseline for no hits, peak-preserving downsampling.
    function sparkSvg(vals) {
        var W = 440, H = 52;
        if (vals === null) {
            return '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none"><text x="' + (W / 2) + '" y="' + (H / 2 + 4) + '" text-anchor="middle" font-size="11" fill="#9ca3af">timeline unavailable</text></svg>';
        }
        if (!vals.length || !vals.some(function (v) { return v > 0; })) {
            return '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none"><line x1="0" y1="' + (H - 1.5) + '" x2="' + W + '" y2="' + (H - 1.5) + '" stroke="#e5e7eb" stroke-width="1"/></svg>';
        }
        var max = Math.max.apply(null, vals);
        var n = vals.length;
        var step = n > 1 ? W / (n - 1) : W;
        var y = function (v) { return 2 + (H - 4) * (1 - v / max); };
        var pts = vals.map(function (v, i) { return (n > 1 ? i * step : W / 2).toFixed(1) + ' ' + y(v).toFixed(1); });
        var line = 'M' + pts.join(' L');
        return '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none"><path d="' + line + ' L' + W + ' ' + H + ' L0 ' + H + ' Z" fill="rgba(25,103,210,.12)"/><path d="' + line + '" fill="none" stroke="#1967d2" stroke-width="1.5" vector-effect="non-scaling-stroke"/></svg>';
    }

    window.devdbotpKbInit = function (root) {
        root = root || document;
        var view = root.querySelector ? root.querySelector('#bp-kb-view') : null;
        if (!view || view.getAttribute('data-wired')) { return; }
        view.setAttribute('data-wired', '1');

        var data = {};
        try { data = JSON.parse(view.getAttribute('data-kb') || '{}') || {}; } catch (e) { data = {}; }
        var blocked = new Set(data.blocked || []);
        var range = '7'; // range keys = days, as the other tabs' pills (1 = Today, hourly)
        var version = 0;          // bumps on every local change
        var saving = false;
        var queued = false;
        var saveTimer = null;
        var fadeTimer = null;
        var saveEl = view.querySelector('[data-bp-save]');
        var syncText = String(data.synced || '');

        /* ---------------- rows ---------------- */

        function setRowState(row, allowed) {
            var st = row.querySelector('.bpk-state');
            if (st) { st.textContent = allowed ? 'Allowed' : 'Blocked'; st.classList.toggle('blocked', !allowed); }
            row.classList.toggle('off', !allowed);
        }
        function flashRow(row, on) {
            var fl = row.querySelector('.bpk-flash');
            if (!fl) { return; }
            fl.textContent = on ? 'Allowed' : 'Blocked';
            fl.classList.remove('ok', 'no');
            fl.classList.add(on ? 'ok' : 'no');
            row.classList.add('flashing');
            void fl.offsetWidth;
            fl.classList.add('show');
            clearTimeout(fl._t);
            fl._t = setTimeout(function () { fl.classList.remove('show'); row.classList.remove('flashing'); }, 950);
        }
        function paintSwitches() {
            view.querySelectorAll('input[data-bot]').forEach(function (cb) {
                var allowed = !blocked.has(cb.getAttribute('data-bot'));
                cb.checked = allowed;
                var row = cb.closest('.bpk-row');
                if (row) { setRowState(row, allowed); }
            });
        }

        /* ---------------- hits + sparklines ---------------- */

        function seriesFor(id) {
            if (!data.series || !data.series.ranges || !data.series.ranges[range]) { return null; }
            var r = data.series.ranges[range];
            return r.bots && r.bots[id] ? r.bots[id] : r.labels.map(function () { return 0; });
        }
        function paintHits() {
            view.querySelectorAll('.bpk-spark[data-spark]').forEach(function (sp) {
                var vals = seriesFor(sp.getAttribute('data-spark'));
                sp.innerHTML = sparkSvg(vals);
                var num = sp.parentNode.querySelector('.bpk-num');
                if (num) { num.textContent = vals === null ? '-' : bpNum(vals.reduce(function (a, b) { return a + b; }, 0)); }
            });
        }

        /* ---------------- autosave ---------------- */

        function showSave(cls, html) {
            if (!saveEl) { return; }
            saveEl.className = 'bp-autosave' + (cls ? ' ' + cls : '');
            saveEl.innerHTML = html;
        }
        function queueSave() {
            version++;
            clearTimeout(saveTimer);
            clearTimeout(fadeTimer);
            showSave('', '<span class="bp-spin"></span>Saving…');
            saveTimer = setTimeout(doSave, 300);
        }
        function doSave() {
            if (saving) { queued = true; return; }
            saving = true;
            var sent = version;
            var body = new URLSearchParams();
            body.set('action', 'devdbotp_kb_save');
            body.set('nonce', data.nonce || '');
            body.set('blocked_json', JSON.stringify(Array.from(blocked)));
            fetch(data.ajax || window.ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    saving = false;
                    if (!j || !j.success) { throw new Error('not saved'); }
                    // The server's list wins when nothing changed meanwhile (the list as the site stored it).
                    if (sent === version && Array.isArray(j.data.blocked)) {
                        blocked = new Set(j.data.blocked);
                        paintSwitches();
                    }
                    if (queued) { queued = false; doSave(); return; }
                    showSave('saved', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Saved');
                    fadeTimer = setTimeout(function () { showSave('', ''); }, 2200);
                })
                .catch(function () {
                    saving = false;
                    queued = false;
                    showSave('error', 'Not saved, flip again to retry');
                });
        }

        /* ---------------- controls ---------------- */

        view.querySelectorAll('input[data-bot]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var id = cb.getAttribute('data-bot');
                if (cb.checked) { blocked.delete(id); } else { blocked.add(id); }
                var row = cb.closest('.bpk-row');
                if (row) { setRowState(row, cb.checked); flashRow(row, cb.checked); }
                queueSave();
            });
        });
        view.querySelectorAll('[data-all]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var group = (data.groups || []).filter(function (g) { return g.id === btn.getAttribute('data-all'); })[0];
                if (!group) { return; }
                var anyAllowed = group.bots.some(function (b) { return !blocked.has(b.id); });
                group.bots.forEach(function (b) { if (anyAllowed) { blocked.add(b.id); } else { blocked.delete(b.id); } });
                paintSwitches();
                queueSave();
            });
        });
        var rec = document.getElementById('bp-kb-rec');
        if (rec) {
            rec.addEventListener('click', function () {
                var go = function () { blocked = new Set(data.rec || []); paintSwitches(); queueSave(); };
                if (window.devdbotpConfirm) { window.devdbotpConfirm('Set every bot to its recommended setting?', go, true); } else { go(); }
            });
        }
        var robots = document.getElementById('bp-kb-robots');
        if (robots) {
            robots.addEventListener('click', function () {
                // The dashboard's merge-aware SNIPPET, not a replacement file.
                var lines = [
                    '# DevDome Bot Protection - robots.txt SNIPPET (not a complete file)',
                    '# Site: ' + (data.site || '') + '   Date: ' + new Date().toISOString().slice(0, 10),
                    '#',
                    '# MERGE these groups into your existing robots.txt. Do not replace the file:',
                    '# that would discard your current rules and Sitemap entries. Keep your',
                    '# existing "User-agent: *" group and Sitemap lines as they are.',
                    '# Rules apply per host: root and www may need separate files.',
                    '#',
                    '# robots.txt is a request, not a wall: polite crawlers honor it, others',
                    '# ignore it (user-triggered fetchers like ChatGPT-User may not follow it).',
                    '# The DevDome Bot Protection plugin can enforce blocking only when it is',
                    '# installed on the site and set to Live mode.',
                    ''
                ];
                var any = false;
                (data.groups || []).forEach(function (g) {
                    g.bots.forEach(function (b) {
                        if (!blocked.has(b.id)) { return; }
                        any = true;
                        lines.push('# ' + b.name);
                        b.robots.forEach(function (t) { lines.push('User-agent: ' + t); });
                        lines.push('Disallow: /', '');
                    });
                });
                if (!any) { lines.push('# No crawlers are blocked in your Known Bots policy - nothing to add.', ''); }
                var blob = new Blob([lines.join('\n') + '\n'], { type: 'text/plain' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'devdome-robots-snippet.txt';
                document.body.appendChild(a);
                a.click();
                setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
            });
        }
        view.querySelectorAll('.date-button[data-range]').forEach(function (b) {
            b.addEventListener('click', function () {
                range = b.getAttribute('data-range');
                view.querySelectorAll('.date-button[data-range]').forEach(function (x) { x.classList.toggle('active', x === b); });
                paintHits();
            });
        });

        /* ---------------- kebab + page hint ---------------- */

        var kebab = view.querySelector('#bp-kb-kebab');
        var kmenu = view.querySelector('#bp-kb-kebab-menu');
        var hint = view.querySelector('#bp-kb-hint');
        function closeKebab() { if (kmenu) { kmenu.classList.add('hidden'); } if (kebab) { kebab.setAttribute('aria-expanded', 'false'); } }
        if (kebab && kmenu) {
            kebab.addEventListener('click', function () {
                var nowHidden = kmenu.classList.toggle('hidden');
                kebab.setAttribute('aria-expanded', nowHidden ? 'false' : 'true');
            });
            document.addEventListener('pointerdown', function (e) { if (!kmenu.classList.contains('hidden') && !kebab.parentElement.contains(e.target)) { closeKebab(); } });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeKebab(); } });
            kmenu.querySelectorAll('[data-kact]').forEach(function (it) {
                it.addEventListener('click', function () {
                    closeKebab();
                    var target = document.getElementById(it.getAttribute('data-kact'));
                    if (target) { target.click(); }
                });
            });
            var hintItem = kmenu.querySelector('[data-kb-hint]');
            if (hintItem && hint) {
                hintItem.addEventListener('click', function () {
                    closeKebab();
                    hint.hidden = !hint.hidden;
                    hintItem.setAttribute('aria-expanded', hint.hidden ? 'false' : 'true');
                });
            }
        }

        /* ---------------- sparkline tooltip (dashboard crosshair + card) ---------------- */

        var tip = document.createElement('div');
        tip.className = 'bp-tip';
        document.body.appendChild(tip);
        var cross = document.createElement('div');
        cross.className = 'bpk-cross';
        function hideTip() { tip.style.display = 'none'; if (cross.parentNode) { cross.parentNode.removeChild(cross); } }
        view.addEventListener('mousemove', function (e) {
            var sp = e.target && e.target.closest ? e.target.closest('.bpk-spark[data-spark]') : null;
            if (!sp) { hideTip(); return; }
            var vals = seriesFor(sp.getAttribute('data-spark'));
            if (!vals || !vals.length) { hideTip(); return; }
            var rect = sp.getBoundingClientRect();
            var idx = vals.length > 1 ? Math.round(((e.clientX - rect.left) / rect.width) * (vals.length - 1)) : 0;
            idx = Math.max(0, Math.min(vals.length - 1, idx));
            var label = data.series.ranges[range].labels[idx] || '';
            cross.style.left = (vals.length > 1 ? (idx / (vals.length - 1)) * rect.width : rect.width / 2) + 'px';
            if (cross.parentNode !== sp) { sp.appendChild(cross); }
            tip.innerHTML = '<span class="bp-tip-date"></span><span class="bp-tip-row"><span class="bp-tip-label"><span class="bp-tip-chip"></span><span class="bp-tip-name"></span></span><span class="bp-tip-value"></span></span>';
            tip.querySelector('.bp-tip-date').textContent = label;
            tip.querySelector('.bp-tip-name').textContent = sp.getAttribute('data-spark-name') || '';
            tip.querySelector('.bp-tip-value').textContent = bpNum(vals[idx]);
            tip.style.display = 'flex';
            var tw = tip.offsetWidth;
            var left = e.clientX + 14;
            if (left + tw > window.innerWidth - 8) { left = e.clientX - tw - 14; }
            tip.style.left = left + 'px';
            tip.style.top = (rect.top - 8 - tip.offsetHeight > 8 ? rect.top - 8 - tip.offsetHeight : rect.bottom + 8) + 'px';
        });
        view.addEventListener('mouseleave', hideTip);

        paintSwitches();
        paintHits();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { window.devdbotpKbInit(document); });
    } else {
        window.devdbotpKbInit(document);
    }
})();
