<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// docs/13: a person edits their own profile, photo, and CV.

beforeEach(function () {
    Storage::fake('local');
    $this->person = userWithRole(Role::Employee);
});

test('the profile page shows the person their own data', function () {
    $this->actingAs($this->person)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('profile/Edit')
            ->where('profile.username', $this->person->username)
            ->where('profile.cv', null));
});

test('a person saves their profile and the nickname becomes the display name', function () {
    $this->actingAs($this->person)->put(route('profile.update'), [
        'name' => 'Rani Kusuma',
        'nickname' => 'Rani',
        'job_title' => '3D Animator',
        'phone' => '+62 812-3456-7890',
        'birth_date' => '1998-04-02',
        'gender' => 'female',
        'portfolio_url' => 'https://artstation.example/rani',
    ])->assertSessionHasNoErrors();

    expect($this->person->refresh())
        ->nickname->toBe('Rani')
        ->displayName()->toBe('Rani')
        ->and($this->person->birth_date->format('Y-m-d'))->toBe('1998-04-02')
        ->and(AuditLog::query()->where('action', 'profile.updated')->exists())->toBeTrue();

    $this->actingAs($this->person)->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('auth.user.display_name', 'Rani'));
});

test('profile fields are validated', function () {
    $this->actingAs($this->person)->put(route('profile.update'), [
        'name' => '',
        'phone' => 'call me',
        'birth_date' => '2999-01-01',
        'portfolio_url' => 'javascript:alert(1)',
        'gender' => 'other',
    ])->assertSessionHasErrors(['name', 'phone', 'birth_date', 'portfolio_url', 'gender']);
});

test('a person cannot change fields Superadmin manages through the profile', function () {
    $this->actingAs($this->person)->put(route('profile.update'), [
        'name' => $this->person->name,
        'username' => 'someone-else',
        'email' => 'new@example.com',
        'employment_type' => 'freelance',
    ])->assertSessionHasNoErrors();

    expect($this->person->refresh())
        ->username->not->toBe('someone-else')
        ->employment_type->value->toBe('permanent');
});

test('a photo is stored privately and served to colleagues', function () {
    $this->actingAs($this->person)
        ->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('me.png', 200, 200)])
        ->assertSessionHasNoErrors();

    $path = $this->person->refresh()->avatar_path;
    Storage::disk('local')->assertExists($path);

    $colleague = userWithRole(Role::Employee);
    $this->actingAs($colleague)->get(route('people.photo', $this->person))->assertOk();

    $this->actingAs($this->person)->delete(route('profile.photo.destroy'));
    Storage::disk('local')->assertMissing($path);
});

test('a photo must be an image', function () {
    $this->actingAs($this->person)
        ->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->create('me.svg', 5, 'image/svg+xml')])
        ->assertSessionHasErrors('photo');
});

test('a CV is a PDF that only its owner and Superadmin can download', function () {
    $this->actingAs($this->person)
        ->post(route('profile.cv.store'), ['cv' => UploadedFile::fake()->create('CV Rani.pdf', 200, 'application/pdf')])
        ->assertSessionHasNoErrors();

    expect($this->person->refresh()->cv_original_name)->toBe('CV Rani.pdf');

    $this->actingAs($this->person)->get(route('people.cv', $this->person))->assertOk()->assertDownload('CV Rani.pdf');
    $this->actingAs(userWithRole(Role::Superadmin))->get(route('people.cv', $this->person))->assertOk();
    $this->actingAs(userWithRole(Role::TeamLead))->get(route('people.cv', $this->person))->assertForbidden();

    $this->actingAs($this->person)
        ->post(route('profile.cv.store'), ['cv' => UploadedFile::fake()->create('cv.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])
        ->assertSessionHasErrors('cv');
});
