<?php

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/15 section 1: tasks.assignee_id moves into task_assignees, and down() brings it back. Schema changes end the
// test transaction in MySQL (implicit commit), so this test deletes every row it made instead of relying on rollback.

it('moves each assignee into a part that follows the task status, and back', function () {
    $migration = require database_path('migrations/2026_09_24_090000_create_task_assignees_table.php');
    $creator = User::factory()->create();
    $rani = User::factory()->create();
    $bayu = User::factory()->create();
    $projectId = null;
    $taskIds = [];

    $migration->down();

    try {
        expect(Schema::hasColumn('tasks', 'assignee_id'))->toBeTrue()
            ->and(Schema::hasTable('task_assignees'))->toBeFalse();

        $projectId = DB::table('projects')->insertGetId(['name' => 'Migrasi pengerja', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $subId = DB::table('sub_projects')->insertGetId(['project_id' => $projectId, 'name' => 'Episode 1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $task = function (string $key, string $status, ?int $assignee, bool $deleted = false) use (&$taskIds, $projectId, $subId, $creator) {
            $taskIds[$key] = DB::table('tasks')->insertGetId([
                'project_id' => $projectId, 'sub_project_id' => $subId, 'title' => 'Tugas '.$key, 'status' => $status, 'priority' => 'normal',
                'assignee_id' => $assignee, 'created_by' => $creator->id, 'evidence_required' => true,
                'created_at' => '2026-09-20 02:00:00', 'updated_at' => now(), 'deleted_at' => $deleted ? now() : null,
                'completed_at' => $status === 'done' ? '2026-09-21 02:00:00' : null,
            ]);
        };
        $pending = function (string $key, User $by) use (&$taskIds) {
            DB::table('task_submissions')->insert([
                'task_id' => $taskIds[$key], 'submitted_by' => $by->id, 'note' => 'Render pass pertama', 'review_status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        foreach (['proposed', 'rejected', 'todo', 'in_progress', 'in_review', 'changes_requested', 'done'] as $status) {
            $task($status, $status, $rani->id);
        }
        $pending('in_review', $rani);
        // The assignee changed while Bayu's evidence waited: Rani has sent nothing
        $task('in_review_changed', 'in_review', $rani->id);
        $pending('in_review_changed', $bayu);
        $task('in_review_deleted', 'in_review', $bayu->id, deleted: true);
        $pending('in_review_deleted', $bayu);
        // Nobody on it: only proposals, rejected and done tasks keep their status
        foreach (['todo', 'in_progress', 'in_review', 'changes_requested', 'done', 'proposed'] as $status) {
            $task($status.'_nobody', $status, null);
        }

        $migration->up();

        $parts = DB::table('task_assignees')->whereIn('task_id', $taskIds)->get()->keyBy('task_id');
        $withParts = collect($taskIds)->reject(fn (int $id, string $key) => str_ends_with($key, '_nobody'));

        expect(Schema::hasColumn('tasks', 'assignee_id'))->toBeFalse()
            ->and($parts)->toHaveCount(9)
            ->and($withParts->map(fn (int $id) => $parts[$id]->part_status)->all())->toBe([
                'proposed' => 'open',
                'rejected' => 'open',
                'todo' => 'open',
                'in_progress' => 'open',
                'in_review' => 'submitted',
                'changes_requested' => 'changes_requested',
                'done' => 'approved',
                'in_review_changed' => 'open',
                'in_review_deleted' => 'submitted',
            ])
            ->and($parts[$taskIds['todo']]->user_id)->toBe($rani->id)
            ->and($parts[$taskIds['todo']]->assigned_by)->toBe($creator->id)
            ->and(substr($parts[$taskIds['todo']]->assigned_at, 0, 19))->toBe('2026-09-20 02:00:00')
            ->and($parts[$taskIds['in_review_deleted']]->user_id)->toBe($bayu->id);

        $tasks = DB::table('tasks')->whereIn('id', $taskIds)->get()->keyBy('id');
        $status = fn (string $key) => [$tasks[$taskIds[$key]]->status, $tasks[$taskIds[$key]]->completed_at === null];

        expect($status('in_review'))->toBe(['in_review', true])
            ->and($status('in_review_changed'))->toBe(['in_progress', true])
            ->and($status('in_review_deleted'))->toBe(['in_review', true])
            ->and($status('done'))->toBe(['done', false])
            ->and($status('todo_nobody'))->toBe(['todo', true])
            ->and($status('in_progress_nobody'))->toBe(['todo', true])
            ->and($status('in_review_nobody'))->toBe(['todo', true])
            ->and($status('changes_requested_nobody'))->toBe(['todo', true])
            ->and($status('done_nobody'))->toBe(['done', false])
            ->and($status('proposed_nobody'))->toBe(['proposed', true]);

        // A second person joins later; going back keeps the one assigned first
        DB::table('task_assignees')->insert(['task_id' => $taskIds['todo'], 'user_id' => $bayu->id, 'part_status' => 'open', 'assigned_at' => '2026-09-23 02:00:00', 'created_at' => now(), 'updated_at' => now()]);

        $migration->down();

        expect(DB::table('tasks')->whereIn('id', $taskIds)->orderBy('id')->pluck('assignee_id', 'id')->all())->toBe(
            collect($taskIds)->mapWithKeys(fn (int $id, string $key) => [$id => match (true) {
                str_ends_with($key, '_nobody') => null,
                $key === 'in_review_deleted' => $bayu->id,
                default => $rani->id,
            }])->all()
        );
    } finally {
        if (Schema::hasColumn('tasks', 'assignee_id')) {
            $migration->up();
        }

        DB::table('tasks')->whereIn('id', $taskIds)->delete();
        if ($projectId !== null) {
            DB::table('sub_projects')->where('project_id', $projectId)->delete();
            DB::table('projects')->where('id', $projectId)->delete();
        }
        DB::table('users')->whereIn('id', [$creator->id, $rani->id, $bayu->id])->delete();
    }
});
