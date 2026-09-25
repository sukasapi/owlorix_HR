<?php

namespace App\Modules\Shared\Navigation;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Web navigation, layout A of docs/desainUI_v2: three groups by the job people come for (own work, the team,
 * production) and a pinned group at the bottom (Pengaturan, Panduan). Pages that belong together sit under one item
 * as `children`: Persetujuan and Pantauan show them as tabs, Pengaturan lists them on its own page by `section`.
 *
 * A page shows only when its route exists and the person holds one of its permissions, so pages that are not built
 * yet never appear. An item with children shows when at least one child does and opens its own route, or else the
 * first child the person can open. Labels and icons are resolved on the client by `key`.
 */
class Navigation
{
    /**
     * @return list<array{group: string, items: list<array<string, mixed>>}>
     */
    private function definition(): array
    {
        return [
            ['group' => 'my_work', 'items' => [
                ['key' => 'my_day', 'route' => 'my-day', 'permissions' => [Permission::ClockIn]],
                ['key' => 'my_tasks', 'route' => 'projects.mine', 'permissions' => [Permission::ViewProjects]],
                ['key' => 'history', 'route' => 'history', 'permissions' => [Permission::ClockIn]],
                ['key' => 'overtime', 'route' => 'overtime.mine', 'permissions' => [Permission::ClockIn]],
                ['key' => 'leave', 'route' => 'leave.mine', 'permissions' => [Permission::RequestLeave]],
                ['key' => 'activity_log', 'route' => 'activity.index', 'permissions' => [Permission::LogActivity]],
            ]],
            ['group' => 'team', 'items' => [
                ['key' => 'team_today', 'route' => 'team.today', 'permissions' => [Permission::ViewTeamBoard]],
                ['key' => 'approvals', 'children' => [
                    ['key' => 'approvals', 'route' => 'approvals.index', 'permissions' => [Permission::ApproveOvertime, Permission::ChangeOvertimeDecisions]],
                    // Leave requests to decide; someone who manages all leave finds them under Pengaturan
                    ['key' => 'leave_approvals', 'route' => 'leave.approvals', 'permissions' => [Permission::ApproveLeave], 'unless' => Permission::ManageLeave],
                    // Management proposes corrections; someone who also applies them finds the page under Pengaturan
                    ['key' => 'corrections', 'route' => 'corrections.index', 'permissions' => [Permission::ProposeCorrections], 'unless' => Permission::ApplyCorrections],
                ]],
                ['key' => 'calendar', 'route' => 'calendar.index', 'permissions' => [Permission::OpenWorkdays, Permission::ManageCalendar]],
                ['key' => 'reports', 'route' => 'reports.index', 'permissions' => [Permission::ViewTeamReports, Permission::ViewAllReports]],
            ]],
            ['group' => 'production', 'items' => [
                ['key' => 'projects', 'route' => 'projects.index', 'permissions' => [Permission::ViewProjects, Permission::ManageProjects]],
                ['key' => 'monitoring', 'children' => [
                    ['key' => 'work_monitor', 'route' => 'monitoring.work', 'permissions' => [Permission::ViewWorkMonitor]],
                    ['key' => 'workload', 'route' => 'monitoring.workload', 'permissions' => [Permission::ViewWorkMonitor]],
                ]],
            ]],
            ['group' => 'more', 'items' => [
                ['key' => 'settings', 'route' => 'settings.index', 'children' => [
                    ['key' => 'people', 'section' => 'people', 'route' => 'admin.people.index', 'permissions' => [Permission::ManageUsers]],
                    ['key' => 'teams', 'section' => 'people', 'route' => 'admin.teams.index', 'permissions' => [Permission::ManageTeams]],
                    ['key' => 'devices', 'section' => 'people', 'route' => 'admin.devices.index', 'permissions' => [Permission::ManageDevices]],
                    // Only while IMPOSTER_MODE is on; otherwise the route answers 404 and the item would lead nowhere
                    ['key' => 'imposter', 'section' => 'people', 'route' => 'imposter.index', 'permissions' => [Permission::ImpersonateUsers], 'config' => 'owlorix.imposter.enabled'],
                    ['key' => 'corrections', 'section' => 'work', 'route' => 'corrections.index', 'permissions' => [Permission::ApplyCorrections]],
                    ['key' => 'leave_admin', 'section' => 'work', 'route' => 'admin.leave.index', 'permissions' => [Permission::ManageLeave]],
                    ['key' => 'pipeline', 'section' => 'work', 'route' => 'admin.pipeline.index', 'permissions' => [Permission::ManagePipeline]],
                    ['key' => 'rules', 'section' => 'work', 'route' => 'admin.settings.edit', 'permissions' => [Permission::ManageSettings]],
                    ['key' => 'app_settings', 'section' => 'work', 'route' => 'admin.app-settings.edit', 'permissions' => [Permission::ManageSettings]],
                    ['key' => 'audit', 'section' => 'oversight', 'route' => 'admin.audit.index', 'permissions' => [Permission::ViewAuditLog]],
                    ['key' => 'activity_monitor', 'section' => 'oversight', 'route' => 'admin.activity.index', 'permissions' => [Permission::ViewActivityMonitor]],
                ]],
                // No permissions: everyone who is signed in reads the guide
                ['key' => 'guide', 'route' => 'guide.index', 'permissions' => []],
            ]],
        ];
    }

    /**
     * @return list<array{group: string, items: list<array{key: string, href: string, route: string, children?: list<array{key: string, href: string, route: string, section?: string}>}>}>
     */
    public function forUser(User $user): array
    {
        $groups = [];

        foreach ($this->definition() as $group) {
            $items = [];

            foreach ($group['items'] as $item) {
                if (! isset($item['children'])) {
                    if ($this->allowed($item, $user)) {
                        $items[] = $this->link($item);
                    }

                    continue;
                }

                $children = array_values(array_map(
                    fn (array $child) => $this->link($child),
                    array_filter($item['children'], fn (array $child) => $this->allowed($child, $user)),
                ));

                if ($children === [] || (isset($item['route']) && ! Route::has($item['route']))) {
                    continue;
                }

                $own = isset($item['route']) ? $this->link($item) : ['key' => $item['key'], 'href' => $children[0]['href'], 'route' => $children[0]['route']];
                $items[] = [...$own, 'children' => $children];
            }

            if ($items !== []) {
                $groups[] = ['group' => $group['group'], 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * The pages listed on the Pengaturan page for this person, grouped by section in menu order.
     *
     * @return array<string, list<array{key: string, href: string, route: string, section?: string}>>
     */
    public function settingsFor(User $user): array
    {
        $settings = collect($this->forUser($user))->flatMap(fn (array $group) => $group['items'])->firstWhere('key', 'settings');

        return collect($settings['children'] ?? [])->groupBy('section')->map(fn ($children) => $children->values()->all())->all();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function allowed(array $item, User $user): bool
    {
        return Route::has($item['route'])
            && ($item['permissions'] === [] || collect($item['permissions'])->contains(fn (Permission $p) => $user->hasPermission($p)))
            && ! (isset($item['unless']) && $user->hasPermission($item['unless']))
            && (! isset($item['config']) || (bool) config($item['config']));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{key: string, href: string, route: string, section?: string}
     */
    private function link(array $item): array
    {
        return [
            'key' => $item['key'],
            'href' => route($item['route'], absolute: false),
            'route' => $item['route'],
            ...(isset($item['section']) ? ['section' => $item['section']] : []),
        ];
    }
}
