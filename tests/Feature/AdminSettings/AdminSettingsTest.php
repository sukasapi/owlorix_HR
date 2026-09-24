<?php

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\ShiftStatus;
use App\Modules\Attendance\Services\ShiftStateResolver;
use App\Modules\Identity\Access\Role;
use App\Modules\Shared\Audit\AuditLog;
use App\Modules\Shared\Http\Controllers\Admin\SettingsFields;
use App\Modules\Shared\Settings\Setting;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Tests\Feature\Attendance\Support\Desk;
use Tests\Feature\WebClock\Support\Browser;

// Aturan: rule settings of docs/02. Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->travelTo(Desk::time('2026-09-14 08:00'));
    $this->admin = userWithRole(Role::Superadmin);
});

/** @return array<string, int|bool> every editable value as the page sends it */
function currentRuleValues(): array
{
    $settings = app(Settings::class);

    return collect(SettingsFields::all())->mapWithKeys(fn (array $field) => [$field['key'] => $settings->get($field['key'])])->all();
}

describe('authorization', function () {
    it('shows the page to Superadmin with every setting, the default, and a read-only time zone', function () {
        $this->actingAs($this->admin)->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/settings/Edit')
                ->where('timezone', 'Asia/Jakarta')
                ->has('fields', count(config('owlorix.settings')) - 1)
                ->where('fields.0.key', 'attendance.regular_limit_minutes')
                ->where('fields.0.value', 480)
                ->where('fields.0.default', 480)
                ->where('fields.0.applies', 'new_shifts')
                ->where('fields.0.rule', '3.3.1')
                ->where('fields.0.changed_by', null)
                ->where('nav', fn ($nav) => collect(collect($nav)->firstWhere('group', 'admin')['items'])->contains('key', 'rules')));
    });

    it('refuses everyone else', function (Role $role) {
        $user = userWithRole($role);

        $this->actingAs($user)->get(route('admin.settings.edit'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.settings.update'), ['values' => ['attendance.regular_limit_minutes' => 420]])->assertForbidden();

        expect(app(Settings::class)->int('attendance.regular_limit_minutes'))->toBe(480);
    })->with([
        'employee' => [Role::Employee],
        'team lead' => [Role::TeamLead],
        'project manager' => [Role::ProjectManager],
        'project director' => [Role::ProjectDirector],
    ]);
});

it('covers every key in config/owlorix.php', function () {
    $listed = collect(SettingsFields::all())->pluck('key')->merge(SettingsFields::READ_ONLY)->sort()->values()->all();

    expect($listed)->toBe(collect(config('owlorix.settings'))->keys()->sort()->values()->all());
});

describe('validation', function () {
    it('refuses values outside the limits or not numbers, and saves nothing', function () {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['values' => [
            ...currentRuleValues(),
            'attendance.regular_limit_minutes' => 30,
            'attendance.idle_threshold_minutes' => 'sepuluh',
            'overtime.late_claim_hours' => 500,
            'attendance.web_clock_in' => 'mungkin',
            'attendance.prompt_auto_close_minutes' => 45,
        ]])->assertSessionHasErrors([
            'attendance.regular_limit_minutes' => 'range',
            'attendance.idle_threshold_minutes' => 'integer',
            'overtime.late_claim_hours' => 'range',
            'attendance.web_clock_in' => 'boolean',
        ]);

        expect(Setting::query()->count())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0);
    });

    it('refuses values that contradict each other', function () {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['values' => [
            ...currentRuleValues(),
            'sync.heartbeat_local_seconds' => 120,
            'sync.heartbeat_upload_seconds' => 90,
            'attendance.prompt_repeat_minutes' => 40,
            'attendance.prompt_auto_close_minutes' => 30,
        ]])->assertSessionHasErrors([
            'sync.heartbeat_upload_seconds' => 'upload_below_local',
            'attendance.prompt_repeat_minutes' => 'repeat_above_close',
        ]);

        expect(Setting::query()->count())->toBe(0);
    });
});

describe('saving', function () {
    it('saves only the changed keys, audits each with before and after, and shows who changed it', function () {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['values' => [
            ...currentRuleValues(),
            'attendance.regular_limit_minutes' => '450',
            'attendance.web_clock_in' => false,
            'app.timezone' => 'Asia/Makassar',
        ]])->assertSessionHasNoErrors()->assertRedirect();

        $settings = app(Settings::class);
        $logs = AuditLog::query()->where('action', 'settings.updated')->orderBy('id')->get();

        expect($settings->int('attendance.regular_limit_minutes'))->toBe(450)
            ->and($settings->get('attendance.web_clock_in'))->toBeFalse()
            ->and($settings->get('app.timezone'))->toBe('Asia/Jakarta')
            ->and(Setting::query()->pluck('key')->sort()->values()->all())->toBe(['attendance.regular_limit_minutes', 'attendance.web_clock_in'])
            ->and($logs)->toHaveCount(2)
            ->and($logs[0]->actor_id)->toBe($this->admin->id)
            ->and($logs[0]->before)->toBe(['attendance.regular_limit_minutes' => 480])
            ->and($logs[0]->after)->toBe(['attendance.regular_limit_minutes' => 450])
            ->and($logs[1]->before)->toBe(['attendance.web_clock_in' => true])
            ->and($logs[1]->after)->toBe(['attendance.web_clock_in' => false]);

        $this->actingAs($this->admin)->get(route('admin.settings.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('fields.0.value', 450)
                ->where('fields.0.default', 480)
                ->where('fields.0.changed_by', $this->admin->name)
                ->where('fields.0.changed_at', '2026-09-14T01:00:00.000Z'));

        // Saving the same values again writes no new entries
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['values' => currentRuleValues()])->assertSessionHasNoErrors();

        expect(AuditLog::query()->count())->toBe(2);
    });

    it('goes back to the default like any other value', function () {
        app(Settings::class)->set('attendance.idle_threshold_minutes', 15, $this->admin->id);

        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['values' => [...currentRuleValues(), 'attendance.idle_threshold_minutes' => 10]])
            ->assertSessionHasNoErrors();

        expect(app(Settings::class)->int('attendance.idle_threshold_minutes'))->toBe(10)
            ->and(AuditLog::query()->sole()->after)->toBe(['attendance.idle_threshold_minutes' => 10]);
    });
});

describe('the engine reads the new values', function () {
    it('copies the regular limit onto shifts at clock-in, so a change applies to the next shift only', function () {
        $person = userWithRole(Role::Employee);
        $browser = new Browser($this, $person);
        $browser->post('/absen/masuk', at: '2026-09-14 09:00')->assertSessionHasNoErrors();

        app('auth')->forgetGuards();
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['values' => [...currentRuleValues(), 'attendance.regular_limit_minutes' => 420]])
            ->assertSessionHasNoErrors();

        expect($browser->shift()->regular_limit_minutes)->toBe(480);

        $browser->post('/absen/pulang', at: '09:30')->assertSessionHasNoErrors();
        $browser->post('/absen/masuk', at: '10:00')->assertSessionHasNoErrors();

        expect($browser->shift()->regular_limit_minutes)->toBe(420);
    });

    it('uses a changed prompt timeout for a shift that is already running', function () {
        $person = userWithRole(Role::Employee);
        app(Settings::class)->set('attendance.regular_limit_minutes', 60);
        $browser = new Browser($this, $person);
        $browser->post('/absen/masuk', at: '2026-09-14 09:00')->assertSessionHasNoErrors();
        $browser->keepAlive('09:30');

        app('auth')->forgetGuards();
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['values' => [...currentRuleValues(), 'attendance.prompt_auto_close_minutes' => 10]])
            ->assertSessionHasNoErrors();

        $browser->keepAlive('10:16');
        $result = app(ShiftStateResolver::class)->shift($browser->shift(), CarbonImmutable::now())->result;

        // With the default 30 minutes the prompt would still be waiting at 10:16
        expect($result->status)->toBe(ShiftStatus::Closed)
            ->and($result->endReason)->toBe(EndReason::AutoNoAnswer)
            ->and($result->clockOutAt->toIso8601ZuluString())->toBe('2026-09-14T03:00:00Z');
    });
});

describe('weekly work target', function () {
    it('lets Superadmin set the targets and refuses values out of range', function () {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['values' => [
            ...currentRuleValues(),
            'target.weekly_hours' => 35,
            'target.intern_days_per_week' => 1,
            'target.intern_minutes_per_day' => 300,
        ]])->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        expect($settings->int('target.weekly_hours'))->toBe(35)
            ->and($settings->int('target.intern_days_per_week'))->toBe(1)
            ->and($settings->int('target.intern_minutes_per_day'))->toBe(300);

        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['values' => [
            ...currentRuleValues(),
            'target.weekly_hours' => 0,
            'target.intern_days_per_week' => 8,
        ]])->assertSessionHasErrors(['target.weekly_hours' => 'range', 'target.intern_days_per_week' => 'range']);
    });
});
