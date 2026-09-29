<?php

return [
    'code' => 'Kode :status',
    'home' => 'Buka Hari ini',
    'back' => 'Kembali ke halaman sebelumnya',
    'reload' => 'Muat ulang halaman',
    'desktop' => 'Jam masuk dan pulang dari aplikasi desktop tetap tersimpan di PC dan terkirim sendiri setelah server pulih.',

    '403' => [
        'title' => 'Halaman ini tidak terbuka untuk akunmu',
        'body' => 'Akunmu belum punya akses ke sini. Kalau pekerjaanmu butuh halaman ini, minta Superadmin menambahkan aksesnya.',
    ],
    '404' => [
        'title' => 'Halaman ini tidak ada',
        'body' => 'Alamatnya mungkin salah ketik, atau halamannya sudah dipindah atau dihapus.',
    ],
    '419' => [
        'title' => 'Halaman ini terlalu lama terbuka',
        'body' => 'Demi keamanan, halaman yang dibiarkan lama harus dimuat ulang sebelum mengirim data. Isian yang belum terkirim perlu diisi lagi.',
    ],
    '429' => [
        'title' => 'Tunggu sebentar',
        'body' => 'Terlalu banyak permintaan masuk dalam waktu singkat. Tunggu sekitar satu menit, lalu coba lagi.',
    ],
    '4xx' => [
        'title' => 'Permintaan ini tidak bisa diproses',
        'body' => 'Coba kembali ke halaman sebelumnya, atau mulai lagi dari Hari ini.',
    ],
    '500' => [
        'title' => 'Ada yang tidak beres di server',
        'body' => 'Permintaanmu gagal diproses, dan ini bukan kesalahanmu. Coba lagi sebentar lagi. Kalau terus terjadi, beri tahu Superadmin jam kejadiannya.',
    ],
    '503' => [
        'title' => 'Aplikasi sedang dirawat',
        'body' => 'Kami sedang memperbarui aplikasi. Coba muat ulang dalam beberapa menit.',
    ],
    'database' => [
        'title' => 'Data belum bisa dibuka',
        'body' => 'Server sedang tidak terhubung ke database. Coba muat ulang sebentar lagi.',
    ],

    'database_api' => 'Server sedang tidak terhubung ke database. Data tetap tersimpan di PC ini dan dikirim lagi nanti.',
];
