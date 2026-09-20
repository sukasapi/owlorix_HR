<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Access\SyncRolesAndPermissions;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => app(SyncRolesAndPermissions::class)())
    ->in('Feature');

function userWithRole(Role ...$roles): User
{
    return User::factory()->withRole(...$roles)->create();
}
