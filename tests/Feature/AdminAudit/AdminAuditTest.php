<?php

use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\Device;
use App\Modules\Organization\Models\Team;
use App\Modules\Shared\Audit\AuditLog;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Attendance\Support\Desk;

// Log audit. Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->travelTo(Desk::time('2026-09-14 12:00'));
    $this->admin = userWithRole(Role::Superadmin);
});

/** An audit row written at a studio wall-clock time. */
function auditAt(string $at, array $attributes): AuditLog
{
    return AuditLog::query()->create([
        'actor_id' => null,
        'subject_type' => null,
        'subject_id' => null,
        'before' => null,
        'after' => null,
        'ip' => '10.0.0.7',
        ...$attributes,
        'created_at' => Desk::time($at),
    ]);
}

/**
 * Action names passed to `->record(` anywhere in app/, including both sides of a ternary.
 *
 * @return list<string>
 */
function recordedAuditActions(): array
{
    $actions = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        preg_match_all('/->record\(\s*([^,]*),/s', $file->getContents(), $calls);

        foreach ($calls[1] as $firstArgument) {
            preg_match_all('/[\'"]([a-z_]+(?:\.[a-z_]+)+)[\'"]/', $firstArgument, $names);
            array_push($actions, ...$names[1]);
        }
    }

    return array_values(array_unique($actions));
}

/** @return list<string> action keys under `actions` in a lang file */
function auditLabelKeys(string $locale): array
{
    $source = file_get_contents(resource_path("js/lang/{$locale}/audit.ts"));
    preg_match_all('/[\'"]([a-z_]+(?:\.[a-z_]+)+)[\'"]\s*:/', $source, $keys);

    return $keys[1];
}

describe('authorization', function () {
    it('shows the page to Superadmin and puts it in the menu', function () {
        $this->actingAs($this->admin)->get(route('admin.audit.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/audit/Index')
                ->where('nav', fn ($nav) => collect(collect($nav)->firstWhere('group', 'oversight')['items'])->contains('key', 'audit')));
    });

    it('refuses everyone else', function (Role $role) {
        $this->actingAs(userWithRole($role))->get(route('admin.audit.index'))->assertForbidden();
    })->with([
        'employee' => [Role::Employee],
        'team lead' => [Role::TeamLead],
        'project manager' => [Role::ProjectManager],
        'project director' => [Role::ProjectDirector],
    ]);
});

it('has an Indonesian and English label for every action the code records', function () {
    $actions = recordedAuditActions();

    expect($actions)->toContain('device.revoked', 'device.restored', 'settings.updated', 'user.created', 'overtime.decided', 'overtime.decision_changed');

    foreach (['id', 'en'] as $locale) {
        $missing = array_values(array_diff($actions, auditLabelKeys($locale)));

        expect($missing)->toBe([], "Missing {$locale} labels in resources/js/lang/{$locale}/audit.ts");
    }
});

describe('list', function () {
    it('lists newest first with actor, subject links, and names for ids in the diff', function () {
        $person = userWithRole(Role::Employee);
        $team = Team::factory()->create(['name' => 'Animation']);
        $device = Device::factory()->create(['hostname' => 'PC-ANIM-07']);
        $shift = Shift::factory()->create(['user_id' => $person->id, 'work_date' => '2026-09-11']);

        auditAt('2026-09-14 09:00', ['action' => 'user.roles_changed', 'actor_id' => $this->admin->id, 'subject_type' => $person->getMorphClass(), 'subject_id' => $person->id, 'before' => ['roles' => ['employee']], 'after' => ['roles' => ['employee', 'team_lead']]]);
        auditAt('2026-09-14 10:00', ['action' => 'team.member_added', 'actor_id' => $this->admin->id, 'subject_type' => $team->getMorphClass(), 'subject_id' => $team->id, 'after' => ['user_id' => $person->id]]);
        auditAt('2026-09-14 11:00', ['action' => 'shift.corrected', 'actor_id' => $this->admin->id, 'subject_type' => $shift->getMorphClass(), 'subject_id' => $shift->id, 'before' => ['clock_out_at' => null], 'after' => ['clock_out_at' => '2026-09-11T10:00:00.000Z']]);
        auditAt('2026-09-14 11:30', ['action' => 'device.revoked', 'actor_id' => $this->admin->id, 'before' => ['device_id' => $device->id, 'hostname' => 'PC-ANIM-07', 'kind' => 'desktop', 'status' => 'active'], 'after' => ['device_id' => $device->id, 'hostname' => 'PC-ANIM-07', 'kind' => 'desktop', 'status' => 'revoked', 'reason' => 'Rusak']]);

        $this->actingAs($this->admin)->get(route('admin.audit.index'))
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 4)
                ->where('entries.data.0.action', 'device.revoked')
                ->where('entries.data.0.subject.kind', 'device')
                ->where('entries.data.0.subject.label', 'PC-ANIM-07')
                ->where('entries.data.0.subject.href', route('admin.devices.index', ['jenis' => 'desktop', 'q' => $device->id, 'status' => 'all'], false))
                ->where('entries.data.1.subject.kind', 'shift')
                ->where('entries.data.1.subject.person', $person->name)
                ->where('entries.data.1.subject.date', '2026-09-11')
                ->where('entries.data.1.subject.href', null)
                ->where('entries.data.2.subject.kind', 'team')
                ->where('entries.data.2.subject.label', 'Animation')
                ->where('entries.data.2.subject.href', route('admin.teams.index', absolute: false))
                ->where('entries.data.3.created_at', '2026-09-14T02:00:00.000Z')
                ->where('entries.data.3.actor.name', $this->admin->name)
                ->where('entries.data.3.subject.kind', 'person')
                ->where('entries.data.3.subject.href', route('admin.people.index', ['q' => $person->username, 'status' => 'all'], false))
                ->where('entries.data.3.ip', '10.0.0.7')
                ->where("names.people.{$person->id}", $person->name)
                ->where('options.groups', ['device', 'shift', 'team', 'user']));
    });

    it('hides the IP address from anyone who is not Superadmin', function () {
        $viewer = userWithRole(Role::ProjectDirector);
        $viewer->givePermissionTo(Permission::ViewAuditLog->value);
        auditAt('2026-09-14 09:00', ['action' => 'team.created', 'after' => ['name' => 'Rigging']]);

        $this->actingAs($viewer)->get(route('admin.audit.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('entries.data.0.ip', null));
    });

    it('shows an empty page when nothing was recorded', function () {
        $this->actingAs($this->admin)->get(route('admin.audit.index'))
            ->assertInertia(fn ($page) => $page->has('entries.data', 0)->where('entries.total', 0)->where('options.groups', []));
    });
});

describe('filters', function () {
    beforeEach(function () {
        $this->person = userWithRole(Role::Employee);
        $this->other = userWithRole(Role::Employee);
        $this->lead = userWithRole(Role::ProjectDirector);
        $team = Team::factory()->create();

        auditAt('2026-09-12 23:30', ['action' => 'user.updated', 'actor_id' => $this->admin->id, 'subject_type' => $this->person->getMorphClass(), 'subject_id' => $this->person->id]);
        auditAt('2026-09-13 00:10', ['action' => 'team.member_added', 'actor_id' => $this->admin->id, 'subject_type' => $team->getMorphClass(), 'subject_id' => $team->id, 'after' => ['user_id' => $this->person->id]]);
        auditAt('2026-09-13 15:00', ['action' => 'overtime.decided', 'actor_id' => $this->lead->id, 'before' => ['status' => 'pending'], 'after' => ['status' => 'approved']]);
        auditAt('2026-09-14 08:00', ['action' => 'user.updated', 'actor_id' => $this->admin->id, 'subject_type' => $this->other->getMorphClass(), 'subject_id' => $this->other->id]);
    });

    it('filters by action group', function () {
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['grup' => 'user']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 2)->where('filters.group', 'user'));

        // An invalid group is ignored rather than matching everything by accident
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['grup' => "user%' or 1=1"]))
            ->assertInertia(fn ($page) => $page->has('entries.data', 4)->where('filters.group', ''));
    });

    it('filters by actor', function () {
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['pelaku' => $this->lead->id]))
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.action', 'overtime.decided')
                ->where('options.actors', fn ($actors) => collect($actors)->pluck('id')->sort()->values()->all() === collect([$this->admin->id, $this->lead->id])->sort()->values()->all()));
    });

    it('filters by the person an entry is about', function () {
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['orang' => $this->person->id]))
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 2)
                ->where('entries.data.0.action', 'team.member_added')
                ->where('entries.data.1.action', 'user.updated'));
    });

    it('filters by studio dates, Asia/Jakarta days', function () {
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['dari' => '2026-09-13', 'sampai' => '2026-09-13']))
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 2)
                ->where('entries.data.0.action', 'overtime.decided')
                ->where('entries.data.1.action', 'team.member_added'));

        // Reversed dates are swapped, a broken date is ignored
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['dari' => '2026-09-14', 'sampai' => '2026-09-13']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 3)->where('filters.from', '2026-09-13'));
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['dari' => '2026-02-31']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 4)->where('filters.from', null));
    });

    it('combines filters', function () {
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['grup' => 'user', 'sampai' => '2026-09-13']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 1)->where('entries.data.0.subject.label', $this->person->name.' ('.$this->person->username.')'));
    });
});
