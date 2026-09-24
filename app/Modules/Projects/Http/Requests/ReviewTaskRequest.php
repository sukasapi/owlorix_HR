<?php

namespace App\Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'changes'])],
            // Evidence is reviewed per person: the submission being decided (docs/15 section 3)
            'submission_id' => ['required', 'integer'],
            'note' => ['nullable', 'required_if:decision,changes', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required_if' => __('projects::messages.changes_note_required'),
            'note.min' => __('projects::messages.note_min'),
        ];
    }
}
