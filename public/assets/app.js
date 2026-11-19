// Progressive enhancement only; every page works without JavaScript.
(function () {
    'use strict';

    // Copy buttons: data-copy="#selector" or data-copy-next (copies the following <pre>).
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-copy], [data-copy-next]');
        if (!btn) { return; }
        var src = btn.hasAttribute('data-copy')
            ? document.querySelector(btn.getAttribute('data-copy'))
            : btn.parentElement.querySelector('pre');
        if (!src) { return; }
        var text = 'value' in src && src.tagName === 'INPUT' ? src.value : src.textContent;
        var done = function () {
            var label = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = label; }, 1500);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { fallback(src, text); done(); });
        } else {
            fallback(src, text);
            done();
        }
    });

    function fallback(src, text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.className = 'visually-hidden';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* ignore */ }
        document.body.removeChild(ta);
    }

    document.querySelectorAll('[data-select-on-focus]').forEach(function (el) {
        el.addEventListener('focus', function () { el.select(); });
    });

    // Confirm destructive actions.
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        var msg = form.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            ev.preventDefault();
            return;
        }
        // Busy state so double clicks do not resubmit.
        if (form.hasAttribute('data-busy') || msg) {
            var btn = form.querySelector('button[type=submit]');
            if (btn) {
                setTimeout(function () { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Working…'; }, 0);
            }
        }
    });

    // Inbox bulk delete (Developers): select all + live count.
    var bulkButton = document.querySelector('[data-bulk-button]');
    if (bulkButton) {
        var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-bulk]'));
        var all = document.querySelector('[data-check-all]');
        var countEl = bulkButton.querySelector('[data-bulk-count]');
        var syncBulk = function () {
            var n = boxes.filter(function (b) { return b.checked; }).length;
            countEl.textContent = String(n);
            bulkButton.disabled = n === 0;
            if (all) {
                all.checked = n > 0 && n === boxes.length;
                all.indeterminate = n > 0 && n < boxes.length;
            }
        };
        boxes.forEach(function (b) { b.addEventListener('change', syncBulk); });
        if (all) {
            all.addEventListener('change', function () {
                boxes.forEach(function (b) { b.checked = all.checked; });
                syncBulk();
            });
        }
        syncBulk();
    }

    // Auto-submit selects (guide platform switcher).
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.submit(); });
    });

    // Period selector: show custom dates only for "custom".
    document.querySelectorAll('[data-period-select]').forEach(function (sel) {
        var form = sel.form;
        var sync = function () {
            form.querySelectorAll('[data-custom-range]').forEach(function (f) { f.hidden = sel.value !== 'custom'; });
        };
        sel.addEventListener('change', function () {
            sync();
            if (sel.value !== 'custom') { form.submit(); }
        });
        sync();
    });

    // Password mode radios: show manual-password fields only when "set" is chosen.
    var toggles = document.querySelectorAll('[data-toggle-target]');
    var syncToggles = function () {
        toggles.forEach(function (r) {
            if (r.checked) {
                var target = document.getElementById(r.getAttribute('data-toggle-target'));
                if (target) { target.hidden = r.getAttribute('data-toggle-show') !== '1'; }
            }
        });
    };
    toggles.forEach(function (r) { r.addEventListener('change', syncToggles); });
    syncToggles();

    // Show/hide password (eye button next to password fields).
    document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
        var input = document.getElementById(btn.getAttribute('data-password-toggle'));
        if (!input) { return; }
        btn.hidden = false;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            var label = show ? 'Hide password' : 'Show password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
            input.focus();
        });
        // Always submit as a password field so browsers handle it as a password.
        if (input.form) {
            input.form.addEventListener('submit', function () { input.type = 'password'; });
        }
    });

    // Chart tooltips (hover + keyboard focus).
    var tip = null;
    var show = function (el, x, y) {
        if (!tip) { tip = document.createElement('div'); tip.className = 'tooltip'; tip.setAttribute('role', 'presentation'); document.body.appendChild(tip); }
        tip.textContent = el.getAttribute('data-tip');
        tip.style.left = x + 'px';
        tip.style.top = y + 'px';
        tip.hidden = false;
    };
    var hide = function () { if (tip) { tip.hidden = true; } };
    document.querySelectorAll('[data-tip]').forEach(function (el) {
        el.addEventListener('mousemove', function (e) { show(el, e.clientX, e.clientY); });
        el.addEventListener('mouseleave', hide);
        el.addEventListener('focus', function () { var r = el.getBoundingClientRect(); show(el, r.left + r.width / 2, r.top); });
        el.addEventListener('blur', hide);
    });
})();
