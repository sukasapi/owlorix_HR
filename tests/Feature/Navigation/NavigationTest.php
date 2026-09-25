<?php

use App\Modules\Identity\Access\Role;

describe('menu layout A (docs/desainUI_v2)', function () {
    it('gives Management three groups, Persetujuan and Pantauan as tabs, and Panduan pinned', function () {
        $this->actingAs(userWithRole(Role::TeamLead))->get(route('my-day'))
            ->assertInertia(fn ($page) => $page
                ->where('nav', fn ($nav) => collect($nav)->pluck('group')->all() === ['my_work', 'team', 'production', 'more'])
                ->where('nav', fn ($nav) => navChildren($nav, 'approvals')->all() === ['approvals', 'leave_approvals', 'corrections'])
                ->where('nav', fn ($nav) => navChildren($nav, 'monitoring')->all() === ['work_monitor', 'workload'])
                // A menu item with tabs opens its first tab
                ->where('nav.1.items', fn ($items) => collect($items)->firstWhere('key', 'approvals')['href'] === route('approvals.index', absolute: false))
                ->where('nav.3.items', fn ($items) => collect($items)->pluck('key')->all() === ['guide']));
    });

    it('keeps the menu at twelve pages or fewer for Superadmin, with admin pages on Pengaturan', function () {
        config(['owlorix.imposter.enabled' => true]);

        $this->actingAs(userWithRole(Role::Superadmin))->get(route('my-day'))
            ->assertInertia(fn ($page) => $page
                ->where('nav', fn ($nav) => collect($nav)->reject(fn ($group) => $group['group'] === 'more')->flatMap(fn ($group) => $group['items'])->count() <= 12)
                ->where('nav', fn ($nav) => navChildren($nav, 'settings')->all() === [
                    'people', 'teams', 'devices', 'imposter', 'corrections', 'leave_admin', 'pipeline', 'rules', 'app_settings', 'audit', 'activity_monitor',
                ])
                ->where('nav.3.items.0.href', route('settings.index', absolute: false)));
    });
});

describe('Pengaturan page', function () {
    it('lists the admin pages a person can open, by section', function () {
        $this->actingAs(userWithRole(Role::Superadmin))->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Index')
                ->where('sections.people.0.key', 'people')
                ->where('sections.people.0.href', route('admin.people.index', absolute: false))
                ->where('sections.work', fn ($items) => collect($items)->pluck('key')->contains('rules'))
                ->where('sections.oversight', fn ($items) => collect($items)->pluck('key')->all() === ['audit', 'activity_monitor']));
    });

    it('shows a Project Director only the pipeline', function () {
        $this->actingAs(userWithRole(Role::ProjectDirector))->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections', fn ($sections) => collect($sections)->flatten(1)->pluck('key')->all() === ['pipeline']));
    });

    it('refuses someone without any admin page, and the menu leaves it out', function () {
        $employee = userWithRole(Role::Employee);

        $this->actingAs($employee)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('my-day'))
            ->assertInertia(fn ($page) => $page->where('nav', fn ($nav) => ! navKeys($nav)->contains('settings')));
    });

    it('sends a guest to sign in', function () {
        $this->get(route('settings.index'))->assertRedirect(route('sign-in'));
    });
});
