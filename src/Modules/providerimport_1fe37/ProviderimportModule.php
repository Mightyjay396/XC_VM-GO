<?php

namespace XcVm\Module\Providerimport;

use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Core\Module\TopbarRegistry;

/** Provider import: admin-only (adv/import_streams), writes only through core services, no own tables. */
class ProviderimportModule extends BaseModule {

    public function getName(): string {
        return 'providerimport';
    }

    public function getVersion(): string {
        return '1.1.2';
    }

    public function boot(ServiceContainer $container): void {
    }

    public function registerRoutes(Router $router): void {
        $rPerm = ['permission' => ['adv', 'import_streams']];
        $router->group('providerimport', function (Router $r) use ($rPerm) {
            $r->get('', [ProviderImportController::class, 'index'], $rPerm);
            $r->api('pcats', [ProviderImportController::class, 'apiCategories'], $rPerm);
            $r->api('plist', [ProviderImportController::class, 'apiList'], $rPerm);
            $r->api('pimport', [ProviderImportController::class, 'apiImport'], $rPerm);
            $r->api('pvodcats', [ProviderImportController::class, 'apiVodCategories'], $rPerm);
            $r->api('pvodlist', [ProviderImportController::class, 'apiVodList'], $rPerm);
            $r->api('pvodqueue', [ProviderImportController::class, 'apiVodQueue'], $rPerm);
            $r->api('pstatus', [ProviderImportController::class, 'apiStatus'], $rPerm);
        });
    }

    public function registerNavbar(NavbarRegistry $registry): void {
        NavbarRegistry::add((new NavbarItem('content.streams.providerimport'))
            ->parent('content.streams')->url('providerimport')
            ->label('', 'ProviderImport')->permissions(['import_streams'])->order(36));
    }

    public function registerTopbar(TopbarRegistry $registry): void {
        TopbarRegistry::add('providers', 'ProviderImport', 'providerimport', 'import_streams', null, 60);
    }
}
