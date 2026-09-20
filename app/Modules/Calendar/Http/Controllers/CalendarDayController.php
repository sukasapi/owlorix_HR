<?php

namespace App\Modules\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\Events\CalendarDatesChanged;
use App\Modules\Calendar\Http\Requests\SaveCalendarDayRequest;
use App\Modules\Calendar\Models\CalendarDay;
use App\Modules\Calendar\Services\DateLabel;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Holidays, studio days off, and studio-wide workdays, managed by Superadmin.
 */
class CalendarDayController extends Controller
{
    public function store(SaveCalendarDayRequest $request, Auditor $auditor): RedirectResponse
    {
        $day = DB::transaction(function () use ($request, $auditor) {
            $day = CalendarDay::query()->create([
                ...$request->validated(),
                'created_by' => $request->user()->getKey(),
            ]);

            $auditor->record('calendar.day.created', $day, null, $this->snapshot($day));

            return $day;
        });

        CalendarDatesChanged::dispatch([$day->date->toDateString()]);

        return back()->with('status', __('calendar::messages.entry_created', [
            'name' => $day->name,
            'date' => DateLabel::long($day->date->toDateString()),
        ]));
    }

    public function update(SaveCalendarDayRequest $request, CalendarDay $calendarDay, Auditor $auditor): RedirectResponse
    {
        $before = $this->snapshot($calendarDay);
        $calendarDay->fill($request->validated());
        $after = $this->snapshot($calendarDay);

        if ($before === $after) {
            return back()->with('status', __('calendar::messages.entry_unchanged'));
        }

        DB::transaction(function () use ($calendarDay, $auditor, $before, $after) {
            $calendarDay->save();
            $auditor->record('calendar.day.updated', $calendarDay, $before, $after);
        });

        CalendarDatesChanged::dispatch(array_values(array_unique([$before['date'], $after['date']])));

        return back()->with('status', __('calendar::messages.entry_updated', [
            'name' => $after['name'],
            'date' => DateLabel::long($after['date']),
        ]));
    }

    public function destroy(CalendarDay $calendarDay, Auditor $auditor): RedirectResponse
    {
        Gate::authorize('delete', $calendarDay);

        $before = $this->snapshot($calendarDay);

        DB::transaction(function () use ($calendarDay, $auditor, $before) {
            $auditor->record('calendar.day.deleted', $calendarDay, $before, null);
            $calendarDay->delete();
        });

        CalendarDatesChanged::dispatch([$before['date']]);

        return back()->with('status', __('calendar::messages.entry_deleted', [
            'name' => $before['name'],
            'date' => DateLabel::long($before['date']),
        ]));
    }

    /** @return array{date: string, type: string, name: string} */
    private function snapshot(CalendarDay $day): array
    {
        return [
            'date' => $day->date->toDateString(),
            'type' => $day->type->value,
            'name' => $day->name,
        ];
    }
}
