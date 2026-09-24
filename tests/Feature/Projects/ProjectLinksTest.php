<?php

use App\Modules\Identity\Access\Role;
use App\Modules\Projects\Enums\PartStatus;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectLink;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskAssignee;
use App\Modules\Shared\Audit\AuditLog;

// docs/16: document links on the project page. Only people involved in the project see them; managers set them.

beforeEach(function () {
    $this->project = Project::query()->create(['name' => 'Animasi Ayat Alkitab', 'status' => ProjectStatus::Active]);
    $this->member = userWithRole(Role::Employee);
    ProjectMember::query()->create(['project_id' => $this->project->id, 'user_id' => $this->member->id, 'assigned_at' => now()]);
});

function docLinkPayload(array $overrides = []): array
{
    return array_merge([
        'category' => 'storyboard',
        'label' => '',
        'url' => 'https://drive.google.com/drive/folders/1AbC',
        'note' => '',
        'managers_only' => false,
    ], $overrides);
}

function addDocLink(Project $project, string $category, int $position, array $extra = []): ProjectLink
{
    return $project->links()->create([
        'category' => $category,
        'url' => 'https://drive.google.com/drive/folders/'.$category,
        'position' => $position,
        ...$extra,
    ]);
}

describe('who sees the links', function () {
    it('shows members the links in the set order without the managers-only ones', function () {
        addDocLink($this->project, 'storyboard', 2);
        addDocLink($this->project, 'scenario', 1, ['note' => 'Draft 3']);
        addDocLink($this->project, 'final', 3, ['managers_only' => true]);

        $this->actingAs($this->member)->get(route('projects.show', $this->project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('projects/Show')
                ->has('links', 2)
                ->where('links.0.category', 'scenario')
                ->where('links.0.label', null)
                ->where('links.0.note', 'Draft 3')
                ->where('links.0.service', 'drive_folder')
                ->where('links.0.host', 'drive.google.com')
                ->where('links.1.category', 'storyboard')
                ->where('link_categories', ['scenario', 'storyboard', 'character_assets', 'sound', 'voice_over', 'animation', 'final', 'tracker', 'other']));
    });

    it('gives managers every link, managers-only ones included, even when they are not members', function () {
        addDocLink($this->project, 'scenario', 1);
        addDocLink($this->project, 'final', 2, ['managers_only' => true]);

        $this->actingAs(userWithRole(Role::TeamLead))->get(route('projects.show', $this->project))
            ->assertInertia(fn ($page) => $page->has('links', 2)->where('links.1.managers_only', true));
    });

    it('sends no links to people outside the project', function () {
        addDocLink($this->project, 'scenario', 1);

        $this->actingAs(userWithRole(Role::Employee))->get(route('projects.show', $this->project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('links', null)->has('milestones'));
    });

    it('counts people on a task and sub project leads as involved, and stops when the task is deleted', function () {
        addDocLink($this->project, 'scenario', 1);
        $assignee = userWithRole(Role::Employee);
        $lead = userWithRole(Role::Employee);
        $sub = $this->project->subProjects()->create(['name' => 'Episode 1', 'status' => ProjectStatus::Active, 'lead_user_id' => $lead->id]);
        $task = Task::query()->create([
            'project_id' => $this->project->id,
            'sub_project_id' => $sub->id,
            'title' => 'Animasi shot 010',
            'status' => TaskStatus::Todo,
            'priority' => 'normal',
            'created_by' => $lead->id,
            'evidence_required' => true,
        ]);
        TaskAssignee::query()->create(['task_id' => $task->id, 'user_id' => $assignee->id, 'part_status' => PartStatus::Open, 'assigned_by' => $lead->id, 'assigned_at' => now()]);

        $this->actingAs($assignee)->get(route('projects.show', $this->project))->assertInertia(fn ($page) => $page->has('links', 1));
        $this->actingAs($lead)->get(route('projects.show', $this->project))->assertInertia(fn ($page) => $page->has('links', 1));

        $task->delete();
        $this->actingAs($assignee)->get(route('projects.show', $this->project))->assertInertia(fn ($page) => $page->where('links', null));
    });
});

it('lets project managers add, edit, reorder, and delete links, with audit', function () {
    $lead = userWithRole(Role::TeamLead);

    $this->actingAs($lead)->post(route('projects.links.store', $this->project), docLinkPayload(['category' => 'scenario', 'label' => '   ', 'note' => '  Versi terbaru di folder v3  ']))
        ->assertSessionHasNoErrors();
    $this->actingAs($lead)->post(route('projects.links.store', $this->project), docLinkPayload(['category' => 'other', 'label' => ' Folder utama ', 'url' => ' https://drive.google.com/drive/folders/root ']))
        ->assertSessionHasNoErrors();

    [$scenario, $main] = ProjectLink::query()->orderBy('id')->get()->all();
    expect($scenario)->label->toBeNull()->note->toBe('Versi terbaru di folder v3')->position->toBe(1)->created_by->toBe($lead->id)
        ->and($main)->label->toBe('Folder utama')->url->toBe('https://drive.google.com/drive/folders/root')->position->toBe(2);

    $this->actingAs($lead)->put(route('projects.links.update', [$this->project, $scenario]), docLinkPayload(['category' => 'scenario', 'label' => 'Skenario episode 1', 'managers_only' => true]))
        ->assertSessionHasNoErrors();
    expect($scenario->fresh())->label->toBe('Skenario episode 1')->note->toBeNull()->managers_only->toBeTrue();

    $this->actingAs($lead)->post(route('projects.links.move', [$this->project, $main]), ['direction' => 'up'])->assertRedirect();
    expect(ProjectLink::query()->orderBy('position')->pluck('id')->all())->toBe([$main->id, $scenario->id]);

    // Already first: nothing changes and nothing is recorded
    $this->actingAs($lead)->post(route('projects.links.move', [$this->project, $main]), ['direction' => 'up'])->assertRedirect();

    $this->actingAs($lead)->delete(route('projects.links.destroy', [$this->project, $scenario]))
        ->assertRedirect()
        ->assertSessionHas('status', 'Tautan Skenario episode 1 dihapus.');
    expect(ProjectLink::query()->pluck('id')->all())->toBe([$main->id]);

    expect(AuditLog::query()->where('action', 'like', 'project_link.%')->orderBy('id')->pluck('action')->all())
        ->toBe(['project_link.created', 'project_link.created', 'project_link.updated', 'project_link.reordered', 'project_link.deleted']);
    expect(AuditLog::query()->where('action', 'project_link.updated')->first())
        ->before->toMatchArray(['label' => null, 'note' => 'Versi terbaru di folder v3', 'managers_only' => false])
        ->after->toMatchArray(['label' => 'Skenario episode 1', 'note' => null, 'managers_only' => true]);
});

it('names the link after its category in the flash message when the label is empty', function () {
    $this->actingAs(userWithRole(Role::ProjectManager))->post(route('projects.links.store', $this->project), docLinkPayload(['category' => 'voice_over']))
        ->assertSessionHas('status', 'Tautan Voice over ditambahkan.');
});

it('accepts only https addresses and needs a name for Other', function (array $payload, array $errors) {
    $this->actingAs(userWithRole(Role::ProjectManager))
        ->post(route('projects.links.store', $this->project), docLinkPayload($payload))
        ->assertSessionHasErrors($errors);

    expect(ProjectLink::query()->count())->toBe(0);
})->with([
    'javascript address' => [['url' => 'javascript:alert(1)'], ['url']],
    'plain http' => [['url' => 'http://drive.google.com/drive/folders/1AbC'], ['url']],
    'no address' => [['url' => ''], ['url']],
    'unknown category' => [['category' => 'music'], ['category']],
    'other without a name' => [['category' => 'other', 'label' => '  '], ['label']],
    'name too long' => [['label' => str_repeat('a', 81)], ['label']],
]);

it('is read-only for employees and scoped to its project', function () {
    $link = addDocLink($this->project, 'scenario', 1);
    $other = Project::query()->create(['name' => 'Iklan', 'status' => ProjectStatus::Active]);

    $this->actingAs($this->member)->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->has('links', 1)->where('can_manage', false));

    $this->actingAs($this->member)->post(route('projects.links.store', $this->project), docLinkPayload())->assertForbidden();
    $this->actingAs($this->member)->put(route('projects.links.update', [$this->project, $link]), docLinkPayload())->assertForbidden();
    $this->actingAs($this->member)->post(route('projects.links.move', [$this->project, $link]), ['direction' => 'down'])->assertForbidden();
    $this->actingAs($this->member)->delete(route('projects.links.destroy', [$this->project, $link]))->assertForbidden();

    $manager = userWithRole(Role::ProjectDirector);
    $this->actingAs($manager)->delete(route('projects.links.destroy', [$other, $link]))->assertNotFound();
    expect($link->fresh())->not->toBeNull();
});

it('names the service from the address', function (string $url, ?string $service, ?string $host) {
    $link = new ProjectLink(['url' => $url]);

    expect($link->service())->toBe($service)->and($link->host())->toBe($host);
})->with([
    ['https://drive.google.com/drive/folders/1AbC?usp=sharing', 'drive_folder', 'drive.google.com'],
    ['https://drive.google.com/file/d/1AbC/view', 'drive', 'drive.google.com'],
    ['https://docs.google.com/document/d/1AbC/edit', 'docs', 'docs.google.com'],
    ['https://docs.google.com/spreadsheets/d/1AbC/edit', 'sheets', 'docs.google.com'],
    ['https://docs.google.com/presentation/d/1AbC/edit', 'slides', 'docs.google.com'],
    ['https://www.figma.com/file/1AbC/Style-frame', 'figma', 'figma.com'],
    ['https://youtu.be/1AbC', 'youtube', 'youtu.be'],
    ['https://vimeo.com/123', 'vimeo', 'vimeo.com'],
    ['https://trello.com/b/1AbC', null, 'trello.com'],
]);
