<?php

namespace App\Modules\Calendar\Http\Requests;

use App\Modules\Calendar\Enums\OpenedScope;
use App\Modules\Identity\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the input only. Whether this person may open a day for the chosen team or person
 * is decided by OpenedWorkdayPolicy in the controller, once the scope is known to be valid.
 */
class OpenWorkdayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $scopeExists = match (OpenedScope::tryFrom((string) $this->input('scope_type'))) {
            OpenedScope::Team => Rule::exists('teams', 'id'),
            OpenedScope::User => Rule::exists('users', 'id')->whereNull('deleted_at')->where('status', UserStatus::Active->value),
            default => null,
        };

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'scope_type' => ['required', Rule::enum(OpenedScope::class)],
            'scope_id' => array_values(array_filter(['bail', 'required', 'integer', $scopeExists])),
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'date' => __('calendar::messages.attributes.date'),
            'scope_type' => __('calendar::messages.attributes.scope_type'),
            'scope_id' => __('calendar::messages.attributes.scope_id'),
            'note' => __('calendar::messages.attributes.note'),
        ];
    }

    public function scope(): OpenedScope
    {
        return OpenedScope::from($this->validated('scope_type'));
    }

    public function scopeId(): int
    {
        return (int) $this->validated('scope_id');
    }
}
