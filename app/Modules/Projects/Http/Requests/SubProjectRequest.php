<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Identity\Access\Permission;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Services\HourBudget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create or edit a sub project. The controller checks that the lead manages projects. */
class SubProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(Permission::ManageProjects);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'budget_hours' => HourBudget::rules($this->user()),
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'lead_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            ...HourBudget::messages(),
            'name.required' => __('projects::messages.sub_name_required'),
        ];
    }
}
