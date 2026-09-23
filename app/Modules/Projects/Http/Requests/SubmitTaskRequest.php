<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Evidence for review: a note, plus a link or a file when the task requires evidence. The optional time range is
 * used only when no timer session exists, so the work still reaches Log kerja.
 */
class SubmitTaskRequest extends FormRequest
{
    public const FILE_MAX_KB = 20480;

    public const FILE_TYPES = 'jpg,jpeg,png,webp,gif,pdf,mp4,mov,webm,zip';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['evidence_url', 'worked_from', 'worked_until'] as $field) {
            if (is_string($this->input($field)) && trim($this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:10', 'max:5000'],
            'evidence_url' => ['nullable', 'url:http,https', 'max:500'],
            'evidence_file' => ['nullable', 'file', 'mimes:'.self::FILE_TYPES, 'max:'.self::FILE_MAX_KB],
            'worked_from' => ['nullable', 'required_with:worked_until', 'date'],
            'worked_until' => ['nullable', 'required_with:worked_from', 'date', 'after:worked_from'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Task $task */
            $task = $this->route('task');

            if ($task->evidence_required && blank($this->input('evidence_url')) && ! $this->hasFile('evidence_file')) {
                $validator->errors()->add('evidence_url', __('projects::messages.evidence_needed'));
            }
        }];
    }

    public function messages(): array
    {
        return [
            'note.required' => __('projects::messages.note_min'),
            'note.min' => __('projects::messages.note_min'),
            'evidence_url.url' => __('projects::messages.evidence_url'),
            'evidence_file.mimes' => __('projects::messages.evidence_file_type'),
            'evidence_file.max' => __('projects::messages.evidence_file_size', ['mb' => self::FILE_MAX_KB / 1024]),
            'worked_until.after' => __('projects::messages.ended_after_start'),
        ];
    }
}
