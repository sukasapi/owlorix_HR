<?php

namespace App\Modules\Leave\Http\Requests;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add or edit a leave type on Admin cuti. */
class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim(preg_replace('/\s+/u', ' ', $this->input('name')) ?? '')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var LeaveType|null $type */
        $type = $this->route('leaveType');

        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('leave_types', 'name')->ignore($type?->id)],
            'counts_against_quota' => ['required', 'boolean'],
            'requires_note' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => __('leave::messages.type_name_required'),
            'name.unique' => __('leave::messages.type_name_taken'),
        ];
    }
}
