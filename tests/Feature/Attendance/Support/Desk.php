<?php

namespace Tests\Feature\Attendance\Support;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The desktop app of one person on one PC, talking to the real API.
 * Times are Asia/Jakarta wall clock: "2026-09-14 09:00", or "09:00" on the Jakarta date the test clock is at.
 */
final class Desk
{
    public readonly Device $device;

    private string $token;

    private string $bootId;

    public function __construct(private readonly TestCase $test, public readonly User $user, string $hostname = 'PC-ANIM-07', ?Device $device = null)
    {
        $this->device = $device ?? Device::factory()->create(['id' => $hostname.':'.Str::lower(Str::random(4)), 'hostname' => $hostname]);
        $this->device->users()->syncWithoutDetaching([$user->id => ['last_online_sign_in_at' => now()->format('Y-m-d H:i:s.v')]]);
        $this->token = $user->createToken($this->device->id)->plainTextToken;
        $this->bootId = Str::lower(Str::random(8));
    }

    public static function for(TestCase $test, User $user, string $hostname = 'PC-ANIM-07'): self
    {
        return new self($test, $user, $hostname);
    }

    /** Another person signing in on this same PC. */
    public function samePcFor(User $user): self
    {
        return new self($this->test, $user, $this->device->hostname, $this->device);
    }

    public static function time(string $at): CarbonImmutable
    {
        if (str_contains($at, '-')) {
            return CarbonImmutable::parse($at, 'Asia/Jakarta')->utc();
        }

        $date = CarbonImmutable::now()->setTimezone('Asia/Jakarta')->toDateString();

        return CarbonImmutable::parse("{$date} {$at}", 'Asia/Jakarta')->utc();
    }

    public function at(string $at): self
    {
        $this->test->travelTo(self::time($at));

        return $this;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function event(string $type, array $payload = [], ?string $at = null, array $overrides = []): array
    {
        $occurred = $at !== null ? self::time($at) : CarbonImmutable::now();

        return array_merge([
            'id' => (string) Str::uuid7(),
            'type' => $type,
            'user_id' => $this->user->id,
            'device_id' => $this->device->id,
            'occurred_at_device' => $occurred->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'boot_id' => $this->bootId,
            'uptime_ms' => max(0, $occurred->getTimestampMs() - CarbonImmutable::parse('2026-01-01', 'UTC')->getTimestampMs()),
            'server_offset_ms' => 0,
            'offline' => false,
            'payload' => $payload,
        ], $overrides);
    }

    /** Sends one event happening now, after moving the test clock to $at when given. */
    public function send(string $type, array $payload = [], ?string $at = null): TestResponse
    {
        if ($at !== null) {
            $this->at($at);
        }

        return $this->sync([$this->event($type, $payload)])->assertOk();
    }

    public function heartbeat(?string $at = null): TestResponse
    {
        return $this->send('heartbeat', [], $at);
    }

    /** @param list<array<string, mixed>> $events */
    public function sync(array $events): TestResponse
    {
        return $this->request('POST', '/api/v1/sync/events', ['events' => $events]);
    }

    /** @return array<string, mixed> */
    public function state(?string $at = null): array
    {
        if ($at !== null) {
            $this->at($at);
        }

        return $this->request('GET', '/api/v1/me/shift')->assertOk()->json();
    }

    /** @param array<string, mixed> $data */
    public function request(string $method, string $uri, array $data = []): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->test->withToken($this->token)->json($method, $uri, $data);
    }

    public function tokenId(): int
    {
        return (int) explode('|', $this->token)[0];
    }

    /** The person's latest shift as saved. */
    public function shift(): ?Shift
    {
        return Shift::query()->where('user_id', $this->user->id)->orderByDesc('clock_in_at')->first();
    }
}
