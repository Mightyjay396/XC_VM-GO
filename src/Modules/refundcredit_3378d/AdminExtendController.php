<?php

namespace XcVm\Module\Refundcredit;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\User\UserCredits;
use XcVm\Domain\User\UserRepository;

/** Admin-only "Extend" by official package: adds the package duration, sets the package's connections, keeps bouquets, no credits. */
class AdminExtendController {

    use \XcVm\Infrastructure\Database\DatabaseAware;

    const UNITS = ['hours' => 'hour', 'days' => 'day', 'months' => 'month', 'years' => 'year'];

    private static function json(array $rData): void {
        header('Content-Type: application/json');
        echo json_encode($rData);
        exit();
    }

    public static function allowed(): bool {
        global $rUserInfo, $rPermissions;
        return !empty($rPermissions['is_admin']);
    }

    /** GET adminextend/packages */
    public function packages(): void {
        if (!self::allowed()) {
            self::json(['result' => false, 'error' => 'Unauthorized']);
        }
        $db = self::db();
        $db->query("SELECT `id`, `package_name`, `official_duration`, `official_duration_in`, `max_connections` FROM `users_packages` WHERE `is_official` = 1 AND `is_addon` = 0 AND `official_duration` > 0 ORDER BY `id`;");
        self::json(['result' => true, 'data' => $db->get_rows()]);
    }

    /** POST adminextend/extend (user_id, package_id) */
    public function extend(): void {
        global $rUserInfo;
        if (!self::allowed()) {
            self::json(['result' => false, 'error' => 'Unauthorized']);
        }
        $rLineID = intval(RequestManager::get('user_id') ?? 0);
        $rPackageID = intval(RequestManager::get('package_id') ?? 0);
        $rLine = $rLineID > 0 ? UserRepository::getLineById($rLineID) : null;
        if (!$rLine) {
            self::json(['result' => false, 'error' => 'Line not found']);
        }
        if (empty($rLine['exp_date'])) {
            self::json(['result' => false, 'error' => 'Line has no expiry date (unlimited)']);
        }
        $db = self::db();
        $db->query("SELECT `id`, `package_name`, `official_duration`, `official_duration_in`, `max_connections` FROM `users_packages` WHERE `id` = ? AND `is_official` = 1 AND `is_addon` = 0;", $rPackageID);
        $rPackage = $db->get_row();
        $rUnit = self::UNITS[$rPackage['official_duration_in'] ?? ''] ?? null;
        $rCount = intval($rPackage['official_duration'] ?? 0);
        if (!$rPackage || !$rUnit || $rCount <= 0) {
            self::json(['result' => false, 'error' => 'Invalid package']);
        }
        $rOld = intval($rLine['exp_date']);
        $rNew = (new \DateTime('@' . max($rOld, time())))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->modify("+{$rCount} {$rUnit}")->getTimestamp();
        $rOldCons = intval($rLine['max_connections']);
        $rCons = intval($rPackage['max_connections'] ?? 0) > 0 ? intval($rPackage['max_connections']) : $rOldCons;
        $db->query('UPDATE `lines` SET `exp_date` = ?, `max_connections` = ? WHERE `id` = ?;', $rNew, $rCons, $rLineID);
        $rAdminID = intval($rUserInfo['id']);
        $db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', 'admin_extend', ?, ?, 0, ?, ?, ?);",
            $rAdminID, $rLineID, $rPackageID, UserCredits::balance($rAdminID), time(), json_encode(['old_exp' => $rOld, 'new_exp' => $rNew, 'old_cons' => $rOldCons, 'new_cons' => $rCons]));
        LineService::updateLineSignal($rLineID);
        self::json(['result' => true, 'username' => $rLine['username'], 'package' => $rPackage['package_name'], 'old_exp' => date('Y-m-d H:i', $rOld), 'new_exp' => date('Y-m-d H:i', $rNew), 'old_cons' => $rOldCons, 'new_cons' => $rCons]);
    }
}
