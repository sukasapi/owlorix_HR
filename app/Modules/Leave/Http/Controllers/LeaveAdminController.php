<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Http\Requests\LeaveTypeRequest;
use App\Modules\Leave\Models\LeaveQuota;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalance;
use App\Modules\Leave\Services\LeavePresenter;
use App\Modules\Shared\Audit\Auditor;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin cuti (Superadmin, `leave.manage`): every request with filters (`?status=&orang=&bulan=YYYY-MM&page=N`),
 * the yearly quota per person (`?tahun=YYYY`), and the leave types. Decisions and cancellations go through the
 * same routes as Persetujuan cuti and Cuti.
 */
class LeaveAdminController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request, LeavePresenter $presenter, LeaveBalance $balance, Settings $settings): Response
    {
        $viewer = $request->user();
        $now = CarbonImmutable::now();
        $thisYear = (int) substr(Time::workDate($now), 0, 4);

        $status = $request->query('status');
        $month = $request->query('bulan');
        $person = $request->query('orang');
        $year = $request->query('tahun');

        $filters = [
            'status' => is_string($status) && LeaveStatus::tryFrom($status) !== null ? $status : 'all',
            'bulan' => is_string($month) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) && (int) $m[1] >= 2000 && (int) $m[1] <= 2999 ? $month : null,
            'orang' => is_string($person) && ctype_digit($person) && strlen($person) <= 18 ? (int) $person : null,
        ];
        $quotaYear = is_string($year) && preg_match('/^\d{4}$/', $year) && abs((int) $year - $thisYear) <= 5 ? (int) $year : $thisYear;

        $query = $presenter->withDetails(LeaveRequest::query())
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['orang'] !== null, fn ($q) => $q->where('user_id', $filters['orang']))
            ->when($filters['bulan'] !== null, function ($q) use ($filters) {
                $first = CarbonImmutable::parse($filters['bulan'].'-01');
                $q->overlapping($first->toDateString(), $first->endOfMonth()->toDateString());
            })
            // Pending first, oldest first, so the request waiting longest is on top
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [LeaveStatus::Pending->value])
            ->orderByRaw('CASE WHEN status = ? THEN created_at END ASC', [LeaveStatus::Pending->value])
            ->orderByDesc('start_date')
            ->orderByDesc('id');

        $page = $query->paginate(self::PER_PAGE)->withQueryString();
        $open = $page->getCollection()->filter(fn (LeaveRequest $r) => $r->status === LeaveStatus::Pending && $r->type?->counts_against_quota);
        $openBalances = $open->groupBy(fn (LeaveRequest $r) => $r->year())
            ->map(fn ($group, int $year) => $balance->forMany($group->pluck('user_id')->unique()->values()->all(), $year));
        $page->setCollection($page->getCollection()->map(fn (LeaveRequest $r) => [
            ...$presenter->item($r, $viewer, $now),
            'balance' => $openBalances[$r->year()][$r->user_id] ?? null,
        ]));

        $people = User::query()
            ->active()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'username', 'status']);

        // People who left still appear in the filter when they have requests
        $withRequests = User::withTrashed()
            ->whereIn('id', LeaveRequest::query()->select('user_id')->distinct())
            ->whereNotIn('id', $people->modelKeys())
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'status']);

        $balances = $balance->forMany($people->modelKeys(), $quotaYear);

        return Inertia::render('admin/leave/Index', [
            'filters' => $filters,
            'requests' => $page,
            'pending_count' => LeaveRequest::query()->where('status', LeaveStatus::Pending)->count(),
            'people' => $people->concat($withRequests)
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'status' => $u->status?->value])
                ->values()->all(),
            'quota' => [
                'year' => $quotaYear,
                'this_year' => $thisYear,
                'default_days' => $settings->int('leave.annual_quota_days'),
                'rows' => $people->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'initials' => $u->initials(),
                    ...$balances[$u->id],
                ])->values()->all(),
            ],
            'types' => LeaveType::query()->ordered()->withCount('requests')->get()
                ->map(fn (LeaveType $t) => [...$t->toSummary(), 'requests_count' => $t->requests_count])
                ->values()->all(),
        ]);
    }

    public function updateQuota(Request $request, User $user, Auditor $auditor, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2999'],
            'days' => ['required', 'integer', 'min:0', 'max:365'],
        ], [
            'year.*' => __('leave::messages.quota_year'),
            'days.*' => __('leave::messages.quota_days'),
        ]);

        $year = (int) $data['year'];
        $days = (int) $data['days'];

        DB::transaction(function () use ($user, $year, $days, $auditor, $settings) {
            $row = LeaveQuota::query()->where('user_id', $user->id)->where('year', $year)->lockForUpdate()->first();
            $before = $row?->days ?? $settings->int('leave.annual_quota_days');

            LeaveQuota::query()->updateOrCreate(['user_id' => $user->id, 'year' => $year], ['days' => $days]);

            $auditor->record('leave.quota_changed', $user,
                ['year' => $year, 'days' => $before, 'custom' => $row !== null],
                ['year' => $year, 'days' => $days],
            );
        });

        return back()->with('status', __('leave::messages.quota_saved', ['name' => $user->name, 'year' => $year, 'days' => $days]));
    }

    public function storeType(LeaveTypeRequest $form, Auditor $auditor): RedirectResponse
    {
        $data = $form->validated();

        $type = DB::transaction(function () use ($data, $auditor) {
            $type = LeaveType::query()->create([
                'code' => $this->uniqueCode($data['name']),
                'name' => $data['name'],
                'counts_against_quota' => (bool) $data['counts_against_quota'],
                'requires_note' => (bool) $data['requires_note'],
                'is_active' => (bool) $data['is_active'],
                'sort' => (int) LeaveType::query()->max('sort') + 10,
            ]);

            $auditor->record('leave.type_created', $type, null, $type->only(['code', 'name', 'counts_against_quota', 'requires_note', 'is_active']));

            return $type;
        });

        return back()->with('status', __('leave::messages.type_created', ['name' => $type->name]));
    }

    public function updateType(LeaveTypeRequest $form, LeaveType $leaveType, Auditor $auditor): RedirectResponse
    {
        $fields = ['name', 'counts_against_quota', 'requires_note', 'is_active'];
        $before = $leaveType->only($fields);

        $leaveType->fill([
            'name' => $form->validated('name'),
            'counts_against_quota' => (bool) $form->validated('counts_against_quota'),
            'requires_note' => (bool) $form->validated('requires_note'),
            'is_active' => (bool) $form->validated('is_active'),
        ]);

        if ($leaveType->isDirty()) {
            $changed = array_keys($leaveType->getDirty());
            $leaveType->save();
            $auditor->record('leave.type_updated', $leaveType, array_intersect_key($before, array_flip($changed)), $leaveType->only($changed));
        }

        return back()->with('status', __('leave::messages.type_updated', ['name' => $leaveType->name]));
    }

    private function uniqueCode(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 32, '') ?: 'type';
        $code = $base;
        $n = 2;

        while (LeaveType::query()->where('code', $code)->exists()) {
            $code = $base.'_'.$n++;
        }

        return $code;
    }
}
