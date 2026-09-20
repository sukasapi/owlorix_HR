<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Access\Role;
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
            'roles.required' => PeopleMessages::get('roles_required'),
            'roles.min' => PeopleMessages::get('roles_required'),
        ];
    }
}
