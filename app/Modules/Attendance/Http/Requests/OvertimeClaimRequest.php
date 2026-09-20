<?php

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OvertimeClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // 3.3.4: a reason has at least 10 characters
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'work_report' => ['required', 'string', 'max:5000'],
            // When the person actually stopped working
            'ended_at' => ['required', 'date'],
        ];
    }
}
