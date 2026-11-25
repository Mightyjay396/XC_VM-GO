<?php
$rDefaultServer = null;
foreach ($rServers as $rS) {
    if (stripos($rS['server_name'], 'LBMain') !== false) {
        $rDefaultServer = intval($rS['id']);
    }
}
?>
        <div class="col-lg-6" id="live-options-col">
            <div class="card mb-6">
                <div class="card-header"><h5 class="mb-0"><i class="icon-base ti tabler-settings me-1"></i>2. <?= $pt('options'); ?></h5></div>
                <div class="card-body">
                    <div class="mb-4">
                        <label class="form-label" for="servers"><?= $pt('servers'); ?></label>
                        <select id="servers" name="servers[]" class="form-select" multiple data-testid="providerimport-servers-select">
                            <?php foreach ($rServers as $rS): ?>
                                <option value="<?= intval($rS['id']); ?>" <?= intval($rS['id']) === $rDefaultServer ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rS['server_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="on_demand"><?= $pt('on_demand'); ?></label>
                        <select id="on_demand" name="on_demand[]" class="form-select" multiple data-testid="providerimport-ondemand-select">
                            <?php foreach ($rServers as $rS): ?>
                                <option value="<?= intval($rS['id']); ?>" <?= intval($rS['id']) === $rDefaultServer ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rS['server_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="bouquets"><?= $pt('bouquets'); ?></label>
                        <select id="bouquets" name="bouquets[]" class="form-select" multiple data-placeholder="<?= $pt('none'); ?>" data-testid="providerimport-bouquets-select">
                            <?php foreach ($rBouquets as $rB): ?>
                                <option value="<?= intval($rB['id']); ?>"><?= htmlspecialchars((string) $rB['bouquet_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="fallback_category"><?= htmlspecialchars($rFallbackLabel, ENT_QUOTES); ?></label>
                        <select id="fallback_category" name="fallback_category" class="form-select" data-testid="providerimport-fallback-select">
                            <option value="0"><?= $pt('choose'); ?></option>
                            <?php foreach ($rCategories as $rC): ?>
                                <option value="<?= intval($rC['id']); ?>"><?= htmlspecialchars((string) $rC['category_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
