<?php

namespace App\Modules\Shared\Branding;

use App\Modules\Shared\Settings\Setting;
use InvalidArgumentException;

/**
 * App name, studio name, footer, and logo that Superadmin edits on Pengaturan aplikasi (docs/13). Stored as
 * `branding.*` rows in the `settings` table, defaults in config('owlorix.branding'). The logo file sits on the
 * private disk and is served by BrandLogoController, so it works on hosting without a storage symlink.
 */
class Branding
{
    public const DEFAULT_LOGO = '/owlorix-logo.png';

    /** @var array<string, mixed>|null */
    private ?array $overrides = null;

    public function get(string $key): mixed
    {
        $defaults = config('owlorix.branding');

        if (! array_key_exists($key, $defaults)) {
            throw new InvalidArgumentException("Unknown branding key [{$key}].");
        }

        $this->overrides ??= Setting::query()
            ->where('key', 'like', 'branding.%')
            ->pluck('value', 'key')
            ->mapWithKeys(fn ($value, string $k) => [substr($k, strlen('branding.')) => $value])
            ->all();

        return $this->overrides[$key] ?? $defaults[$key];
    }

    public function set(string $key, mixed $value, ?int $actorId = null): void
    {
        $this->get($key);

        Setting::query()->updateOrCreate(['key' => 'branding.'.$key], ['value' => $value, 'updated_by' => $actorId]);

        $this->overrides = null;
    }

    public function logoPath(): ?string
    {
        $path = $this->get('logo_path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** Uploaded logo through its route (versioned for the browser cache), or the bundled Owlorix logo. */
    public function logoUrl(): string
    {
        $path = $this->logoPath();

        return $path === null ? self::DEFAULT_LOGO : route('brand.logo', ['v' => substr(md5($path), 0, 8)], absolute: false);
    }

    /**
     * @return array{name: string, studio: string, footer_text: string, footer_link_label: string, footer_link_url: string, contact_email: string, logo_url: string, custom_logo: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => (string) $this->get('app_name'),
            'studio' => (string) $this->get('studio_name'),
            'footer_text' => (string) $this->get('footer_text'),
            'footer_link_label' => (string) $this->get('footer_link_label'),
            'footer_link_url' => (string) $this->get('footer_link_url'),
            'contact_email' => (string) $this->get('contact_email'),
            'logo_url' => $this->logoUrl(),
            'custom_logo' => $this->logoPath() !== null,
        ];
    }
}
