<?php

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(Permission::ManageTeams);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('teams', 'name')->ignore($this->team()->id)],
            'lead_user_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => TeamMessages::get('name_taken')];
    }

    /** A Team Lead must be a member of the team and hold the team_lead role. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $leadId = $this->input('lead_user_id');

                if ($leadId === null || $validator->errors()->has('lead_user_id')) {
                    return;
                }

                $lead = $this->team()->members()->whereKey((int) $leadId)->first();

                if (! $lead instanceof User) {
                    $validator->errors()->add('lead_user_id', TeamMessages::get('lead_not_member'));

                    return;
                }

                if (! $lead->hasRole(Role::TeamLead->value)) {
                    $validator->errors()->add('lead_user_id', TeamMessages::get('lead_without_role'));
                }
            },
        ];
    }

    private function team(): Team
    {
        return $this->route('team');
    }
}
