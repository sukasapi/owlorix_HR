<?php

namespace App\Modules\Overtime\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Bulk approval of selected pending requests, each with the minutes the approver saw. */
class ApprovalBulkRequest extends FormRequest
{
    public const MAX_ITEMS = 100;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.seen_minutes' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required' => 'Pilih minimal satu lembur.',
            'items.min' => 'Pilih minimal satu lembur.',
            'items.max' => 'Paling banyak '.self::MAX_ITEMS.' lembur sekaligus.',
        ];
    }
}
