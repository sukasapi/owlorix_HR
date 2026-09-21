<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Identity\Access\Permission;
use App\Modules\Projects\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateActivityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        $log = $this->route('activity');

        return (bool) $this->user()?->hasPermission(Permission::LogActivity)
            && $log
            && (int) $log->user_id === (int) $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'project_id' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::Planned->value]),
            ],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after:started_at'],
            'evidence_url' => ['required', 'url', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.min' => __('projects::messages.description_min'),
            'ended_at.after' => __('projects::messages.ended_after_start'),
            'evidence_url.url' => __('projects::messages.evidence_url'),
        ];
    }
}
