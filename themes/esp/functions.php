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
use Illuminate\Support\Facades\Log;

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

$serviceClass = \EspTheme\Logic\MaintenanceService::class;

if (!app()->bound($serviceClass)) {
    app()->singleton($serviceClass, function () use ($serviceClass) {
        return new $serviceClass();
    });
}

/**
 * Ensure maintenance artisan commands are available in both web and console contexts.
 */
(function () use ($serviceClass) {
    static $commandsRegistered = false;
    if ($commandsRegistered) {
        return;
    }

    $commands = [
        new \EspTheme\Logic\Commands\MaintenanceCheckCommand(app($serviceClass)),
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

Theme::listen(ThemeEvents::APP_BOOT, function () use ($serviceClass) {
    Lang::addNamespace('esp', __DIR__ . '/lang');
    View::addNamespace('esp', __DIR__ . '/views');

    View::composer('layouts.parts.header', function ($view) use ($serviceClass) {
        $user = user();
        if ($user && !$user->isGuest()) {
            $view->with('espMaintenanceHeader', app($serviceClass)->getHeaderSummaryForUser($user));
        }
    });

    View::composer('layouts.parts.header-user-menu', function ($view) use ($serviceClass) {
        $user = user();
        if ($user && !$user->isGuest()) {
            $service = app($serviceClass);
            $view->with('espMaintenanceMenuVisible', $service->userCanDocumentManage($user));
            $view->with('espMaintenanceHeader', $service->getHeaderSummaryForUser($user));
        }
    });

    View::composer('pages.show', function ($view) use ($serviceClass) {
        $page = $view->getData()['page'] ?? null;
        if ($page instanceof Page) {
            $viewer = user();
            $service = app($serviceClass);
            $record = $service->getMaintenanceForPage($page);

            if ($record && $viewer && $service->shouldShowApprovedContent($record, $viewer)) {
                $approvedRevision = $service->getApprovedRevision($record);
                if ($approvedRevision) {
                    $page->html = $approvedRevision->html;
                    $page->text = $approvedRevision->text;
                    $page->markdown = $approvedRevision->markdown;
                    $page->name = $approvedRevision->name;
                }
            }

            $view->with('espMaintenanceRecord', $record);
            $view->with('espMaintenanceService', $service);
            $canAdminister = $service->userCanAdminister($viewer);
            $view->with('espMaintenanceCanAdminister', $canAdminister);
            if ($canAdminister) {
                $view->with('espMaintenanceUserOptions', User::query()->orderBy('name')->get());
            }
        }
    });

    Page::saved(function (Page $page): void {
        $actor = user();
        if (!$actor || $actor->isGuest()) {
            return;
        }

        try {
            app($serviceClass)->handlePageUpdated($page, $actor);
        } catch (Throwable $e) {
            // 防止影響原有保存流程，僅記錄異常便於排查。
            Log::warning('ESP maintenance sync failed: ' . $e->getMessage());
        }
    });
});

Theme::listen(ThemeEvents::ROUTES_REGISTER_WEB_AUTH, function (Router $router) {
    $controller = '\\EspTheme\\Logic\\MaintenanceController';

    $router->group(['prefix' => 'maintenance'], function () use ($router, $controller) {
        $router->get('tasks', $controller . '@listTasks')->name('maintenance.tasks');
        $router->get('overview', $controller . '@overview')->name('maintenance.overview');
        $router->post('assign/{page}', $controller . '@assign')->where(['page' => '[0-9]+'])->name('maintenance.assign');
        $router->post('start/{page}', $controller . '@startUpdate')->where(['page' => '[0-9]+'])->name('maintenance.start');
        $router->post('submit/{page}', $controller . '@submitReview')->where(['page' => '[0-9]+'])->name('maintenance.submit');
        $router->post('approve/{page}', $controller . '@approve')->where(['page' => '[0-9]+'])->name('maintenance.approve');
        $router->post('reject/{page}', $controller . '@reject')->where(['page' => '[0-9]+'])->name('maintenance.reject');
    });
});
