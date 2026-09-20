<?php

namespace App\Modules\Calendar\Http\Requests;

use App\Modules\Calendar\Enums\CalendarDayType;
use App\Modules\Calendar\Models\CalendarDay;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or edit a calendar entry. One entry per date; a date in the past is allowed (Attendance recalculates).
 */
class SaveCalendarDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $day = $this->route('calendarDay');

        return $day instanceof CalendarDay
            ? $this->user()->can('update', $day)
            : $this->user()->can('create', CalendarDay::class);
    }

    public function rules(): array
    {
        $current = $this->route('calendarDay');

        return [
            'date' => ['bail', 'required', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail) use ($current) {
                $existing = CalendarDay::query()->whereDate('date', $value)->first();

                if ($existing && (! $current instanceof CalendarDay || $existing->isNot($current))) {
                    $fail(__('calendar::messages.entry_exists', ['name' => $existing->name]));
                }
            }],
            'type' => ['required', Rule::enum(CalendarDayType::class)],
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return [
            'date' => __('calendar::messages.attributes.date'),
            'type' => __('calendar::messages.attributes.type'),
            'name' => __('calendar::messages.attributes.name'),
        ];
    }
}
