<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Access\SyncRolesAndPermissions;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => app(SyncRolesAndPermissions::class)())
    ->in('Feature');

function userWithRole(Role ...$roles): User
{
    return User::factory()->withRole(...$roles)->create();
}

/** Keys of the pages under one menu item (tabs of Persetujuan and Pantauan, the pages listed on Pengaturan). */
function navChildren(iterable $nav, string $key): Collection
{
    $item = collect($nav)->flatMap(fn ($group) => $group['items'])->firstWhere('key', $key);

    return collect($item['children'] ?? [])->pluck('key');
}

/** Every page key in the menu, menu items and the pages under them. */
function navKeys(iterable $nav): Collection
{
    return collect($nav)->flatMap(fn ($group) => $group['items'])
        ->flatMap(fn ($item) => [$item['key'], ...collect($item['children'] ?? [])->pluck('key')->all()])
        ->values();
}
