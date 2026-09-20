<?php

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\CorrectionField;
use App\Modules\Attendance\Support\Time;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A correction from the Koreksi form: which shift, which time, the new time as an Asia/Jakarta date and clock time,
 * and why. The preview needs no reason yet; proposing or applying needs one of at least 10 characters.
 */
class CorrectionRequest extends FormRequest
{
    public const REASON_MIN = 10;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $preview = $this->routeIs('corrections.preview');

        return [
            'shift_id' => ['required', 'integer'],
            'field' => ['required', Rule::enum(CorrectionField::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'reason' => $preview ? ['nullable', 'string', 'max:2000'] : ['required', 'string', 'min:'.self::REASON_MIN, 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'shift_id.required' => __('attendance::messages.pick_shift'),
            'field.required' => __('attendance::messages.pick_field'),
            'date.*' => __('attendance::messages.pick_value_date'),
            'time.required' => __('attendance::messages.fill_time'),
            'time.date_format' => __('attendance::messages.time_format'),
            'reason.required' => __('attendance::messages.reason_required'),
            'reason.min' => __('attendance::messages.reason_min', ['min' => self::REASON_MIN]),
            'reason.max' => __('attendance::messages.reason_max'),
        ];
    }

    public function correctionField(): CorrectionField
    {
        return CorrectionField::from((string) $this->validated('field'));
    }

    /** The corrected time in UTC. */
    public function value(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $this->validated('date').' '.$this->validated('time'), Time::zone())
            ->startOfMinute()
            ->utc();
    }
}
