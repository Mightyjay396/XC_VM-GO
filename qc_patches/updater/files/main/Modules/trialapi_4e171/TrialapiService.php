<?php

namespace XcVm\Module\Trialapi;

class TrialapiService {
    
    private static $db = null;
    
    public static function setDb($db): void {
        self::$db = $db;
    }
    
    public static function getDb() {
        return self::$db;
    }
    
    public static function authenticate(string $apiKey): ?array {
        if (empty($apiKey) || !self::$db) return null;
        
        return self::$db->fetchOne(
            "SELECT u.*, g.is_admin, g.is_reseller, g.total_allowed_gen_trials 
             FROM users u LEFT JOIN users_groups g ON g.group_id = u.member_group_id 
             WHERE u.api_key = ? AND LENGTH(u.api_key) > 0 AND u.status = 1", $apiKey
        );
    }
    
    public static function createTrial(array $user, array $params): array {
        $db = self::$db;
        $userId = (int)$user['id'];
        $isAdmin = ($user['is_admin'] == 1);
        $maxTrials = (int)($user['total_allowed_gen_trials'] ?? 0);
        
        if (!$isAdmin && $maxTrials > 0) {
            $current = (int)$db->fetchValue("SELECT COUNT(*) FROM lines WHERE is_trial = 1 AND member_id = ?", $userId);
            if ($current >= $maxTrials) {
                return ['result' => false, 'error' => 'TRIAL_LIMIT_REACHED', 'limit' => $maxTrials, 'current' => $current];
            }
        }
        
        $username = $params['username'] ?? \XcVm\Core\Util\AdminHelpers::generateString(10);
        $password = $params['password'] ?? substr(md5(uniqid()), 0, 10);
        $maxConn = (int)($params['max_connections'] ?? 1);
        $duration = (int)($params['duration_hours'] ?? 24);
        
        $bouquets = [];
        $pkg = $db->fetchOne("SELECT bouquet FROM users_packages WHERE is_trial = 1 LIMIT 1");
        if ($pkg && !empty($pkg['bouquet'])) $bouquets = json_decode($pkg['bouquet'], true) ?: [];
        
        if ($db->fetchOne("SELECT id FROM lines WHERE username = ?", $username)) {
            return ['result' => false, 'error' => 'USERNAME_EXISTS'];
        }
        
        $expDate = time() + ($duration * 3600);
        $lineId = $db->insert('lines', [
            'username' => $username, 'password' => $password, 'is_trial' => 1,
            'exp_date' => $expDate, 'max_connections' => $maxConn,
            'bouquet' => json_encode($bouquets), 'allowed_outputs' => '[1,2]',
            'created_at' => time(), 'member_id' => $userId, 'is_restreamer' => 0,
            'play_token' => md5(uniqid() . time()), 'admin_enabled' => 1, 'enabled' => 1
        ]);
        
        return $lineId > 0 ? [
            'result' => true, 'line_id' => $lineId, 'username' => $username, 'password' => $password,
            'exp_date' => date('Y-m-d H:i:s', $expDate), 'max_connections' => $maxConn, 'created_by' => $user['username']
        ] : ['result' => false, 'error' => 'INSERT_FAILED'];
    }
    
    public static function listTrials(array $user, int $limit = 100): array {
        $db = self::$db;
        $userId = (int)$user['id'];
        $isAdmin = ($user['is_admin'] == 1);
        
        $trials = $isAdmin 
            ? $db->fetchAll("SELECT l.*, u.username as created_by FROM lines l LEFT JOIN users u ON u.id = l.member_id WHERE l.is_trial = 1 ORDER BY l.created_at DESC LIMIT " . $limit)
            : $db->fetchAll("SELECT * FROM lines WHERE is_trial = 1 AND member_id = ? ORDER BY created_at DESC LIMIT " . $limit, $userId);
        
        $result = [];
        foreach ($trials ?: [] as $row) {
            $result[] = [
                'id' => $row['id'], 'username' => $row['username'], 'password' => $row['password'],
                'exp_date' => $row['exp_date'] ? date('Y-m-d H:i:s', $row['exp_date']) : null,
                'expired' => $row['exp_date'] && $row['exp_date'] < time(),
                'max_connections' => $row['max_connections'],
                'created_at' => date('Y-m-d H:i:s', $row['created_at']),
                'created_by' => $row['created_by'] ?? null
            ];
        }
        return ['result' => true, 'trials' => $result, 'count' => count($result)];
    }
    
    public static function deleteTrial(array $user, int $lineId): array {
        if ($lineId <= 0) return ['result' => false, 'error' => 'LINE_ID_REQUIRED'];
        $db = self::$db;
        $affected = ($user['is_admin'] == 1)
            ? $db->delete('lines', 'id = ? AND is_trial = 1', $lineId)
            : $db->delete('lines', 'id = ? AND is_trial = 1 AND member_id = ?', $lineId, (int)$user['id']);
        return $affected > 0 ? ['result' => true] : ['result' => false, 'error' => 'NOT_FOUND'];
    }
    
    public static function getInfo(array $user): array {
        $db = self::$db;
        $userId = (int)$user['id'];
        $isAdmin = ($user['is_admin'] == 1);
        $maxTrials = (int)($user['total_allowed_gen_trials'] ?? 0);
        $count = (int)$db->fetchValue("SELECT COUNT(*) FROM lines WHERE is_trial = 1 AND member_id = ?", $userId);
        
        return [
            'result' => true, 'user' => $user['username'], 'user_id' => $userId,
            'is_admin' => $isAdmin, 'is_reseller' => ($user['is_reseller'] == 1),
            'trial_limit' => $isAdmin ? 'unlimited' : $maxTrials,
            'trials_created' => $count, 'trials_remaining' => $isAdmin ? 'unlimited' : max(0, $maxTrials - $count)
        ];
    }
}
