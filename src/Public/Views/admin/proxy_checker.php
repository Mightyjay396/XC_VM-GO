<?php
use XcVm\Core\Util\LayoutRenderer;


/**
 * Proxy checker (Bootstrap 5). Checks a list of proxies against a YouTube (or other
 * platform) link the way a stream uses them: the browser posts each proxy to
 * ./api?action=check_proxy, a few at a time, and fills the results table as answers come.
 */
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('proxy_checker'); ?></h4>
</div>

<div class="card mb-4">
    <div class="card-body">
        <p class="text-body-secondary"><?= $language::get('proxy_checker_info'); ?></p>
        <div class="row mb-3">
            <label class="col-md-3 col-form-label" for="pc_url"><?= $language::get('proxy_checker_url'); ?></label>
            <div class="col-md-9"><input type="text" class="form-control" id="pc_url" placeholder="https://www.youtube.com/watch?v=..."></div>
        </div>
        <div class="row mb-3">
            <label class="col-md-3 col-form-label" for="pc_list"><?= $language::get('proxy_checker_list'); ?></label>
            <div class="col-md-9"><textarea class="form-control font-monospace" id="pc_list" rows="10" placeholder="1.2.3.4:8080&#10;http://user:pass@host:port"></textarea></div>
        </div>
        <div class="row mb-4">
            <label class="col-md-3 col-form-label" for="pc_threads"><?= $language::get('proxy_checker_threads'); ?></label>
            <div class="col-md-2"><input type="number" class="form-control" id="pc_threads" min="1" max="20" value="5"></div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-primary" id="pc_start"><i class="icon-base ti tabler-player-play me-1"></i><?= $language::get('start'); ?></button>
            <button type="button" class="btn btn-label-secondary" id="pc_stop" disabled><i class="icon-base ti tabler-player-stop me-1"></i><?= $language::get('stop'); ?></button>
            <span class="ms-md-3" id="pc_counts"></span>
        </div>
        <div class="progress mt-3" style="height: 6px;"><div class="progress-bar" id="pc_progress" style="width: 0%"></div></div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <h5 class="mb-0"><?= $language::get('proxy_checker_results'); ?></h5>
        <button type="button" class="btn btn-sm btn-success" id="pc_copy" disabled><i class="icon-base ti tabler-copy me-1"></i><?= $language::get('proxy_checker_copy'); ?></button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr><th>#</th><th>Proxy</th><th><?= $language::get('status'); ?></th><th><?= $language::get('ip_address'); ?></th><th><?= $language::get('time'); ?></th></tr>
            </thead>
            <tbody id="pc_rows"></tbody>
        </table>
    </div>
</div>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        var toast = window.xcToast || function() {};
        var el = function(id) { return document.getElementById(id); };
        // ok: plays; blocked: YouTube refuses it; noplay: resolved but the video does
        // not play through it (SOCKS, or a new IP per request); dead: no answer.
        var BADGES = {
            ok: ['success', 'OK'],
            blocked: ['danger', 'Blocked by YouTube'],
            noplay: ['warning', 'Does not play'],
            dead: ['secondary', 'Not responding'],
            error: ['dark', 'Error']
        };
        var running = false, queue = [], results = [], total = 0;

        try { el('pc_url').value = localStorage.getItem('pc_url') || ''; } catch (e) {}

        function counts() {
            var c = { ok: 0, blocked: 0, noplay: 0, dead: 0, error: 0 };
            results.forEach(function(r) { if (r.status) { c[r.status]++; } });
            return c;
        }

        function refresh() {
            var c = counts(), done = c.ok + c.blocked + c.noplay + c.dead + c.error;
            el('pc_progress').style.width = (total ? Math.round(done * 100 / total) : 0) + '%';
            el('pc_counts').textContent = done + ' / ' + total + '  ·  OK ' + c.ok + '  ·  Blocked ' + c.blocked + '  ·  No play ' + c.noplay + '  ·  Dead ' + c.dead;
            el('pc_copy').disabled = c.ok === 0;
        }

        function row(r) {
            var tr = document.createElement('tr');
            [r.n, r.proxy, '', '', ''].forEach(function(v) {
                var td = document.createElement('td');
                td.textContent = v;
                tr.appendChild(td);
            });
            tr.cells[1].className = 'font-monospace text-break';
            tr.cells[2].innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            el('pc_rows').appendChild(tr);
            r.tr = tr;
        }

        function show(r) {
            var b = BADGES[r.status] || BADGES.error;
            var span = document.createElement('span');
            span.className = 'badge bg-label-' + b[0];
            span.textContent = b[1];
            r.tr.cells[2].replaceChildren(span);
            r.tr.cells[3].textContent = r.ip || '';
            r.tr.cells[4].textContent = r.seconds !== undefined ? r.seconds + ' s' : '';
            // An exit IP already taken by another working proxy is the same IP to YouTube.
            if (r.status === 'ok' && results.some(function(o) { return o !== r && o.status === 'ok' && o.ip === r.ip; })) {
                r.tr.cells[3].textContent += ' (duplicate)';
            }
            refresh();
        }

        function next() {
            if (!running) {
                return;
            }
            var r = queue.shift();
            if (!r) {
                if (results.every(function(o) { return o.status; })) {
                    finish();
                }
                return;
            }
            var body = new FormData();
            body.append('proxy', r.proxy);
            body.append('url', el('pc_url').value.trim());
            fetch('./api?action=check_proxy', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(res) { return res.json(); })
                .then(function(d) {
                    r.status = d.result ? d.status : 'error';
                    r.ip = d.ip;
                    r.seconds = d.seconds;
                    if (!d.result && d.error) {
                        toast(d.error, 'error');
                    }
                })
                .catch(function() { r.status = 'error'; })
                .then(function() { show(r); next(); });
        }

        function finish() {
            running = false;
            el('pc_start').disabled = false;
            el('pc_stop').disabled = true;
        }

        el('pc_start').addEventListener('click', function() {
            var url = el('pc_url').value.trim();
            var list = el('pc_list').value.split(/\r?\n/).map(function(s) { return s.trim(); }).filter(Boolean);
            if (!url || !list.length) {
                toast(<?= json_encode($language::get('proxy_checker_required')); ?>, 'error');
                return;
            }
            try { localStorage.setItem('pc_url', url); } catch (e) {}
            list = list.filter(function(p, i) { return list.indexOf(p) === i; });
            results = list.map(function(p, i) { return { n: i + 1, proxy: p }; });
            queue = results.slice();
            total = results.length;
            el('pc_rows').replaceChildren();
            results.forEach(row);
            running = true;
            el('pc_start').disabled = true;
            el('pc_stop').disabled = false;
            refresh();
            var threads = Math.min(20, Math.max(1, parseInt(el('pc_threads').value, 10) || 5));
            for (var i = 0; i < threads; i++) {
                next();
            }
        });

        el('pc_stop').addEventListener('click', function() {
            queue.forEach(function(r) { r.tr.cells[2].textContent = '—'; });
            queue = [];
            finish();
        });

        el('pc_copy').addEventListener('click', function() {
            var seen = {}, out = [];
            results.forEach(function(r) {
                if (r.status === 'ok' && !seen[r.ip]) {
                    seen[r.ip] = true;
                    out.push(r.proxy);
                }
            });
            var text = out.join('\n');
            var intoList = function() {
                el('pc_list').value = text;
                toast(out.length + ' working proxies put into the list', 'success');
            };
            // No clipboard API over plain http: hand the list back in the textarea.
            if (!navigator.clipboard || !window.isSecureContext) {
                intoList();
                return;
            }
            navigator.clipboard.writeText(text).then(function() {
                toast(out.length + ' copied', 'success');
            }, intoList);
        });
    })();
</script>
