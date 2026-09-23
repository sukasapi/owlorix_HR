<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Auth\AccountLookup;
use App\Modules\Identity\Models\Device;
use App\Modules\Monitoring\Services\ActivityTimeline;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Attendance\Support\Desk;

// Monitor aktivitas page (docs/14 2.4). Monday 2026-09-14 12:00, Asia/Jakarta.

beforeEach(function () {
    $this->travelTo(Desk::time('2026-09-14 12:00'));
    $this->admin = userWithRole(Role::Superadmin);
    $this->person = userWithRole(Role::Employee);
});

/** An access row at a studio wall-clock time. */
function accessAt(string $at, array $attributes = []): void
{
    DB::table('access_logs')->insert([
        'event' => 'page_view',
        'method' => 'GET',
        'route_name' => 'my-day',
        'path' => '/',
        'status' => 200,
        'ip' => '10.0.0.7',
        ...$attributes,
        'created_at' => Desk::time($at)->format('Y-m-d H:i:s.v'),
    ]);
}

function attendanceAt(string $at, int $userId, string $type = 'clock_in'): void
{
    $device = Device::query()->firstOrCreate(['id' => 'PC-ANIM-07:abcd'], ['hostname' => 'PC-ANIM-07', 'app_version' => '0.1.0', 'last_seen_at' => now()]);

    DB::table('attendance_events')->insert([
        'id' => (string) Str::uuid7(),
        'user_id' => $userId,
        'device_id' => $device->id,
        'type' => $type,
        'occurred_at' => Desk::time($at)->format('Y-m-d H:i:s.v'),
        'occurred_at_device' => Desk::time($at)->format('Y-m-d H:i:s.v'),
        'boot_id' => 'boot',
        'uptime_ms' => 1000,
        'server_offset_ms' => 0,
        'offline' => false,
        'payload' => '{}',
        'received_at' => Desk::time($at)->format('Y-m-d H:i:s.v'),
    ]);
}

/** The timeline props of one page load, without the page views earlier loads of this page recorded. */
function monitorTimeline(Tests\TestCase $test, $viewer, array $query = []): array
{
    DB::table('access_logs')->where('route_name', 'admin.activity.index')->delete();

    return $test->actingAs($viewer)->get(route('admin.activity.index', $query))->viewData('page')['props']['timeline'];
}

function auditRowAt(string $at, ?int $actorId, string $action = 'user.updated'): void
{
    AuditLog::query()->create(['actor_id' => $actorId, 'action' => $action, 'ip' => '10.0.0.9', 'created_at' => Desk::time($at)]);
}

describe('authorization', function () {
    it('shows the page to Superadmin and puts it in the admin menu', function () {
        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/activity/Index')
                ->where('limits.retention_days', 365)
                ->where('nav', fn ($nav) => collect(collect($nav)->firstWhere('group', 'oversight')['items'])->contains('key', 'activity_monitor')));
    });

    it('refuses everyone else', function (Role $role) {
        $this->actingAs(userWithRole($role))->get(route('admin.activity.index'))->assertForbidden();
    })->with([
        'employee' => [Role::Employee],
        'team lead' => [Role::TeamLead],
        'project manager' => [Role::ProjectManager],
        'project director' => [Role::ProjectDirector],
    ]);

    it('sends guests to sign in', function () {
        $this->get(route('admin.activity.index'))->assertRedirect(route('sign-in'));
    });
});

describe('perlu dicek', function () {
    it('lists a username from five failed sign-ins in the last 24 hours, with the last address', function () {
        foreach (['08:00', '08:01', '08:02', '08:03'] as $time) {
            accessAt("2026-09-14 {$time}", ['event' => 'sign_in_failed', 'username' => 'rani', 'user_id' => null, 'ip' => '10.0.0.1']);
        }
        accessAt('2026-09-14 08:04', ['event' => 'device_sign_in_failed', 'username' => 'rani', 'ip' => '10.0.0.2', 'device_id' => 'PC-ANIM-07:abcd']);
        // Four in the window and one a day earlier: not listed
        foreach (['2026-09-13 11:00', '2026-09-14 09:00', '2026-09-14 09:01', '2026-09-14 09:02', '2026-09-14 09:03'] as $at) {
            accessAt($at, ['event' => 'sign_in_failed', 'username' => 'budi']);
        }

        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertInertia(fn ($page) => $page
                ->has('attention.failed', 1)
                ->where('attention.failed.0.username', 'rani')
                ->where('attention.failed.0.attempts', 5)
                ->where('attention.failed.0.locked', false)
                ->where('attention.failed.0.last_ip', '10.0.0.2')
                ->where('attention.failed.0.last_device', 'PC-ANIM-07:abcd')
                ->where('attention.failed.0.last_at', '2026-09-14T01:04:00.000Z')
                ->where('attention.failed.0.masked_prefix', null)
                ->where('attention.failed.0.person', null));
    });

    it('groups tries of the same unknown text under its masked value, with only its start shown', function () {
        foreach (range(1, 5) as $minute) {
            $this->post(route('sign-in.store'), ['username' => 'Rahasia123', 'password' => 'x']);
        }
        // Another unknown text with the same start is its own group
        $this->post(route('sign-in.store'), ['username' => 'rahasia124', 'password' => 'x']);

        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertInertia(fn ($page) => $page
                ->has('attention.failed', 1)
                ->where('attention.failed.0.username', AccountLookup::logName('rahasia123', null))
                ->where('attention.failed.0.masked_prefix', 'ra')
                ->where('attention.failed.0.attempts', 5)
                ->where('attention.failed.0.person', null)
                ->where('timeline.data.0.masked_prefix', 'ra')
                ->where('timeline.data.0.person', null));
    });

    it('names the account and says when it was locked', function () {
        foreach (range(1, 5) as $minute) {
            accessAt("2026-09-14 10:0{$minute}", ['event' => 'sign_in_failed', 'username' => $this->person->username, 'user_id' => $this->person->id]);
        }
        accessAt('2026-09-14 10:06', ['event' => 'locked_out', 'username' => $this->person->username, 'user_id' => $this->person->id]);

        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertInertia(fn ($page) => $page
                ->where('attention.failed.0.attempts', 6)
                ->where('attention.failed.0.locked', true)
                ->where('attention.failed.0.person.id', $this->person->id));
    });

    it('lists people refused with 403 in the last 24 hours, not 429 or older refusals', function () {
        accessAt('2026-09-14 10:00', ['event' => 'forbidden', 'status' => 403, 'user_id' => $this->person->id, 'route_name' => 'admin.people.index', 'path' => '/admin/orang']);
        accessAt('2026-09-14 11:00', ['event' => 'forbidden', 'status' => 403, 'user_id' => $this->person->id, 'route_name' => 'admin.audit.index', 'path' => '/admin/log-audit']);
        $other = userWithRole(Role::Employee);
        accessAt('2026-09-14 11:00', ['event' => 'forbidden', 'status' => 429, 'user_id' => $other->id]);
        accessAt('2026-09-12 11:00', ['event' => 'forbidden', 'status' => 403, 'user_id' => $other->id]);
        accessAt('2026-09-14 11:00', ['event' => 'forbidden', 'status' => 403, 'user_id' => null]);

        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertInertia(fn ($page) => $page
                ->has('attention.forbidden', 1)
                ->where('attention.forbidden.0.person.id', $this->person->id)
                ->where('attention.forbidden.0.refusals', 2)
                ->where('attention.forbidden.0.last_route', 'admin.audit.index'));
    });

    it('is empty when nothing needs checking', function () {
        accessAt('2026-09-14 10:00', ['event' => 'sign_in_failed', 'username' => 'rani']);

        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertInertia(fn ($page) => $page->where('attention.failed', [])->where('attention.forbidden', []));
    });
});

describe('sedang online', function () {
    it('lists desktop apps that used their token in the last 5 minutes', function () {
        $desk = Desk::for($this, $this->person, 'PC-RENDER-02');
        $quiet = Desk::for($this, userWithRole(Role::Employee), 'PC-ANIM-09');
        DB::table('personal_access_tokens')->where('id', $desk->tokenId())->update(['last_used_at' => Desk::time('2026-09-14 11:57')->format('Y-m-d H:i:s')]);
        DB::table('personal_access_tokens')->where('id', $quiet->tokenId())->update(['last_used_at' => Desk::time('2026-09-14 11:50')->format('Y-m-d H:i:s')]);

        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertInertia(fn ($page) => $page
                ->has('online.desktop', 1)
                ->where('online.desktop.0.person.id', $this->person->id)
                ->where('online.desktop.0.hostname', 'PC-RENDER-02')
                ->where('online.desktop.0.last_seen_at', '2026-09-14T04:57:00.000Z'));
    });

    it('lists web sessions active in the last 5 minutes with the browser', function () {
        config(['session.driver' => 'database']);
        $row = fn (string $id, ?int $userId, string $at) => DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '10.0.0.5',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36 Edg/140.0',
            'payload' => '',
            'last_activity' => Desk::time($at)->getTimestamp(),
        ]);
        $row('a', $this->person->id, '2026-09-14 11:58');
        $row('b', $this->person->id, '2026-09-14 11:40');
        $row('c', null, '2026-09-14 11:59');

        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertInertia(fn ($page) => $page
                ->where('online.web_tracked', true)
                ->where('online.web', fn ($web) => collect($web)->pluck('person.id')->contains($this->person->id)
                    && collect($web)->firstWhere('person.id', $this->person->id)['browser'] === 'Edge'
                    && collect($web)->firstWhere('person.id', $this->person->id)['os'] === 'Windows'
                    && collect($web)->where('person.id', $this->person->id)->count() === 1));
    });

    it('says web sessions are not tracked without the database session driver', function () {
        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertInertia(fn ($page) => $page->where('online.web_tracked', false)->where('online.web', []));
    });
});

describe('chart', function () {
    it('counts each kind per studio day for the last 30 days', function () {
        accessAt('2026-09-14 06:30');
        accessAt('2026-09-14 06:59', ['event' => 'sign_in']);
        // 06.30 on the 14th in Jakarta is still the 13th in UTC: it counts on the 14th
        accessAt('2026-09-13 23:30');
        auditRowAt('2026-09-13 08:00', $this->admin->id);
        attendanceAt('2026-09-14 08:00', $this->person->id);
        accessAt('2026-08-15 09:00');

        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertInertia(fn ($page) => $page
                ->has('chart', 30)
                ->where('chart.0.date', '2026-08-16')
                ->where('chart.29', ['date' => '2026-09-14', 'access' => 2, 'change' => 0, 'attendance' => 1])
                ->where('chart.28', ['date' => '2026-09-13', 'access' => 1, 'change' => 1, 'attendance' => 0]));
    });
});

describe('linimasa', function () {
    beforeEach(function () {
        accessAt('2026-09-14 09:00', ['user_id' => $this->person->id]);
        auditRowAt('2026-09-14 10:00', $this->admin->id);
        attendanceAt('2026-09-14 08:00', $this->person->id);
        accessAt('2026-09-12 09:00', ['event' => 'sign_in_failed', 'username' => 'orang.asing', 'route_name' => 'sign-in.store']);
    });

    it('merges access, changes, and attendance, newest first', function () {
        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertInertia(fn ($page) => $page
                ->where('timeline.total', 4)
                ->where('timeline.data.0.kind', 'change')
                ->where('timeline.data.0.event', 'user.updated')
                ->where('timeline.data.0.person.id', $this->admin->id)
                ->where('timeline.data.0.audit_href', '/admin/log-audit?pelaku='.$this->admin->id.'&dari=2026-09-14&sampai=2026-09-14')
                ->where('timeline.data.1.kind', 'access')
                ->where('timeline.data.1.route', 'my-day')
                ->where('timeline.data.1.ip', '10.0.0.7')
                ->where('timeline.data.2.kind', 'attendance')
                ->where('timeline.data.2.event', 'clock_in')
                ->where('timeline.data.2.device.hostname', 'PC-ANIM-07')
                ->where('timeline.data.2.at', '2026-09-14T01:00:00.000Z')
                ->where('timeline.data.3.username', 'orang.asing')
                ->where('timeline.data.3.person', null));
    });

    it('filters by person, kind, and studio dates', function () {
        $keys = fn (array $query) => collect(monitorTimeline($this, $this->admin, $query)['data'])->pluck('kind')->all();

        expect($keys(['orang' => $this->person->id]))->toBe(['access', 'attendance'])
            ->and($keys(['orang' => $this->admin->id]))->toBe(['change'])
            ->and($keys(['jenis' => 'absensi']))->toBe(['attendance'])
            ->and($keys(['jenis' => 'akses']))->toBe(['access', 'access'])
            ->and($keys(['jenis' => 'perubahan', 'orang' => $this->person->id]))->toBe([])
            ->and($keys(['dari' => '2026-09-12', 'sampai' => '2026-09-12']))->toBe(['access'])
            ->and($keys(['dari' => '2026-09-14']))->toBe(['change', 'access', 'attendance'])
            ->and($keys(['jenis' => 'lainnya', 'orang' => 'x']))->toHaveCount(4);
    });

    it('sends the filters back with Indonesian values', function () {
        $this->actingAs($this->admin)->get(route('admin.activity.index', ['orang' => $this->person->id, 'jenis' => 'akses', 'dari' => '2026-09-14', 'sampai' => '2026-09-01']))
            ->assertInertia(fn ($page) => $page->where('filters', ['person' => $this->person->id, 'kind' => 'akses', 'from' => '2026-09-01', 'until' => '2026-09-14']));
    });

    it('pages 50 rows at a time across the three tables', function () {
        foreach (range(1, 30) as $i) {
            accessAt('2026-09-13 '.sprintf('%02d:%02d', intdiv($i, 60) + 8, $i % 60));
            attendanceAt('2026-09-13 '.sprintf('%02d:%02d', intdiv($i, 60) + 9, $i % 60), $this->person->id, 'idle_start');
        }

        $first = monitorTimeline($this, $this->admin);
        $second = monitorTimeline($this, $this->admin, ['page' => 2]);

        $all = collect($first['data'])->merge($second['data']);

        expect($first['total'])->toBe(64)
            ->and($first['data'])->toHaveCount(50)
            ->and($first['last_page'])->toBe(2)
            ->and($second['data'])->toHaveCount(14)
            ->and($all->pluck('key')->unique())->toHaveCount(64)
            ->and($all->pluck('at')->all())->toBe($all->pluck('at')->sortDesc()->values()->all());
    });

    it('reloads only the timeline on a partial visit', function () {
        $version = $this->actingAs($this->admin)->get(route('admin.activity.index'))->viewData('page')['version'];

        $response = $this->actingAs($this->admin)->get(route('admin.activity.index', ['jenis' => 'absensi']), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'admin/activity/Index',
            'X-Inertia-Partial-Data' => 'timeline,filters',
        ])->assertOk();

        expect(array_keys($response->json('props')))->not->toContain('attention')
            ->and($response->json('props.timeline.total'))->toBe(1);
    });
});

describe('linimasa paging', function () {
    it('shows rows sharing one timestamp exactly once across a page boundary', function () {
        $at = Desk::time('2026-09-13 10:00')->format('Y-m-d H:i:s.v');
        DB::table('access_logs')->insert(array_map(fn (int $i) => [
            'event' => 'page_view', 'method' => 'GET', 'route_name' => 'my-day', 'path' => '/', 'status' => 200, 'ip' => '10.0.0.7', 'created_at' => $at,
        ], range(1, 60)));
        foreach (range(1, 30) as $ignored) {
            auditRowAt('2026-09-13 10:00', $this->admin->id);
        }
        foreach (range(1, 12) as $ignored) {
            attendanceAt('2026-09-13 10:00', $this->person->id);
        }

        $query = ['dari' => '2026-09-13', 'sampai' => '2026-09-13'];
        $pages = array_map(fn (int $page) => monitorTimeline($this, $this->admin, [...$query, 'page' => $page]), [1, 2, 3]);
        $keys = collect($pages)->flatMap(fn (array $p) => $p['data'])->pluck('key');
        $accessIds = collect($pages)->flatMap(fn (array $p) => $p['data'])->where('kind', 'access')->pluck('key')->map(fn ($k) => (int) substr($k, 7))->all();

        expect($pages[0]['total'])->toBe(102)
            ->and($pages[0]['total_capped'])->toBeFalse()
            ->and(array_map(fn (array $p) => count($p['data']), $pages))->toBe([50, 50, 2])
            ->and($keys->unique()->count())->toBe(102)
            // Same time: kinds in a fixed order, newest id first inside a kind
            ->and(collect($pages[0]['data'])->pluck('kind')->unique()->values()->all())->toBe(['access'])
            ->and(collect($pages[1]['data'])->pluck('kind')->unique()->values()->all())->toBe(['access', 'attendance', 'change'])
            ->and($accessIds)->toBe(collect($accessIds)->sortDesc()->values()->all());
    });

    it('stops counting past the last reachable page and says there are more', function () {
        $at = Desk::time('2026-09-13 10:00')->format('Y-m-d H:i:s.v');
        $row = ['event' => 'page_view', 'method' => 'GET', 'route_name' => 'my-day', 'path' => '/', 'status' => 200, 'ip' => '10.0.0.7', 'created_at' => $at];
        foreach (array_chunk(range(1, ActivityTimeline::COUNT_CAP + 50), 1000) as $chunk) {
            DB::table('access_logs')->insert(array_fill(0, count($chunk), $row));
        }

        $capped = monitorTimeline($this, $this->admin, ['dari' => '2026-09-13', 'sampai' => '2026-09-13']);
        $reachable = ActivityTimeline::MAX_PAGE * ActivityTimeline::PER_PAGE;

        expect($capped['total'])->toBe($reachable)
            ->and($capped['total_capped'])->toBeTrue()
            ->and($capped['last_page'])->toBe(ActivityTimeline::MAX_PAGE);

        DB::table('access_logs')->where('id', '>', DB::table('access_logs')->min('id') + 99)->delete();

        expect(monitorTimeline($this, $this->admin, ['dari' => '2026-09-13', 'sampai' => '2026-09-13']))
            ->total->toBe(100)
            ->total_capped->toBeFalse();
    });
});
