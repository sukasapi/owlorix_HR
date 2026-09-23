<?php

namespace App\Modules\Identity;

use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/lang', 'identity');
    }
}
