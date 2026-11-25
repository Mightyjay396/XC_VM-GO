<?php
/** @var array $rServers @var array $rBouquets @var array $rMovieCats @var array $rSeriesCats */
$rVodServer = null;
foreach ($rServers as $rS) {
    if (stripos($rS['server_name'], 'MOVIELB') !== false && $rVodServer === null) {
        $rVodServer = intval($rS['id']);
    }
}
?>
<div class="card mb-6 d-none" id="vod-options-card" data-testid="vod-options-card">
    <div class="card-header"><h5 class="mb-0"><i class="icon-base ti tabler-movie me-1"></i><?= $pt('vod_options'); ?></h5></div>
    <div class="card-body">
        <?php if (!\XcVm\Module\Providerimport\VodImport::hasTmdbKey()): ?>
            <div class="alert alert-danger" data-testid="vod-tmdb-key-missing">
                <strong><?= $pt('tmdb_missing_title'); ?></strong> <?= $pt('tmdb_missing_text'); ?>
                <?= str_replace('{link}', '<a href="settings" class="alert-link">' . $pt('settings_tmdb') . '</a>', $pt('tmdb_missing_add')); ?>
            </div>
        <?php endif; ?>
        <p class="text-muted small"><?= $pt('vod_help'); ?></p>
        <div class="mb-4" data-testid="vod-mode-group">
            <label class="form-label d-block"><?= $pt('vod_mode'); ?></label>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="vod_mode" id="vod_mode_direct" value="direct" checked data-testid="vod-mode-direct-radio">
                <label class="form-check-label" for="vod_mode_direct"><?= $pt('vod_mode_direct'); ?></label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="vod_mode" id="vod_mode_store" value="store" data-testid="vod-mode-store-radio">
                <label class="form-check-label" for="vod_mode_store"><?= $pt('vod_mode_store'); ?></label>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-4">
                <label class="form-label" for="vod_servers"><?= $pt('servers'); ?></label>
                <select id="vod_servers" class="form-select" multiple data-testid="vod-servers-select">
                    <?php foreach ($rServers as $rS): ?>
                        <option value="<?= intval($rS['id']); ?>" <?= intval($rS['id']) === $rVodServer ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rS['server_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-4">
                <label class="form-label" for="vod_bouquets"><?= $pt('bouquets'); ?></label>
                <select id="vod_bouquets" class="form-select" multiple data-placeholder="<?= $pt('vod_bouquets_ph'); ?>" data-testid="vod-bouquets-select">
                    <?php foreach ($rBouquets as $rB): ?>
                        <option value="<?= intval($rB['id']); ?>"><?= htmlspecialchars((string) $rB['bouquet_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-4">
                <label class="form-label" for="movie_fallback"><?= $pt('movie_fallback'); ?></label>
                <select id="movie_fallback" class="form-select" data-testid="vod-movie-fallback-select">
                    <option value="0"><?= $pt('choose'); ?></option>
                    <?php foreach ($rMovieCats as $rC): ?>
                        <option value="<?= intval($rC['id']); ?>"><?= htmlspecialchars((string) $rC['category_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-4">
                <label class="form-label" for="series_fallback"><?= $pt('series_fallback'); ?></label>
                <select id="series_fallback" class="form-select" data-testid="vod-series-fallback-select">
                    <option value="0"><?= $pt('choose'); ?></option>
                    <?php foreach ($rSeriesCats as $rC): ?>
                        <option value="<?= intval($rC['id']); ?>"><?= htmlspecialchars((string) $rC['category_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="create_categories" data-testid="vod-create-categories-checkbox">
            <label class="form-check-label" for="create_categories"><?= $pt('create_cats'); ?></label>
        </div>
    </div>
</div>
