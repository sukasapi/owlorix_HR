<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Identity\Access\Permission;
use App\Modules\Projects\Enums\LinkCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(Permission::ManageProjects);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'label' => is_string($this->label) && trim($this->label) !== '' ? trim($this->label) : null,
            'url' => is_string($this->url) ? trim($this->url) : $this->url,
            'note' => is_string($this->note) && trim($this->note) !== '' ? trim($this->note) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(LinkCategory::class)],
            'label' => ['nullable', 'required_if:category,'.LinkCategory::Other->value, 'string', 'max:80'],
            // https only: the address is rendered as a link, so javascript: and data: never reach the page
            'url' => ['required', 'string', 'max:500', 'url:https'],
            'note' => ['nullable', 'string', 'max:200'],
            'managers_only' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'label.required_if' => __('projects::messages.link_label_required'),
            'url.required' => __('projects::messages.link_url_required'),
            'url.url' => __('projects::messages.link_url_https'),
        ];
    }
}
