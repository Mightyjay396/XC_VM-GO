<?php

namespace XcVm\Module\Refundcredit;

use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;

class RefundcreditModule extends BaseModule {

    public function getName(): string {
        return 'refundcredit';
    }

    public function getVersion(): string {
        return '1.1.0';
    }

    public function boot(ServiceContainer $container): void {
    }

    /** Admin and reseller panels both mount these under the panel URL. */
    public function registerRoutes(Router $router): void {
        $router->group('refundcredit', function (Router $r) {
            $r->get('calculate', [RefundcreditController::class, 'calculate']);
            $r->any('delete', [RefundcreditController::class, 'delete']);
            $r->get('history', [RefundcreditController::class, 'history']);
        });
        $router->group('adminextend', function (Router $r) { // ADMINEXTEND_PATCH
            $r->get('packages', [AdminExtendController::class, 'packages']);
            $r->any('extend', [AdminExtendController::class, 'extend']);
        });
    }

    public function install(): void {
        self::ensureTable();
    }

    public static function ensureTable(): void {
        $db = \XcVm\Infrastructure\Database\DatabaseFactory::get();
        if ($db) {
            $db->query(file_get_contents(__DIR__ . '/database.sql'));
        }
    }
}
