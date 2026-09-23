<?php

namespace App\Modules\Leave\Http\Requests;

use App\Modules\Leave\Enums\LeaveDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Approve or reject from Persetujuan cuti or Admin cuti. DecideLeave checks who may and the note on rejection. */
class LeaveDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(LeaveDecision::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['note.max' => __('leave::messages.note_max')];
    }
}
