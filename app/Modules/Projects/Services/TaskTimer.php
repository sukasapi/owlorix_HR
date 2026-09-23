<?php

namespace App\Modules\Projects\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskWorkSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The task timer: one running session per person (unique `open_user_id`). Starting another task stops the running
 * one first, so time is never counted twice. Sessions are turned into work log rows when evidence is sent.
 */
class TaskTimer
{
    public function running(User $user): ?TaskWorkSession
    {
        return TaskWorkSession::query()->where('user_id', $user->id)->whereNull('ended_at')->first();
    }

    public function start(User $user, Task $task, ?CarbonImmutable $now = null): TaskWorkSession
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($user, $task, $now) {
            $running = TaskWorkSession::query()->where('user_id', $user->id)->whereNull('ended_at')->lockForUpdate()->first();

            if ($running !== null && $running->task_id === $task->id) {
                return $running;
            }

            $running?->forceFill(['ended_at' => $now])->save();

            $session = TaskWorkSession::query()->create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'started_at' => $now,
            ]);

            if ($task->status === TaskStatus::Todo || $task->status === TaskStatus::ChangesRequested) {
                $task->forceFill(['status' => TaskStatus::InProgress])->save();
            }

            return $session;
        });
    }

    public function stop(User $user, ?CarbonImmutable $now = null, ?int $onlyTaskId = null): ?TaskWorkSession
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($user, $now, $onlyTaskId) {
            $running = TaskWorkSession::query()
                ->where('user_id', $user->id)
                ->whereNull('ended_at')
                ->when($onlyTaskId !== null, fn ($q) => $q->where('task_id', $onlyTaskId))
                ->lockForUpdate()
                ->first();

            $running?->forceFill(['ended_at' => $now])->save();

            return $running;
        });
    }
}
