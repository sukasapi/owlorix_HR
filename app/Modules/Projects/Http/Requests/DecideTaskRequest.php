<?php

namespace App\Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'required_if:decision,reject', 'string', 'min:10', 'max:2000'],
            // Approving may replace or add assignees; without the field the proposer stays the only one
            ...TaskRequest::assigneeRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'note.required_if' => __('projects::messages.reject_note_required'),
            'note.min' => __('projects::messages.note_min'),
            ...TaskRequest::assigneeMessages(),
        ];
    }
}
