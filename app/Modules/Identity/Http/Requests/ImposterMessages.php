<?php

namespace App\Modules\Identity\Http\Requests;

class ImposterMessages
{
    private const TEXT = [
        'id' => [
            'self' => 'Kamu tidak bisa menjadi Imposter untuk akun sendiri.',
            'inactive' => 'Orang ini tidak aktif. Pilih orang dengan status aktif.',
            'superadmin' => 'Tidak bisa menjadi Imposter untuk Superadmin lain.',
            'started' => 'Sekarang kamu bertindak sebagai :name.',
            'stopped' => 'Kamu kembali ke akun Superadmin.',
        ],
        'en' => [
            'self' => 'You cannot Imposter your own account.',
            'inactive' => 'This person is not active. Pick someone with an active status.',
            'superadmin' => 'You cannot Imposter another Superadmin.',
            'started' => 'You are now acting as :name.',
            'stopped' => 'You are back on your Superadmin account.',
        ],
    ];

    /** @param  array<string, string>  $replace */
    public static function get(string $key, array $replace = []): string
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'id';
        $text = self::TEXT[$locale][$key] ?? self::TEXT['id'][$key] ?? $key;

        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, $value, $text);
        }

        return $text;
    }
}
