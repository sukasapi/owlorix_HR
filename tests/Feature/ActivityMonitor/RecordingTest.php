<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Auth\AccountLookup;
use App\Modules\Monitoring\Enums\AccessEvent;
use App\Modules\Monitoring\Models\AccessLog;
use App\Modules\Monitoring\Services\AccessRecorder;
use App\Modules\Shared\Settings\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Attendance\Support\Desk;

// Monitor aktivitas, what access_logs records (docs/14 2.2). Monday 2026-09-14, Asia/Jakarta.

beforeEach(function () {
    $this->travelTo(Desk::time('2026-09-14 09:00'));
    $this->person = userWithRole(Role::Employee);
});

/** @return list<string> */
function accessEvents(): array
{
    return AccessLog::query()->orderBy('id')->get()->map(fn (AccessLog $log) => $log->event->value)->all();
}

describe('web sign-in', function () {
    it('records a sign-in with the person, route, address, and browser', function () {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0 Safari/537.36'])
            ->post(route('sign-in.store'), ['username' => $this->person->username, 'password' => 'password-for-tests'])
            ->assertRedirect(route('my-day'));

        $log = AccessLog::query()->sole();

        expect($log->event)->toBe(AccessEvent::SignIn)
            ->and($log->user_id)->toBe($this->person->id)
            ->and($log->route_name)->toBe('sign-in.store')
            ->and($log->method)->toBe('POST')
            ->and($log->path)->toBe('/masuk')
            ->and($log->ip)->toBe('127.0.0.1')
            ->and($log->user_agent)->toContain('Chrome/140.0')
            ->and($log->username)->toBeNull()
            ->and($log->created_at->toIso8601ZuluString())->toBe('2026-09-14T02:00:00Z');
    });

    it('records a wrong password with the username and without the password', function () {
        $this->from(route('sign-in'))
            ->post(route('sign-in.store'), ['username' => $this->person->username, 'password' => 'rahasia-yang-salah'])
            ->assertSessionHasErrors('username');

        $log = AccessLog::query()->sole();

        expect($log->event)->toBe(AccessEvent::SignInFailed)
            ->and($log->user_id)->toBe($this->person->id)
            ->and($log->username)->toBe($this->person->username)
            ->and(json_encode(DB::table('access_logs')->first()))->not->toContain('rahasia-yang-salah');
    });

    it('masks text that matches no account, and records an email try under its account username', function () {
        $this->post(route('sign-in.store'), ['username' => 'Bukan.Siapa', 'password' => 'x']);
        $this->post(route('sign-in.store'), ['username' => $this->person->email, 'password' => 'x']);

        $logs = AccessLog::query()->orderBy('id')->get();

        expect($logs[0]->user_id)->toBeNull()
            ->and($logs[0]->username)->toMatch('/^bu\*\*\*#[0-9a-f]{8}$/')
            ->and($logs[0]->username)->toBe(AccountLookup::logName('bukan.siapa', null))
            ->and($logs[1]->user_id)->toBe($this->person->id)
            ->and($logs[1]->username)->toBe($this->person->username);
    });

    it('never stores a password typed into the username box, and groups repeated tries of it', function () {
        $typed = 'Kopi-Susu#2026';

        foreach (range(1, 6) as $ignored) {
            $this->post(route('sign-in.store'), ['username' => $typed, 'password' => 'x']);
        }
        $this->post(route('sign-in.store'), ['username' => 'lain.lagi', 'password' => 'x']);

        $logs = AccessLog::query()->orderBy('id')->get();
        $dump = json_encode(DB::table('access_logs')->get());

        expect($logs->pluck('event')->map->value->all())->toBe([...array_fill(0, 5, 'sign_in_failed'), 'locked_out', 'sign_in_failed'])
            ->and($logs->take(6)->pluck('username')->unique()->all())->toBe([AccountLookup::logName($typed, null)])
            ->and($logs[0]->username)->toStartWith('ko***#')
            ->and($logs[6]->username)->not->toBe($logs[0]->username)
            ->and($dump)->not->toContain('Kopi-Susu')->not->toContain('kopi-susu')->not->toContain('lain.lagi');
    });

    it('never shows more than a third of a short unknown text', function () {
        expect(AccountLookup::logName('ab', null))->toStartWith('***#')
            ->and(AccountLookup::logName('abc', null))->toStartWith('a***#')
            ->and(AccountLookup::maskedPrefix(AccountLookup::logName('ab', null)))->toBe('')
            ->and(AccountLookup::maskedPrefix(AccountLookup::logName('kopisusu', null)))->toBe('ko')
            ->and(AccountLookup::maskedPrefix('rani'))->toBeNull()
            ->and(AccountLookup::logName('RANI.X', $this->person))->toBe($this->person->username);
    });

    it('records the lockout after five failures', function () {
        foreach (range(1, 6) as $ignored) {
            $this->post(route('sign-in.store'), ['username' => $this->person->username, 'password' => 'wrong']);
        }

        expect(accessEvents())->toBe([...array_fill(0, 5, 'sign_in_failed'), 'locked_out'])
            ->and(AccessLog::query()->latest('id')->first()->username)->toBe($this->person->username);
    });

    it('records a sign-out and not a separate action for it', function () {
        $this->actingAs($this->person)->post(route('sign-out'))->assertRedirect(route('sign-in'));

        $log = AccessLog::query()->sole();

        expect($log->event)->toBe(AccessEvent::SignOut)
            ->and($log->user_id)->toBe($this->person->id);
    });

    it('does not record sign-ins while an imposter starts or stops', function () {
        config(['owlorix.imposter.enabled' => true]);
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->post(route('imposter.start', $this->person))->assertRedirect();
        $this->post(route('imposter.stop'))->assertRedirect();

        expect(accessEvents())->toBe(['action', 'action'])
            ->and(AccessLog::query()->pluck('route_name')->all())->toBe(['imposter.start', 'imposter.stop']);
    });
});

describe('desktop sign-in', function () {
    $login = fn (array $overrides = []) => array_merge([
        'password' => 'password-for-tests',
        'device_id' => 'PC-ANIM-07:3f9c',
        'hostname' => 'PC-ANIM-07',
        'app_version' => '0.1.0',
    ], $overrides);

    it('records a device sign-in, a failure, and a sign-out with the device id', function () use ($login) {
        $this->postJson('/api/v1/auth/device-login', $login(['username' => $this->person->username, 'password' => 'wrong']))->assertUnprocessable();
        $token = $this->postJson('/api/v1/auth/device-login', $login(['username' => $this->person->username]))->assertOk()->json('token');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $logs = AccessLog::query()->orderBy('id')->get();

        expect($logs->map(fn (AccessLog $log) => $log->event->value)->all())->toBe(['device_sign_in_failed', 'device_sign_in', 'device_sign_out'])
            ->and($logs->pluck('device_id')->unique()->all())->toBe(['PC-ANIM-07:3f9c'])
            ->and($logs->pluck('user_id')->unique()->all())->toBe([$this->person->id])
            ->and($logs[0]->username)->toBe($this->person->username)
            ->and($logs[1]->username)->toBeNull();
    });

    it('masks a desktop sign-in with text that matches no account', function () use ($login) {
        $this->postJson('/api/v1/auth/device-login', $login(['username' => 'rahasia-banget-99']))->assertUnprocessable();

        $log = AccessLog::query()->sole();

        expect($log->event->value)->toBe('device_sign_in_failed')
            ->and($log->user_id)->toBeNull()
            ->and($log->username)->toBe(AccountLookup::logName('rahasia-banget-99', null))
            ->and(json_encode(DB::table('access_logs')->get()))->not->toContain('rahasia-banget');
    });

    it('does not record desktop sync requests', function () {
        Desk::for($this, $this->person)->heartbeat('09:00')->assertOk();

        expect(AccessLog::query()->count())->toBe(0);
    });
});

describe('requests', function () {
    it('records a page view for a signed-in page load', function () {
        $this->actingAs($this->person)->get(route('my-day').'?cari=rahasia')->assertOk();

        $log = AccessLog::query()->sole();

        expect($log->event)->toBe(AccessEvent::PageView)
            ->and($log->route_name)->toBe('my-day')
            ->and($log->path)->toBe('/')
            ->and($log->status)->toBe(200);
    });

    it('records an Inertia visit but not a partial reload or a prefetch', function () {
        $version = $this->actingAs($this->person)->get(route('my-day'))->viewData('page')['version'];
        $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version];

        $this->get(route('history'), $inertia)->assertOk();
        $this->get(route('my-day'), [...$inertia, 'X-Inertia-Partial-Component' => 'my-day/Index', 'X-Inertia-Partial-Data' => 'summary'])->assertOk();
        $this->get(route('history'), [...$inertia, 'Purpose' => 'prefetch'])->assertOk();

        expect(AccessLog::query()->pluck('route_name')->all())->toBe(['my-day', 'history']);
    });

    it('records nothing for a guest page or a non-page GET', function () {
        $this->get(route('sign-in'))->assertOk();
        $this->actingAs($this->person)->get(route('people.photo', $this->person));

        expect(AccessLog::query()->count())->toBe(0);
    });

    it('records a change as an action with route and status, without the form body', function () {
        $this->actingAs($this->person)->patch(route('preferences.update').'?q=rahasia', ['locale' => 'en', 'theme' => 'dark'])->assertRedirect();

        $log = AccessLog::query()->sole();
        $row = (array) DB::table('access_logs')->first();

        expect($log->event)->toBe(AccessEvent::Action)
            ->and($log->route_name)->toBe('preferences.update')
            ->and($log->method)->toBe('PATCH')
            ->and($log->status)->toBe(302)
            ->and($log->path)->toBe('/preferensi')
            ->and(json_encode($row))->not->toContain('dark')
            ->and(json_encode($row))->not->toContain('rahasia');
    });

    it('does not record the web clock heartbeat', function () {
        $this->actingAs($this->person)->post(route('web-clock.heartbeat'));

        expect(AccessLog::query()->where('event', 'action')->count())->toBe(0);
    });

    it('records a refused page as forbidden, once, and a refused guest too', function () {
        $this->actingAs($this->person)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($this->person)->post(route('admin.teams.store'), ['name' => 'X'])->assertForbidden();

        $logs = AccessLog::query()->orderBy('id')->get();

        expect($logs->map(fn (AccessLog $log) => $log->event->value)->all())->toBe(['forbidden', 'forbidden'])
            ->and($logs->pluck('status')->all())->toBe([403, 403])
            ->and($logs->pluck('user_id')->unique()->all())->toBe([$this->person->id])
            ->and($logs->pluck('route_name')->all())->toBe(['admin.audit.index', 'admin.teams.store']);
    });

    it('records too many requests as forbidden and keeps the handoff token out of the path', function () {
        $token = str_repeat('a', 40);

        foreach (range(1, 31) as $ignored) {
            $this->get(route('sign-in.desktop-handoff', $token));
        }

        $log = AccessLog::query()->sole();

        expect($log->event)->toBe(AccessEvent::Forbidden)
            ->and($log->status)->toBe(429)
            ->and($log->user_id)->toBeNull()
            ->and($log->path)->toBe('/masuk/dari-desktop/{token}');
    });

    it('records a CV download', function () {
        Storage::fake('local');
        $this->actingAs($this->person)->post(route('profile.cv.store'), ['cv' => UploadedFile::fake()->create('CV.pdf', 20, 'application/pdf')]);

        $this->actingAs($this->person)->get(route('people.cv', $this->person))->assertOk();

        expect(accessEvents())->toBe(['action', 'download'])
            ->and(AccessLog::query()->latest('id')->first()->route_name)->toBe('people.cv');
    });

    it('never breaks a request when recording fails, and reports the failure', function () {
        Exceptions::fake();
        $this->app->instance(AccessRecorder::class, new class extends AccessRecorder
        {
            public function record(AccessEvent $event, Request $request, ?int $userId = null, array $extra = []): void
            {
                throw new RuntimeException('recorder down');
            }
        });

        $this->actingAs($this->person)->get(route('my-day'))->assertOk();

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'recorder down');
    });

    it('swallows a database error while writing', function () {
        Exceptions::fake();
        config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => storage_path('framework/testing/no-such.sqlite'), 'prefix' => '']]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('broken');

        try {
            (new AccessRecorder)->record(AccessEvent::SignIn, Request::create('/'), 1);
        } finally {
            DB::setDefaultConnection($default);
        }

        Exceptions::assertReportedCount(1);
        expect(AccessLog::query()->count())->toBe(0);
    });

    it('cuts a long user agent to 255 characters', function () {
        $this->actingAs($this->person)->withHeaders(['User-Agent' => str_repeat('x', 400)])->get(route('my-day'));

        expect(strlen(AccessLog::query()->sole()->user_agent))->toBe(255);
    });
});

describe('retention', function () {
    it('deletes rows older than the setting and keeps the audit log', function () {
        $at = fn (string $when) => DB::table('access_logs')->insert([
            'event' => 'page_view',
            'user_id' => $this->person->id,
            'created_at' => Desk::time($when)->format('Y-m-d H:i:s.v'),
        ]);

        $at('2026-09-14 08:00');
        $at('2026-06-01 08:00');
        $at('2025-08-01 08:00');
        DB::table('audit_logs')->insert(['action' => 'user.updated', 'created_at' => Desk::time('2025-01-01 08:00')->format('Y-m-d H:i:s.v')]);

        $this->artisan('monitoring:prune-access-logs')->assertSuccessful();
        expect(AccessLog::query()->count())->toBe(2);

        app(Settings::class)->set('monitoring.access_log_days', 30);
        $this->artisan('monitoring:prune-access-logs')->assertSuccessful();

        expect(AccessLog::query()->count())->toBe(1)
            ->and(DB::table('audit_logs')->count())->toBe(1);
    });

    it('is scheduled daily', function () {
        $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'monitoring:prune-access-logs'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('30 2 * * *')
            ->and($events->first()->timezone)->toBe('Asia/Jakarta');
    });

    it('is editable on Aturan between 30 and 730 days', function () {
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->put(route('admin.settings.update'), ['values' => ['monitoring.access_log_days' => '10']])
            ->assertSessionHasErrors(['monitoring.access_log_days' => 'range']);
        $this->actingAs($admin)->put(route('admin.settings.update'), ['values' => ['monitoring.access_log_days' => '365']])
            ->assertSessionHasNoErrors();

        expect(app(Settings::class)->int('monitoring.access_log_days'))->toBe(365);
    });
});
