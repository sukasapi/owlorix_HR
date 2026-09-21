<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(Permission::ManageUsers);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => trim($this->input('username'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')],
            'email' => ['nullable', 'string', 'email', 'max:190', Rule::unique('users', 'email')],
            'employee_code' => ['nullable', 'string', 'max:30', Rule::unique('users', 'employee_code')],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::enum(Role::class)],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['integer', 'distinct', Rule::exists('teams', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => PeopleMessages::get('username_format'),
            'username.unique' => PeopleMessages::get('username_taken'),
            'email.unique' => PeopleMessages::get('email_taken'),
            'employee_code.unique' => PeopleMessages::get('employee_code_taken'),
            'employment_type.required' => PeopleMessages::get('employment_type_required'),
            'roles.required' => PeopleMessages::get('roles_required'),
            'roles.min' => PeopleMessages::get('roles_required'),
        ];
    }
}
