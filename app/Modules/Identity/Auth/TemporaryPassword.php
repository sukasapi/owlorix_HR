<?php

namespace App\Modules\Identity\Auth;

/**
 * Issued passwords that are easy to read aloud or copy by hand, e.g. "hutan-7-kopi-4".
 * The person must replace it at first sign-in.
 */
class TemporaryPassword
{
    private const WORDS = [
        'hutan', 'kopi', 'bulan', 'awan', 'sungai', 'gunung', 'pantai', 'kertas', 'pensil', 'layar',
        'lampu', 'jendela', 'kamera', 'warna', 'garis', 'bayang', 'cahaya', 'pohon', 'batu', 'angin',
        'hujan', 'pelangi', 'kabut', 'daun', 'bintang', 'ombak', 'rumput', 'kuas', 'tinta', 'sketsa',
    ];

    public static function generate(): string
    {
        $pick = fn () => self::WORDS[random_int(0, count(self::WORDS) - 1)];

        return sprintf('%s-%d-%s-%d', $pick(), random_int(2, 9), $pick(), random_int(2, 9));
    }
}
