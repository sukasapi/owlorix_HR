<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Actions\IssueWebHandoff;
use App\Modules\Identity\Models\WebHandoff;
use App\Modules\Shared\Audit\AuditLog;
use Tests\Feature\Attendance\Support\Desk;

beforeEach(function () {
    $this->person = userWithRole(Role::Employee);
    $this->travelTo(Desk::time('2026-09-14 08:00'));
});

it('issues a one-time web handoff URL for a signed-in device', function () {
    $desk = Desk::for($this, $this->person);

    $response = $desk->request('POST', '/api/v1/auth/web-handoff')
        ->assertOk()
        ->assertJsonStructure(['url', 'expires_at']);

    $url = $response->json('url');
    expect($url)->toContain('/masuk/dari-desktop/')
        ->and(WebHandoff::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'auth.web_handoff_issued')->count())->toBe(1);

    $token = basename(parse_url($url, PHP_URL_PATH));
    expect(WebHandoff::query()->where('token_hash', hash('sha256', $token))->exists())->toBeTrue();
});

it('refuses a web handoff without a device token', function () {
    $this->postJson('/api/v1/auth/web-handoff')->assertUnauthorized();
});

it('signs the person into the web from a valid handoff link once', function () {
    $desk = Desk::for($this, $this->person);
    $url = $desk->request('POST', '/api/v1/auth/web-handoff')->json('url');

    app('auth')->forgetGuards();
    $this->get($url)->assertRedirect(route('my-day'));
    $this->assertAuthenticatedAs($this->person, 'web');
    expect(WebHandoff::query()->sole()->used_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'auth.web_handoff_used')->count())->toBe(1);

    app('auth')->forgetGuards();
    $this->get($url)
        ->assertRedirect(route('sign-in'))
        ->assertSessionHasErrors(['username' => __('auth.handoff_invalid')]);
});

it('rejects an expired handoff link', function () {
    $desk = Desk::for($this, $this->person);
    $url = $desk->request('POST', '/api/v1/auth/web-handoff')->json('url');

    $this->travel(IssueWebHandoff::TTL_SECONDS + 1)->seconds();

    app('auth')->forgetGuards();
    $this->get($url)
        ->assertRedirect(route('sign-in'))
        ->assertSessionHasErrors(['username' => __('auth.handoff_invalid')]);
    $this->assertGuest('web');
});

it('replaces a previous unused handoff from the same PC', function () {
    $desk = Desk::for($this, $this->person);
    $first = $desk->request('POST', '/api/v1/auth/web-handoff')->json('url');
    $second = $desk->request('POST', '/api/v1/auth/web-handoff')->json('url');

    expect($first)->not->toBe($second)
        ->and(WebHandoff::query()->count())->toBe(1);

    app('auth')->forgetGuards();
    $this->get($first)
        ->assertRedirect(route('sign-in'))
        ->assertSessionHasErrors(['username' => __('auth.handoff_invalid')]);

    $this->get($second)->assertRedirect(route('my-day'));
    $this->assertAuthenticatedAs($this->person, 'web');
});

it('switches the web session when another person was already signed in', function () {
    $colleague = userWithRole(Role::Employee);
    $this->actingAs($colleague, 'web');

    $desk = Desk::for($this, $this->person);
    $url = $desk->request('POST', '/api/v1/auth/web-handoff')->json('url');

    app('auth')->forgetGuards();
    $this->withSession([])->actingAs($colleague, 'web');
    $this->get($url)->assertRedirect(route('my-day'));
    $this->assertAuthenticatedAs($this->person, 'web');
});

it('sends a person who must change password to the password page', function () {
    $this->person->forceFill(['must_change_password' => true])->save();
    $desk = Desk::for($this, $this->person);
    $url = $desk->request('POST', '/api/v1/auth/web-handoff')->json('url');

    app('auth')->forgetGuards();
    $this->get($url)->assertRedirect(route('password.edit'));
    $this->assertAuthenticatedAs($this->person, 'web');
});

it('refuses a handoff when the account was suspended after the link was issued', function () {
    $desk = Desk::for($this, $this->person);
    $url = $desk->request('POST', '/api/v1/auth/web-handoff')->json('url');

    $this->person->forceFill(['status' => 'suspended'])->save();

    app('auth')->forgetGuards();
    $this->withoutToken()->get($url)
        ->assertRedirect(route('sign-in'))
        ->assertSessionHasErrors(['username' => __('auth.handoff_invalid')]);
    $this->assertGuest('web');
    expect(WebHandoff::query()->sole()->used_at)->not->toBeNull();
});
