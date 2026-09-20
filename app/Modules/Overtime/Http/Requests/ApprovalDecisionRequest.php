<?php

namespace App\Modules\Overtime\Http\Requests;

use App\Modules\Overtime\Enums\Decision;
use App\Modules\Overtime\Enums\OvertimeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One decision from the Persetujuan page. `seen_*` is what the approver was looking at, so a decision is never
 * applied to a request someone else decided meanwhile or whose minutes changed (3.4.5).
 */
class ApprovalDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(Decision::class)],
            'note' => ['nullable', 'string', 'max:2000'],
            'seen_status' => ['required', Rule::enum(OvertimeStatus::class)],
            'seen_minutes' => ['required', 'integer', 'min:0'],
            'seen_decision_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.max' => 'Catatan paling panjang 2000 karakter.',
        ];
    }
}
