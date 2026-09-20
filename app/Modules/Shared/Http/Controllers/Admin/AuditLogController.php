<?php

namespace App\Modules\Shared\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Models\Correction;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Models\OvertimeRequest;
use App\Modules\Shared\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Log audit: every recorded change, newest first, filtered by action group, actor, the person it concerns, and
 * studio dates (Asia/Jakarta). Read-only.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 30;

    public function __invoke(Request $request, AuditLogEntries $entries): Response
    {
        $filters = $this->filters($request);

        $page = AuditLog::query()
            ->with('actor:id,name,username')
            ->when($filters['group'] !== '', fn (Builder $q) => $q->where('action', 'like', addcslashes($filters['group'], '%_\\').'.%'))
            ->when($filters['actor'] !== null, fn (Builder $q) => $q->where('actor_id', $filters['actor']))
            ->when($filters['person'] !== null, fn (Builder $q) => $this->concerning($q, $filters['person']))
            ->when($filters['from'] !== null, fn (Builder $q) => $q->where('created_at', '>=', Time::db(CarbonImmutable::parse($filters['from'], Time::zone())->startOfDay())))
            ->when($filters['until'] !== null, fn (Builder $q) => $q->where('created_at', '<', Time::db(CarbonImmutable::parse($filters['until'], Time::zone())->addDay()->startOfDay())))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $presented = $entries->present($page->getCollection(), $request->user());

        return Inertia::render('admin/audit/Index', [
            'entries' => [
                'data' => $presented['rows'],
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
            'names' => ['people' => (object) $presented['people'], 'teams' => (object) $presented['teams']],
            'filters' => $filters,
            'options' => [
                'groups' => AuditLog::query()
                    ->selectRaw("distinct substring_index(action, '.', 1) as action_group")
                    ->orderBy('action_group')
                    ->pluck('action_group')
                    ->all(),
                'actors' => User::withTrashed()
                    ->whereIn('id', AuditLog::query()->whereNotNull('actor_id')->select('actor_id'))
                    ->orderBy('name')
                    ->get(['id', 'name', 'username']),
                'people' => User::withTrashed()->orderBy('name')->get(['id', 'name', 'username']),
            ],
        ]);
    }

    /** Entries about one person: their account, their shifts, overtime and corrections, their opened days, team changes naming them. */
    private function concerning(Builder $query, int $personId): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $w) => $w->where('subject_type', (new User)->getMorphClass())->where('subject_id', $personId))
            ->orWhere(fn (Builder $w) => $w->where('subject_type', (new Shift)->getMorphClass())
                ->whereIn('subject_id', Shift::query()->where('user_id', $personId)->select('id')))
            ->orWhere(fn (Builder $w) => $w->where('subject_type', (new OvertimeRequest)->getMorphClass())
                ->whereIn('subject_id', OvertimeRequest::query()->where('user_id', $personId)->select('id')))
            ->orWhere(fn (Builder $w) => $w->where('subject_type', (new Correction)->getMorphClass())
                ->whereIn('subject_id', Correction::query()->whereIn('shift_id', Shift::query()->where('user_id', $personId)->select('id'))->select('id')))
            ->orWhere(fn (Builder $w) => $w->where('subject_type', (new OpenedWorkday)->getMorphClass())
                ->whereIn('subject_id', OpenedWorkday::query()->where('scope_type', 'user')->where('scope_id', $personId)->select('id')))
            ->orWhere('before->user_id', $personId)
            ->orWhere('after->user_id', $personId)
            ->orWhere('after->person_id', $personId));
    }

    /** @return array{group: string, actor: ?int, person: ?int, from: ?string, until: ?string} */
    private function filters(Request $request): array
    {
        $group = $request->query('grup', '');
        $date = fn (mixed $value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
        $id = fn (mixed $value) => is_string($value) && ctype_digit($value) && strlen($value) < 19 ? (int) $value : null;

        $from = $date($request->query('dari'));
        $until = $date($request->query('sampai'));

        if ($from !== null && $until !== null && $from > $until) {
            [$from, $until] = [$until, $from];
        }

        return [
            'group' => is_string($group) && preg_match('/^[a-z_]{1,40}$/', $group) === 1 ? $group : '',
            'actor' => $id($request->query('pelaku')),
            'person' => $id($request->query('orang')),
            'from' => $from,
            'until' => $until,
        ];
    }
}
