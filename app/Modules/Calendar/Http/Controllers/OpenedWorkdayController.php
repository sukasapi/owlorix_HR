<?php

namespace App\Modules\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Calendar\Http\Requests\OpenWorkdayRequest;
use App\Modules\Calendar\Models\OpenedWorkday;
use App\Modules\Calendar\Services\DateLabel;
use App\Modules\Calendar\Services\WorkdayOpening;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Management opens an extra workday for a team or a person, and closes it again (docs/02-attendance-rules.md 3.2.2).
 */
class OpenedWorkdayController extends Controller
{
    public function store(OpenWorkdayRequest $request, WorkdayOpening $opening, Auditor $auditor): RedirectResponse
    {
        $date = $request->validated('date');
        $scope = $request->scope();
        $scopeId = $request->scopeId();

        Gate::authorize('create', [OpenedWorkday::class, $scope, $scopeId]);

        $labels = ['date' => DateLabel::long($date), 'scope' => $opening->scopeName($scope, $scopeId)];

        // The unique key covers soft-deleted rows, so a closed day for this scope is restored instead of inserted.
        $existing = OpenedWorkday::withTrashed()
            ->whereDate('date', $date)
            ->where('scope_type', $scope)
            ->where('scope_id', $scopeId)
            ->first();

        if ($existing && ! $existing->trashed()) {
            throw ValidationException::withMessages(['date' => __('calendar::messages.already_opened', $labels)]);
        }

        if ($opening->isAlreadyWorkday($date, $scope, $scopeId)) {
            $key = $scope === OpenedScope::Team ? 'already_workday_team' : 'already_workday_user';

            throw ValidationException::withMessages(['date' => __("calendar::messages.{$key}", $labels)]);
        }

        $attributes = [
            'opened_by' => $request->user()->getKey(),
            'note' => $request->validated('note'),
        ];

        DB::transaction(function () use ($existing, $attributes, $date, $scope, $scopeId, $opening, $auditor) {
            if ($existing) {
                $before = [...$opening->snapshot($existing), 'closed_at' => $existing->deleted_at?->toIso8601String()];
                $existing->fill($attributes);
                $existing->restore();

                $auditor->record('calendar.opened_workday.reopened', $existing, $before, $opening->snapshot($existing));

                return;
            }

            $opened = OpenedWorkday::query()->create([
                ...$attributes,
                'date' => $date,
                'scope_type' => $scope,
                'scope_id' => $scopeId,
            ]);

            $auditor->record('calendar.opened_workday.opened', $opened, null, $opening->snapshot($opened));
        });

        CalendarDatesChanged::dispatch([$date], $opening->affectedUserIds($scope, $scopeId));

        return back()->with('status', __('calendar::messages.opened', $labels));
    }

    public function destroy(OpenedWorkday $openedWorkday, WorkdayOpening $opening, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('delete', $openedWorkday);

        $before = $opening->snapshot($openedWorkday);
        $scopeId = (int) $openedWorkday->scope_id;

        DB::transaction(function () use ($openedWorkday, $auditor, $before) {
            $openedWorkday->delete();
            $auditor->record('calendar.opened_workday.closed', $openedWorkday, $before, null);
        });

        CalendarDatesChanged::dispatch([$before['date']], $opening->affectedUserIds($openedWorkday->scope_type, $scopeId));

        return back()->with('status', __('calendar::messages.closed', [
            'date' => DateLabel::long($before['date']),
            'scope' => $opening->scopeName($openedWorkday->scope_type, $scopeId),
        ]));
    }
}
