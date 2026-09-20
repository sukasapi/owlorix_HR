<?php

namespace App\Modules\Shared\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Audit\Auditor;
use App\Modules\Shared\Settings\Setting;
use App\Modules\Shared\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aturan: Superadmin edits the rule values of docs/02. Only changed keys are saved, each with its own audit entry.
 * Errors are codes (`integer`, `range`, `upload_below_local`, `repeat_above_close`), translated by the page.
 */
class SettingsController extends Controller
{
    public function edit(Settings $settings): Response
    {
        $defaults = config('owlorix.settings');
        $rows = Setting::query()->get()->keyBy('key');
        $editors = User::withTrashed()->whereIn('id', $rows->pluck('updated_by')->filter()->unique())->pluck('name', 'id');

        $fields = array_map(function (array $field) use ($settings, $defaults, $rows, $editors) {
            $row = $rows->get($field['key']);

            return [
                ...$field,
                'value' => $this->cast($field, $settings->get($field['key'])),
                'default' => $this->cast($field, $defaults[$field['key']]),
                'changed_by' => $row?->updated_by !== null ? ($editors[$row->updated_by] ?? null) : null,
                'changed_at' => Time::iso($row?->updated_at),
            ];
        }, SettingsFields::all());

        return Inertia::render('admin/settings/Edit', [
            'fields' => $fields,
            'timezone' => (string) $settings->get('app.timezone'),
        ]);
    }

    public function update(Request $request, Settings $settings, Auditor $auditor): RedirectResponse
    {
        $input = $request->input('values');

        if (! is_array($input)) {
            throw ValidationException::withMessages(['values' => 'integer']);
        }

        $fields = collect(SettingsFields::all())->keyBy('key');
        $next = [];
        $errors = [];

        foreach ($fields as $key => $field) {
            $current = $this->cast($field, $settings->get($key));

            if (! array_key_exists($key, $input)) {
                $next[$key] = $current;

                continue;
            }

            $value = $this->parse($field, $input[$key]);

            if ($value === null) {
                $errors[$key] = $field['type'] === 'boolean' ? 'boolean' : 'integer';
            } elseif ($field['type'] === 'integer' && ($value < $field['min'] || $value > $field['max'])) {
                $errors[$key] = 'range';
            }

            $next[$key] = $value ?? $current;
        }

        // Values that only make sense together
        if (! isset($errors['sync.heartbeat_upload_seconds']) && $next['sync.heartbeat_upload_seconds'] < $next['sync.heartbeat_local_seconds']) {
            $errors['sync.heartbeat_upload_seconds'] = 'upload_below_local';
        }

        if (! isset($errors['attendance.prompt_repeat_minutes']) && $next['attendance.prompt_repeat_minutes'] > $next['attendance.prompt_auto_close_minutes']) {
            $errors['attendance.prompt_repeat_minutes'] = 'repeat_above_close';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($fields, $next, $settings, $auditor, $request) {
            foreach ($fields as $key => $field) {
                $before = $this->cast($field, $settings->get($key));

                if ($before === $next[$key]) {
                    continue;
                }

                $settings->set($key, $next[$key], $request->user()->id);
                $auditor->record('settings.updated', null, [$key => $before], [$key => $next[$key]]);
            }
        });

        return back();
    }

    /** @param array{type: string} $field */
    private function cast(array $field, mixed $value): int|bool
    {
        return $field['type'] === 'boolean' ? (bool) $value : (int) $value;
    }

    /**
     * A submitted value as int or bool, or null when it is not one.
     *
     * @param  array{type: string}  $field
     */
    private function parse(array $field, mixed $value): int|bool|null
    {
        if ($field['type'] === 'boolean') {
            return is_bool($value) ? $value : (in_array($value, [0, 1, '0', '1'], true) ? (bool) (int) $value : null);
        }

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\s*\d{1,6}\s*$/', $value) === 1 ? (int) trim($value) : null;
    }
}
