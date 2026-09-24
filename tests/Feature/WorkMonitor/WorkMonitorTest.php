<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Projects\Enums\MilestoneKind;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\PipelineStage;
use App\Modules\Projects\Models\ProjectMilestone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\WorkMonitor\Support\WorkFixtures as W;

// docs/14 5.1 and 1.1: Monitor kerja, its scope, and its numbers. "Now" is Wednesday 23 September 2026, 10.00 in
// the studio, so this week starts on Monday 21 September.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Jakarta'));
});

function monitorProps(\App\Modules\Identity\Models\User $viewer, array $query = []): array
{
    return test()->actingAs($viewer)->get(route('monitoring.work', $query))->assertOk()->original->getData()['page']['props'];
}

it('lets Team Leads, PMs, PDs and Superadmins in and keeps employees out', function () {
    $this->actingAs(userWithRole(Role::Employee))->get(route('monitoring.work'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Employee))->get(route('monitoring.workload'))->assertForbidden();

    foreach ([Role::TeamLead, Role::ProjectManager, Role::ProjectDirector, Role::Superadmin] as $role) {
        $user = userWithRole($role);
        $this->actingAs($user)->get(route('monitoring.work'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('monitoring/Work'));
        $this->actingAs($user)->get(route('monitoring.workload'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('monitoring/Workload'));
    }

    auth()->logout();
    $this->get(route('monitoring.work'))->assertRedirect();
});

it('shows a Team Lead their team members and the sub projects they lead, nothing else', function () {
    $lead = userWithRole(Role::TeamLead);
    $mine = userWithRole(Role::Employee);
    $other = userWithRole(Role::Employee);
    W::team('Animasi', $lead, $mine);
    W::team('Lighting', userWithRole(Role::TeamLead), $other);

    $film = W::project('Film Pendek');
    $iklan = W::project('Iklan Kopi');
    $ledSub = W::sub($film, 'Episode 1', $lead);
    $otherSub = W::sub($iklan, 'Shot 30 detik');

    W::task($otherSub, $mine, extra: ['title' => 'Tugas anggota tim']);
    W::task($ledSub, $other, extra: ['title' => 'Tugas di sub proyek yang dipimpin']);
    W::task($otherSub, $other, extra: ['title' => 'Tugas tim lain']);

    $props = monitorProps($lead);

    expect($props['scope'])->toBe(['studio' => false, 'empty_reason' => null])
        ->and($props['headline']['open'])->toBe(2)
        ->and(collect($props['options']['projects'])->pluck('name')->sort()->values()->all())->toBe(['Film Pendek', 'Iklan Kopi'])
        ->and(collect($props['status']['rows'])->mapWithKeys(fn ($r) => [$r['name'] => $r['todo']])->all())->toBe(['Film Pendek' => 1, 'Iklan Kopi' => 1]);

    $workload = $this->actingAs($lead)->get(route('monitoring.workload'))->original->getData()['page']['props'];
    expect(collect($workload['people'])->pluck('person.id')->all())->toBe([$mine->id]);
});

it('shows a Team Lead a shared task when any one of its assignees is in their team', function () {
    $lead = userWithRole(Role::TeamLead);
    $mine = userWithRole(Role::Employee);
    $other = userWithRole(Role::Employee);
    W::team('Animasi', $lead, $mine);
    $sub = W::sub(W::project('Iklan Kopi'), 'Shot 30 detik');

    $shared = W::task($sub, [$other, $mine], TaskStatus::InProgress, ['title' => 'Tugas bersama', 'due_date' => '2026-09-22']);
    W::task($sub, [$other], extra: ['title' => 'Tugas tim lain', 'due_date' => '2026-09-22']);

    $props = monitorProps($lead);

    expect($props['headline']['open'])->toBe(1)
        ->and(collect($props['due']['rows'])->pluck('id')->all())->toBe([$shared->id])
        ->and(collect($props['due']['rows'][0]['assignees'])->pluck('id')->all())->toBe([$other->id, $mine->id]);
});

it('tells a Team Lead without a team or a led sub project why the page is empty', function () {
    $lead = userWithRole(Role::TeamLead);
    W::task(W::sub(W::project('Film Pendek'), 'Episode 1'), userWithRole(Role::Employee));

    $props = monitorProps($lead);
    expect($props['scope']['empty_reason'])->toBe('no_team_or_sub_project')
        ->and($props['headline'])->toBe(['open' => 0, 'overdue' => 0, 'in_review' => 0, 'done_in_period' => 0])
        ->and($props['options']['projects'])->toBe([]);

    $workload = $this->actingAs($lead)->get(route('monitoring.workload'))->original->getData()['page']['props'];
    expect($workload['scope']['empty_reason'])->toBe('no_team')->and($workload['people'])->toBe([]);
});

it('tells a lead of sub projects only that Beban kerja lists team members', function () {
    $lead = userWithRole(Role::TeamLead);
    $worker = userWithRole(Role::Employee);
    W::task(W::sub(W::project('Film Pendek'), 'Episode 1', $lead), $worker, extra: ['due_date' => '2026-09-24', 'estimate_minutes' => 60]);

    expect(monitorProps($lead)['scope']['empty_reason'])->toBeNull();

    $workload = $this->actingAs($lead)->get(route('monitoring.workload'))->original->getData()['page']['props'];
    expect($workload['scope'])->toBe(['studio' => false, 'empty_reason' => 'no_team', 'leads_team' => false])
        ->and($workload['people'])->toBe([]);

    // Leading a team, even one without members yet, is what Beban kerja needs
    W::team('Animasi', $lead);
    $workload = $this->actingAs($lead)->get(route('monitoring.workload'))->original->getData()['page']['props'];
    expect($workload['scope']['empty_reason'])->toBeNull();
});

it('shows the whole studio to people who oversee projects', function () {
    $sub = W::sub(W::project('Film Pendek'), 'Episode 1');
    W::task($sub, userWithRole(Role::Employee));
    W::task(W::sub(W::project('Iklan Kopi'), 'Shot'), userWithRole(Role::Employee), TaskStatus::InReview);
    W::task($sub, null, TaskStatus::InProgress);

    foreach ([Role::ProjectManager, Role::ProjectDirector, Role::Superadmin] as $role) {
        $props = monitorProps(userWithRole($role));
        expect($props['scope'])->toBe(['studio' => true, 'empty_reason' => null])
            ->and($props['headline']['open'])->toBe(3)
            ->and($props['headline']['in_review'])->toBe(1);
    }
});

it('counts tasks created and finished per Monday-based studio week', function () {
    $sub = W::sub(W::project('Film Pendek'), 'Episode 1');
    $person = userWithRole(Role::Employee);

    // Sunday 20 Sept 23.30 in Jakarta belongs to the week of 14 Sept; 00.30 on Monday 21 Sept to this week
    W::task($sub, $person, extra: ['created_at' => CarbonImmutable::parse('2026-09-20 16:30:00', 'UTC')]);
    W::task($sub, $person, extra: ['created_at' => CarbonImmutable::parse('2026-09-20 17:30:00', 'UTC')]);
    W::task($sub, $person, TaskStatus::Done, ['created_at' => CarbonImmutable::parse('2026-09-01 03:00:00', 'UTC'), 'completed_at' => CarbonImmutable::parse('2026-09-20 17:00:00', 'UTC')]);
    // Before the 4-week period (it starts on Monday 31 August)
    W::task($sub, $person, extra: ['created_at' => CarbonImmutable::parse('2026-08-30 03:00:00', 'UTC')]);
    // Proposals are not work
    W::task($sub, $person, TaskStatus::Proposed, ['created_at' => CarbonImmutable::parse('2026-09-22 03:00:00', 'UTC')]);

    $props = monitorProps(userWithRole(Role::ProjectManager));

    expect($props['period'])->toBe(['from' => '2026-08-31', 'until' => '2026-09-23', 'weeks' => 4])
        ->and($props['throughput'])->toBe([
            ['week' => '2026-08-31', 'created' => 1, 'done' => 0],
            ['week' => '2026-09-07', 'created' => 0, 'done' => 0],
            ['week' => '2026-09-14', 'created' => 1, 'done' => 0],
            ['week' => '2026-09-21', 'created' => 1, 'done' => 1],
        ])
        ->and($props['headline']['done_in_period'])->toBe(1);

    expect(monitorProps(userWithRole(Role::ProjectManager), ['minggu' => 8])['throughput'])->toHaveCount(8)
        ->and(monitorProps(userWithRole(Role::ProjectManager), ['minggu' => 5])['filters']['weeks'])->toBe(4);
});

it('groups task status per project without proposals or rejected proposals', function () {
    $person = userWithRole(Role::Employee);
    $film = W::project('Film Pendek');
    $sub = W::sub($film, 'Episode 1');
    foreach ([TaskStatus::Proposed, TaskStatus::Rejected, TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::ChangesRequested, TaskStatus::InReview, TaskStatus::Done, TaskStatus::Done] as $status) {
        W::task($sub, $person, $status);
    }

    $props = monitorProps(userWithRole(Role::ProjectDirector));

    expect($props['status']['by'])->toBe('project')
        ->and($props['status']['rows'])->toBe([[
            'id' => $film->id, 'name' => 'Film Pendek', 'code' => null, 'href' => route('projects.show', $film->id, false),
            'todo' => 1, 'doing' => 2, 'review' => 1, 'done' => 2,
        ]])
        ->and($props['headline']['open'])->toBe(4);

    // One project picked: rows per sub project
    $props = monitorProps(userWithRole(Role::ProjectDirector), ['proyek' => $film->id]);
    expect($props['status']['by'])->toBe('sub_project')->and($props['status']['rows'][0]['name'])->toBe('Episode 1');
});

it('shows progress per pipeline stage for one project, tasks without a stage last', function () {
    $person = userWithRole(Role::Employee);
    $film = W::project('Film Pendek');
    $sub = W::sub($film, 'Episode 1');
    $storyboard = PipelineStage::query()->where('name', 'Storyboard')->sole();
    $rigging = PipelineStage::query()->where('name', 'Rigging')->sole();

    W::task($sub, $person, TaskStatus::Done, ['stage_id' => $rigging->id]);
    W::task($sub, $person, TaskStatus::Todo, ['stage_id' => $storyboard->id]);
    W::task($sub, $person, TaskStatus::InReview, ['stage_id' => $storyboard->id]);
    W::task($sub, $person, TaskStatus::InProgress);
    W::task($sub, $person, TaskStatus::Proposed, ['stage_id' => $rigging->id]);

    expect(monitorProps(userWithRole(Role::ProjectManager))['stages'])->toBeNull();

    expect(monitorProps(userWithRole(Role::ProjectManager), ['proyek' => $film->id])['stages'])->toBe([
        ['id' => $storyboard->id, 'name' => 'Storyboard', 'phase' => 'pre_production', 'todo' => 1, 'doing' => 0, 'review' => 1, 'done' => 0],
        ['id' => $rigging->id, 'name' => 'Rigging', 'phase' => 'production', 'todo' => 0, 'doing' => 0, 'review' => 0, 'done' => 1],
        ['id' => null, 'name' => null, 'phase' => null, 'todo' => 0, 'doing' => 1, 'review' => 0, 'done' => 0],
    ]);
});

it('adds up logged hours per project in the period without deleted logs', function () {
    $person = userWithRole(Role::Employee);
    $film = W::project('Film Pendek');
    $iklan = W::project('Iklan Kopi');

    W::log($person, $film, 90, '2026-09-21 02:00:00');
    W::log($person, $film, 30, '2026-09-22 02:00:00');
    W::log($person, $film, 600, '2026-09-22 05:00:00')->delete();
    W::log($person, $iklan, 45, '2026-09-01 02:00:00');
    // Before the period
    W::log($person, $iklan, 300, '2026-08-30 02:00:00');

    expect(monitorProps(userWithRole(Role::ProjectManager))['hours'])->toBe([
        ['id' => $film->id, 'name' => 'Film Pendek', 'code' => null, 'href' => route('projects.show', $film->id, false), 'minutes' => 120],
        ['id' => $iklan->id, 'name' => 'Iklan Kopi', 'code' => null, 'href' => route('projects.show', $iklan->id, false), 'minutes' => 45],
    ]);
});

it('sends hour budgets only to budget holders', function () {
    $lead = userWithRole(Role::TeamLead);
    $member = userWithRole(Role::Employee);
    W::team('Animasi', $lead, $member);
    $film = W::project('Film Pendek', 600);
    W::task(W::sub($film, 'Episode 1'), $member);
    W::log($member, $film, 90, '2026-09-21 02:00:00');
    W::log($member, $film, 60, '2026-08-01 02:00:00');

    $this->actingAs($lead)->get(route('monitoring.work'))->assertInertia(fn (Assert $page) => $page->missing('budgets'));
    expect(json_encode(monitorProps($lead)))->not->toContain('budget');

    expect(monitorProps(userWithRole(Role::ProjectManager))['budgets'])->toBe([
        ['id' => $film->id, 'name' => 'Film Pendek', 'code' => null, 'href' => route('projects.show', $film->id, false), 'budget_minutes' => 600, 'logged_minutes' => 150],
    ]);

    // Also with one project picked
    W::project('Iklan Kopi', 60);
    expect(monitorProps(userWithRole(Role::ProjectDirector), ['proyek' => $film->id])['budgets'])->toHaveCount(1)
        ->{0}->toMatchArray(['id' => $film->id, 'logged_minutes' => 150]);
});

it('measures review quality with its base: retakes over all reviews and the median wait', function () {
    $person = userWithRole(Role::Employee);
    $lead = userWithRole(Role::ProjectManager);
    $sub = W::sub(W::project('Film Pendek'), 'Episode 1');
    $task = W::task($sub, $person, TaskStatus::InReview);
    $done = W::task($sub, $person, TaskStatus::Done);

    W::review($task, $person, 'changes_requested', '2026-09-21 02:00:00', '2026-09-21 03:00:00'); // 60 min
    W::review($done, $person, 'approved', '2026-09-21 02:00:00', '2026-09-21 06:00:00');          // 240 min
    W::review($done, $person, 'changes_requested', '2026-09-14 02:00:00', '2026-09-14 02:30:00'); // 30 min
    // Waiting, and one reviewed before the period: neither counts
    \App\Modules\Projects\Models\TaskSubmission::query()->create(['task_id' => $task->id, 'submitted_by' => $person->id, 'note' => 'Revisi pose', 'review_status' => 'pending']);
    W::review($done, $person, 'approved', '2026-08-01 02:00:00', '2026-08-02 02:00:00');

    expect(monitorProps($lead)['review'])->toBe(['reviewed' => 3, 'changes_requested' => 2, 'median_wait_minutes' => 60]);

    // No reviews: no median, not a zero
    expect(monitorProps(userWithRole(Role::ProjectManager), ['proyek' => W::project('Kosong')->id])['review'])
        ->toBe(['reviewed' => 0, 'changes_requested' => 0, 'median_wait_minutes' => null]);
});

it('lists open tasks that are overdue or due within 7 days, earliest first', function () {
    $person = userWithRole(Role::Employee);
    $sub = W::sub(W::project('Film Pendek'), 'Episode 1');
    $late = W::task($sub, $person, TaskStatus::InProgress, ['title' => 'Terlambat', 'due_date' => '2026-09-20']);
    $soon = W::task($sub, $person, TaskStatus::Todo, ['title' => 'Minggu depan', 'due_date' => '2026-09-30']);
    W::task($sub, $person, TaskStatus::Todo, ['title' => 'Masih lama', 'due_date' => '2026-10-01']);
    W::task($sub, $person, TaskStatus::Done, ['title' => 'Sudah selesai', 'due_date' => '2026-09-01']);
    W::task($sub, $person, TaskStatus::Proposed, ['title' => 'Usulan', 'due_date' => '2026-09-01']);

    $props = monitorProps(userWithRole(Role::ProjectManager));

    expect($props['due']['total'])->toBe(2)
        ->and(collect($props['due']['rows'])->map(fn ($r) => [$r['id'], $r['title'], $r['days_until']])->all())->toBe([
            [$late->id, 'Terlambat', -3],
            [$soon->id, 'Minggu depan', 7],
        ])
        ->and($props['headline']['overdue'])->toBe(1)
        ->and(collect($props['due']['rows'][0]['assignees'])->pluck('id')->all())->toBe([$person->id]);
});

it('lists open milestones that are overdue or due in the next 30 days', function () {
    $film = W::project('Film Pendek');
    W::sub($film, 'Episode 1', $lead = userWithRole(Role::TeamLead));
    $make = fn (string $name, string $due, bool $done = false) => ProjectMilestone::query()->create([
        'project_id' => $film->id, 'name' => $name, 'kind' => MilestoneKind::Delivery, 'due_date' => $due, 'done_at' => $done ? now() : null,
    ]);
    $make('Terlambat', '2026-09-10');
    $make('Review klien', '2026-10-23');
    $make('Terlalu jauh', '2026-10-24');
    $make('Sudah selesai', '2026-09-24', true);

    foreach ([userWithRole(Role::ProjectManager), $lead] as $viewer) {
        $props = monitorProps($viewer);
        expect($props['milestones']['total'])->toBe(2)
            ->and(collect($props['milestones']['rows'])->map(fn ($m) => [$m['name'], $m['status'], $m['project']['name']])->all())->toBe([
                ['Terlambat', 'overdue', 'Film Pendek'],
                ['Review klien', 'scheduled', 'Film Pendek'],
            ]);
    }
});

it('reads the page in a small, fixed number of queries', function () {
    $person = userWithRole(Role::Employee);
    $viewer = userWithRole(Role::ProjectManager);
    foreach (range(1, 3) as $i) {
        $project = W::project("Proyek {$i}", 600);
        $sub = W::sub($project, 'Episode');
        foreach (range(1, 5) as $j) {
            W::task($sub, $person, TaskStatus::Todo, ['due_date' => '2026-09-24']);
        }
        W::log($person, $project, 60, '2026-09-21 02:00:00');
    }

    // The first request also warms the permission cache
    $this->actingAs($viewer)->get(route('monitoring.work'))->assertOk();
    DB::enableQueryLog();
    $this->get(route('monitoring.work'))->assertOk();
    $few = count(DB::getQueryLog());

    foreach (range(4, 8) as $i) {
        $sub = W::sub(W::project("Proyek {$i}", 600), 'Episode');
        W::task($sub, $person, TaskStatus::Todo, ['due_date' => '2026-09-24']);
    }
    DB::flushQueryLog();
    $this->get(route('monitoring.work'))->assertOk();

    expect(count(DB::getQueryLog()))->toBe($few)->toBeLessThan(40);
});
