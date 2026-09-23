<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Profil: fields a person fills in about themselves. Email, username, roles, and employment type stay with Superadmin.
 */
class UpdateProfileRequest extends FormRequest
{
    public const FIELDS = [
        'name', 'nickname', 'job_title', 'phone', 'birth_place', 'birth_date', 'gender', 'address',
        'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone', 'bio', 'portfolio_url',
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (self::FIELDS as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $trimmed[$field] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
            }
        }

        $this->merge($trimmed);
    }

    public function rules(): array
    {
        $phone = ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ().-]{6,30}$/'];

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'nickname' => ['nullable', 'string', 'max:60'],
            'job_title' => ['nullable', 'string', 'max:80'],
            'phone' => $phone,
            'birth_place' => ['nullable', 'string', 'max:80'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:60'],
            'emergency_contact_phone' => $phone,
            'bio' => ['nullable', 'string', 'max:500'],
            'portfolio_url' => ['nullable', 'url:http,https', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => __('identity::profile.phone_format'),
            'emergency_contact_phone.regex' => __('identity::profile.phone_format'),
            'portfolio_url.url' => __('identity::profile.url_format'),
            'birth_date.before' => __('identity::profile.birth_date_past'),
        ];
    }
}
