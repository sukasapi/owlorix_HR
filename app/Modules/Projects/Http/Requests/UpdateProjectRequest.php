<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Identity\Access\Permission;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Services\HourBudget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(Permission::ManageProjects);
    }

    public function rules(): array
    {
        $id = $this->route('project')->getKey();

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('projects', 'code')->ignore($id)],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'budget_hours' => HourBudget::rules($this->user()),
        ];
    }

    public function messages(): array
    {
        return [
            ...HourBudget::messages(),
            'name.required' => __('projects::messages.name_required'),
            'code.unique' => __('projects::messages.code_taken'),
        ];
    }
}
