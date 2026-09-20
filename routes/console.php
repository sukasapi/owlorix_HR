<?php

use App\Modules\Identity\Access\SyncRolesAndPermissions;
use Illuminate\Support\Facades\Artisan;

Artisan::command('owlorix:sync-roles', function () {
    app(SyncRolesAndPermissions::class)();
    $this->info('Roles and permissions synced.');
})->purpose('Write the Role and Permission enums into the permission tables');
