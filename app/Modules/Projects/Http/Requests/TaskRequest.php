<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Models\PipelineStage;
use App\Modules\Projects\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Task fields. Assignee and "evidence required" are applied only when a lead saves (the controller decides).
 * The pipeline stage can be set by anyone who may save the task, a proposer included.
 */
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
            'stage_id' => ['nullable', 'integer', Rule::exists('pipeline_stages', 'id')],
        ];
    }

    /** New stage values must be active; a task may keep a stage that was switched off after it was set. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $stageId = $this->input('stage_id');

            if ($validator->errors()->has('stage_id') || blank($stageId)) {
                return;
            }

            /** @var Task|null $task */
            $task = $this->route('task');

            if ($task !== null && (int) $task->stage_id === (int) $stageId) {
                return;
            }

            if (! PipelineStage::query()->whereKey($stageId)->where('is_active', true)->exists()) {
                $validator->errors()->add('stage_id', __('projects::messages.stage_inactive'));
            }
        }];
    }

    public function messages(): array
    {
        return [
            'title.required' => __('projects::messages.task_title_required'),
            'title.min' => __('projects::messages.task_title_required'),
        ];
    }
}
