<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\EmploymentType;
use App\Modules\Monitoring\Models\AppUsageSession;
use App\Modules\Shared\Audit\AuditLog;
use App\Modules\Shared\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Attendance\Support\Desk;

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->desk = Desk::for($this, $this->person);
    $this->desk->send('clock_in', [], '2026-09-24 09:00');
    $this->desk->at('2026-09-24 11:00');
});

function usage(string $start, string $end, array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'app' => 'Blender',
        'exe' => 'blender.exe',
        'title' => 'scene_07.blend',
        'browser' => false,
        'started_at' => Desk::time($start)->toIso8601ZuluString('millisecond'),
        'ended_at' => Desk::time($end)->toIso8601ZuluString('millisecond'),
        ...$overrides,
    ];
}

function upload(Desk $desk, array $sessions)
{
    return $desk->request('POST', '/api/v1/app-usage', ['sessions' => $sessions]);
}

describe('upload from the desktop app', function () {
    it('stores sessions inside a shift, and answers a resend as duplicate', function () {
        $first = usage('2026-09-24 09:05', '2026-09-24 09:45');
        $browser = usage('2026-09-24 09:45', '2026-09-24 09:50', ['app' => 'Chrome', 'exe' => 'chrome.exe', 'title' => 'Referensi gerak kancil', 'browser' => true]);

        upload($this->desk, [$first, $browser])->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('accepted', [$first['id'], $browser['id']]);

        $stored = AppUsageSession::query()->where('client_id', $first['id'])->sole();
        expect($stored->seconds)->toBe(2400)
            ->and($stored->shift_id)->toBe($this->desk->shift()->id)
            ->and($stored->device_id)->toBe($this->desk->device->id);

        upload($this->desk, [$first])->assertOk()->assertJsonPath('duplicates', [$first['id']])->assertJsonPath('accepted', []);
        expect(AppUsageSession::query()->count())->toBe(2);
    });

    it('stores nothing outside a shift, even if the app sends it', function () {
        $session = usage('2026-09-24 07:00', '2026-09-24 07:30');

        upload($this->desk, [$session])->assertOk()
            ->assertJsonPath('rejected.0.code', 'outside_shift');
        expect(AppUsageSession::query()->count())->toBe(0);
    });

    it('stores nothing while the rule is turned off', function () {
        app(Settings::class)->set('monitoring.app_usage', false);

        upload($this->desk, [usage('2026-09-24 09:05', '2026-09-24 09:45')])->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('rejected.0.code', 'disabled');
        expect(AppUsageSession::query()->count())->toBe(0);
    });

    it('records permanent and contract employees only, as switched in Aturan', function () {
        $this->person->forceFill(['employment_type' => EmploymentType::Intern])->save();
        upload($this->desk, [usage('2026-09-24 09:05', '2026-09-24 09:15')])->assertOk()->assertJsonPath('rejected.0.code', 'employment_type');

        $this->person->forceFill(['employment_type' => EmploymentType::Freelance])->save();
        upload($this->desk, [usage('2026-09-24 09:15', '2026-09-24 09:25')])->assertOk()->assertJsonPath('rejected.0.code', 'employment_type');

        $this->person->forceFill(['employment_type' => EmploymentType::Contract])->save();
        upload($this->desk, [usage('2026-09-24 09:25', '2026-09-24 09:35')])->assertOk()->assertJsonCount(1, 'accepted');

        app(Settings::class)->set('monitoring.app_usage_contract', false);
        upload($this->desk, [usage('2026-09-24 09:35', '2026-09-24 09:45')])->assertOk()->assertJsonPath('rejected.0.code', 'employment_type');

        app(Settings::class)->set('monitoring.app_usage_intern', true);
        $this->person->forceFill(['employment_type' => EmploymentType::Intern])->save();
        upload($this->desk, [usage('2026-09-24 09:45', '2026-09-24 09:55')])->assertOk()->assertJsonCount(1, 'accepted');

        expect(AppUsageSession::query()->count())->toBe(2);
    });

    it('tells each desktop app whether its person is recorded', function () {
        $this->desk->request('GET', '/api/v1/config')->assertOk()->assertJsonPath('settings', fn ($s) => $s['monitoring.app_usage'] === true);

        $this->person->forceFill(['employment_type' => EmploymentType::Intern])->save();
        $this->desk->request('GET', '/api/v1/config')->assertOk()->assertJsonPath('settings', fn ($s) => $s['monitoring.app_usage'] === false);

        $this->person->forceFill(['employment_type' => EmploymentType::Permanent])->save();
        app(Settings::class)->set('monitoring.app_usage', false);
        $this->desk->request('GET', '/api/v1/config')->assertOk()->assertJsonPath('settings', fn ($s) => $s['monitoring.app_usage'] === false);
    });

    it('refuses impossible times', function () {
        upload($this->desk, [usage('2026-09-24 10:00', '2026-09-24 09:00')])->assertOk()->assertJsonPath('rejected.0.code', 'invalid_time');
    });

    it('refuses a request without a device token', function () {
        $this->withoutToken()->app['auth']->forgetGuards();

        $this->postJson('/api/v1/app-usage', ['sessions' => []])->assertUnauthorized();
    });
});

describe('Aktivitas detail page', function () {
    beforeEach(function () {
        upload($this->desk, [
            usage('2026-09-24 09:05', '2026-09-24 09:45'),
            usage('2026-09-24 09:45', '2026-09-24 09:50', ['app' => 'Chrome', 'exe' => 'chrome.exe', 'title' => 'Referensi', 'browser' => true]),
            usage('2026-09-24 09:50', '2026-09-24 10:10'),
        ])->assertOk();
    });

    it('shows one person and day to Superadmin, sorted apps first, and records the look in the audit log', function () {
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->get(route('monitoring.app-usage', ['orang' => $this->person->id, 'tanggal' => '2026-09-24']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('monitoring/AppUsage')
                ->where('person.total_seconds', 3900)
                ->where('person.browser_seconds', 300)
                ->where('person.apps.0.app', 'Blender')
                ->where('person.apps.0.seconds', 3600)
                ->has('person.sessions', 3)
                ->where('person.sessions.0.title', 'scene_07.blend')
                ->where('person.from_archive', false));

        expect(AuditLog::query()->where('action', 'app_usage.viewed')->where('actor_id', $admin->id)->count())->toBe(1);
    });

    it('is closed to everyone but Superadmin, and only Superadmin sees the tab', function (Role $role) {
        $this->actingAs(userWithRole($role))->get(route('monitoring.app-usage'))->assertForbidden();
    })->with([Role::Employee, Role::TeamLead, Role::ProjectManager, Role::ProjectDirector]);

    it('adds the tab under Pantauan for Superadmin only', function () {
        $this->actingAs(userWithRole(Role::Superadmin))->get(route('monitoring.app-usage'))
            ->assertInertia(fn ($page) => $page->where('nav', fn ($nav) => navChildren($nav, 'monitoring')->all() === ['work_monitor', 'workload', 'app_usage']));
    });

    it('moves old rows to the archive without deleting them, and still shows them', function () {
        $this->travelTo(Desk::time('2027-01-10 03:00'));
        $this->artisan('monitoring:archive-app-usage')->assertSuccessful();

        expect(AppUsageSession::query()->count())->toBe(0)
            ->and(DB::table('app_usage_archive')->count())->toBe(3);

        $this->actingAs(userWithRole(Role::Superadmin))->get(route('monitoring.app-usage', ['orang' => $this->person->id, 'tanggal' => '2026-09-24']))
            ->assertInertia(fn ($page) => $page->has('person.sessions', 3)->where('person.from_archive', true));

        // A resend of an archived id is still a duplicate, not a second copy
        $archived = DB::table('app_usage_archive')->value('client_id');
        upload($this->desk, [[...usage('2026-09-24 09:05', '2026-09-24 09:45'), 'id' => $archived]])->assertJsonPath('duplicates', [$archived]);
    });
});
