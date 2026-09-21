<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Auth\ImposterSession;
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
});
