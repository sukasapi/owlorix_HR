<?php

namespace App\Modules\Shared\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Audit\Auditor;
use App\Modules\Shared\Branding\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan aplikasi: Superadmin changes the app name, studio name, footer, and logo (docs/13).
 * Each changed value writes its own audit entry, like Aturan.
 */
class AppSettingsController extends Controller
{
    public const LOGO_MAX_KB = 1024;

    private const TEXT_KEYS = ['app_name', 'studio_name', 'footer_text', 'footer_link_label', 'footer_link_url', 'contact_email'];

    public function edit(Branding $branding): Response
    {
        return Inertia::render('admin/app-settings/Edit', [
            'values' => collect(self::TEXT_KEYS)->mapWithKeys(fn (string $key) => [$key => (string) $branding->get($key)])->all(),
            'defaults' => collect(self::TEXT_KEYS)->mapWithKeys(fn (string $key) => [$key => (string) config("owlorix.branding.{$key}")])->all(),
            'logo_url' => $branding->logoUrl(),
            'custom_logo' => $branding->logoPath() !== null,
            'logo_max_kb' => self::LOGO_MAX_KB,
        ]);
    }

    public function update(Request $request, Branding $branding, Auditor $auditor): RedirectResponse
    {
        foreach (self::TEXT_KEYS as $key) {
            if (is_string($request->input($key))) {
                $request->merge([$key => trim($request->input($key))]);
            }
        }

        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:40'],
            'studio_name' => ['required', 'string', 'max:80'],
            'footer_text' => ['nullable', 'string', 'max:120'],
            'footer_link_label' => ['nullable', 'required_with:footer_link_url', 'string', 'max:60'],
            'footer_link_url' => ['nullable', 'required_with:footer_link_label', 'url:http,https', 'max:300'],
            'contact_email' => ['nullable', 'email', 'max:190'],
        ], [
            'footer_link_url.url' => __('shared::app_settings.url_format'),
            'footer_link_label.required_with' => __('shared::app_settings.link_pair'),
            'footer_link_url.required_with' => __('shared::app_settings.link_pair'),
        ]);

        DB::transaction(function () use ($data, $branding, $auditor, $request) {
            foreach (self::TEXT_KEYS as $key) {
                $before = (string) $branding->get($key);
                $next = (string) ($data[$key] ?? '');

                if ($before === $next) {
                    continue;
                }

                $branding->set($key, $next, $request->user()->id);
                $auditor->record('branding.updated', null, [$key => $before], [$key => $next]);
            }
        });

        return back()->with('status', __('shared::app_settings.saved'));
    }

    public function storeLogo(Request $request, Branding $branding, Auditor $auditor): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.self::LOGO_MAX_KB, 'dimensions:min_width=32,min_height=32,max_width=4000,max_height=4000'],
        ], [
            'logo.mimes' => __('shared::app_settings.logo_type'),
            'logo.image' => __('shared::app_settings.logo_type'),
            'logo.max' => __('shared::app_settings.logo_size', ['kb' => self::LOGO_MAX_KB]),
            'logo.dimensions' => __('shared::app_settings.logo_dimensions'),
        ]);

        $file = $request->file('logo');
        $path = $file->storeAs('branding', 'logo-'.Str::random(16).'.'.$file->extension(), 'local');
        $old = $branding->logoPath();

        $branding->set('logo_path', $path, $request->user()->id);
        $auditor->record('branding.logo_changed', null, ['logo' => $old], ['logo' => $path]);

        if ($old !== null) {
            Storage::disk('local')->delete($old);
        }

        return back()->with('status', __('shared::app_settings.logo_saved'));
    }

    public function destroyLogo(Request $request, Branding $branding, Auditor $auditor): RedirectResponse
    {
        $old = $branding->logoPath();

        if ($old !== null) {
            // The settings value column is not nullable; an empty path means the bundled logo
            $branding->set('logo_path', '', $request->user()->id);
            $auditor->record('branding.logo_changed', null, ['logo' => $old], ['logo' => null]);
            Storage::disk('local')->delete($old);
        }

        return back()->with('status', __('shared::app_settings.logo_reset'));
    }
}
