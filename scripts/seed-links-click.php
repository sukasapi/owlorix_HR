<?php

/**
 * Seeds the disposable database for scripts/clickthrough-links.mjs (not for owlorix_hrdb).
 * Create the database and migrate it first:
 *   mysql -uroot -e "CREATE DATABASE owlorix_hr_links_click CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *   DB_DATABASE=owlorix_hr_links_click php artisan migrate
 *   DB_DATABASE=owlorix_hr_links_click php scripts/seed-links-click.php
 */

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Access\SyncRolesAndPermissions;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Without DB_DATABASE in the shell, web/.env points at owlorix_hrdb (real data).
if (DB::connection()->getDatabaseName() !== 'owlorix_hr_links_click') {
    fwrite(STDERR, "Refused: run with DB_DATABASE=owlorix_hr_links_click.\n");
    exit(1);
}

app(SyncRolesAndPermissions::class)();

$make = fn (string $username, string $name, Role $role): User => User::factory()->withRole($role)->create([
    'username' => $username,
    'name' => $name,
    'password' => bcrypt('Klik-uji-2026'),
]);

$make('lead.klik', 'Lead Klik', Role::TeamLead);
$member = $make('anggota.klik', 'Anggota Klik', Role::Employee);
$make('luar.klik', 'Luar Klik', Role::Employee);

$project = Project::query()->create(['name' => 'Proyek Animasi Ayat Alkitab', 'code' => 'AAA', 'status' => ProjectStatus::Active, 'description' => 'Proyek uji klik untuk tautan dokumen.']);
ProjectMember::query()->create(['project_id' => $project->id, 'user_id' => $member->id, 'assigned_at' => now()]);

$empty = Project::query()->create(['name' => 'Proyek Kosong', 'code' => 'KSG', 'status' => ProjectStatus::Active]);
ProjectMember::query()->create(['project_id' => $empty->id, 'user_id' => $member->id, 'assigned_at' => now()]);

echo "project={$project->id} empty={$empty->id}\n";
