<?php

/**
 * Proxys sources réutilisables (Serveurs > Proxys sources) : liste, ajout/modification/suppression, test de connexion (IP / pays de sortie).
 * Une chaîne choisit un proxy dans sa fiche (champ « Proxy (liste) ») ; modifier un proxy ici met à jour toutes ses chaînes.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Util\LayoutRenderer;

if (!Authorization::check('adv', 'servers')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    LayoutRenderer::renderFooter('admin');
    echo '</body></html>';
    return;
endif;
$rProxies = $rProxies ?? [];
$rUsage = $rUsage ?? [];
?>
<div class="card" data-testid="proxies-page">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Proxys sources (HTTP) <span class="badge bg-secondary ms-2"><?= count($rProxies); ?></span></h5>
        <div>
            <button class="btn btn-outline-secondary btn-sm me-2" id="btn-test-all" data-testid="proxy-test-all">Tout tester</button>
            <button class="btn btn-primary btn-sm" id="btn-add-proxy" data-testid="proxy-add">Ajouter un proxy</button>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted small">Choisissez ensuite le proxy dans la fiche d'une chaîne (onglet <b>Advanced</b>, champ <b>Proxy (liste)</b>). Modifier un proxy ici réécrit l'URL de toutes ses chaînes ; elles l'utilisent à leur prochain (re)démarrage.</p>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="proxies-table">
                <thead><tr><th>Nom</th><th>Type</th><th>Hôte:port</th><th>Utilisateur</th><th>Chaînes</th><th>Dernier test</th><th>Actif</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rProxies as $p): ?>
                    <tr data-id="<?= (int) $p['id']; ?>" data-testid="proxy-row-<?= (int) $p['id']; ?>" data-proxy='<?= htmlspecialchars(json_encode(['id' => (int) $p['id'], 'name' => $p['name'], 'type' => $p['type'], 'host' => $p['host'], 'port' => (int) $p['port'], 'username' => (string) $p['username'], 'enabled' => (int) $p['enabled'], 'notes' => (string) $p['notes']]), ENT_QUOTES); ?>'>
                        <td><b><?= htmlspecialchars($p['name']); ?></b><?php if ($p['notes']): ?><br><small class="text-muted"><?= htmlspecialchars($p['notes']); ?></small><?php endif; ?></td>
                        <td><span class="badge bg-info text-dark"><?= htmlspecialchars($p['type']); ?></span></td>
                        <td><code><?= htmlspecialchars($p['host'] . ':' . $p['port']); ?></code></td>
                        <td><?= htmlspecialchars((string) $p['username']) ?: '<span class="text-muted">—</span>'; ?></td>
                        <td><span class="badge bg-secondary"><?= (int) ($rUsage[$p['id']] ?? 0); ?></span></td>
                        <td class="test-cell"><?php if ($p['last_check']): ?><span class="badge <?= $p['last_ok'] ? 'bg-success' : 'bg-danger'; ?>"><?= $p['last_ok'] ? 'OK' : 'ÉCHEC'; ?></span> <small class="text-muted"><?= htmlspecialchars((string) ($p['last_country'] ?? '')); ?> <?= $p['last_ip'] ? htmlspecialchars($p['last_ip']) : ''; ?> <?= $p['last_ms'] ? (int) $p['last_ms'] . ' ms' : ''; ?><br><?= date('Y-m-d H:i', (int) $p['last_check']); ?></small><?php else: ?><span class="text-muted">jamais</span><?php endif; ?></td>
                        <td><span class="badge <?= $p['enabled'] ? 'bg-success' : 'bg-secondary'; ?>"><?= $p['enabled'] ? 'oui' : 'non'; ?></span></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary btn-test" data-testid="proxy-test-<?= (int) $p['id']; ?>">Tester</button>
                            <button class="btn btn-sm btn-outline-primary btn-edit" data-testid="proxy-edit-<?= (int) $p['id']; ?>">Modifier</button>
                            <button class="btn btn-sm btn-outline-danger btn-delete" data-testid="proxy-delete-<?= (int) $p['id']; ?>">Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rProxies): ?><tr><td colspan="8" class="text-center text-muted">Aucun proxy. Cliquez « Ajouter un proxy ».</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="proxyModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form id="proxy-form" autocomplete="off">
        <div class="modal-header"><h5 class="modal-title" id="proxy-modal-title">Proxy</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input type="hidden" name="id" id="px-id" value="0">
            <div class="mb-3"><label class="form-label">Nom</label><input class="form-control" name="name" id="px-name" required data-testid="proxy-name"></div>
            <div class="row">
                <div class="col-md-3 mb-3"><label class="form-label">Type</label><select class="form-select" name="type" id="px-type" data-testid="proxy-type"><option value="http">http</option><option value="socks5">socks5</option></select></div>
                <div class="col-md-6 mb-3"><label class="form-label">Hôte / IP</label><input class="form-control" name="host" id="px-host" required data-testid="proxy-host"></div>
                <div class="col-md-3 mb-3"><label class="form-label">Port</label><input class="form-control" type="number" min="1" max="65535" name="port" id="px-port" required data-testid="proxy-port"></div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Utilisateur</label><input class="form-control" name="username" id="px-username" data-testid="proxy-username"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Mot de passe <small class="text-muted" id="px-pass-hint"></small></label><input class="form-control" type="password" name="password" id="px-password" data-testid="proxy-password"></div>
            </div>
            <div class="mb-3"><label class="form-label">Notes</label><input class="form-control" name="notes" id="px-notes"></div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="px-enabled" checked><label class="form-check-label" for="px-enabled">Actif</label></div>
            <div class="alert alert-danger mt-3 d-none" id="px-error" data-testid="proxy-error"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary" data-testid="proxy-save">Enregistrer</button></div>
    </form>
</div></div></div>
<?php LayoutRenderer::renderFooter('admin'); ?>
<script>
(function () {
    var modal = new bootstrap.Modal(document.getElementById('proxyModal'));
    function openForm(p) {
        p = p || {id: 0, name: '', type: 'http', host: '', port: '', username: '', enabled: 1, notes: ''};
        document.getElementById('proxy-modal-title').textContent = p.id ? 'Modifier le proxy' : 'Ajouter un proxy';
        ['id', 'name', 'type', 'host', 'port', 'username', 'notes'].forEach(function (k) { document.getElementById('px-' + k).value = p[k]; });
        document.getElementById('px-password').value = '';
        document.getElementById('px-pass-hint').textContent = p.id ? '(laisser vide pour conserver)' : '';
        document.getElementById('px-enabled').checked = !!p.enabled;
        document.getElementById('px-error').classList.add('d-none');
        modal.show();
    }
    function api(action, data) {
        return $.ajax({url: './api?action=' + action, method: 'POST', data: data, dataType: 'json', headers: {'X-Requested-With': 'XMLHttpRequest'}});
    }
    document.getElementById('btn-add-proxy').addEventListener('click', function () { openForm(null); });
    document.querySelectorAll('.btn-edit').forEach(function (b) { b.addEventListener('click', function () { openForm(JSON.parse(b.closest('tr').dataset.proxy)); }); });
    document.querySelectorAll('.btn-delete').forEach(function (b) {
        b.addEventListener('click', function () {
            var tr = b.closest('tr'), p = JSON.parse(tr.dataset.proxy);
            if (!confirm('Supprimer le proxy « ' + p.name + ' » ? Les chaînes qui l\'utilisent perdront leur proxy.')) return;
            api('source_proxy_delete', {id: p.id}).done(function () { location.reload(); });
        });
    });
    function runTest(tr) {
        var cell = tr.querySelector('.test-cell');
        cell.innerHTML = '<span class="spinner-border spinner-border-sm"></span> test…';
        return api('source_proxy_test', {id: tr.dataset.id}).done(function (r) {
            cell.innerHTML = r.ok ? '<span class="badge bg-success">OK</span> <small class="text-muted">' + (r.country || '') + ' ' + (r.ip || '') + ' ' + r.ms + ' ms</small>'
                                  : '<span class="badge bg-danger">ÉCHEC</span> <small class="text-muted">' + (r.error || '') + '</small>';
        }).fail(function () { cell.innerHTML = '<span class="badge bg-danger">erreur</span>'; });
    }
    document.querySelectorAll('.btn-test').forEach(function (b) { b.addEventListener('click', function () { runTest(b.closest('tr')); }); });
    document.getElementById('btn-test-all').addEventListener('click', function () { document.querySelectorAll('#proxies-table tbody tr[data-id]').forEach(runTest); });
    document.getElementById('proxy-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var d = {};
        ['id', 'name', 'type', 'host', 'port', 'username', 'password', 'notes'].forEach(function (k) { d[k] = document.getElementById('px-' + k).value; });
        d.enabled = document.getElementById('px-enabled').checked ? 1 : 0;
        api('source_proxy_save', d).done(function (r) {
            if (r && r.result) { location.reload(); return; }
            var el = document.getElementById('px-error'); el.textContent = (r && r.error) || 'Erreur'; el.classList.remove('d-none');
        }).fail(function (x) { var el = document.getElementById('px-error'); el.textContent = 'Erreur ' + x.status; el.classList.remove('d-none'); });
    });
})();
</script>
</body>
</html>
