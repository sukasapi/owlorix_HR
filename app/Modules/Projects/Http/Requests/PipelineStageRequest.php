<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Identity\Access\Permission;
use App\Modules\Projects\Enums\PipelinePhase;
use App\Modules\Projects\Models\PipelineStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add a stage (name and phase) or edit one (name, active). A stage keeps its phase once made. */
class PipelineStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(Permission::ManagePipeline);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => preg_replace('/\s+/u', ' ', trim($this->input('name')))]);
        }
    }

    public function rules(): array
    {
        /** @var PipelineStage|null $stage */
        $stage = $this->route('stage');

        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('pipeline_stages', 'name')->ignore($stage?->id)],
            'phase' => $stage === null ? ['required', Rule::enum(PipelinePhase::class)] : ['prohibited'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('projects::messages.stage_name_required'),
            'name.unique' => __('projects::messages.stage_name_taken'),
        ];
    }
}
