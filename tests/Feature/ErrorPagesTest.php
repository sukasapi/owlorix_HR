<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Identity\Access\Role;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('test-server-bug', fn () => throw new RuntimeException('boom'));
    Route::middleware('web')->post('test-expired', fn () => abort(419));
});

/** @return array<string, string> */
function inertiaHeaders(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With' => 'XMLHttpRequest',
    ];
}

it('shows a plain-language page for an address that does not exist', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertSee(__('errors.404.title'))
        ->assertSee(__('errors.home'))
        ->assertSee(__('errors.code', ['status' => 404]))
        ->assertDontSee(__('errors.desktop'));
});

it('offers the previous page only when it is on this site', function () {
    $this->get('/no-such-page', ['Referer' => url('/history')])->assertSee('href="'.url('/history').'"', escape: false);
    $this->get('/no-such-page', ['Referer' => 'https://localhost.evil.test/'])->assertDontSee(__('errors.back'));
});

it('explains a refused page without technical words', function () {
    $this->actingAs(userWithRole(Role::Employee))->get(route('team.today'))
        ->assertForbidden()
        ->assertSee(__('errors.403.title'));
});

it('asks to reload the form page after a session expired', function () {
    $this->post('/test-expired', [], ['Referer' => url('/leave')])
        ->assertStatus(419)
        ->assertSee(__('errors.419.title'))
        ->assertSee('href="'.url('/leave').'"', escape: false);
});

it('hides the stack trace behind a plain page in production, and mentions the desktop outbox', function () {
    config(['app.debug' => false]);

    $this->get('/test-server-bug')
        ->assertStatus(500)
        ->assertSee(__('errors.500.title'))
        ->assertSee(__('errors.desktop'))
        ->assertDontSee('boom');
});

it('replaces the page with errors/Show on a visit inside the app instead of an HTML modal', function () {
    $this->actingAs(userWithRole(Role::Employee))->get(route('team.today'), inertiaHeaders())
        ->assertForbidden()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'errors/Show')
        ->assertJsonPath('props.page.title', __('errors.403.title'))
        ->assertJsonMissingPath('props.auth');
});

it('keeps the stack trace for a server error in debug mode, also on a visit inside the app', function () {
    config(['app.debug' => true]);

    $this->get('/test-server-bug', inertiaHeaders())
        ->assertStatus(500)
        ->assertHeaderMissing('X-Inertia');
});
