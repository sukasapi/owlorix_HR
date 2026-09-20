<?php

namespace App\Modules\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Calendar\Http\Requests\UpdateWorkWeekRequest;
use App\Modules\Calendar\Models\WorkWeekDay;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class WorkWeekController extends Controller
{
    public function update(UpdateWorkWeekRequest $request, Auditor $auditor): RedirectResponse
    {
        $before = $this->workdays();
        $after = $request->workdays();

        if ($before === $after) {
            return back()->with('status', __('calendar::messages.work_week_unchanged'));
        }

        DB::transaction(function () use ($request, $auditor, $before, $after) {
            foreach (range(1, 7) as $weekday) {
                WorkWeekDay::query()->updateOrCreate(
                    ['weekday' => $weekday],
                    ['is_workday' => in_array($weekday, $after, true), 'updated_by' => $request->user()->getKey()],
                );
            }

            $auditor->record('calendar.work_week.updated', null, ['workdays' => $before], ['workdays' => $after]);
        });

        CalendarDatesChanged::dispatch([], null, true);

        return back()->with('status', __('calendar::messages.work_week_saved'));
    }

    /** @return list<int> */
    private function workdays(): array
    {
        return WorkWeekDay::query()->where('is_workday', true)->orderBy('weekday')->pluck('weekday')->map(fn ($d) => (int) $d)->all();
    }
}
