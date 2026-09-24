<?php
use XcVm\Core\Util\LayoutRenderer;


/**
 * Category Templates Management View (Admin).
 *
 * The admin counterpart of reseller/category_templates.php: every template on
 * the panel as cards, filtered by owner and name, with create (optionally as a
 * system template), clone, delete, system on/off and "apply to all lines" —
 * all through the category_template_* actions of CategoryTemplateAjaxController.
 */

$rTemplates    = $templates ?? [];
$rOwners       = $owners ?? [];
$rSearch       = (string) ($search ?? '');
$rSelectedOwner = $selectedOwner ?? null;
?>

<div class="container-xxl flex-grow-1 container-p-y">
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="icon-base ti tabler-layout-grid text-primary fs-3"></i>
                <span><?= $language::get('category_templates'); ?></span>
                <span class="badge bg-label-primary rounded-pill fs-7"><?= count($rTemplates); ?> <?= $language::get('templates'); ?></span>
            </h4>
            <p class="text-muted mb-0"><?= $language::get('category_templates_desc'); ?></p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-primary d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                <i class="icon-base ti tabler-plus"></i>
                <span><?= $language::get('create_category_template'); ?></span>
            </button>
        </div>
    </div>

    <!-- Filter: name and owner -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body p-3">
            <form method="GET" action="category_templates" id="filterForm" class="row g-3 align-items-center">
                <div class="col-12 col-md-6">
                    <div class="input-group input-group-merge">
                        <span class="input-group-text"><i class="icon-base ti tabler-search"></i></span>
                        <input type="text" name="search" id="liveSearchInput" class="form-control" placeholder="<?= $language::get('search_templates'); ?>" value="<?= htmlspecialchars($rSearch, ENT_QUOTES); ?>">
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <select name="owner_id" id="filterOwner" class="form-select">
                        <option value=""><?= $language::get('all_templates'); ?></option>
                        <?php foreach ($rOwners as $rOwnerID => $rOwner): ?>
                            <?php if (empty($rOwner['username'])): continue;
                            endif; ?>
                            <option value="<?= (int) $rOwnerID; ?>" <?= ((int) $rSelectedOwner === (int) $rOwnerID) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rOwner['username'], ENT_QUOTES); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-label-secondary w-100">
                        <i class="icon-base ti tabler-filter me-1"></i> <?= $language::get('filter'); ?>
                    </button>
                    <?php if ($rSearch !== '' || $rSelectedOwner): ?>
                        <a href="category_templates" class="btn btn-outline-secondary" title="<?= $language::get('clear_filter'); ?>">
                            <i class="icon-base ti tabler-x"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Templates Grid -->
    <div class="row g-4" id="templatesContainer">
        <?php if (empty($rTemplates)): ?>
            <div class="col-12 text-center py-5">
                <div class="avatar avatar-xl bg-label-secondary mx-auto mb-3" style="width:72px;height:72px;">
                    <i class="icon-base ti tabler-folder-off fs-1 text-muted"></i>
                </div>
                <h5 class="mb-1 text-muted"><?= $language::get('no_templates_found'); ?></h5>
                <p class="text-body-secondary mb-3"><?= $language::get('no_templates_desc'); ?></p>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                    <i class="icon-base ti tabler-plus me-1"></i> <?= $language::get('create_template_now'); ?>
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($rTemplates as $tmpl): ?>
                <?php
                $tmplId   = (int) $tmpl['id'];
                $tmplName = htmlspecialchars((string) $tmpl['name'], ENT_QUOTES);
                $isSystem = (int) $tmpl['is_system'] === 1;
                $isShared = (int) $tmpl['is_shared'] === 1;
                $subCount = (int) ($tmpl['subscriber_count'] ?? 0);
                $cLive    = (int) ($tmpl['live_count'] ?? 0);
                $cVod     = (int) ($tmpl['vod_count'] ?? 0);
                $cSeries  = (int) ($tmpl['series_count'] ?? 0);
                $cRadio   = (int) ($tmpl['radio_count'] ?? 0);
                $cTotal   = $cLive + $cVod + $cSeries + $cRadio;
                ?>
                <div class="col-12 col-md-6 col-xl-4 template-card-wrapper" data-name="<?= strtolower($tmplName); ?>" data-owner="<?= strtolower(htmlspecialchars((string) ($tmpl['owner_name'] ?? ''), ENT_QUOTES)); ?>">
                    <div class="card h-100 border-0 shadow-sm template-card <?= $isSystem ? 'border-system-template' : ''; ?>">
                        <div class="card-body d-flex flex-column justify-content-between p-4">
                            <div>
                                <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
                                    <div class="d-flex align-items-center gap-2.5 min-w-0">
                                        <div class="template-card-avatar flex-shrink-0">
                                            <i class="icon-base ti <?= $isSystem ? 'tabler-world' : 'tabler-category-2'; ?>"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <h5 class="card-title mb-0 fw-bold text-heading text-truncate" title="<?= $tmplName; ?>"><?= $tmplName; ?></h5>
                                            <div class="d-flex align-items-center gap-1.5 mt-1 text-body-secondary small flex-wrap">
                                                <span class="d-inline-flex align-items-center gap-1">
                                                    <i class="icon-base ti tabler-user fs-7 text-muted"></i>
                                                    <strong class="text-heading"><?= htmlspecialchars((string) ($tmpl['owner_name'] ?? 'System'), ENT_QUOTES); ?></strong>
                                                </span>
                                                <span class="text-muted opacity-50">•</span>
                                                <span class="d-inline-flex align-items-center gap-1 text-muted">
                                                    <i class="icon-base ti tabler-calendar fs-7"></i>
                                                    <?= !empty($tmpl['created_at']) ? date('Y-m-d H:i', strtotime((string) $tmpl['created_at'])) : ''; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column align-items-end flex-shrink-0 gap-1">
                                        <?php if ($isSystem): ?>
                                            <span class="badge bg-label-info rounded-pill d-inline-flex align-items-center gap-1" title="<?= $language::get('system_template_desc'); ?>">
                                                <i class="icon-base ti tabler-world fs-7"></i> <?= $language::get('system'); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($isShared): ?>
                                            <span class="badge bg-label-warning rounded-pill d-inline-flex align-items-center gap-1" title="<?= $language::get('shared_desc'); ?>">
                                                <i class="icon-base ti tabler-share fs-7"></i> <?= $language::get('shared_with_subresellers'); ?>
                                            </span>
                                        <?php elseif (!$isSystem): ?>
                                            <span class="badge bg-label-secondary rounded-pill d-inline-flex align-items-center gap-1">
                                                <i class="icon-base ti tabler-lock fs-7"></i> <?= $language::get('private_template'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center justify-content-between p-2 rounded-2 bg-body-tertiary border mb-3 small">
                                    <span class="text-body-secondary">
                                        <i class="icon-base ti tabler-users fs-7 me-1"></i>
                                        <strong class="text-heading"><?= $subCount; ?></strong> <?= $language::get('users'); ?>
                                    </span>
                                    <span class="badge <?= $subCount > 0 ? 'bg-label-success' : 'bg-label-secondary text-muted'; ?> rounded-pill px-2 fs-8">
                                        <?= $subCount > 0 ? $language::get('active') : $language::get('unused'); ?>
                                    </span>
                                </div>

                                <div class="template-breakdown-box mb-4">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="fw-bold text-heading fs-7"><i class="icon-base ti tabler-chart-pie-2 text-primary fs-6 me-1"></i><?= $language::get('categories'); ?></span>
                                        <span class="badge bg-label-primary rounded-pill px-2.5 py-1 fw-bold fs-8"><?= $cTotal; ?> <?= $language::get('total'); ?></span>
                                    </div>
                                    <div class="row g-2">
                                        <?php foreach ([['live', $cLive, 'info', 'tabler-device-tv', 'stat-live'], ['movies', $cVod, 'success', 'tabler-movie', 'stat-movies'], ['series', $cSeries, 'warning', 'tabler-device-tv-old', 'stat-series'], ['radio', $cRadio, 'danger', 'tabler-radio', 'stat-radio']] as [$rLabel, $rCount, $rColor, $rIcon, $rClass]): ?>
                                            <div class="col-6 col-sm-3">
                                                <div class="template-stat-chip <?= $rClass; ?> h-100 d-flex flex-column justify-content-between">
                                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                                        <span class="stat-label text-<?= $rColor; ?>"><?= $language::get($rLabel); ?></span>
                                                        <i class="icon-base ti <?= $rIcon; ?> text-<?= $rColor; ?> fs-6"></i>
                                                    </div>
                                                    <span class="stat-value text-heading"><?= (int) $rCount; ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="pt-3 border-top d-flex align-items-center justify-content-between gap-2">
                                <a href="category_template?id=<?= $tmplId; ?>" class="btn btn-sm btn-primary d-flex align-items-center gap-1.5 flex-grow-1 justify-content-center">
                                    <i class="icon-base ti tabler-pencil fs-6"></i>
                                    <span><?= $language::get('edit_template'); ?></span>
                                </a>
                                <button type="button" class="btn btn-sm btn-label-success d-flex align-items-center gap-1.5 js-btn-apply-all px-2.5" data-id="<?= $tmplId; ?>" data-name="<?= $tmplName; ?>" data-owner="<?= (int) $tmpl['owner_id']; ?>" title="<?= $language::get('apply_to_all_desc'); ?>">
                                    <i class="icon-base ti tabler-users-group fs-6"></i>
                                    <span><?= $language::get('apply_to_all_subscribers'); ?></span>
                                </button>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-label-secondary btn-icon dropdown-toggle hide-arrow shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="icon-base ti tabler-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow">
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center gap-2 js-btn-clone py-2 px-3" href="javascript:void(0);" data-id="<?= $tmplId; ?>" data-name="<?= $tmplName; ?>">
                                                <i class="icon-base ti tabler-copy text-primary fs-6"></i><span><?= $language::get('clone_template'); ?></span>
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center gap-2 js-btn-system py-2 px-3" href="javascript:void(0);" data-id="<?= $tmplId; ?>" data-system="<?= $isSystem ? '0' : '1'; ?>">
                                                <i class="icon-base ti <?= $isSystem ? 'tabler-world-off' : 'tabler-world'; ?> text-info fs-6"></i><span><?= $language::get('system'); ?>: <?= $isSystem ? $language::get('disable') : $language::get('enable'); ?></span>
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider my-1"></li>
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center gap-2 text-danger js-btn-delete py-2 px-3" href="javascript:void(0);" data-id="<?= $tmplId; ?>" data-name="<?= $tmplName; ?>">
                                                <i class="icon-base ti tabler-trash fs-6"></i><span><?= $language::get('delete_template'); ?></span>
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Create Template -->
<div class="modal fade" id="createTemplateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="icon-base ti tabler-layout-grid-add text-primary"></i>
                    <span><?= $language::get('create_category_template'); ?></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="createTemplateForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="new_template_name"><?= $language::get('template_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="new_template_name" name="name" placeholder="<?= $language::get('enter_template_name'); ?>" required>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="new_is_shared" name="is_shared" value="1">
                        <label class="form-check-label" for="new_is_shared"><?= $language::get('share_with_subresellers'); ?></label>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="new_is_system" name="is_system" value="1">
                        <label class="form-check-label" for="new_is_system"><?= $language::get('system'); ?> — <?= $language::get('system_template_desc'); ?></label>
                    </div>
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary d-flex align-items-center gap-1" id="btnSubmitCreate">
                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                        <span><?= $language::get('create'); ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Apply to All Lines -->
<div class="modal fade" id="applyAllModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-label-success border-bottom">
                <h5 class="modal-title d-flex align-items-center gap-2 text-success">
                    <i class="icon-base ti tabler-users-group"></i>
                    <span><?= $language::get('apply_to_all_subscribers'); ?></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="applyAllForm">
                <input type="hidden" id="apply_template_id" value="">
                <div class="modal-body">
                    <div class="alert alert-warning py-2 px-3 small mb-3">
                        <i class="icon-base ti tabler-alert-triangle me-1"></i>
                        <?= $language::get('action_cannot_be_undone'); ?>
                    </div>
                    <p class="mb-3"><?= $language::get('template'); ?>: <strong id="apply_template_name_display" class="text-primary"></strong></p>
                    <label class="form-label fw-semibold" for="apply_target_reseller"><?= $language::get('owner'); ?></label>
                    <select id="apply_target_reseller" class="form-select">
                        <?php foreach ($rOwners as $rOwnerID => $rOwner): ?>
                            <?php if (empty($rOwner['username'])): continue;
                            endif; ?>
                            <option value="<?= (int) $rOwnerID; ?>"><?= htmlspecialchars((string) $rOwner['username'], ENT_QUOTES); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text"><?= $language::get('apply_to_all_desc'); ?></div>
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('cancel'); ?></button>
                    <button type="submit" class="btn btn-success d-flex align-items-center gap-1" id="btnSubmitApplyAll">
                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                        <span><?= $language::get('apply_template'); ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
LayoutRenderer::renderFooter('admin');
?>

<script>
    (function() {
        'use strict';

        // xcToast / xcConfirm come from the admin footer; fall back if a page
        // is ever rendered without it.
        var toast = function(msg, type) {
            if (window.xcToast) {
                window.xcToast(msg, type);
            } else {
                alert(msg);
            }
        };
        var confirmAction = function(text) {
            return window.xcConfirm ? window.xcConfirm(text) : Promise.resolve(window.confirm(text));
        };
        var post = function(action, params, body) {
            var query = Object.keys(params || {}).map(function(k) {
                return '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
            }).join('');
            return fetch('./api?action=' + action + query, {
                method: 'POST',
                body: body,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function(res) {
                return res.json();
            });
        };
        var failed = function() {
            toast('Connection error to server.', 'error');
        };

        var searchInput = document.getElementById('liveSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                var val = this.value.toLowerCase().trim();
                document.querySelectorAll('.template-card-wrapper').forEach(function(card) {
                    var hit = (card.getAttribute('data-name') || '').includes(val) || (card.getAttribute('data-owner') || '').includes(val);
                    card.style.display = hit ? '' : 'none';
                });
            });
        }

        // The "Create Category Template" menu entry lands here with ?create=1.
        if (new URLSearchParams(window.location.search).get('create') === '1') {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('createTemplateModal')).show();
        }

        document.getElementById('createTemplateForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('btnSubmitCreate');
            var spinner = btn.querySelector('.spinner-border');
            btn.disabled = true;
            spinner.classList.remove('d-none');
            post('category_template_create', {}, new FormData(this)).then(function(data) {
                btn.disabled = false;
                spinner.classList.add('d-none');
                if (data.result && data.id) {
                    window.location.href = 'category_template?id=' + encodeURIComponent(data.id);
                } else {
                    toast(data.message || 'Failed to create template.', 'error');
                }
            }).catch(function() {
                btn.disabled = false;
                spinner.classList.add('d-none');
                failed();
            });
        });

        document.querySelectorAll('.js-btn-clone').forEach(function(el) {
            el.addEventListener('click', function() {
                var id = this.getAttribute('data-id');
                confirmAction('Clone "' + this.getAttribute('data-name') + '"?').then(function(ok) {
                    if (!ok) {
                        return;
                    }
                    post('category_template_clone', {
                        id: id
                    }).then(function(data) {
                        if (data.result) {
                            window.location.reload();
                        } else {
                            toast(data.message || 'Failed to clone template.', 'error');
                        }
                    }).catch(failed);
                });
            });
        });

        document.querySelectorAll('.js-btn-system').forEach(function(el) {
            el.addEventListener('click', function() {
                post('category_template_toggle_system', {
                    id: this.getAttribute('data-id'),
                    is_system: this.getAttribute('data-system')
                }).then(function(data) {
                    if (data.result) {
                        window.location.reload();
                    } else {
                        toast(data.message || 'Failed to update the template.', 'error');
                    }
                }).catch(failed);
            });
        });

        document.querySelectorAll('.js-btn-delete').forEach(function(el) {
            el.addEventListener('click', function() {
                var id = this.getAttribute('data-id');
                confirmAction('Delete "' + this.getAttribute('data-name') + '"? This cannot be undone.').then(function(ok) {
                    if (!ok) {
                        return;
                    }
                    post('category_template_delete', {
                        id: id
                    }).then(function(data) {
                        if (data.result) {
                            window.location.reload();
                        } else {
                            toast(data.message || 'Failed to delete template.', 'error');
                        }
                    }).catch(failed);
                });
            });
        });

        document.querySelectorAll('.js-btn-apply-all').forEach(function(el) {
            el.addEventListener('click', function() {
                document.getElementById('apply_template_id').value = this.getAttribute('data-id');
                document.getElementById('apply_template_name_display').textContent = this.getAttribute('data-name');
                // Default target: the template's owner, as the service does.
                var target = document.getElementById('apply_target_reseller');
                if (target.querySelector('option[value="' + this.getAttribute('data-owner') + '"]')) {
                    target.value = this.getAttribute('data-owner');
                }
                bootstrap.Modal.getOrCreateInstance(document.getElementById('applyAllModal')).show();
            });
        });

        document.getElementById('applyAllForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('btnSubmitApplyAll');
            var spinner = btn.querySelector('.spinner-border');
            btn.disabled = true;
            spinner.classList.remove('d-none');
            post('category_template_apply_all', {
                id: document.getElementById('apply_template_id').value,
                target_reseller_id: document.getElementById('apply_target_reseller').value
            }).then(function(data) {
                btn.disabled = false;
                spinner.classList.add('d-none');
                bootstrap.Modal.getOrCreateInstance(document.getElementById('applyAllModal')).hide();
                toast(data.message || (data.result ? 'Template applied.' : 'Failed to apply template.'), data.result ? 'success' : 'error');
            }).catch(function() {
                btn.disabled = false;
                spinner.classList.add('d-none');
                failed();
            });
        });
    })();
</script>
