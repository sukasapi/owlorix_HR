<?php

namespace App\Modules\Shared\Http\Controllers\Admin;

use App\Modules\Attendance\Models\Correction;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Support\Collection;

/**
 * Turns audit rows into what Log audit shows: the subject as a readable label with a link when a page for it exists,
 * and names for the person and team ids inside before/after. Loads every subject of one page with a few queries.
 */
class AuditLogEntries
{
    /** Payload keys holding a person id, a list of person ids, or a list of team ids, resolved to names for the diff */
    private const PERSON_KEYS = ['user_id', 'person_id', 'lead_user_id', 'opened_by', 'proposed_by', 'approved_by', 'decided_by'];

    private const PERSON_LIST_KEYS = ['assignee_ids', 'assignees_added', 'assignees_removed'];

    private const TEAM_KEYS = ['team_id', 'team_ids'];

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array{rows: list<array<string, mixed>>, people: array<int, string>, teams: array<int, string>}
     */
    public function present(Collection $logs, User $viewer): array
    {
        $subjects = $this->loadSubjects($logs);
        $showIp = $viewer->hasRoleEnum(Role::Superadmin);
        $links = [
            'people' => $viewer->hasPermission(Permission::ManageUsers),
            'teams' => $viewer->hasPermission(Permission::ManageTeams),
            'calendar' => $viewer->hasPermission(Permission::ManageCalendar) || $viewer->hasPermission(Permission::OpenWorkdays),
            'devices' => $viewer->hasPermission(Permission::ManageDevices),
        ];

        [$personIds, $teamIds] = $this->idsInPayloads($logs);

        $rows = $logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'created_at' => Time::iso($log->created_at),
            'action' => $log->action,
            'group' => explode('.', $log->action)[0],
            'actor' => $log->actor instanceof User ? ['id' => $log->actor->id, 'name' => $log->actor->name, 'username' => $log->actor->username] : null,
            'subject' => $this->subject($log, $subjects[$log->subject_type][$log->subject_id] ?? null, $links),
            'before' => $log->before,
            'after' => $log->after,
            'ip' => $showIp ? $log->ip : null,
        ])->values()->all();

        return [
            'rows' => $rows,
            'people' => User::withTrashed()->whereIn('id', $personIds)->pluck('name', 'id')->all(),
            'teams' => Team::query()->whereIn('id', $teamIds)->pluck('name', 'id')->all(),
        ];
    }

    /**
     * @param  array<string, bool>  $links
     * @return array{kind: string, label: ?string, person: ?string, date: ?string, href: ?string}|null
     */
    private function subject(AuditLog $log, mixed $model, array $links): ?array
    {
        $payload = array_merge((array) $log->before, (array) $log->after);
        $date = is_string($payload['date'] ?? null) ? $payload['date'] : null;
        $calendarHref = fn (?string $d) => $links['calendar'] ? route('calendar.index', $d !== null ? ['bulan' => substr($d, 0, 7)] : [], false) : null;

        return match (true) {
            $model instanceof User => $this->make('person', $model->name.' ('.$model->username.')', href: $links['people'] && ! $model->trashed()
                ? route('admin.people.index', ['q' => $model->username, 'status' => 'all'], false)
                : null),
            $model instanceof Team => $this->make('team', $model->name, href: $links['teams'] ? route('admin.teams.index', absolute: false) : null),
            $model instanceof OvertimeRequest => $this->make('overtime', person: $model->user?->name, date: $model->shift?->work_date),
            $model instanceof Shift => $this->make('shift', person: $model->user?->name, date: $model->work_date),
            $model instanceof Correction => $this->make('correction', person: $model->shift?->user?->name, date: $model->shift?->work_date),
            $model instanceof CalendarDay => $this->make('calendar_day', $model->name, date: $model->date->toDateString(), href: $calendarHref($model->date->toDateString())),
            $model instanceof OpenedWorkday => $this->make('opened_workday', $model->scope_type->value === 'team' ? $model->team?->name : $model->user?->name, date: $model->date->toDateString(), href: $calendarHref($model->date->toDateString())),
            str_starts_with($log->action, 'device.') && is_string($payload['device_id'] ?? null) => $this->make('device', (string) ($payload['hostname'] ?? $payload['device_id']), href: $links['devices']
                ? route('admin.devices.index', ['jenis' => ($payload['kind'] ?? '') === 'browser' ? 'browser' : 'desktop', 'q' => $payload['device_id'], 'status' => 'all'], false)
                : null),
            $log->action === 'settings.updated' => $this->make('setting', (string) array_key_first((array) ($log->after ?? $log->before))),
            $log->action === 'calendar.work_week.updated' => $this->make('work_week', href: $calendarHref(null)),
            // The subject row is gone (a deleted team or calendar date): name it from the saved values
            $log->subject_type === (new Team)->getMorphClass() => $this->make('team', is_string($payload['name'] ?? null) ? $payload['name'] : null),
            $log->subject_type === (new CalendarDay)->getMorphClass() => $this->make('calendar_day', is_string($payload['name'] ?? null) ? $payload['name'] : null, date: $date, href: $calendarHref($date)),
            $log->subject_type === (new OpenedWorkday)->getMorphClass() => $this->make('opened_workday', date: $date, href: $calendarHref($date)),
            $log->subject_type !== null => $this->make('other', class_basename($log->subject_type).' #'.$log->subject_id),
            default => null,
        };
    }

    /** @return array{kind: string, label: ?string, person: ?string, date: ?string, href: ?string} */
    private function make(string $kind, ?string $label = null, ?string $person = null, ?string $date = null, ?string $href = null): array
    {
        return ['kind' => $kind, 'label' => $label, 'person' => $person, 'date' => $date, 'href' => $href];
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array<string, array<int|string, mixed>> subject type => id => model
     */
    private function loadSubjects(Collection $logs): array
    {
        $with = [
            (new User)->getMorphClass() => fn (array $ids) => User::withTrashed()->whereIn('id', $ids)->get(),
            (new Team)->getMorphClass() => fn (array $ids) => Team::query()->whereIn('id', $ids)->get(),
            (new OvertimeRequest)->getMorphClass() => fn (array $ids) => OvertimeRequest::query()->with(['user:id,name', 'shift:id,work_date'])->whereIn('id', $ids)->get(),
            (new Shift)->getMorphClass() => fn (array $ids) => Shift::query()->with('user:id,name')->whereIn('id', $ids)->get(['id', 'user_id', 'work_date']),
            (new Correction)->getMorphClass() => fn (array $ids) => Correction::query()->with('shift:id,user_id,work_date', 'shift.user:id,name')->whereIn('id', $ids)->get(),
            (new CalendarDay)->getMorphClass() => fn (array $ids) => CalendarDay::query()->whereIn('id', $ids)->get(),
            (new OpenedWorkday)->getMorphClass() => fn (array $ids) => OpenedWorkday::query()->with(['team:id,name', 'user:id,name'])->whereIn('id', $ids)->get(),
        ];

        $loaded = [];

        foreach ($logs->whereNotNull('subject_type')->groupBy('subject_type') as $type => $group) {
            if (! isset($with[$type])) {
                continue;
            }

            $loaded[$type] = $with[$type]($group->pluck('subject_id')->unique()->values()->all())->keyBy('id')->all();
        }

        return $loaded;
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array{0: list<int>, 1: list<int>}
     */
    private function idsInPayloads(Collection $logs): array
    {
        $people = [];
        $teams = [];

        foreach ($logs as $log) {
            foreach ([(array) $log->before, (array) $log->after] as $payload) {
                foreach (self::PERSON_KEYS as $key) {
                    if (is_int($payload[$key] ?? null)) {
                        $people[] = $payload[$key];
                    }
                }

                foreach (self::PERSON_LIST_KEYS as $key) {
                    foreach ((array) ($payload[$key] ?? []) as $id) {
                        if (is_int($id)) {
                            $people[] = $id;
                        }
                    }
                }

                foreach (self::TEAM_KEYS as $key) {
                    foreach ((array) ($payload[$key] ?? []) as $id) {
                        if (is_int($id)) {
                            $teams[] = $id;
                        }
                    }
                }

                if (($payload['scope_type'] ?? null) === 'user' && is_int($payload['scope_id'] ?? null)) {
                    $people[] = $payload['scope_id'];
                }

                if (($payload['scope_type'] ?? null) === 'team' && is_int($payload['scope_id'] ?? null)) {
                    $teams[] = $payload['scope_id'];
                }
            }
        }

        return [array_values(array_unique($people)), array_values(array_unique($teams))];
    }
}
