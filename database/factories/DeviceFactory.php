<?php

namespace Database\Factories;

use App\Modules\Identity\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        $hostname = 'PC-ANIM-'.str_pad((string) fake()->unique()->numberBetween(1, 999), 2, '0', STR_PAD_LEFT);

        return [
            'id' => $hostname.':'.Str::lower(Str::random(4)),
            'hostname' => $hostname,
            'app_version' => '0.1.0',
            'last_seen_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
