<?php

namespace App\Modules\Shared;

use App\Modules\Shared\Settings\Settings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Loads each module's routes: `app/Modules/<Module>/routes/web*.php` (session) and `api.php` (under /api/v1),
 * and registers `app/Modules/<Module>/<Module>ServiceProvider.php` when a module has one (listeners, schedules).
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Settings::class);

        foreach (glob(app_path('Modules/*/*ServiceProvider.php')) ?: [] as $file) {
            $module = basename(dirname($file));
            $class = 'App\\Modules\\'.$module.'\\'.basename($file, '.php');

            if ($module !== 'Shared' && class_exists($class)) {
                $this->app->register($class);
            }
        }
    }

    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        // web.php plus web-<feature>.php files, so parallel features in one module keep separate route files.
        foreach (glob(app_path('Modules/*/routes/web*.php')) ?: [] as $file) {
            Route::middleware('web')->group($file);
        }

        foreach (glob(app_path('Modules/*/routes/api.php')) ?: [] as $file) {
            Route::middleware('api')->prefix('api/v1')->name('api.v1.')->group($file);
        }
    }
}
