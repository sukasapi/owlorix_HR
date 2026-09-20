<?php

namespace App\Modules\Shared\Settings;

use InvalidArgumentException;

/**
 * Rule settings editable by Superadmin. Values in the `settings` table override config/owlorix.php.
 */
class Settings
{
    /** @var array<string, mixed>|null */
    private ?array $overrides = null;

    public function get(string $key): mixed
    {
        $defaults = config('owlorix.settings');

        if (! array_key_exists($key, $defaults)) {
            throw new InvalidArgumentException("Unknown setting [{$key}].");
        }

        $this->overrides ??= Setting::query()->pluck('value', 'key')->all();

        return $this->overrides[$key] ?? $defaults[$key];
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return collect(config('owlorix.settings'))
            ->mapWithKeys(fn ($default, string $key) => [$key => $this->get($key)])
            ->all();
    }

    public function set(string $key, mixed $value, ?int $actorId = null): void
    {
        $this->get($key);

        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $actorId]);

        $this->overrides = null;
    }
}
