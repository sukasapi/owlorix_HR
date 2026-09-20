<?php

namespace App\Modules\Shared\Navigation;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Web navigation grouped by module (docs/DESIGN.md, App shell). An item shows only when its route
 * exists and the person holds one of its permissions, so pages that are not built yet never appear.
 * Labels and icons are resolved on the client by `key`.
 */
class Navigation
{
    /**
     * @return list<array{group: string, items: list<array{key: string, route: string, permissions: list<Permission>, unless?: Permission}>}>
     */
    private function definition(): array
    {
        return [
            ['group' => 'my_work', 'items' => [
                ['key' => 'my_day', 'route' => 'my-day', 'permissions' => [Permission::ClockIn]],
                ['key' => 'history', 'route' => 'history', 'permissions' => [Permission::ClockIn]],
                ['key' => 'overtime', 'route' => 'overtime.mine', 'permissions' => [Permission::ClockIn]],
            ]],
            ['group' => 'team', 'items' => [
                ['key' => 'team_today', 'route' => 'team.today', 'permissions' => [Permission::ViewTeamBoard]],
                ['key' => 'approvals', 'route' => 'approvals.index', 'permissions' => [Permission::ApproveOvertime, Permission::ChangeOvertimeDecisions]],
                ['key' => 'reports', 'route' => 'reports.index', 'permissions' => [Permission::ViewTeamReports, Permission::ViewAllReports]],
                ['key' => 'calendar', 'route' => 'calendar.index', 'permissions' => [Permission::OpenWorkdays, Permission::ManageCalendar]],
                // Management proposes corrections; someone who also applies them finds the page under Admin
                ['key' => 'corrections', 'route' => 'corrections.index', 'permissions' => [Permission::ProposeCorrections], 'unless' => Permission::ApplyCorrections],
            ]],
            ['group' => 'admin', 'items' => [
                ['key' => 'people', 'route' => 'admin.people.index', 'permissions' => [Permission::ManageUsers]],
                ['key' => 'teams', 'route' => 'admin.teams.index', 'permissions' => [Permission::ManageTeams]],
                ['key' => 'corrections', 'route' => 'corrections.index', 'permissions' => [Permission::ApplyCorrections]],
                ['key' => 'devices', 'route' => 'admin.devices.index', 'permissions' => [Permission::ManageDevices]],
                ['key' => 'rules', 'route' => 'admin.settings.edit', 'permissions' => [Permission::ManageSettings]],
                ['key' => 'audit', 'route' => 'admin.audit.index', 'permissions' => [Permission::ViewAuditLog]],
            ]],
        ];
    }

    /**
     * @return list<array{group: string, items: list<array{key: string, href: string, route: string}>}>
     */
    public function forUser(User $user): array
    {
        $groups = [];

        foreach ($this->definition() as $group) {
            $items = [];

            foreach ($group['items'] as $item) {
                if (! Route::has($item['route'])) {
                    continue;
                }

                $allowed = collect($item['permissions'])->contains(fn (Permission $p) => $user->hasPermission($p))
                    && ! (isset($item['unless']) && $user->hasPermission($item['unless']));

                if ($allowed) {
                    $items[] = ['key' => $item['key'], 'href' => route($item['route'], absolute: false), 'route' => $item['route']];
                }
            }

            if ($items !== []) {
                $groups[] = ['group' => $group['group'], 'items' => $items];
            }
        }

        return $groups;
    }
}
