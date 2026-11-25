<?php
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Line\LineService;
use XcVm\Infrastructure\Database\DatabaseFactory;

$db = new DatabaseHandler();
DatabaseFactory::set($db);

$rIP = NetworkUtils::getUserIP();
$settings = SettingsManager::getAll();

// IP whitelist: check ServerRepository + settings + hardcoded
$rAllowed = ServerRepository::getAllowedIPs();
$rSettingsIPs = $settings['api_ips'] ?? '[]';
if (is_string($rSettingsIPs)) {
    // Could be JSON or PHP serialized
    $decoded = json_decode($rSettingsIPs, true);
    if (!is_array($decoded)) $decoded = @unserialize($rSettingsIPs) ?: [];
    $rSettingsIPs = $decoded;
}
$rAllIPs = array_merge($rAllowed, $rSettingsIPs ?: [], ['127.0.0.1', '::1']);

if (!in_array($rIP, $rAllIPs)) {
    http_response_code(403);
    die(json_encode(['error' => 'IP_NOT_ALLOWED', 'your_ip' => $rIP, 'checked' => count($rAllIPs)]));
}

$apiKey = $_REQUEST['api_key'] ?? '';
if (empty($apiKey)) {
    http_response_code(401);
    die(json_encode(['error' => 'API_KEY_REQUIRED']));
}

$user = $db->fetchOne(
    "SELECT u.*, g.is_admin, g.is_reseller, g.total_allowed_gen_trials 
     FROM users u LEFT JOIN users_groups g ON g.group_id = u.member_group_id 
     WHERE u.api_key = ? AND LENGTH(u.api_key) > 0 AND u.status = 1", $apiKey);

if (!$user) {
    http_response_code(401);
    die(json_encode(['error' => 'INVALID_API_KEY']));
}

$isAdmin = ($user['is_admin'] == 1);
$isReseller = ($user['is_reseller'] == 1);
$userId = (int)$user['id'];
$maxTrials = (int)($user['total_allowed_gen_trials'] ?? 0);

if (!$isAdmin && !$isReseller) {
    http_response_code(403);
    die(json_encode(['error' => 'NO_PERMISSION']));
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'create':
        if (!$isAdmin && $maxTrials > 0) {
            $cnt = $db->fetchValue("SELECT COUNT(*) FROM `lines` WHERE is_trial = 1 AND member_id = ?", $userId);
            if ($cnt >= $maxTrials) die(json_encode(['result' => false, 'error' => 'TRIAL_LIMIT_REACHED']));
        }
        
        $username = \XcVm\Core\Util\AdminHelpers::generateString(10);
        $password = substr(md5(uniqid(mt_rand(), true)), 0, 10);
        $email = $_REQUEST["email"] ?? "";
        $maxConn = (int)($_REQUEST['max_connections'] ?? 1);
        $duration = (int)($_REQUEST['duration_hours'] ?? 24);
        
        $bouquets = [];
        // Get all bouquets except RESTREAMER (id=2)
        $allBouquets = $db->fetchAll("SELECT id FROM bouquets WHERE id != 2 ORDER BY id");
        $bouquets = array_map(function($b) { return (string)$b["id"]; }, $allBouquets ?: []);
        // Also try trial package as reference
        $pkg = $db->fetchOne("SELECT bouquets FROM users_packages WHERE is_trial = 1 LIMIT 1");
        if ($pkg && !empty($pkg["bouquets"])) {
            $pkgBouquets = json_decode($pkg["bouquets"], true) ?: [];
            // Remove RESTREAMER (id=2) from package bouquets
            $bouquets = array_values(array_filter($pkgBouquets, function($id) { return (int)$id !== 2; }));
        }
        
        if ($db->fetchOne("SELECT id FROM `lines` WHERE username = ?", $username)) {
            die(json_encode(['result' => false, 'error' => 'USERNAME_EXISTS']));
        }
        
        $expDate = time() + ($duration * 3600);
        $lineId = $db->insert('lines', [
            'username' => $username, 'password' => $password, 'is_trial' => 1,
            'exp_date' => $expDate, 'max_connections' => $maxConn,
            'bouquet' => json_encode($bouquets), 'allowed_outputs' => '[1,2]',
            'created_at' => time(), 'member_id' => $userId, 'is_restreamer' => 0,
            'play_token' => md5(uniqid() . time()), 'admin_enabled' => 1, 'enabled' => 1, 'reseller_notes' => $email
        ]);
        
        if ($lineId > 0) {
            LineService::updateLineSignal((int) $lineId);
        }
        echo json_encode($lineId > 0 ? [
            'result' => true, 'line_id' => $lineId, 'username' => $username, 'password' => $password,
            'exp_date' => date('Y-m-d H:i:s', $expDate), 'max_connections' => $maxConn, 'created_by' => $user['username']
        ] : ['result' => false, 'error' => 'INSERT_FAILED']);
        break;
        
    case 'list':
        $limit = (int)($_REQUEST['limit'] ?? 100);
        
        if ($isAdmin) {
            $db->query("SELECT l.id, l.username, l.password, l.exp_date, l.max_connections, l.created_at, l.member_id, u.username as created_by 
                        FROM `lines` l LEFT JOIN `users` u ON u.id = l.member_id
                        WHERE l.is_trial = 1 ORDER BY l.created_at DESC LIMIT " . $limit);
        } else {
            $db->query("SELECT id, username, password, exp_date, max_connections, created_at 
                        FROM `lines` WHERE is_trial = 1 AND member_id = " . $userId . " ORDER BY created_at DESC LIMIT " . $limit);
        }
        
        $trials = [];
        $numRows = $db->num_rows();
        $rows = ($numRows > 0) ? $db->get_rows() : [];
        
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $trials[] = [
                    'id' => $row['id'], 'username' => $row['username'], 'password' => $row['password'],
                    'exp_date' => $row['exp_date'] ? date('Y-m-d H:i:s', $row['exp_date']) : null,
                    'expired' => $row['exp_date'] && $row['exp_date'] < time(),
                    'max_connections' => $row['max_connections'],
                    'created_at' => date('Y-m-d H:i:s', $row['created_at']),
                    'created_by' => $row['created_by'] ?? null
                ];
            }
        }
        echo json_encode(['result' => true, 'trials' => $trials, 'count' => count($trials), 'user' => $user['username'], 'is_admin' => $isAdmin]);
        break;
        
    case 'delete':
        $lineId = (int)($_REQUEST['line_id'] ?? 0);
        if ($lineId <= 0) die(json_encode(['result' => false, 'error' => 'LINE_ID_REQUIRED']));
        
        // Vérifier si le trial existe et appartient à l'utilisateur (si non-admin)
        if ($isAdmin) {
            $exists = $db->fetchOne("SELECT id FROM `lines` WHERE id = ? AND is_trial = 1", $lineId);
        } else {
            $exists = $db->fetchOne("SELECT id FROM `lines` WHERE id = ? AND is_trial = 1 AND member_id = ?", $lineId, $userId);
        }
        
        if (!$exists) {
            echo json_encode(['result' => false, 'error' => 'NOT_FOUND_OR_NO_PERMISSION']);
            break;
        }
        
        // Supprimer
        $rDeleted = LineService::deleteLineById($lineId);
        echo json_encode(['result' => (bool) $rDeleted, 'deleted' => $lineId]);
        break;
    
    case 'info':
        $cnt = $db->fetchValue("SELECT COUNT(*) FROM `lines` WHERE is_trial = 1 AND member_id = ?", $userId);
        echo json_encode([
            'result' => true, 'user' => $user['username'], 'user_id' => $userId,
            'is_admin' => $isAdmin, 'is_reseller' => $isReseller,
            'trial_limit' => $isAdmin ? 'unlimited' : $maxTrials,
            'trials_created' => $cnt, 'trials_remaining' => $isAdmin ? 'unlimited' : max(0, $maxTrials - $cnt)
        ]);
        break;
        
    default:
        echo json_encode(['error' => 'INVALID_ACTION', 'available' => ['create', 'list', 'delete', 'info']]);
}
