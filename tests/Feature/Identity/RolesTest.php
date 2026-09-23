<?php

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Access\Role;

it('gives every role only its own permissions', function (Role $role, array $has, array $lacks) {
    $user = userWithRole($role);

    foreach ($has as $permission) {
        expect($user->hasPermission($permission))->toBeTrue("{$role->value} should have {$permission->value}");
    }
    foreach ($lacks as $permission) {
        expect($user->hasPermission($permission))->toBeFalse("{$role->value} should not have {$permission->value}");
    }
})->with([
    'employee' => [Role::Employee, [Permission::ClockIn], [Permission::ApproveOvertime, Permission::ViewTeamBoard, Permission::ManageUsers, Permission::OpenWorkdays]],
    'team lead' => [Role::TeamLead, [Permission::ApproveOvertime, Permission::ViewTeamBoard, Permission::OpenWorkdays], [Permission::ApproveAnyOvertime, Permission::ManageUsers, Permission::ManageCalendar]],
    'project manager' => [Role::ProjectManager, [Permission::ApproveOvertime, Permission::ApproveAnyOvertime], [Permission::ChangeOvertimeDecisions, Permission::ManageUsers]],
    'project director' => [Role::ProjectDirector, [Permission::ApproveAnyOvertime, Permission::ChangeOvertimeDecisions], [Permission::ManageUsers, Permission::ApplyCorrections]],
    'superadmin' => [Role::Superadmin, [Permission::ManageUsers, Permission::ManageCalendar, Permission::ApplyCorrections, Permission::ExportReports], [Permission::ApproveOvertime, Permission::ViewTeamBoard]],
]);

it('combines permissions when a person holds several roles', function () {
    $user = userWithRole(Role::Superadmin, Role::ProjectDirector);

    expect($user->hasPermission(Permission::ManageUsers))->toBeTrue()
        ->and($user->hasPermission(Permission::ApproveAnyOvertime))->toBeTrue();
});

it('shows only navigation items whose page exists and the person may open', function () {
    $employee = userWithRole(Role::Employee);

    $this->actingAs($employee)->get(route('my-day'))
        ->assertInertia(fn ($page) => $page
            ->component('my-day/Index')
            ->where('nav.0.group', 'my_work')
            ->where('nav.0.items.0.key', 'my_day')
            // Team shows only the project list an employee may read; no management or admin pages
            ->where('nav.1.group', 'team')
            ->where('nav.1.items', fn ($items) => collect($items)->pluck('key')->all() === ['projects'])
            ->where('nav.2.group', 'help')
            ->where('nav.2.items.0.key', 'guide')
            ->missing('nav.3'));
});
