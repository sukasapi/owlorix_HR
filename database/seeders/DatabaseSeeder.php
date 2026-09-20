<?php

namespace Database\Seeders;

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Access\SyncRolesAndPermissions;
use App\Modules\Identity\Auth\TemporaryPassword;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Roles, permissions, and the first Superadmin account. No sample people are created:
     * Superadmin adds real accounts from the web app.
     */
    public function run(): void
    {
        app(SyncRolesAndPermissions::class)();

        if (User::query()->where('username', 'superadmin')->exists()) {
            return;
        }

        $password = env('SEED_SUPERADMIN_PASSWORD') ?: TemporaryPassword::generate();

        $user = User::query()->create([
            'username' => 'superadmin',
            'name' => 'Superadmin',
            'password' => $password,
            'must_change_password' => true,
        ]);
        $user->assignRole(Role::Superadmin->value);

        $this->command?->warn("Superadmin created. Username: superadmin  Temporary password: {$password}");
    }
}
