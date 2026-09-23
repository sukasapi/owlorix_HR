<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Identity\Access\Permission;
use App\Modules\Projects\Enums\MilestoneKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(Permission::ManageProjects);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::enum(MilestoneKind::class)],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('projects::messages.milestone_name_required'),
            'due_date.required' => __('projects::messages.milestone_date_required'),
            'due_date.date_format' => __('projects::messages.milestone_date_required'),
        ];
    }
}
