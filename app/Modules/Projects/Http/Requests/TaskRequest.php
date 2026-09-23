<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Task fields. Assignee and "evidence required" are applied only when a lead saves (the controller decides). */
class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'estimate_hours' => ['nullable', 'numeric', 'min:0.25', 'max:999'],
            'evidence_required' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => __('projects::messages.task_title_required'),
            'title.min' => __('projects::messages.task_title_required'),
        ];
    }
}
