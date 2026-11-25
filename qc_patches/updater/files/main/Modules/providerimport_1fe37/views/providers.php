<?php
/** @var array $rProviders @var array $rBouquets @var array $rServers @var array $rCategories */
$pt = static fn(string $k, array $r = []): string => htmlspecialchars(\XcVm\Module\Providerimport\ProviderImportController::t($k, $r), ENT_QUOTES);
?>

<div class="d-flex align-items-center mb-4">
    <a href="providers" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0" data-testid="providerimport-title">ProviderImport <small class="text-muted fs-6"><?= $pt('title_sub'); ?></small></h4>
</div>

<form action="#" method="POST" id="pimport-form" autocomplete="off">
    <div class="row">
        <div class="col-lg-6">
            <div class="card mb-6">
                <div class="card-header"><h5 class="mb-0"><i class="icon-base ti tabler-world me-1"></i>1. <?= $pt('provider'); ?></h5></div>
                <div class="card-body">
                    <div class="mb-4">
                        <label class="form-label" for="content_kind"><?= $pt('content'); ?></label>
                        <select id="content_kind" class="form-select" data-testid="pimport-content-select">
                            <option value="live"><?= $pt('kind_live_tv'); ?></option>
                            <option value="movie"><?= $pt('kind_movies'); ?></option>
                            <option value="series"><?= $pt('kind_series_all'); ?></option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="provider_id"><?= $pt('provider'); ?></label>
                        <select id="provider_id" class="form-select" data-testid="pimport-provider-select">
                            <option value="0"><?= $pt('choose'); ?></option>
                            <?php foreach ($rProviders as $rP): ?>
                                <option value="<?= intval($rP['id']); ?>"><?= htmlspecialchars((string) $rP['name'], ENT_QUOTES); ?> (<?= $pt('n_channels', ['{n}' => (string) intval($rP['live'])]); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="provider_category"><?= $pt('provider_category'); ?></label>
                        <select id="provider_category" class="form-select" data-testid="pimport-category-select" disabled>
                            <option value="__all__"><?= $pt('all_categories'); ?></option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="search"><?= $pt('search_name'); ?></label>
                        <input type="text" id="search" class="form-control" placeholder="<?= $pt('search_ph'); ?>" data-testid="pimport-search-input">
                    </div>
                    <div class="mb-4 live-only">
                        <label class="form-label" for="category_mode"><?= $pt('cat_mode'); ?></label>
                        <select id="category_mode" class="form-select" data-testid="pimport-category-mode-select">
                            <option value="provider"><?= $pt('cat_mode_provider'); ?></option>
                            <option value="fixed"><?= $pt('cat_mode_fixed'); ?></option>
                        </select>
                    </div>
                    <div class="mb-4 d-none" id="fixed_category_wrap">
                        <label class="form-label" for="fixed_category"><?= $pt('dest_category'); ?></label>
                        <select id="fixed_category" class="form-select" data-testid="pimport-fixed-category-select">
                            <option value="0"><?= $pt('choose'); ?></option>
                            <?php foreach ($rCategories as $rC): ?>
                                <option value="<?= intval($rC['id']); ?>"><?= htmlspecialchars((string) $rC['category_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4 live-only">
                        <label class="form-label" for="kind_filter"><?= $pt('channel_type'); ?></label>
                        <select id="kind_filter" class="form-select" data-testid="pimport-kind-filter">
                            <option value="all"><?= $pt('all'); ?></option>
                            <option value="live"><?= $pt('live_only'); ?></option>
                            <option value="247"><?= $pt('only_247'); ?></option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-label-primary" id="btn-load" data-testid="pimport-load-btn"><i class="icon-base ti tabler-list-search me-1"></i><?= $pt('show_list'); ?></button>
                </div>
            </div>
        </div>
        <?php include __DIR__ . '/_options.php'; ?>
    </div>
    <?php include __DIR__ . '/_vod_options.php'; ?>

    <div id="vod-status" class="alert d-none mb-6" data-testid="pimport-vod-status"></div>

    <div class="card mb-6 d-none" id="list-card" data-testid="pimport-list-card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="icon-base ti tabler-list-check me-1"></i>3. <?= $pt('list_heading'); ?> : <span id="sel-count" data-testid="pimport-selected-count">0</span> <?= $pt('selected_of'); ?> <span id="list-count">0</span> <small class="text-muted fs-6" id="kind-counts"></small></h5>
            <div class="d-flex gap-2 align-items-center">
                <div class="form-check form-switch me-2 mb-0">
                    <input class="form-check-input" type="checkbox" id="hide_imported" checked data-testid="pimport-hide-imported">
                    <label class="form-check-label" for="hide_imported"><?= $pt('hide_imported'); ?></label>
                </div>
                <button type="button" class="btn btn-label-secondary" id="btn-sel-new" data-testid="pimport-select-new-btn"><?= $pt('select_new'); ?></button>
                <button type="button" class="btn btn-primary" id="btn-import" data-testid="pimport-import-btn"><i class="icon-base ti tabler-upload me-1"></i><?= $pt('import_selection'); ?></button>
            </div>
        </div>
        <div class="card-body">
            <div class="progress mb-4 d-none" id="import-progress" style="height: 20px;">
                <div class="progress-bar" role="progressbar" style="width: 0%" id="import-progress-bar" data-testid="pimport-progress">0%</div>
            </div>
            <div id="import-result" class="mb-4" data-testid="pimport-result"></div>
            <div id="list-warning" class="alert alert-warning d-none"></div>
            <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                <table class="table table-sm table-hover mb-0">
                    <thead class="sticky-top bg-body"><tr>
                        <th style="width:36px"><input type="checkbox" class="form-check-input" id="chk-all" data-testid="pimport-check-all"></th>
                        <th style="width:60px"></th><th><?= $pt('col_name'); ?></th><th><?= $pt('col_type'); ?></th><th><?= $pt('col_category'); ?></th><th><?= $pt('col_epg'); ?></th><th><?= $pt('col_status'); ?></th>
                    </tr></thead>
                    <tbody id="list-body" data-testid="pimport-list-table"></tbody>
                </table>
            </div>
        </div>
    </div>
</form>
