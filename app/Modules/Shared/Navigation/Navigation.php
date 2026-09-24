<?php

namespace App\Modules\Shared\Navigation;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Web navigation grouped by the job people come for (docs/DESIGN.md, App shell): own work, the team, production,
 * people and access, settings, oversight, help. An item shows only when its route
 * exists and the person holds one of its permissions, so pages that are not built yet never appear.
 * Labels and icons are resolved on the client by `key`.
 */
class Navigation
{
    /**
     * @return list<array{group: string, items: list<array{key: string, route: string, permissions: list<Permission>, unless?: Permission, config?: string}>}>
     */
    private function definition(): array
    {
        return [
            ['group' => 'my_work', 'items' => [
                ['key' => 'my_day', 'route' => 'my-day', 'permissions' => [Permission::ClockIn]],
                ['key' => 'history', 'route' => 'history', 'permissions' => [Permission::ClockIn]],
                ['key' => 'overtime', 'route' => 'overtime.mine', 'permissions' => [Permission::ClockIn]],
                ['key' => 'leave', 'route' => 'leave.mine', 'permissions' => [Permission::RequestLeave]],
                ['key' => 'my_tasks', 'route' => 'projects.mine', 'permissions' => [Permission::ViewProjects]],
                ['key' => 'activity_log', 'route' => 'activity.index', 'permissions' => [Permission::LogActivity]],
            ]],
            ['group' => 'team', 'items' => [
                ['key' => 'team_today', 'route' => 'team.today', 'permissions' => [Permission::ViewTeamBoard]],
                ['key' => 'approvals', 'route' => 'approvals.index', 'permissions' => [Permission::ApproveOvertime, Permission::ChangeOvertimeDecisions]],
                // Leave requests to decide; someone who manages all leave finds them under Pengaturan
                ['key' => 'leave_approvals', 'route' => 'leave.approvals', 'permissions' => [Permission::ApproveLeave], 'unless' => Permission::ManageLeave],
                ['key' => 'calendar', 'route' => 'calendar.index', 'permissions' => [Permission::OpenWorkdays, Permission::ManageCalendar]],
                // Management proposes corrections; someone who also applies them finds the page under Pengaturan
                ['key' => 'corrections', 'route' => 'corrections.index', 'permissions' => [Permission::ProposeCorrections], 'unless' => Permission::ApplyCorrections],
                ['key' => 'reports', 'route' => 'reports.index', 'permissions' => [Permission::ViewTeamReports, Permission::ViewAllReports]],
            ]],
            ['group' => 'production', 'items' => [
                ['key' => 'projects', 'route' => 'projects.index', 'permissions' => [Permission::ViewProjects, Permission::ManageProjects]],
                ['key' => 'work_monitor', 'route' => 'monitoring.work', 'permissions' => [Permission::ViewWorkMonitor]],
                ['key' => 'workload', 'route' => 'monitoring.workload', 'permissions' => [Permission::ViewWorkMonitor]],
            ]],
            ['group' => 'people', 'items' => [
                ['key' => 'people', 'route' => 'admin.people.index', 'permissions' => [Permission::ManageUsers]],
                ['key' => 'teams', 'route' => 'admin.teams.index', 'permissions' => [Permission::ManageTeams]],
                ['key' => 'devices', 'route' => 'admin.devices.index', 'permissions' => [Permission::ManageDevices]],
                // Only while IMPOSTER_MODE is on; otherwise the route answers 404 and the item would lead nowhere
                ['key' => 'imposter', 'route' => 'imposter.index', 'permissions' => [Permission::ImpersonateUsers], 'config' => 'owlorix.imposter.enabled'],
            ]],
            ['group' => 'admin', 'items' => [
                ['key' => 'corrections', 'route' => 'corrections.index', 'permissions' => [Permission::ApplyCorrections]],
                ['key' => 'leave_admin', 'route' => 'admin.leave.index', 'permissions' => [Permission::ManageLeave]],
                ['key' => 'pipeline', 'route' => 'admin.pipeline.index', 'permissions' => [Permission::ManagePipeline]],
                ['key' => 'rules', 'route' => 'admin.settings.edit', 'permissions' => [Permission::ManageSettings]],
                ['key' => 'app_settings', 'route' => 'admin.app-settings.edit', 'permissions' => [Permission::ManageSettings]],
            ]],
            ['group' => 'oversight', 'items' => [
                ['key' => 'audit', 'route' => 'admin.audit.index', 'permissions' => [Permission::ViewAuditLog]],
                ['key' => 'activity_monitor', 'route' => 'admin.activity.index', 'permissions' => [Permission::ViewActivityMonitor]],
            ]],
            // No permissions: everyone who is signed in reads the guide
            ['group' => 'help', 'items' => [
                ['key' => 'guide', 'route' => 'guide.index', 'permissions' => []],
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

                $allowed = ($item['permissions'] === [] || collect($item['permissions'])->contains(fn (Permission $p) => $user->hasPermission($p)))
                    && ! (isset($item['unless']) && $user->hasPermission($item['unless']))
                    && (! isset($item['config']) || (bool) config($item['config']));

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
