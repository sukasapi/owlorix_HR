<?php

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Identity\Access\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(Permission::ManageTeams);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('teams', 'name')],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => TeamMessages::get('name_taken')];
    }
}
