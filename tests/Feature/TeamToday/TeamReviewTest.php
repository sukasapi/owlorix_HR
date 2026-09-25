<?php

use App\Modules\Attendance\Models\IdlePeriod;
use App\Modules\Attendance\Models\IdleReview;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskWorkSession;
use App\Modules\Projects\Models\WorkActivityLog;
use App\Modules\Shared\Audit\AuditLog;
use Carbon\CarbonImmutable;

// Tim hari ini tabs (2026-09-25): PC diam with the lead's review, the team's work log, and the running task.

function reviewPerson(string $name, Role $role = Role::Employee): User
{
    return User::factory()->withRole($role)->create(['name' => $name]);
}

function jakarta(string $at): CarbonImmutable
{
    return CarbonImmutable::parse($at, 'Asia/Jakarta')->utc();
}

/** A closed shift on 2026-09-24 with one quiet period 10:12 to 10:46. */
function quietDay(User $person, ?string $tag = 'meeting', ?string $note = 'Rapat animatic'): IdlePeriod
{
    $shift = Shift::factory()->create([
        'user_id' => $person->id, 'work_date' => '2026-09-24',
        'clock_in_at' => jakarta('2026-09-24 09:00'), 'regular_ends_at' => jakarta('2026-09-24 17:00'),
        'clock_out_at' => jakarta('2026-09-24 17:05'), 'last_seen_at' => jakarta('2026-09-24 17:05'), 'overtime_minutes' => 0,
    ]);

    return IdlePeriod::query()->create([
        'shift_id' => $shift->id, 'started_at' => jakarta('2026-09-24 10:12'), 'ended_at' => jakarta('2026-09-24 10:46'),
        'minutes' => 34, 'tag' => $tag, 'note' => $note,
    ]);
}

beforeEach(function () {
    $this->travelTo(jakarta('2026-09-25 09:00'));
    $this->lead = reviewPerson('Lead Animation', Role::TeamLead);
    $this->member = reviewPerson('Anggota Animation');
    $this->outsider = reviewPerson('Anggota Lighting');
    Team::factory()->create(['name' => 'Animation', 'lead_user_id' => $this->lead->id])->members()->attach($this->member);
    Team::factory()->create(['name' => 'Lighting'])->members()->attach($this->outsider);
});

describe('PC diam tab', function () {
    it('shows the quiet periods of the lead\'s team only, with tag and note', function () {
        quietDay($this->member);
        quietDay($this->outsider);

        $this->actingAs($this->lead)->get(route('team.idle', ['tanggal' => '2026-09-24']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('team-today/Idle')
                ->has('groups', 1)
                ->where('groups.0.id', $this->member->id)
                ->where('groups.0.total_minutes', 34)
                ->where('groups.0.periods.0.tag', 'meeting')
                ->where('groups.0.periods.0.note', 'Rapat animatic')
                ->where('groups.0.periods.0.review', null));
    });

    it('lets the lead mark a period checked, and hides it under "only not checked"', function () {
        $period = quietDay($this->member);

        $this->actingAs($this->lead)->post(route('team.idle.check'), ['shift_id' => $period->shift_id, 'started_at' => $period->started_at->toIso8601ZuluString('millisecond')])
            ->assertRedirect();

        $review = IdleReview::query()->sole();
        expect($review->status)->toBe(IdleReview::CHECKED)->and($review->checked_by)->toBe($this->lead->id);

        $this->actingAs($this->lead)->get(route('team.idle', ['tanggal' => '2026-09-24', 'belum' => 1]))
            ->assertInertia(fn ($page) => $page->has('groups', 0));
        expect(AuditLog::query()->where('action', 'idle_review.checked')->count())->toBe(1);
    });

    it('asks the person, who sees the question on Hari ini and answers it', function () {
        $period = quietDay($this->member, tag: null, note: null);

        $this->actingAs($this->lead)->post(route('team.idle.ask'), [
            'shift_id' => $period->shift_id, 'started_at' => $period->started_at->toIso8601ZuluString('millisecond'), 'question' => 'Sedang apa saat itu?',
        ])->assertRedirect();

        $this->actingAs($this->member)->get(route('my-day'))
            ->assertInertia(fn ($page) => $page
                ->has('idle_questions', 1)
                ->where('idle_questions.0.question', 'Sedang apa saat itu?')
                ->where('idle_questions.0.asked_by', 'Lead Animation')
                ->where('idle_questions.0.minutes', 34));

        $review = IdleReview::query()->sole();
        $this->actingAs($this->member)->post(route('idle-reviews.answer', $review), ['answer' => 'Render adegan 7 di PC sebelah'])->assertRedirect();

        expect($review->fresh()->status)->toBe(IdleReview::ANSWERED);
        $this->actingAs($this->member)->get(route('my-day'))->assertInertia(fn ($page) => $page->has('idle_questions', 0));
        $this->actingAs($this->lead)->get(route('team.idle', ['tanggal' => '2026-09-24']))
            ->assertInertia(fn ($page) => $page
                ->where('groups.0.periods.0.review.status', 'answered')
                ->where('groups.0.periods.0.review.answer', 'Render adegan 7 di PC sebelah'));

        // A second answer is refused; nobody else may answer
        $this->actingAs($this->member)->post(route('idle-reviews.answer', $review), ['answer' => 'Jawaban kedua'])->assertSessionHasErrors('answer');
        $this->actingAs($this->outsider)->post(route('idle-reviews.answer', $review), ['answer' => 'Bukan punyaku'])->assertForbidden();
    });

    it('refuses a review of someone outside the lead\'s teams, and employees', function () {
        $period = quietDay($this->outsider);
        $body = ['shift_id' => $period->shift_id, 'started_at' => $period->started_at->toIso8601ZuluString('millisecond')];

        $this->actingAs($this->lead)->post(route('team.idle.check'), $body)->assertForbidden();
        $this->actingAs($this->member)->get(route('team.idle'))->assertForbidden();
        $this->actingAs($this->member)->post(route('team.idle.check'), $body)->assertForbidden();
        expect(IdleReview::query()->count())->toBe(0);
    });

    it('lets Superadmin review anyone', function () {
        $period = quietDay($this->outsider);

        $this->actingAs(reviewPerson('Admin', Role::Superadmin))
            ->post(route('team.idle.check'), ['shift_id' => $period->shift_id, 'started_at' => $period->started_at->toIso8601ZuluString('millisecond')])
            ->assertRedirect();
        expect(IdleReview::query()->count())->toBe(1);
    });
});

describe('Log kerja tab', function () {
    beforeEach(function () {
        $this->project = Project::query()->create(['name' => 'Serial Kancil', 'code' => 'KCL', 'status' => ProjectStatus::Active]);
        $log = fn (User $who, string $start, string $end, string $text) => WorkActivityLog::query()->create([
            'user_id' => $who->id, 'project_id' => $this->project->id, 'description' => $text,
            'started_at' => jakarta($start), 'ended_at' => jakarta($end), 'evidence_url' => 'https://drive.google.com/x',
        ]);
        $log($this->member, '2026-09-24 09:00', '2026-09-24 11:30', 'Rigging kaki kancil');
        $log($this->member, '2026-09-10 09:00', '2026-09-10 10:00', 'Terlalu lama');
        $log($this->outsider, '2026-09-24 09:00', '2026-09-24 10:00', 'Lighting adegan 2');
    });

    it('lists the lead\'s team in the date range, with the total', function () {
        $this->actingAs($this->lead)->get(route('team.logs'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('team-today/Logs')
                ->where('filters.dari', '2026-09-19')
                ->where('filters.sampai', '2026-09-25')
                ->has('entries.data', 1)
                ->where('entries.data.0.description', 'Rigging kaki kancil')
                ->where('entries.data.0.date', '2026-09-24')
                ->where('entries.data.0.minutes', 150)
                ->where('total_minutes', 150));
    });

    it('filters by person, project, and dates; everyone for a Project Director', function () {
        $director = reviewPerson('Director', Role::ProjectDirector);

        $this->actingAs($director)->get(route('team.logs', ['dari' => '2026-09-01', 'sampai' => '2026-09-25']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 3));
        $this->actingAs($director)->get(route('team.logs', ['dari' => '2026-09-01', 'orang' => $this->outsider->id]))
            ->assertInertia(fn ($page) => $page->has('entries.data', 1)->where('entries.data.0.person', 'Anggota Lighting'));
        $this->actingAs($director)->get(route('team.logs', ['dari' => '2026-09-01', 'proyek' => 999]))
            ->assertInertia(fn ($page) => $page->has('entries.data', 0));
    });

    it('is closed to employees', function () {
        $this->actingAs($this->member)->get(route('team.logs'))->assertForbidden();
    });
});

it('shows the task each person is working on right now on the board', function () {
    $project = Project::query()->create(['name' => 'Serial Kancil', 'status' => ProjectStatus::Active]);
    $sub = $project->subProjects()->create(['name' => 'Episode 1', 'status' => ProjectStatus::Active]);
    $task = Task::query()->create([
        'project_id' => $project->id, 'sub_project_id' => $sub->id, 'title' => 'Rigging karakter Kancil',
        'status' => TaskStatus::InProgress, 'priority' => 'normal', 'created_by' => $this->lead->id,
    ]);
    TaskWorkSession::query()->create(['task_id' => $task->id, 'user_id' => $this->member->id, 'started_at' => jakarta('2026-09-25 08:30')]);

    $this->actingAs($this->lead)->get(route('team.today'))
        ->assertInertia(fn ($page) => $page
            ->where('board.people.0.task.title', 'Rigging karakter Kancil')
            ->where('board.people.0.task.project', 'Serial Kancil'));
});

it('puts the three views under Tim hari ini as tabs', function () {
    $this->actingAs($this->lead)->get(route('team.today'))
        ->assertInertia(fn ($page) => $page->where('nav', fn ($nav) => navChildren($nav, 'team_today')->all() === ['team_today', 'team_idle', 'team_logs']));
});
