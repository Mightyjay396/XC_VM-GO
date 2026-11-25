<?php

namespace XcVm\Module\Trialapi;

use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;

class TrialapiModule extends BaseModule {

    public function getName(): string {
        return 'trialapi';
    }

    public function getVersion(): string {
        return '1.0.0';
    }

    public function boot(ServiceContainer $container): void {
        $db = $container->get('db');
        if ($db) {
            TrialapiService::setDb($db);
        }
    }

    public function registerRoutes(Router $router): void {
        $router->api('trial/create', [TrialapiController::class, 'create'], []);
        $router->api('trial/list', [TrialapiController::class, 'listTrials'], []);
        $router->api('trial/delete', [TrialapiController::class, 'delete'], []);
        $router->api('trial/info', [TrialapiController::class, 'info'], []);
    }
}
