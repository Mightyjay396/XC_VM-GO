<?php
// line_api: paid lines for the payment backend (payment backend). Same IP whitelist + api_key as trial_api.
// Actions: info (username), list_by_email (email), stream_status (category, admin, read-only), create (email, months, max_connections), renew (username, months[, max_connections]).
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;

$db = new DatabaseHandler();
DatabaseFactory::set($db);

function out(array $rData, int $rCode = 200): void {
    http_response_code($rCode);
    die(json_encode($rData));
}

// IP whitelist: identical to trial_api
$rIP = NetworkUtils::getUserIP();
$settings = SettingsManager::getAll();
$rSettingsIPs = $settings['api_ips'] ?? '[]';
if (is_string($rSettingsIPs)) {
    $decoded = json_decode($rSettingsIPs, true);
    if (!is_array($decoded)) $decoded = @unserialize($rSettingsIPs) ?: [];
    $rSettingsIPs = $decoded;
}
// Same extra IPs as trial_api (read from its hardcoded list so both stay in sync)
$rAllIPs = array_merge(ServerRepository::getAllowedIPs(), $rSettingsIPs ?: [], ['127.0.0.1', '::1']);
$rTrialSrc = @file_get_contents(__DIR__ . '/trial_api.php');
if ($rTrialSrc && preg_match("/\\['127\\.0\\.0\\.1'[^\\]]*\\]/", $rTrialSrc, $m) && preg_match_all("/'([0-9a-fA-F.:]+)'/", $m[0], $ips)) {
    $rAllIPs = array_merge($rAllIPs, $ips[1]);
}
if (!in_array($rIP, $rAllIPs)) {
    out(['result' => false, 'error' => 'IP_NOT_ALLOWED'], 403);
}

$apiKey = $_REQUEST['api_key'] ?? '';
if (empty($apiKey)) {
    out(['result' => false, 'error' => 'API_KEY_REQUIRED'], 401);
}
$user = $db->fetchOne(
    "SELECT u.*, g.is_admin, g.is_reseller FROM users u LEFT JOIN users_groups g ON g.group_id = u.member_group_id
     WHERE u.api_key = ? AND LENGTH(u.api_key) > 0 AND u.status = 1", $apiKey);
if (!$user) {
    out(['result' => false, 'error' => 'INVALID_API_KEY'], 401);
}
$isAdmin = ($user['is_admin'] == 1);
if (!$isAdmin && $user['is_reseller'] != 1) {
    out(['result' => false, 'error' => 'NO_PERMISSION'], 403);
}
$userId = (int) $user['id'];

// Key owner + all sub-resellers (recursive)
function ownedMemberIds(DatabaseHandler $db, int $rUserID): array {
    $rIDs = [$rUserID];
    $rQueue = [$rUserID];
    while ($rQueue) {
        $rIn = implode(',', array_map('intval', $rQueue));
        $rQueue = [];
        foreach ($db->fetchAll("SELECT id FROM users WHERE owner_id IN ($rIn)") ?: [] as $r) {
            if (!in_array((int) $r['id'], $rIDs, true)) {
                $rIDs[] = (int) $r['id'];
                $rQueue[] = (int) $r['id'];
            }
        }
    }
    return $rIDs;
}

function findLine(DatabaseHandler $db, int $rUserID, string $rUsername): ?array {
    if ($rUsername === '') return null;
    $rIn = implode(',', ownedMemberIds($db, $rUserID));
    $rLine = $db->fetchOne("SELECT * FROM `lines` WHERE username = ? AND is_mag = 0 AND is_e2 = 0 AND member_id IN ($rIn) LIMIT 1", $rUsername);
    return $rLine ?: null;
}

function findPackage(DatabaseHandler $db, int $rMonths, int $rCons): ?array {
    $rPkg = $db->fetchOne(
        "SELECT * FROM users_packages WHERE is_official = 1 AND is_addon = 0 AND is_trial = 0 AND is_line = 1
         AND official_duration = ? AND official_duration_in = 'months' AND max_connections = ? ORDER BY id LIMIT 1", $rMonths, $rCons);
    return $rPkg ?: null;
}

function addMonths(int $rFrom, int $rMonths): int {
    return (new DateTime('@' . $rFrom))->setTimezone(new DateTimeZone(date_default_timezone_get()))->modify("+{$rMonths} month")->getTimestamp();
}

// Deducts credits atomically (resellers only; admins are not charged, as in the panel). Returns credits after, or null.
function charge(DatabaseHandler $db, array $rUser, bool $rIsAdmin, float $rCost): ?float {
    $rID = (int) $rUser['id'];
    $rCredits = (float) $db->fetchValue('SELECT `credits` FROM `users` WHERE `id` = ? FOR UPDATE', $rID);
    if ($rIsAdmin || $rCost <= 0) return $rCredits;
    if ($rCredits < $rCost) return null;
    $db->query('UPDATE `users` SET `credits` = `credits` - ? WHERE `id` = ?;', $rCost, $rID);
    return (float) $db->fetchValue('SELECT `credits` FROM `users` WHERE `id` = ?', $rID);
}

function logAction(DatabaseHandler $db, int $rUserID, string $rAction, int $rLineID, array $rPkg, float $rCost, float $rAfter, array $rInfo): void {
    $db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, ?, ?, ?, ?, ?);",
        $rUserID, $rAction, $rLineID, (int) $rPkg['id'], $rCost, $rAfter, time(), json_encode($rInfo));
}

function lineStatus(array $rLine): string {
    if (!$rLine['admin_enabled']) return 'banned';
    if (!$rLine['enabled']) return 'disabled';
    if ($rLine['exp_date'] && $rLine['exp_date'] < time()) return 'expired';
    return 'active';
}

$action = $_REQUEST['action'] ?? '';
$months = (int) ($_REQUEST['months'] ?? 0);

switch ($action) {
    case 'info':
        $rLine = findLine($db, $userId, trim((string) ($_REQUEST['username'] ?? '')));
        if (!$rLine) out(['result' => false, 'error' => 'NOT_FOUND']);
        out(['result' => true, 'exists' => true, 'username' => $rLine['username'],
             'exp_date' => $rLine['exp_date'] ? (int) $rLine['exp_date'] : null, 'status' => lineStatus($rLine),
             'max_connections' => (int) $rLine['max_connections'], 'is_trial' => (bool) $rLine['is_trial']]);

    case 'create':
        $rCons = (int) ($_REQUEST['max_connections'] ?? 1);
        $rEmail = trim((string) ($_REQUEST['email'] ?? ''));
        if ($rEmail !== '' && !filter_var($rEmail, FILTER_VALIDATE_EMAIL)) out(['result' => false, 'error' => 'INVALID_EMAIL']);
        $rPkg = findPackage($db, $months, $rCons);
        if (!$rPkg) out(['result' => false, 'error' => 'NO_PACKAGE']);
        $rCost = (float) $rPkg['official_credits'];
        do {
            $rUsername = AdminHelpers::generateString(10);
        } while ($db->fetchOne('SELECT id FROM `lines` WHERE username = ?', $rUsername));
        $rPassword = AdminHelpers::generateString(10);
        $rBouquets = array_values(array_map('intval', json_decode($rPkg['bouquets'] ?: '[]', true) ?: []));
        $rOutputs = json_decode($rPkg['output_formats'] ?: '[1,2]', true) ?: [1, 2];
        $rExp = addMonths(time(), $months);
        $db->beginTransaction();
        $rAfter = charge($db, $user, $isAdmin, $rCost);
        if ($rAfter === null) {
            $db->rollback();
            out(['result' => false, 'error' => 'INSUFFICIENT_CREDITS']);
        }
        $lineId = $db->insert('lines', [
            'username' => $rUsername, 'password' => $rPassword, 'is_trial' => 0,
            'exp_date' => $rExp, 'max_connections' => $rCons, 'package_id' => (int) $rPkg['id'],
            'bouquet' => json_encode($rBouquets), 'allowed_outputs' => json_encode($rOutputs),
            'is_restreamer' => (int) $rPkg['is_restreamer'], 'is_isplock' => (int) $rPkg['is_isplock'],
            'created_at' => time(), 'member_id' => $userId, 'play_token' => md5(uniqid() . time()),
            'admin_enabled' => 1, 'enabled' => 1, 'reseller_notes' => $rEmail, 'contact' => $rEmail,
        ]);
        if (!($lineId > 0)) {
            $db->rollback();
            out(['result' => false, 'error' => 'INSERT_FAILED']);
        }
        logAction($db, $userId, 'new', (int) $lineId, $rPkg, $isAdmin ? 0 : $rCost, $rAfter,
            ['id' => (int) $lineId, 'member_id' => $userId, 'username' => $rUsername, 'exp_date' => $rExp, 'source' => 'line_api']);
        $db->commit();
        LineService::updateLineSignal((int) $lineId);
        out(['result' => true, 'username' => $rUsername, 'password' => $rPassword, 'exp_date' => $rExp, 'max_connections' => $rCons]);

    case 'renew':
        $rLine = findLine($db, $userId, trim((string) ($_REQUEST['username'] ?? '')));
        if (!$rLine) out(['result' => false, 'error' => 'NOT_FOUND']);
        if (empty($rLine['exp_date'])) out(['result' => false, 'error' => 'UNLIMITED_LINE']);
        $rCons = isset($_REQUEST['max_connections']) && $_REQUEST['max_connections'] !== '' ? (int) $_REQUEST['max_connections'] : (int) $rLine['max_connections'];
        $rPkg = findPackage($db, $months, $rCons);
        if (!$rPkg) out(['result' => false, 'error' => 'NO_PACKAGE']);
        $rCost = (float) $rPkg['official_credits'];
        $rOld = (int) $rLine['exp_date'];
        $rExp = addMonths(max($rOld, time()), $months);
        $db->beginTransaction();
        $rAfter = charge($db, $user, $isAdmin, $rCost);
        if ($rAfter === null) {
            $db->rollback();
            out(['result' => false, 'error' => 'INSUFFICIENT_CREDITS']);
        }
        // a free trial becomes a paid line (same username/password, trial bouquets kept)
        $db->query('UPDATE `lines` SET `exp_date` = ?, `max_connections` = ?, `package_id` = ?, `is_trial` = 0 WHERE `id` = ?;', $rExp, $rCons, (int) $rPkg['id'], (int) $rLine['id']);
        logAction($db, $userId, 'extend', (int) $rLine['id'], $rPkg, $isAdmin ? 0 : $rCost, $rAfter,
            ['id' => (int) $rLine['id'], 'username' => $rLine['username'], 'old_exp' => $rOld, 'exp_date' => $rExp, 'was_trial' => (bool) $rLine['is_trial'], 'source' => 'line_api']);
        $db->commit();
        LineService::updateLineSignal((int) $rLine['id']);
        out(['result' => true, 'username' => $rLine['username'], 'exp_date' => $rExp, 'was_trial' => (bool) $rLine['is_trial']]);

    case 'list_by_email':
        $rEmail = strtolower(trim((string) ($_REQUEST['email'] ?? '')));
        if (!filter_var($rEmail, FILTER_VALIDATE_EMAIL)) out(['result' => false, 'error' => 'INVALID_EMAIL']);
        $rIn = implode(',', ownedMemberIds($db, $userId));
        $rLike = '%' . addcslashes($rEmail, '%_\\') . '%';
        $rAccounts = [];
        foreach ($db->fetchAll("SELECT * FROM `lines` WHERE is_mag = 0 AND is_e2 = 0 AND member_id IN ($rIn) AND (contact LIKE ? OR reseller_notes LIKE ? OR admin_notes LIKE ?) ORDER BY id", $rLike, $rLike, $rLike) ?: [] as $rLine) {
            // exact match on a whole email address (no partial matches like x.name@ vs name@)
            preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', html_entity_decode($rLine['contact'] . ' ' . $rLine['reseller_notes'] . ' ' . $rLine['admin_notes']), $m);
            if (!in_array($rEmail, array_map('strtolower', $m[0]), true)) continue;
            $rAccounts[] = ['username' => $rLine['username'], 'exp_date' => $rLine['exp_date'] ? (int) $rLine['exp_date'] : null,
                            'status' => lineStatus($rLine), 'max_connections' => (int) $rLine['max_connections'], 'is_trial' => (bool) $rLine['is_trial']];
        }
        if (!$rAccounts) out(['result' => false, 'error' => 'NOT_FOUND']);
        out(['result' => true, 'email' => $rEmail, 'count' => count($rAccounts), 'accounts' => $rAccounts]);

    case 'stream_status':
        // Lecture seule (admin): état des chaînes d'une catégorie selon la sonde interne XC_VM (streams_servers).
        if (!$isAdmin) out(['result' => false, 'error' => 'NO_PERMISSION'], 403);
        $rCatName = trim((string) ($_REQUEST['category'] ?? ''));
        $rCat = $rCatName !== '' ? $db->fetchOne('SELECT id, category_name FROM streams_categories WHERE category_name = ? LIMIT 1', $rCatName) : null;
        if (!$rCat) out(['result' => false, 'error' => 'CATEGORY_NOT_FOUND']);
        $rCatID = (int) $rCat['id'];
        $rRows = $db->fetchAll(
            "SELECT s.id, s.stream_display_name, s.direct_source, ss.server_id, ss.stream_status, ss.on_demand, ss.pid, ss.bitrate, ss.updated, ss.stream_info, ss.video_codec, ss.audio_codec
             FROM streams s JOIN streams_servers ss ON ss.stream_id = s.id
             WHERE s.type = 1 AND JSON_CONTAINS(s.category_id, ?) ORDER BY s.`order`, s.id", (string) $rCatID) ?: [];
        $rOut = [];
        foreach ($rRows as $r) {
            // Qualité vidéo (ffprobe interne XC_VM au lancement du flux) : largeur/hauteur/fps
            $rW = $rH = 0; $rFps = 0.0;
            $rInfo = $r['stream_info'] ? json_decode($r['stream_info'], true) : null;
            $rVideo = is_array($rInfo) ? ($rInfo['codecs']['video'] ?? null) : null;
            if (is_array($rVideo)) {
                $rW = (int) ($rVideo['width'] ?? 0); $rH = (int) ($rVideo['height'] ?? 0);
                foreach (['avg_frame_rate', 'r_frame_rate'] as $rKey) {
                    $rRate = (string) ($rVideo[$rKey] ?? '0/1');
                    if (strpos($rRate, '/') !== false) { [$rN, $rD] = array_map('floatval', explode('/', $rRate, 2)); $rFps = $rD > 0 ? round($rN / $rD, 2) : 0.0; }
                    else { $rFps = (float) $rRate; }
                    if ($rFps > 0 && $rFps <= 240) break;
                    $rFps = 0.0;
                }
            }
            $rOut[] = ['stream_id' => (int) $r['id'], 'name' => $r['stream_display_name'], 'server_id' => (int) $r['server_id'],
                       'stream_status' => (int) $r['stream_status'], 'on_demand' => (int) $r['on_demand'], 'running' => ((int) $r['pid']) > 0,
                       'bitrate' => (int) $r['bitrate'], 'direct_source' => (int) $r['direct_source'], 'updated' => $r['updated'],
                       'width' => $rW, 'height' => $rH, 'fps' => $rFps,
                       'video_codec' => (string) ($r['video_codec'] ?? ''), 'audio_codec' => (string) ($r['audio_codec'] ?? '')];
        }
        out(['result' => true, 'category' => $rCat['category_name'], 'count' => count($rOut), 'checked_at' => time(), 'streams' => $rOut]);

    default:
        out(['result' => false, 'error' => 'INVALID_ACTION']);
}
