<?php

namespace App\Modules\Monitoring\Services;

/**
 * A short "which browser on which system" from a user agent, enough to tell two sessions of one person apart.
 * Order matters: Edge and Opera also say Chrome, Chrome also says Safari.
 */
final class BrowserName
{
    private const BROWSERS = ['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser/' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'FxiOS/' => 'Firefox', 'CriOS/' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'];

    private const SYSTEMS = ['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Mac OS X' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux'];

    /** @return array{browser: ?string, os: ?string} */
    public static function from(?string $agent): array
    {
        return [
            'browser' => self::first($agent, self::BROWSERS),
            'os' => self::first($agent, self::SYSTEMS),
        ];
    }

    /** @param array<string, string> $needles */
    private static function first(?string $agent, array $needles): ?string
    {
        foreach ($needles as $needle => $name) {
            if ($agent !== null && str_contains($agent, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
