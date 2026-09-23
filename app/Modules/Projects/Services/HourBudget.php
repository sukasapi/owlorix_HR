<?php

namespace App\Modules\Projects\Services;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Hour budgets of projects and sub projects against the hours logged in Log kerja (docs/14 3.3).
 * Logged time counts work log rows that are not deleted: for a project every row with its project_id, for a sub
 * project every row whose task belongs to it (also when that task was deleted later, the work still happened).
 * Only people with projects.budget may receive these numbers; callers check that.
 */
class HourBudget
{
    public const MAX_HOURS = 99999;

    /**
     * @param  list<int>  $projectIds
     * @return array<int, int> logged minutes by project id, 0 for projects without logs
     */
    public function loggedByProject(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $seconds = DB::table('work_activity_logs')
            ->whereNull('deleted_at')
            ->whereIn('project_id', $projectIds)
            ->groupBy('project_id')
            ->selectRaw('project_id as id, sum(timestampdiff(second, started_at, ended_at)) as seconds')
            ->pluck('seconds', 'id');

        return $this->minutes($projectIds, $seconds->all());
    }

    /**
     * @param  list<int>  $subProjectIds
     * @return array<int, int> logged minutes by sub project id, 0 for sub projects without logs
     */
    public function loggedBySubProject(array $subProjectIds): array
    {
        if ($subProjectIds === []) {
            return [];
        }

        $seconds = DB::table('work_activity_logs')
            ->join('tasks', 'tasks.id', '=', 'work_activity_logs.task_id')
            ->whereNull('work_activity_logs.deleted_at')
            ->whereIn('tasks.sub_project_id', $subProjectIds)
            ->groupBy('tasks.sub_project_id')
            ->selectRaw('tasks.sub_project_id as id, sum(timestampdiff(second, work_activity_logs.started_at, work_activity_logs.ended_at)) as seconds')
            ->pluck('seconds', 'id');

        return $this->minutes($subProjectIds, $seconds->all());
    }

    public function loggedForProject(int $projectId): int
    {
        return $this->loggedByProject([$projectId])[$projectId];
    }

    public function loggedForSubProject(int $subProjectId): int
    {
        return $this->loggedBySubProject([$subProjectId])[$subProjectId];
    }

    /** Props for a budget holder. @return array{minutes: ?int, logged_minutes: int} */
    public static function view(?int $budgetMinutes, int $loggedMinutes): array
    {
        return ['minutes' => $budgetMinutes, 'logged_minutes' => $loggedMinutes];
    }

    /**
     * Validation of the `budget_hours` form field. Someone without projects.budget may not send a value at all; the
     * controllers also leave the stored budget alone for them.
     *
     * @return list<mixed>
     */
    public static function rules(?User $user): array
    {
        return [
            'nullable',
            Rule::prohibitedIf(fn () => ! $user?->hasPermission(Permission::ManageBudgets)),
            'numeric',
            'min:0.25',
            'max:'.self::MAX_HOURS,
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'budget_hours.prohibited' => __('projects::messages.budget_not_allowed'),
            'budget_hours.numeric' => __('projects::messages.budget_hours'),
            'budget_hours.min' => __('projects::messages.budget_hours'),
            'budget_hours.max' => __('projects::messages.budget_hours'),
        ];
    }

    public static function canSee(?User $user): bool
    {
        return (bool) $user?->hasPermission(Permission::ManageBudgets);
    }

    /**
     * Whether the validated data carries a budget the person may set. A form without the field keeps the stored
     * budget, so an edit by someone who cannot see budgets never clears one.
     *
     * @param  array<string, mixed>  $data
     */
    public static function submitted(?User $user, array $data): bool
    {
        return array_key_exists('budget_hours', $data) && self::canSee($user);
    }

    /** Hours from the form (12.5) to stored minutes; empty means no budget. */
    public static function minutesFromHours(mixed $hours): ?int
    {
        return $hours === null || $hours === '' ? null : (int) round(((float) $hours) * 60);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int|string, mixed>  $seconds
     * @return array<int, int>
     */
    private function minutes(array $ids, array $seconds): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = intdiv((int) ($seconds[$id] ?? 0), 60);
        }

        return $out;
    }
}
