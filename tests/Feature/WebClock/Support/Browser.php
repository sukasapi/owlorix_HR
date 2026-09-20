<?php

namespace Tests\Feature\WebClock\Support;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\TodaySummary;
use App\Modules\Attendance\Services\WebDevice;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Attendance\Support\Desk;
use Tests\TestCase;

/**
 * One person in one web browser. Keeps the browser's device cookie between requests, like a real browser does.
 * Times are Asia/Jakarta wall clock, as in Desk.
 */
final class Browser
{
    public const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    public const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    public ?string $deviceId = null;

    public ?TestResponse $last = null;

    public function __construct(
        private readonly TestCase $test,
        public readonly User $user,
        private readonly string $userAgent = self::CHROME_WINDOWS,
    ) {}

    public function at(string $at): self
    {
        $this->test->travelTo(Desk::time($at));

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function post(string $uri, array $data = [], ?string $at = null): TestResponse
    {
        if ($at !== null) {
            $this->at($at);
        }

        $cookies = [];

        if ($this->deviceId !== null) {
            $encrypter = app('encrypter');
            $cookies[WebDevice::COOKIE] = $encrypter->encrypt(CookieValuePrefix::create(WebDevice::COOKIE, $encrypter->getKey()).$this->deviceId, false);
        }

        // The test app lives across requests; a cookie queued for another browser must not reach this one
        app('cookie')->flushQueuedCookies();
        app('auth')->forgetGuards();
        $this->test->actingAs($this->user);
        $response = $this->test->call('POST', $uri, $data, $cookies, [], ['HTTP_USER_AGENT' => $this->userAgent, 'HTTP_REFERER' => '/']);

        $cookie = $response->headers->getCookies();

        foreach ($cookie as $item) {
            if ($item->getName() === WebDevice::COOKIE) {
                $this->deviceId = $response->getCookie(WebDevice::COOKIE)->getValue();
            }
        }

        return $this->last = $response;
    }

    /** Heartbeats every 4 minutes (the web gap threshold) from now until $until, like an open Hari ini tab. */
    public function keepAlive(string $until): void
    {
        $end = Desk::time($until);

        while (CarbonImmutable::now()->addMinutes(4)->lessThanOrEqualTo($end)) {
            $this->test->travelTo(CarbonImmutable::now()->addMinutes(4));
            $this->post('/absen/detak');
        }

        $this->test->travelTo($end);
        $this->post('/absen/detak');
    }

    /** @return array<string, mixed> */
    public function summary(?string $at = null): array
    {
        if ($at !== null) {
            $this->at($at);
        }

        return app(TodaySummary::class)->for($this->user, null, $this->deviceId);
    }

    public function shift(): ?Shift
    {
        return Shift::query()->where('user_id', $this->user->id)->orderByDesc('clock_in_at')->first();
    }
}
