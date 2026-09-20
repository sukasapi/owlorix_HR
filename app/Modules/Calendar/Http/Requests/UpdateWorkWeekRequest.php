<?php

namespace App\Modules\Calendar\Http\Requests;

use App\Modules\Calendar\Models\WorkWeekDay;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkWeekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', WorkWeekDay::class);
    }

    /** Any combination is allowed, including no workdays at all. */
    public function rules(): array
    {
        return [
            'workdays' => ['present', 'array', 'max:7'],
            'workdays.*' => ['integer', 'between:1,7', 'distinct'],
        ];
    }

    public function attributes(): array
    {
        return [
            'workdays' => __('calendar::messages.attributes.workdays'),
            'workdays.*' => __('calendar::messages.attributes.workdays'),
        ];
    }

    /** @return list<int> sorted ISO weekdays */
    public function workdays(): array
    {
        $days = array_map('intval', $this->validated('workdays'));
        sort($days);

        return $days;
    }
}
