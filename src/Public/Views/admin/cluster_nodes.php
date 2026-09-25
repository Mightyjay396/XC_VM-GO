<?php

/**
 * Cluster nodes (Bootstrap 5): the load balancers enrolled in the cluster API,
 * with liveness from their heartbeats; code enrolments waiting for the SAS
 * the node shows; enrolment codes for nodes MAIN cannot reach over SSH; and
 * revocation. Every action POSTs `cluster_action` back to this page
 * (ClusterNodesController → Domain\Cluster\ClusterAdmin).
 */

use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Core\Util\LayoutRenderer;

$rBadge = static fn(string $rState): string => match ($rState) {
	'ok', 'active' => 'success',
	'suspect', 'enrolling' => 'warning',
	'offline', 'revoked', 'quarantined' => 'danger',
	default => 'secondary',
};
$rWhen = static fn(?int $rTs): string => $rTs ? gmdate('Y-m-d H:i:s', $rTs) . ' UTC' : '—';
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('cluster_nodes'); ?></h4>
</div>

<?php if (!empty($clusterFlash)): ?>
    <div class="alert alert-<?= htmlspecialchars($clusterFlash['type'], ENT_QUOTES); ?> alert-dismissible" role="alert">
        <?= htmlspecialchars($language::get($clusterFlash['message']), ENT_QUOTES); ?>
        <?php if (!empty($clusterFlash['code'])): ?>
            <div class="mt-3">
                <code class="d-block fs-5 text-break user-select-all js-cluster-code"><?= htmlspecialchars($clusterFlash['code'], ENT_QUOTES); ?></code>
                <div class="mt-2 small"><?= $language::get('cluster_code_run_on_node'); ?></div>
                <code class="d-block text-break user-select-all small">sudo -u xc_vm /home/xc_vm/bin/xc_agent/xc_agent enrol -state /home/xc_vm/config/cluster/agent.json <?= htmlspecialchars($clusterFlash['code'], ENT_QUOTES); ?></code>
            </div>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (empty($clusterEnabled)): ?>
    <div class="alert alert-info" role="alert"><?= $language::get('cluster_api_off'); ?> <a href="settings#cluster"><?= $language::get('cluster'); ?></a></div>
<?php endif; ?>

<?php if (ClusterHealth::read()['guard']): ?>
    <div class="alert alert-danger" role="alert"><i class="icon-base ti tabler-alert-triangle me-1"></i><?= $language::get('cluster_fleet_silence'); ?></div>
<?php endif; ?>

<?php if (!empty($clusterPending)): ?>
    <div class="card mb-4 border-warning">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="icon-base ti tabler-user-check me-1"></i><?= $language::get('cluster_pending_enrolments'); ?></h5>
        </div>
        <div class="card-body">
            <p class="text-body-secondary"><?= $language::get('cluster_sas_help'); ?></p>
            <?php foreach ($clusterPending as $rReq): ?>
                <form method="POST" class="row g-2 align-items-center mb-2">
                    <input type="hidden" name="server_id" value="<?= (int) $rReq['server_id']; ?>">
                    <div class="col-md-4">
                        <strong><?= htmlspecialchars($rReq['server_name'], ENT_QUOTES); ?></strong>
                        <div class="small text-body-secondary"><?= htmlspecialchars((string) $rReq['node_uuid'], ENT_QUOTES); ?> · <?= $rWhen((int) $rReq['created_at']); ?></div>
                    </div>
                    <div class="col-md-4"><input type="text" name="sas" class="form-control font-monospace" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false"></div>
                    <div class="col-md-4 text-nowrap">
                        <button type="submit" name="cluster_action" value="approve" class="btn btn-success"><i class="icon-base ti tabler-check me-1"></i><?= $language::get('cluster_approve'); ?></button>
                        <button type="submit" name="cluster_action" value="reject" class="btn btn-label-danger"><?= $language::get('cluster_reject'); ?></button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="icon-base ti tabler-topology-star-3 me-1"></i><?= $language::get('cluster_nodes'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table class="table" style="width:100%">
            <thead>
                <tr>
                    <th><?= $language::get('server_name'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('cluster_mode'); ?></th>
                    <th><?= $language::get('cluster_telemetry_flow'); ?></th>
                    <th><?= $language::get('cluster_epoch'); ?></th>
                    <th><?= $language::get('cluster_token_expires'); ?></th>
                    <th><?= $language::get('cluster_last_seen'); ?></th>
                    <th><?= $language::get('cluster_agent'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clusterNodes)): ?>
                    <tr><td colspan="9" class="text-center text-body-secondary"><?= $language::get('cluster_no_nodes'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($clusterNodes as $rNode): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($rNode['server_name'], ENT_QUOTES); ?>
                            <div class="small text-body-secondary font-monospace"><?= htmlspecialchars((string) $rNode['node_uuid'], ENT_QUOTES); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-label-<?= $rBadge((string) $rNode['health']); ?>"><?= htmlspecialchars((string) $rNode['health'], ENT_QUOTES); ?></span>
                            <?php if (!empty($rNode['quarantine_reason'])): ?>
                                <div class="small text-body-secondary"><?= htmlspecialchars((string) $rNode['quarantine_reason'], ENT_QUOTES); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $rNode['mode']; ?></td>
                        <td>
                            <?php $rTel = ((int) $rNode['flows'] & 1) === 1; ?>
                            <?php if (in_array($rNode['state'], ['active', 'quarantined'], true)): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                    <button type="submit" name="cluster_action" value="<?= $rTel ? 'telemetry_off' : 'telemetry_on'; ?>" class="btn btn-sm <?= $rTel ? 'btn-label-success' : 'btn-label-secondary'; ?>" title="<?= $language::get('cluster_telemetry_help'); ?>"><?= $language::get($rTel ? 'cluster_flow_on' : 'cluster_flow_off'); ?></button>
                                </form>
                            <?php else: ?>
                                <span class="text-body-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $rNode['epoch']; ?> <span class="small text-body-secondary">(gen <?= (int) $rNode['gen']; ?>)</span></td>
                        <td><?= $rWhen($rNode['token_exp'] === null ? null : (int) $rNode['token_exp']); ?></td>
                        <td><?= $rWhen($rNode['last_seen_at'] === null ? null : intdiv((int) $rNode['last_seen_at'], 1000)); ?></td>
                        <td><?= htmlspecialchars((string) ($rNode['agent_version'] ?? '—'), ENT_QUOTES); ?></td>
                        <td class="text-nowrap">
                            <?php if ($rNode['state'] !== 'revoked'): ?>
                                <form method="POST" class="d-inline js-cluster-revoke">
                                    <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                    <button type="submit" name="cluster_action" value="revoke" class="btn btn-sm btn-label-danger"><?= $language::get('cluster_revoke'); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="icon-base ti tabler-key me-1"></i><?= $language::get('cluster_enrol_by_code'); ?></h5>
    </div>
    <div class="card-body">
        <p class="text-body-secondary"><?= $language::get('cluster_code_help'); ?></p>
        <form method="POST" class="row g-2">
            <div class="col-md-4">
                <select name="server_id" class="form-select" required>
                    <?php foreach ($clusterLbs as $rID => $rName): ?>
                        <option value="<?= (int) $rID; ?>"><?= htmlspecialchars($rName, ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5"><input type="text" name="url" class="form-control" placeholder="<?= $language::get('cluster_code_url_placeholder'); ?>"></div>
            <div class="col-md-3"><button type="submit" name="cluster_action" value="code" class="btn btn-primary w-100"<?= empty($clusterLbs) ? ' disabled' : ''; ?>><?= $language::get('cluster_issue_code'); ?></button></div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.js-cluster-revoke').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        if (!confirm(<?= json_encode($language::get('cluster_revoke_confirm')); ?>)) {
            e.preventDefault();
        }
    });
});
</script>

<?php
LayoutRenderer::renderFooter('admin');
?>
