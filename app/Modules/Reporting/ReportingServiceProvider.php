<?php

namespace App\Modules\Reporting;

use Illuminate\Support\ServiceProvider;

/** Export labels live in the module (`__('reporting::export.key')`). */
class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/lang', 'reporting');
    }
}
