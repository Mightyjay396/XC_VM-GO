<?php

namespace XcVm\Module\Trialapi;

use XcVm\Core\Http\RequestManager;

class TrialapiController {
    
    private static function auth(): ?array {
        $apiKey = RequestManager::get('api_key', '');
        if (empty($apiKey)) {
            http_response_code(401);
            echo json_encode(['error' => 'API_KEY_REQUIRED']);
            return null;
        }
        
        $user = TrialapiService::authenticate($apiKey);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'INVALID_API_KEY']);
            return null;
        }
        
        if ($user['is_admin'] != 1 && $user['is_reseller'] != 1) {
            http_response_code(403);
            echo json_encode(['error' => 'NO_PERMISSION']);
            return null;
        }
        
        return $user;
    }
    
    public function create(): void {
        header('Content-Type: application/json');
        $user = self::auth();
        if (!$user) return;
        echo json_encode(TrialapiService::createTrial($user, RequestManager::getAll()));
    }
    
    public function listTrials(): void {
        header('Content-Type: application/json');
        $user = self::auth();
        if (!$user) return;
        $result = TrialapiService::listTrials($user, (int)RequestManager::get('limit', 100));
        $result['user'] = $user['username'];
        $result['is_admin'] = ($user['is_admin'] == 1);
        echo json_encode($result);
    }
    
    public function delete(): void {
        header('Content-Type: application/json');
        $user = self::auth();
        if (!$user) return;
        echo json_encode(TrialapiService::deleteTrial($user, (int)RequestManager::get('line_id', 0)));
    }
    
    public function info(): void {
        header('Content-Type: application/json');
        $user = self::auth();
        if (!$user) return;
        echo json_encode(TrialapiService::getInfo($user));
    }
}
