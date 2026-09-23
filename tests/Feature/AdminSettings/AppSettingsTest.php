<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Shared\Audit\AuditLog;
use App\Modules\Shared\Branding\Branding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// docs/13: Pengaturan aplikasi (app name, studio, footer, logo) for Superadmin.

function brandingPayload(array $overrides = []): array
{
    return array_merge([
        'app_name' => 'Owlorix Studio HR',
        'studio_name' => 'Owlorix Creative Lab',
        'footer_text' => 'dibuat oleh',
        'footer_link_label' => 'Sukasapi',
        'footer_link_url' => 'https://sukasap.com',
        'contact_email' => 'hr@owlorix.example',
    ], $overrides);
}

test('only Superadmin opens and saves app settings', function () {
    $this->actingAs(userWithRole(Role::ProjectDirector))->get(route('admin.app-settings.edit'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Employee))->put(route('admin.app-settings.update'), brandingPayload())->assertForbidden();

    $this->actingAs(userWithRole(Role::Superadmin))->get(route('admin.app-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/app-settings/Edit')->where('values.app_name', 'Owlorix HR'));
});

test('saved values reach every page and each change is audited', function () {
    $admin = userWithRole(Role::Superadmin);

    $this->actingAs($admin)->put(route('admin.app-settings.update'), brandingPayload())->assertSessionHasNoErrors();

    expect(app(Branding::class)->get('app_name'))->toBe('Owlorix Studio HR')
        ->and(AuditLog::query()->where('action', 'branding.updated')->count())->toBe(3);

    $this->actingAs(userWithRole(Role::Employee))->get(route('projects.index'))
        ->assertInertia(fn ($page) => $page->where('app.brand.name', 'Owlorix Studio HR')->where('app.brand.contact_email', 'hr@owlorix.example'));
});

test('the footer link needs both text and address', function () {
    $this->actingAs(userWithRole(Role::Superadmin))
        ->put(route('admin.app-settings.update'), brandingPayload(['footer_link_url' => '', 'app_name' => '']))
        ->assertSessionHasErrors(['footer_link_url', 'app_name']);

    $this->actingAs(userWithRole(Role::Superadmin))
        ->put(route('admin.app-settings.update'), brandingPayload(['footer_link_url' => 'javascript:alert(1)']))
        ->assertSessionHasErrors('footer_link_url');
});

test('an uploaded logo is served publicly and can be reset to the bundled one', function () {
    Storage::fake('local');
    $admin = userWithRole(Role::Superadmin);

    $this->actingAs($admin)
        ->post(route('admin.app-settings.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 256, 256)])
        ->assertSessionHasNoErrors();

    $path = app(Branding::class)->logoPath();
    Storage::disk('local')->assertExists($path);

    auth()->logout();
    $this->get(route('brand.logo'))->assertOk();

    $this->actingAs($admin)->delete(route('admin.app-settings.logo.destroy'))->assertSessionHasNoErrors()->assertRedirect();

    Storage::disk('local')->assertMissing($path);
    $this->get(route('brand.logo'))->assertRedirect(Branding::DEFAULT_LOGO);
});
