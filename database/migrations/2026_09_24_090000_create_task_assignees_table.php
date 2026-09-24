<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One task, several assignees (docs/15). Each assignee has a part with its own status; the single
 * `tasks.assignee_id` moves into `task_assignees` and is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('part_status', ['open', 'submitted', 'changes_requested', 'approved'])->default('open');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('assigned_at', 3);
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
            $table->index(['user_id', 'part_status']);
        });

        // The part follows the task: work not yet sent is open, a finished task approved. In review counts as sent
        // only when the evidence waiting is this person's; after an assignee change the new person has sent nothing
        DB::statement(<<<'SQL'
            insert into task_assignees (task_id, user_id, part_status, assigned_by, assigned_at, created_at, updated_at)
            select t.id, t.assignee_id,
                case
                    when t.status = 'in_review' and t.assignee_id = (
                        select s.submitted_by from task_submissions s
                        where s.task_id = t.id and s.review_status = 'pending'
                        order by s.id desc
                        limit 1
                    ) then 'submitted'
                    when t.status = 'changes_requested' then 'changes_requested'
                    when t.status = 'done' then 'approved'
                    else 'open'
                end,
                t.created_by, coalesce(t.created_at, now(3)), now(), now()
            from tasks t
            where t.assignee_id is not null
            SQL);

        // Statuses the new rules would not produce (docs/15 section 2), so nobody is left unable to act. A task nobody
        // is on is todo; proposals, rejected and done tasks keep theirs
        DB::statement(<<<'SQL'
            update tasks set status = 'todo', completed_at = null
            where assignee_id is null and status not in ('proposed', 'rejected', 'done', 'todo')
            SQL);

        // In review with a part that was never sent: back to work for that person
        DB::statement(<<<'SQL'
            update tasks set status = case
                when exists (select 1 from task_work_sessions w where w.task_id = tasks.id)
                    or exists (select 1 from task_submissions s where s.task_id = tasks.id) then 'in_progress'
                else 'todo'
            end
            where status = 'in_review'
                and exists (select 1 from task_assignees a where a.task_id = tasks.id and a.part_status = 'open')
            SQL);

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['assignee_id']);
            $table->dropIndex(['assignee_id', 'status']);
            $table->dropColumn('assignee_id');
        });
    }

    /**
     * Brings the single column back with the first assignee of each task (by assignment time). Statuses changed by
     * up() stay as they are.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('assignee_id')->nullable()->after('priority')->constrained('users')->nullOnDelete();
            $table->index(['assignee_id', 'status']);
        });

        DB::statement(<<<'SQL'
            update tasks set assignee_id = (
                select ta.user_id from task_assignees ta
                where ta.task_id = tasks.id
                order by ta.assigned_at, ta.id
                limit 1
            )
            SQL);

        Schema::dropIfExists('task_assignees');
    }
};
