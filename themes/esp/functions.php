<?php

use BookStack\Entities\Models\Page;
use BookStack\Facades\Theme;
use BookStack\Theming\ThemeEvents;
use BookStack\Users\Models\User;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\View;

$baseDir = __DIR__;

spl_autoload_register(function (string $class) use ($baseDir) {
    $logicPrefix = 'EspTheme\\Logic\\';
    if (strncmp($class, $logicPrefix, strlen($logicPrefix)) === 0) {
        $relative = substr($class, strlen($logicPrefix));
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
        $file = $baseDir . '/logic/' . $relativePath . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

if (!class_exists(\EspTheme\Logic\MaintenanceService::class)) {
    require_once $baseDir . '/logic/MaintenanceService.php';
}

$maintenanceService = new \EspTheme\Logic\MaintenanceService();

/**
 * Ensure maintenance artisan commands are available in both web and console contexts.
 */
(function () use ($maintenanceService) {
    static $commandsRegistered = false;
    if ($commandsRegistered) {
        return;
    }

    $commands = [
        new \EspTheme\Logic\Commands\MaintenanceCheckCommand($maintenanceService),
        new \EspTheme\Logic\Commands\MaintenanceMigrateCommand(),
    ];

    foreach ($commands as $command) {
        Theme::registerCommand($command);
    }

    if (app()->runningInConsole()) {
        $artisan = Artisan::getFacadeRoot();
        if ($artisan instanceof ArtisanApplication) {
            foreach ($commands as $command) {
                if (!$artisan->has($command->getName())) {
                    $artisan->add($command);
                }
            }
        }

        app()->afterResolving(ConsoleKernel::class, function (ConsoleKernel $kernel) use ($commands) {
            foreach ($commands as $command) {
                if (method_exists($kernel, 'registerCommand')) {
                    $kernel->registerCommand($command);
                }
            }
        });

        if (app()->resolved(ConsoleKernel::class)) {
            $kernel = app(ConsoleKernel::class);
            foreach ($commands as $command) {
                if (method_exists($kernel, 'registerCommand')) {
                    $kernel->registerCommand($command);
                }
            }
        }
    }

    $commandsRegistered = true;
})();

Theme::listen(ThemeEvents::APP_BOOT, function () use ($maintenanceService) {
    Lang::addNamespace('esp', __DIR__ . '/lang');

    View::composer('layouts.parts.header', function ($view) use ($maintenanceService) {
        $user = user();
        if ($user && !$user->isGuest()) {
            $view->with('espMaintenanceHeader', $maintenanceService->getHeaderSummaryForUser($user));
        }
    });

    View::composer('pages.show', function ($view) use ($maintenanceService) {
        $page = $view->getData()['page'] ?? null;
        if ($page instanceof Page) {
            $view->with('espMaintenanceRecord', $maintenanceService->getMaintenanceForPage($page));
            $view->with('espMaintenanceService', $maintenanceService);
            if (user()->hasSystemRole('admin')) {
                $view->with('espMaintenanceUserOptions', User::query()->orderBy('name')->get());
            }
        }
    });
});

Theme::listen(ThemeEvents::ROUTES_REGISTER_WEB_AUTH, function (Router $router) use ($maintenanceService) {
    $controller = new \EspTheme\Logic\MaintenanceController($maintenanceService);

    $router->group(['prefix' => 'maintenance'], function () use ($router, $controller) {
        $router->get('tasks', [$controller, 'listTasks'])->name('maintenance.tasks');
        $router->post('assign/{page}', [$controller, 'assign'])->name('maintenance.assign');
        $router->post('start/{page}', [$controller, 'startUpdate'])->name('maintenance.start');
        $router->post('submit/{page}', [$controller, 'submitReview'])->name('maintenance.submit');
        $router->post('approve/{page}', [$controller, 'approve'])->name('maintenance.approve');
        $router->post('reject/{page}', [$controller, 'reject'])->name('maintenance.reject');
    });
});
