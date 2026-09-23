<?php

namespace App\Modules\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new leave request from Cuti. Only the shape is checked here; RequestLeave checks the type, dates, overlap, and
 * quota against the database.
 */
class StoreLeaveRequest extends FormRequest
{
    public const ATTACHMENT_MAX_KB = 5120;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason')) && trim($this->input('reason')) === '') {
            $this->merge(['reason' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:'.self::ATTACHMENT_MAX_KB],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'leave_type_id.required' => __('leave::messages.type_required'),
            'leave_type_id.exists' => __('leave::messages.type_required'),
            'start_date.required' => __('leave::messages.start_required'),
            'start_date.date_format' => __('leave::messages.start_required'),
            'end_date.required' => __('leave::messages.end_required'),
            'end_date.date_format' => __('leave::messages.end_required'),
            'reason.max' => __('leave::messages.reason_max'),
            'attachment.mimes' => __('leave::messages.attachment_type'),
            'attachment.mimetypes' => __('leave::messages.attachment_type'),
            'attachment.file' => __('leave::messages.attachment_type'),
            'attachment.max' => __('leave::messages.attachment_size', ['mb' => self::ATTACHMENT_MAX_KB / 1024]),
        ];
    }
}
