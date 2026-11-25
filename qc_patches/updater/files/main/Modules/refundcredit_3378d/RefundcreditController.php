<?php

namespace XcVm\Module\Refundcredit;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\User\UserRepository;

class RefundcreditController {

    private static function json(array $rData): void {
        header('Content-Type: application/json');
        echo json_encode($rData);
        exit();
    }

    private static function actor(): array {
        $rUserInfo = $GLOBALS['rUserInfo'] ?? null;
        if (!$rUserInfo || empty($rUserInfo['id'])) {
            self::json(array('result' => false, 'error' => 'Not authenticated'));
        }
        return $rUserInfo;
    }

    /** GET refundcredit/calculate?user_id=ID — what deleting the line refunds. */
    public function calculate(): void {
        self::actor();
        $rLineID = intval(RequestManager::get('user_id') ?? 0);
        $rLine = $rLineID > 0 ? UserRepository::getLineById($rLineID) : null;
        if (!$rLine) {
            self::json(array('result' => false, 'error' => 'Line not found'));
        }
        if (!RefundcreditService::canManageLine($rLine)) {
            self::json(array('result' => false, 'error' => 'Unauthorized'));
        }
        self::json(array('result' => true, 'line_id' => $rLineID, 'username' => $rLine['username']) + RefundcreditService::calculateRefund($rLine, 'line'));
    }

    /** POST refundcredit/delete (user_id) — refund the owner and delete the line. */
    public function delete(): void {
        $rActor = self::actor();
        $rLineID = intval(RequestManager::get('user_id') ?? 0);
        if ($rLineID <= 0) {
            self::json(array('result' => false, 'error' => 'Invalid line ID'));
        }
        $rResult = RefundcreditService::deleteLineWithRefund($rLineID, $rActor);
        self::json(array('result' => $rResult['success'], 'message' => $rResult['message'], 'credits_refunded' => $rResult['credits_refunded'] ?? 0, 'new_balance' => $rResult['new_balance'] ?? null));
    }

    /** GET refundcredit/history — the acting user's refunds. */
    public function history(): void {
        $rActor = self::actor();
        self::json(array('result' => true, 'data' => RefundcreditService::getRefundHistory(intval($rActor['id']))));
    }
}
