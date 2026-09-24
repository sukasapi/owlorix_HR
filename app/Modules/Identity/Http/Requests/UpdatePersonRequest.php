<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\EmploymentType;
use App\Modules\Identity\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(Permission::ManageUsers);
    }

    public function rules(): array
    {
        $id = $this->route('user')->getKey();

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'email', 'max:190', Rule::unique('users', 'email')->ignore($id)],
            'employee_code' => ['nullable', 'string', 'max:30', Rule::unique('users', 'employee_code')->ignore($id)],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            // Intern target per person (docs/02 3.12); empty uses the default on Aturan
            'intern_days_per_week' => ['nullable', 'integer', 'between:1,7'],
            'intern_hours_per_day' => ['nullable', 'numeric', 'min:0.5', 'max:12', 'multiple_of:0.25'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::enum(Role::class)],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['integer', 'distinct', Rule::exists('teams', 'id')],
            'status' => ['required', Rule::enum(UserStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => PeopleMessages::get('email_taken'),
            'employee_code.unique' => PeopleMessages::get('employee_code_taken'),
            'employment_type.required' => PeopleMessages::get('employment_type_required'),
            'roles.required' => PeopleMessages::get('roles_required'),
            'roles.min' => PeopleMessages::get('roles_required'),
        ];
    }
}
