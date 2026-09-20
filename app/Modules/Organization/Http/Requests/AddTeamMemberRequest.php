<?php

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Identity\Access\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(Permission::ManageTeams);
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
                Rule::unique('team_user', 'user_id')->where('team_id', $this->route('team')->id),
            ],
        ];
    }

    public function messages(): array
    {
        return ['user_id.unique' => TeamMessages::get('already_member')];
    }

    public function attributes(): array
    {
        return ['user_id' => app()->getLocale() === 'en' ? 'person' : 'orang'];
    }
}
