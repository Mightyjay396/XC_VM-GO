/**
 * Admin global quick search — client renderer for the `?action=search` JSON.
 *
 * The server returns structured data only (docs/adr/search-json-contract.md);
 * this file owns all markup. `renderSearchItem(item)` turns one contract item
 * into an HTML string (every server string is escaped), and
 * `XcQuickSearch.init()` wires it into a Select2 box that replaces the navbar
 * `#xc-quick-search` input.
 *
 * Action kinds (self-describing, no per-action logic):
 *   navigate    -> navigate(target)
 *   api         -> searchAPI(entity, id, sub)   id = action.id (item id fallback)
 *   fingerprint -> modalFingerprint(id, context)
 *   credits     -> addCredits(id)
 *
 * Localised strings come from window.XC_SEARCH_I18N (set by footer.php).
 */
(function(root) {
    'use strict';

    var ENTITY_API = {
        stream: { action: 'stream', key: 'stream_id', server: true },
        channel: { action: 'stream', key: 'stream_id', server: true },
        radio: { action: 'stream', key: 'stream_id', server: true },
        movie: { action: 'movie', key: 'stream_id', server: true },
        episode: { action: 'episode', key: 'stream_id', server: true },
        line: { action: 'line', key: 'user_id', server: false },
        user: { action: 'reg_user', key: 'user_id', server: false }
    };

    // Legacy (mdi/fa) icon names from the contract -> Tabler icons shipped with the new UI.
    var ICONS = {
        'mdi-pencil': 'tabler-pencil',
        'mdi-stop': 'tabler-player-stop',
        'mdi-play': 'tabler-player-play',
        'mdi-refresh': 'tabler-refresh',
        'mdi-hammer': 'tabler-hammer',
        'fa-hammer': 'tabler-hammer',
        'mdi-fingerprint': 'tabler-fingerprint',
        'mdi-coin': 'tabler-coin',
        'mdi-lock': 'tabler-lock',
        'mdi-power': 'tabler-power',
        'mdi-plus-circle-outline': 'tabler-circle-plus',
        'mdi-eye': 'tabler-eye'
    };

    // Legacy badge variants -> new-UI bg-label-* palette.
    var VARIANTS = {
        purple: 'primary',
        pink: 'danger',
        success: 'success',
        danger: 'danger',
        info: 'info',
        warning: 'warning',
        primary: 'primary',
        secondary: 'secondary',
        dark: 'dark'
    };

    var ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;', '=': '&#61;', '/': '&#47;' };

    /** HTML-escape any value for text or quoted-attribute context. */
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"'`=\/]/g, function(c) {
            return ESC_MAP[c];
        });
    }

    function t(key, fallback) {
        var dict = root.XC_SEARCH_I18N || {};
        return (dict[key] != null && dict[key] !== '') ? String(dict[key]) : fallback;
    }

    function variant(v) {
        return VARIANTS[v] || 'secondary';
    }

    function icon(name) {
        var n = String(name || '');
        if (ICONS[n]) {
            return ICONS[n];
        }
        return /^tabler-[a-z0-9-]+$/.test(n) ? n : 'tabler-point';
    }

    /**
     * Only same-origin relative targets or http(s) URLs are followed; anything
     * else (javascript:, data:, protocol-relative …) is dropped.
     */
    function safeUrl(u) {
        if (u == null) {
            return '';
        }
        var s = String(u).trim();
        if (s === '' || /^\/\//.test(s)) {
            return '';
        }
        var scheme = /^([a-z][a-z0-9+.-]*):/i.exec(s);
        if (scheme && !/^https?$/i.test(scheme[1])) {
            return '';
        }
        return s;
    }

    function num(v) {
        var n = parseInt(v, 10);
        return isNaN(n) ? 0 : n;
    }

    function badge(b) {
        if (!b || b.text == null || b.text === '') {
            return '';
        }
        return '<span class="badge bg-label-' + variant(b.variant) + ' text-uppercase">' + esc(b.text) + '</span>';
    }

    function link(url, inner, cls) {
        var href = safeUrl(url);
        if (!href) {
            return '<span class="' + (cls || '') + '">' + inner + '</span>';
        }
        return '<a class="' + (cls || '') + '" href="' + esc(href) + '">' + inner + '</a>';
    }

    function image(img, fallbackIcon) {
        var src = img ? safeUrl(img.url) : '';
        var tall = img && num(img.size) >= 512;
        var box = 'xc-qs-thumb flex-shrink-0 rounded bg-label-secondary d-flex align-items-center justify-content-center overflow-hidden' + (tall ? ' xc-qs-thumb-tall' : '');
        if (!src) {
            return '<div class="' + box + '"><i class="icon-base ti ' + fallbackIcon + '"></i></div>';
        }
        return '<div class="' + box + '"><img src="' + esc(src) + '" alt="" loading="lazy" referrerpolicy="no-referrer"></div>';
    }

    function status(st) {
        if (!st) {
            return '';
        }
        if (st.kind === 'uptime') {
            return '<span class="badge bg-label-success"><i class="icon-base ti tabler-clock icon-xs me-1"></i>' + esc(st.text) + '</span>';
        }
        if (st.kind === 'progress') {
            var p = Math.max(0, Math.min(100, num(st.percent)));
            return '<div class="progress xc-qs-progress" title="' + p + '%"><div class="progress-bar bg-warning" role="progressbar" style="width:' + p + '%" aria-valuenow="' + p + '" aria-valuemin="0" aria-valuemax="100">' + p + '%</div></div>';
        }
        if (st.label == null || st.label === '') {
            return '';
        }
        return '<span class="badge bg-label-' + variant(st.variant) + '">' + esc(st.label) + '</span>';
    }

    function rating(r) {
        if (!r) {
            return '';
        }
        var full = Math.max(0, Math.min(5, num(r.stars_full)));
        var empty = Math.max(0, Math.min(5, num(r.empty)));
        var html = '';
        var i;
        for (i = 0; i < full; i++) {
            html += '<i class="icon-base ti tabler-star-filled icon-xs text-warning"></i>';
        }
        if (r.half) {
            html += '<i class="icon-base ti tabler-star-half-filled icon-xs text-warning"></i>';
        }
        for (i = 0; i < empty; i++) {
            html += '<i class="icon-base ti tabler-star icon-xs text-body-secondary"></i>';
        }
        if (r.year) {
            html += '<small class="text-body-secondary ms-1">' + esc(r.year) + '</small>';
        }
        return html ? '<div class="d-flex align-items-center">' + html + '</div>' : '';
    }

    function meta(label, value) {
        if (value == null || value === '') {
            return '';
        }
        return '<small class="text-body-secondary text-nowrap me-3"><span class="fw-medium">' + esc(label) + ':</span> ' + esc(value) + '</small>';
    }

    function actions(list) {
        if (!list || !list.length) {
            return '';
        }
        var html = list.map(function(a, idx) {
            if (!a || typeof a !== 'object') {
                return '';
            }
            var title = a.title || (a.kind === 'fingerprint' ? t('fingerprint', 'Fingerprint') : '');
            var disabled = a.enabled === false;
            return '<button type="button" class="btn btn-sm btn-icon btn-text-secondary rounded-pill"' +
                ' data-xc-search-action="' + idx + '"' +
                (title ? ' title="' + esc(title) + '" aria-label="' + esc(title) + '"' : '') +
                (disabled ? ' disabled aria-disabled="true"' : '') +
                '><i class="icon-base ti ' + icon(a.icon) + ' icon-sm"></i></button>';
        }).join('');
        return '<div class="xc-qs-actions d-flex flex-wrap gap-1 mt-2">' + html + '</div>';
    }

    function wrap(item, thumb, body) {
        return '<div class="xc-qs-item d-flex gap-3 align-items-start" data-xc-search-id="' + esc(item.id) + '">' +
            thumb + '<div class="flex-grow-1 min-w-0">' + body + '</div></div>';
    }

    function renderStreamLike(item, d) {
        var titleHtml = link(d.title_link, esc(d.title != null ? d.title : item.text), 'fw-medium text-heading text-truncate');
        var conn = link(d.connections_link, '<i class="icon-base ti tabler-users icon-xs me-1"></i>' + esc(num(d.connections)), 'badge bg-label-' + (num(d.connections) > 0 ? 'success' : 'secondary'));
        var line2 = meta(t('category', 'Category'), d.category) + meta(t('server', 'Server'), d.server);
        var body =
            '<div class="d-flex align-items-center gap-2 flex-wrap">' + badge(d.badge) + '<span class="text-truncate">' + titleHtml + '</span></div>' +
            (line2 ? '<div class="d-flex flex-wrap mt-1">' + line2 + '</div>' : '') +
            '<div class="d-flex align-items-center gap-2 flex-wrap mt-1">' + status(d.status) + '<span title="' + esc(t('connections', 'Connections')) + '">' + conn + '</span>' + rating(d.rating) + '</div>' +
            actions(d.actions);
        var fallback = d.layout === 'vod' ? 'tabler-movie' : 'tabler-broadcast';
        return wrap(item, image(d.image, fallback), body);
    }

    function renderSeries(item, d) {
        var body =
            '<div class="d-flex align-items-center gap-2 flex-wrap">' + badge(d.badge) + '<span class="fw-medium text-heading text-truncate">' + esc(d.title != null ? d.title : item.text) + '</span></div>' +
            '<div class="d-flex flex-wrap mt-1">' + meta(t('category', 'Category'), d.category) + meta(t('seasons', 'Seasons'), num(d.seasons)) + meta(t('episodes', 'Episodes'), num(d.episodes)) + '</div>' +
            (d.rating ? '<div class="mt-1">' + rating(d.rating) + '</div>' : '') +
            actions(d.actions);
        return wrap(item, image(d.image, 'tabler-device-tv'), body);
    }

    function renderUser(item, d) {
        var body =
            '<div class="d-flex align-items-center gap-2 flex-wrap">' + badge(d.badge) + '<span class="fw-medium text-heading text-truncate">' + esc(d.username != null ? d.username : item.text) + '</span>' + status(d.status) + '</div>' +
            '<div class="d-flex flex-wrap mt-1">' +
            meta(t('member_group', 'Member Group'), d.group) +
            meta(t('owner', 'Owner'), d.owner) +
            (d.is_reseller ? meta(t('credits', 'Credits'), num(d.credits)) : '') +
            meta(t('users', 'Users'), num(d.users_count)) +
            meta(t('lines', 'Lines'), num(d.lines_count)) +
            '</div>' +
            actions(d.actions);
        return wrap(item, '<div class="xc-qs-thumb flex-shrink-0 rounded bg-label-warning d-flex align-items-center justify-content-center"><i class="icon-base ti tabler-user"></i></div>', body);
    }

    function renderLine(item, d) {
        var device = d.device_type === 'mag' ? 'MAG' : (d.device_type === 'enigma' ? 'ENIGMA2' : 'LINE');
        var la = d.last_active || {};
        var active;
        if (la.online) {
            active = '<small class="text-success text-nowrap me-3"><i class="icon-base ti tabler-player-play icon-xs me-1"></i>' +
                (la.stream_id ? link('stream_view?id=' + num(la.stream_id), esc(la.stream_name || ('#' + num(la.stream_id))), 'text-success') : '') +
                (la.online_for ? ' <span class="text-body-secondary">(' + esc(la.online_for) + ')</span>' : '') + '</small>';
        } else {
            active = meta(t('last_active', 'Last Active'), la.date ? la.date : t('never', 'Never'));
        }
        var flags = d.flags || {};
        var flagHtml = (flags.restreamer ? '<span class="badge bg-label-info">' + esc(t('restreamer', 'Restreamer')) + '</span>' : '') +
            (flags.trial ? '<span class="badge bg-label-warning">' + esc(t('trial', 'Trial')) + '</span>' : '');
        var body =
            '<div class="d-flex align-items-center gap-2 flex-wrap">' +
            '<span class="badge bg-label-' + variant(d.badge && d.badge.variant) + ' text-uppercase">' + esc(device) + '</span>' +
            '<span class="fw-medium text-heading text-truncate">' + esc(d.title != null ? d.title : item.text) + '</span>' +
            status(d.status) + flagHtml + '</div>' +
            '<div class="d-flex flex-wrap mt-1">' +
            meta(t('owner', 'Owner'), d.owner) +
            meta(t('expires', 'Expires'), d.expires ? d.expires : t('never', 'Never')) +
            meta(t('connections', 'Connections'), num(d.connections)) +
            active + '</div>' +
            actions(d.actions);
        var ico = d.device_type ? 'tabler-device-tv' : 'tabler-user-circle';
        return wrap(item, '<div class="xc-qs-thumb flex-shrink-0 rounded bg-label-danger d-flex align-items-center justify-content-center"><i class="icon-base ti ' + ico + '"></i></div>', body);
    }

    /**
     * Render one search item (contract shape) to an HTML string. All server
     * data is escaped; unknown entities fall back to the plain `text` label.
     */
    function renderSearchItem(item) {
        if (!item || typeof item !== 'object') {
            return '';
        }
        var d = (item.data && typeof item.data === 'object') ? item.data : null;
        if (d) {
            switch (item.entity) {
                case 'stream':
                case 'movie':
                case 'channel':
                case 'radio':
                case 'episode':
                    return renderStreamLike(item, d);
                case 'series':
                    return renderSeries(item, d);
                case 'user':
                    return renderUser(item, d);
                case 'line':
                case 'mag':
                case 'enigma':
                    return renderLine(item, d);
            }
        }
        return '<div class="xc-qs-item" data-xc-search-id="' + esc(item.id) + '"><span class="fw-medium">' + esc(item.text) + '</span></div>';
    }

    // ── Actions ────────────────────────────────────────────────────────

    function toast(msg, type) {
        if (typeof root.xcToast === 'function') {
            root.xcToast(msg, type);
        }
    }

    function getJson(url) {
        return root.fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function(r) {
            return r.json();
        });
    }

    function query(params) {
        return Object.keys(params).map(function(k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
    }

    function navigate(target) {
        var href = safeUrl(target);
        if (href) {
            root.location.href = href;
        }
    }

    /** Run an `api` action: map the item entity onto its admin ajax endpoint. */
    function searchAPI(entity, id, sub) {
        var ep = ENTITY_API[entity];
        var rid = num(id);
        if (!ep || rid <= 0 || !sub) {
            toast(t('error', 'Error'), 'error');
            return Promise.resolve(false);
        }
        var params = { action: ep.action, sub: sub };
        params[ep.key] = rid;
        if (ep.server) {
            params.server_id = -1;
        }
        return getJson('./api?' + query(params)).then(function(r) {
            var ok = !!(r && r.result);
            toast(ok ? t('search_action_done', 'Done') : t('error', 'Error'), ok ? 'success' : 'error');
            return ok;
        }).catch(function() {
            toast(t('error', 'Error'), 'error');
            return false;
        });
    }

    function swalForm(opts) {
        if (!root.Swal) {
            return Promise.resolve(null);
        }
        return root.Swal.fire({
            title: opts.title,
            html: opts.html,
            showCancelButton: true,
            confirmButtonText: opts.confirm,
            cancelButtonText: t('cancel', 'Cancel'),
            focusConfirm: false,
            customClass: {
                confirmButton: 'btn btn-primary',
                cancelButton: 'btn btn-label-secondary ms-2'
            },
            buttonsStyling: false,
            didOpen: opts.didOpen,
            preConfirm: opts.preConfirm
        }).then(function(r) {
            return r && r.isConfirmed ? r.value : null;
        });
    }

    function field(id) {
        var el = document.getElementById(id);
        return el ? el.value : '';
    }

    /** Fingerprint overlay dialog for a stream (context 'stream') or a line (context 'user'). */
    function modalFingerprint(id, context) {
        var rid = num(id);
        if (rid <= 0) {
            return Promise.resolve(false);
        }
        var html =
            '<div class="text-start">' +
            '<label class="form-label" for="xc-fp-type">' + esc(t('type', 'Type')) + '</label>' +
            '<select id="xc-fp-type" class="form-select mb-2">' +
            '<option value="1">' + esc(t('activity_id', 'Activity ID')) + '</option>' +
            '<option value="2">' + esc(t('username', 'Username')) + '</option>' +
            '<option value="3">' + esc(t('message', 'Message')) + '</option>' +
            '</select>' +
            '<div class="row g-2 mb-2">' +
            '<div class="col-4"><label class="form-label" for="xc-fp-size">' + esc(t('size', 'Size')) + '</label><input id="xc-fp-size" type="number" min="1" class="form-control" value="36"></div>' +
            '<div class="col-4"><label class="form-label" for="xc-fp-color">' + esc(t('colour', 'Colour')) + '</label><input id="xc-fp-color" type="color" class="form-control form-control-color w-100" value="#ffffff"></div>' +
            '<div class="col-4"><label class="form-label" for="xc-fp-x">' + esc(t('position', 'Pos')) + '</label><div class="d-flex gap-1"><input id="xc-fp-x" type="number" min="0" class="form-control px-1 text-center" value="10"><input id="xc-fp-y" type="number" min="0" class="form-control px-1 text-center" value="10"></div></div>' +
            '</div>' +
            '<input id="xc-fp-message" type="text" class="form-control" style="display:none" placeholder="' + esc(t('custom_message', 'Custom Message')) + '">' +
            '</div>';
        return swalForm({
            title: t('fingerprint', 'Fingerprint'),
            html: html,
            confirm: t('fingerprint', 'Fingerprint'),
            didOpen: function() {
                var sel = document.getElementById('xc-fp-type');
                var msg = document.getElementById('xc-fp-message');
                if (sel && msg) {
                    sel.addEventListener('change', function() {
                        msg.style.display = sel.value === '3' ? '' : 'none';
                    });
                }
            },
            preConfirm: function() {
                var data = {
                    id: rid,
                    font_size: field('xc-fp-size'),
                    font_color: field('xc-fp-color'),
                    message: '',
                    type: field('xc-fp-type'),
                    xy_offset: ''
                };
                if (data.type === '3') {
                    data.message = field('xc-fp-message');
                }
                var x = field('xc-fp-x');
                var y = field('xc-fp-y');
                if (x !== '' && y !== '' && num(x) >= 0 && num(y) >= 0) {
                    data.xy_offset = num(x) + 'x' + num(y);
                }
                if (context === 'user') {
                    data.user = 1;
                }
                if (!(num(data.font_size) > 0) || !data.font_color || !data.xy_offset || (data.type === '3' && !data.message)) {
                    root.Swal.showValidationMessage(t('fingerprint_fail', 'Invalid fingerprint configuration.'));
                    return false;
                }
                return data;
            }
        }).then(function(data) {
            if (!data) {
                return false;
            }
            return getJson('./api?' + query({ action: 'fingerprint', data: JSON.stringify(data) })).then(function(r) {
                var ok = !!(r && r.result);
                toast(ok ? t('fingerprint_success', 'Fingerprint has been requested.') : t('fingerprint_fail', 'Invalid fingerprint configuration.'), ok ? 'success' : 'error');
                return ok;
            });
        }).catch(function() {
            toast(t('error', 'Error'), 'error');
            return false;
        });
    }

    /** Adjust a reseller's credits (positive adds, negative subtracts). */
    function addCredits(id) {
        var rid = num(id);
        if (rid <= 0) {
            return Promise.resolve(false);
        }
        var html =
            '<div class="text-start">' +
            '<label class="form-label" for="xc-cr-amount">' + esc(t('amount', 'Amount')) + '</label>' +
            '<input id="xc-cr-amount" type="number" class="form-control mb-2" value="0">' +
            '<input id="xc-cr-reason" type="text" class="form-control" placeholder="' + esc(t('reason_for_adjustment', 'Reason for Adjustment...')) + '">' +
            '</div>';
        return swalForm({
            title: t('add_credits', 'Add Credits'),
            html: html,
            confirm: t('add_credits', 'Add Credits'),
            preConfirm: function() {
                var amount = field('xc-cr-amount');
                if (amount === '' || isNaN(parseInt(amount, 10))) {
                    root.Swal.showValidationMessage(t('error', 'Error'));
                    return false;
                }
                return { credits: parseInt(amount, 10), reason: field('xc-cr-reason') };
            }
        }).then(function(v) {
            if (!v) {
                return false;
            }
            return getJson('./api?' + query({ action: 'adjust_credits', id: rid, credits: v.credits, reason: v.reason })).then(function(r) {
                var ok = !!(r && r.result);
                toast(ok ? t('search_action_done', 'Done') : t('error', 'Error'), ok ? 'success' : 'error');
                return ok;
            });
        }).catch(function() {
            toast(t('error', 'Error'), 'error');
            return false;
        });
    }

    /**
     * Numeric id an `api` action acts on. The contract carries it on the action
     * as `id` (for a MAG/Enigma2 item, the owning line's id). Without one, fall
     * back to the item's `<table>#<id>` suffix — except for a device item, whose
     * own id is not what its actions act on, so it yields 0 (no request).
     */
    function actionTargetId(item, action) {
        if (action && action.id != null) {
            return num(action.id);
        }
        if (!item || item.entity === 'mag' || item.entity === 'enigma') {
            return 0;
        }
        var m = /#(\d+)$/.exec(String(item.id || ''));
        return m ? num(m[1]) : 0;
    }

    /** Dispatch one self-describing action. Returns a Promise<boolean> (true = state may have changed). */
    function runAction(item, action) {
        if (!action || action.enabled === false) {
            return Promise.resolve(false);
        }
        switch (action.kind) {
            case 'navigate':
                navigate(action.target);
                return Promise.resolve(false);
            case 'api':
                return searchAPI(action.entity, actionTargetId(item, action), action.sub);
            case 'fingerprint':
                return (root.modalFingerprint || modalFingerprint)(action.id, action.context);
            case 'credits':
                return (root.addCredits || addCredits)(action.id);
        }
        return Promise.resolve(false);
    }

    // ── Select2 wiring ─────────────────────────────────────────────────

    var itemsById = {};

    function init() {
        var $ = root.jQuery;
        var input = document.getElementById('xc-quick-search');
        if (!input || !$ || !$.fn || !$.fn.select2 || input.getAttribute('data-xc-qs') === '1') {
            return false;
        }
        input.setAttribute('data-xc-qs', '1');

        var host = input.parentNode;
        host.classList.add('xc-qs-wrap');
        var legacyBox = document.getElementById('xc-search-results');
        if (legacyBox) {
            legacyBox.parentNode.removeChild(legacyBox);
        }
        input.style.display = 'none';

        var select = document.createElement('select');
        select.id = 'xc-quick-search-select';
        select.className = 'form-select form-select-sm';
        select.setAttribute('aria-label', input.getAttribute('placeholder') || t('search', 'Search'));
        select.appendChild(document.createElement('option'));
        host.appendChild(select);

        var $sel = $(select);
        $sel.select2({
            width: '100%',
            dropdownParent: $(host),
            placeholder: input.getAttribute('placeholder') || '',
            minimumInputLength: 3,
            ajax: {
                url: './api',
                dataType: 'json',
                delay: 300,
                cache: false,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                data: function(params) {
                    return { action: 'search', search: params.term || '' };
                },
                processResults: function(data) {
                    var items = (data && Array.isArray(data.items)) ? data.items : [];
                    itemsById = {};
                    items.forEach(function(it) {
                        if (it && it.id != null) {
                            itemsById[String(it.id)] = it;
                        }
                    });
                    return { results: items };
                }
            },
            templateResult: function(item) {
                if (!item || item.loading || !item.entity) {
                    return item ? item.text : '';
                }
                // A jQuery node (not a string) so Select2 does not re-escape our markup.
                return $('<div class="xc-qs-result"></div>').html(renderSearchItem(item));
            },
            templateSelection: function(item) {
                return (item && item.text) || '';
            },
            language: {
                inputTooShort: function(args) {
                    var n = args ? args.minimum - (args.input || '').length : 3;
                    return t('search_input_too_short', 'Please enter {count} or more characters').replace('{count}', String(n));
                },
                searching: function() {
                    return t('search_searching', 'Searching...');
                },
                noResults: function() {
                    return t('no_results', 'No results');
                },
                errorLoading: function() {
                    return t('search_error_loading', 'The results could not be loaded.');
                }
            }
        });

        $sel.on('select2:select', function(e) {
            var data = e.params && e.params.data;
            $sel.val(null).trigger('change.select2');
            if (data && data.url) {
                navigate(data.url);
            }
        });

        // Buttons/links inside a result must not select the row: Select2
        // selects on `mouseup` delegated from the results list, so stop it at
        // the host in the capture phase before it gets there.
        host.addEventListener('mouseup', function(e) {
            if (e.target && e.target.closest && e.target.closest('.xc-qs-item [data-xc-search-action], .xc-qs-item a[href]')) {
                e.stopPropagation();
            }
        }, true);

        // Capture phase too, so no Select2 handler can swallow the click first.
        host.addEventListener('click', function(e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-xc-search-action]') : null;
            if (!btn || !host.contains(btn)) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            if (btn.disabled) {
                return;
            }
            var row = btn.closest('[data-xc-search-id]');
            var item = row ? itemsById[row.getAttribute('data-xc-search-id')] : null;
            var list = item && item.data && item.data.actions;
            var action = list ? list[num(btn.getAttribute('data-xc-search-action'))] : null;
            if (!action) {
                return;
            }
            runAction(item, action).then(function(changed) {
                if (!changed) {
                    return;
                }
                // Refresh the open result list so the card reflects the new state.
                var field2 = host.querySelector('.select2-search__field');
                if (field2 && field2.value.length >= 3) {
                    $(field2).trigger('keyup');
                }
            });
        }, true);

        return true;
    }

    var api = {
        renderSearchItem: renderSearchItem,
        escapeHtml: esc,
        safeUrl: safeUrl,
        actionTargetId: actionTargetId,
        runAction: runAction,
        init: init
    };

    root.renderSearchItem = renderSearchItem;
    root.XcQuickSearch = api;
    if (typeof root.searchAPI !== 'function') {
        root.searchAPI = searchAPI;
    }
    if (typeof root.modalFingerprint !== 'function') {
        root.modalFingerprint = modalFingerprint;
    }
    if (typeof root.addCredits !== 'function') {
        root.addCredits = addCredits;
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    }
})(typeof window !== 'undefined' ? window : globalThis);
