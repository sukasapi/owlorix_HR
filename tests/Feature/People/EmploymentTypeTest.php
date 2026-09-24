<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\EmploymentType;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Audit\AuditLog;

describe('employment type', function () {
    it('requires employment_type when creating a person', function () {
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->post(route('admin.people.store'), personPayload(['employment_type' => null]))
            ->assertSessionHasErrors('employment_type');

        $this->actingAs($admin)->post(route('admin.people.store'), personPayload(['employment_type' => 'contractor']))
            ->assertSessionHasErrors('employment_type');
    });

    it('stores employment_type and audits a later change', function () {
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->post(route('admin.people.store'), personPayload([
            'employment_type' => EmploymentType::Intern->value,
        ]))->assertSessionHasNoErrors();

        $person = User::query()->where('username', 'nama.uji')->firstOrFail();
        expect($person->employment_type)->toBe(EmploymentType::Intern);

        $this->actingAs($admin)->put(route('admin.people.update', $person), updatePayload($person, [
            'employment_type' => EmploymentType::Freelance->value,
        ]))->assertSessionHasNoErrors();

        $person->refresh();
        expect($person->employment_type)->toBe(EmploymentType::Freelance);

        $log = AuditLog::query()->where('action', 'user.updated')->where('subject_id', $person->id)->latest('id')->first();
        expect($log->before['employment_type'] ?? null)->toBe('intern')
            ->and($log->after['employment_type'] ?? null)->toBe('freelance');
    });

    it('keeps an intern target per person, in hours on the form and minutes in the database', function () {
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->post(route('admin.people.store'), personPayload([
            'employment_type' => EmploymentType::Intern->value,
            'intern_days_per_week' => 1,
            'intern_hours_per_day' => '4.5',
        ]))->assertSessionHasNoErrors();

        $person = User::query()->where('username', 'nama.uji')->firstOrFail();
        expect($person->intern_days_per_week)->toBe(1)
            ->and($person->intern_minutes_per_day)->toBe(270);

        // Empty fields fall back to the default on Aturan
        $this->actingAs($admin)->put(route('admin.people.update', $person), updatePayload($person, [
            'employment_type' => EmploymentType::Intern->value,
            'intern_days_per_week' => null,
            'intern_hours_per_day' => '6',
        ]))->assertSessionHasNoErrors();

        expect($person->refresh()->intern_days_per_week)->toBeNull()
            ->and($person->intern_minutes_per_day)->toBe(360);

        $log = AuditLog::query()->where('action', 'user.updated')->where('subject_id', $person->id)->latest('id')->first();
        expect($log->before['intern_minutes_per_day'] ?? null)->toBe(270)
            ->and($log->after['intern_minutes_per_day'] ?? null)->toBe(360);
    });

    it('drops the intern target when the person is no longer an intern', function () {
        $admin = userWithRole(Role::Superadmin);
        $person = userWithRole(Role::Employee);
        $person->forceFill(['employment_type' => EmploymentType::Intern, 'intern_days_per_week' => 2, 'intern_minutes_per_day' => 300])->save();

        $this->actingAs($admin)->put(route('admin.people.update', $person), updatePayload($person, [
            'employment_type' => EmploymentType::Contract->value,
            'intern_days_per_week' => 2,
            'intern_hours_per_day' => '5',
        ]))->assertSessionHasNoErrors();

        expect($person->refresh())
            ->intern_days_per_week->toBeNull()
            ->intern_minutes_per_day->toBeNull();
    });

    it('refuses an intern target out of range', function () {
        $admin = userWithRole(Role::Superadmin);

        $this->actingAs($admin)->post(route('admin.people.store'), personPayload([
            'employment_type' => EmploymentType::Intern->value,
            'intern_days_per_week' => 8,
            'intern_hours_per_day' => '13',
        ]))->assertSessionHasErrors(['intern_days_per_week', 'intern_hours_per_day']);
    });

    it('sends the Aturan defaults to the People page for the intern fields', function () {
        $this->actingAs(userWithRole(Role::Superadmin))->get(route('admin.people.index'))
            ->assertInertia(fn ($page) => $page->where('intern_defaults', ['days_per_week' => 2, 'minutes_per_day' => 480]));
    });
});
