<?php

namespace XcVm\Module\Refundcredit;

use XcVm\Domain\Line\LineService;
use XcVm\Domain\User\UserCredits;
use XcVm\Domain\User\UserRepository;

/**
 * Credit refund when a reseller deletes a line before it expires:
 * credits paid minus months passed (a started month is passed, the creation
 * day counts as one), returned to the line's owner (users_credits_logs +
 * users_logs action "delete_refund").
 */
class RefundcreditService {

    use \XcVm\Infrastructure\Database\DatabaseAware;

    const MONTH = 30 * 86400;

    /**
     * May the acting user refund-delete this line: a panel admin, or the
     * reseller owning it (or one of their sub-resellers' lines), with the
     * group's line permission. Authorization::check('line') is not usable here:
     * the super admin's group has no all_reports list.
     */
    public static function canManageLine(array $rLine): bool {
        global $rUserInfo, $rPermissions;
        if (empty($rUserInfo['id']) || !is_array($rPermissions)) {
            return false;
        }
        if (!empty($rPermissions['is_admin'])) {
            return true;
        }
        if (empty($rPermissions['create_line'])) {
            return false;
        }
        $rOwners = array_map('intval', array_merge(array($rUserInfo['id']), (array) ($rPermissions['all_reports'] ?? array())));
        return in_array(intval($rLine['member_id']), $rOwners, true);
    }

    /**
     * Credits paid and months bought for a line, from the reseller logs
     * ("new" + "extend" rows, each with its package duration).
     *
     * @return array{credits_paid: float, months_total: int}
     */
    public static function paidFor(int $rLineID, string $rType = 'line'): array {
        $db = self::db();
        $db->query('SELECT l.`cost`, p.`official_duration`, p.`official_duration_in` FROM `users_logs` l LEFT JOIN `users_packages` p ON p.`id` = l.`package_id` WHERE l.`log_id` = ? AND l.`type` = ? AND l.`action` IN (\'new\', \'extend\');', $rLineID, $rType);
        $rPaid = 0.0;
        $rMonths = 0;
        foreach ($db->get_rows() as $rRow) {
            $rPaid += floatval($rRow['cost']);
            $rDuration = intval($rRow['official_duration']);
            switch ((string) $rRow['official_duration_in']) {
                case 'years': $rMonths += $rDuration * 12; break;
                case 'months': $rMonths += $rDuration; break;
                case 'days': $rMonths += intval(round($rDuration / 30)); break;
                case 'hours': $rMonths += intval(round($rDuration / 720)); break;
            }
        }
        return array('credits_paid' => $rPaid, 'months_total' => $rMonths);
    }

    /**
     * What deleting the line now gives back.
     *
     * @return array{credits_paid: float, months_total: int, months_used: int, months_left: int, credits_refund: float}
     */
    public static function calculateRefund(array $rLine, string $rType = 'line'): array {
        $rNow = time();
        $rCreated = intval($rLine['created_at'] ?? 0);
        $rExp = intval($rLine['exp_date'] ?? 0);
        $rOut = array('credits_paid' => 0.0, 'months_total' => 0, 'months_used' => 0, 'months_left' => 0, 'credits_refund' => 0.0);
        if ($rExp <= $rNow || $rCreated <= 0 || !empty($rLine['is_trial'])) {
            return $rOut;
        }
        $rPaidFor = self::paidFor(intval($rLine['id']), $rType);
        $rOut['credits_paid'] = $rPaidFor['credits_paid'];
        $rOut['months_total'] = $rPaidFor['months_total'] > 0 ? $rPaidFor['months_total'] : max(1, intval(round(($rExp - $rCreated) / self::MONTH)));
        // Rule: refund = credits paid - months passed; a started month is passed,
        // so the creation day already counts one (never a full refund).
        $rOut['months_used'] = max(1, intval(ceil(($rNow - $rCreated) / self::MONTH)));
        $rOut['months_left'] = max(0, $rOut['months_total'] - $rOut['months_used']);
        $rOut['credits_refund'] = max(0.0, floatval($rOut['credits_paid']) - $rOut['months_used']);
        return $rOut;
    }

    /**
     * Refund the owner, log it, delete the line. Acting user must own the line
     * (or be its reseller's admin) and hold the group's line permission.
     */
    public static function deleteLineWithRefund(int $rLineID, array $rActor): array {
        $db = self::db();
        $rLine = UserRepository::getLineById($rLineID);
        if (!$rLine) {
            return array('success' => false, 'message' => 'Line not found');
        }
        if (!self::canManageLine($rLine)) {
            return array('success' => false, 'message' => 'Unauthorized');
        }
        $rCalc = self::calculateRefund($rLine, 'line');
        $rRefund = floatval($rCalc['credits_refund']);
        $rOwnerID = intval($rLine['member_id']);
        $rBalance = null;
        if ($rRefund > 0 && $rOwnerID > 0) {
            if (!UserCredits::credit($rOwnerID, $rRefund)) {
                return array('success' => false, 'message' => 'Credit refund failed');
            }
            $rBalance = UserCredits::balance($rOwnerID);
            $db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rOwnerID, intval($rActor['id']), $rRefund, time(), 'Refund deleted line: ' . $rLine['username'] . ' (' . $rCalc['months_left'] . '/' . $rCalc['months_total'] . ' months)');
        }
        $db->query('INSERT INTO `refund_history`(`reseller_id`, `actor_id`, `line_id`, `line_username`, `line_type`, `credits_paid`, `months_total`, `months_used`, `credits_refunded`, `exp_date_at_delete`, `created_at`, `deleted_line_info`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);', $rOwnerID, intval($rActor['id']), $rLineID, $rLine['username'], 'line', $rCalc['credits_paid'], $rCalc['months_total'], $rCalc['months_used'], $rRefund, intval($rLine['exp_date']), time(), json_encode($rLine));
        $db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', 'delete_refund', ?, null, ?, ?, ?, ?);", $rOwnerID, $rLineID, -$rRefund, $rBalance ?? UserCredits::balance($rOwnerID), time(), json_encode($rLine));
        if (!LineService::deleteLineById($rLineID)) {
            return array('success' => false, 'message' => 'Refunded but line deletion failed', 'credits_refunded' => $rRefund);
        }
        return array('success' => true, 'message' => $rRefund . ' credits refunded (' . $rCalc['months_used'] . '/' . $rCalc['months_total'] . ' months used)', 'credits_refunded' => $rRefund, 'new_balance' => $rBalance, 'calc' => $rCalc);
    }

    public static function getRefundHistory(int $rResellerID, int $rLimit = 50): array {
        $db = self::db();
        $db->query('SELECT * FROM `refund_history` WHERE `reseller_id` = ? ORDER BY `created_at` DESC LIMIT ' . intval($rLimit) . ';', $rResellerID);
        return $db->get_rows() ?: array();
    }
}
