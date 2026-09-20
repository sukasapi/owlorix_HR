<?php

// The Excel file is always in Indonesian: it goes to payroll, whatever language the person downloading reads.
return [
    'sheets' => [
        'summary' => 'Ringkasan',
        'daily' => 'Harian',
    ],

    'summary' => [
        'team' => 'Tim',
        'name' => 'Nama',
        'username' => 'Username',
        'employee_code' => 'Kode karyawan',
        'days_worked' => 'Hari kerja',
        'regular_minutes' => 'Jam reguler (menit)',
        'regular_hours' => 'Jam reguler (jam)',
        'approved_minutes' => 'Lembur disetujui (menit)',
        'approved_hours' => 'Lembur disetujui (jam)',
        'pending_minutes' => 'Lembur menunggu (menit)',
        'pending_hours' => 'Lembur menunggu (jam)',
        'rejected_minutes' => 'Lembur ditolak (menit)',
        'rejected_hours' => 'Lembur ditolak (jam)',
        'idle_minutes' => 'PC diam (menit)',
        'idle_hours' => 'PC diam (jam)',
        'short_days' => 'Hari pendek',
        'non_workday_shifts' => 'Shift di hari libur',
        'review_shifts' => 'Shift perlu dicek',
        'late_claims' => 'Klaim lembur terlambat',
        'no_team' => 'Tanpa tim',
        'subtotal' => 'Subtotal :team',
        'total_studio' => 'Total studio',
        'total_selection' => 'Total',
        'people_count' => ':count orang',
    ],

    'daily' => [
        'name' => 'Nama',
        'username' => 'Username',
        'employee_code' => 'Kode karyawan',
        'team' => 'Tim',
        'work_date' => 'Tanggal kerja',
        'day_type' => 'Jenis hari',
        'clock_in' => 'Absen masuk (WIB)',
        'clock_out' => 'Absen pulang (WIB)',
        'regular_minutes' => 'Reguler (menit)',
        'regular_hours' => 'Reguler (jam)',
        'overtime_minutes' => 'Lembur (menit)',
        'overtime_hours' => 'Lembur (jam)',
        'overtime_status' => 'Status lembur',
        'idle_minutes' => 'PC diam (menit)',
        'interruption_minutes' => 'Terputus (menit)',
        'notes' => 'Catatan',
        'workday' => 'Hari kerja',
        'non_workday' => 'Bukan hari kerja',
    ],

    'overtime_status' => [
        'approved' => 'Disetujui',
        'pending' => 'Menunggu',
        'rejected' => 'Ditolak',
    ],

    'notes' => [
        'running' => 'Masih berjalan',
        'needs_review' => 'Perlu dicek',
        'clock_mismatch' => 'Jam PC beda dengan server',
        'gap_unverified' => 'Jeda belum bisa dipastikan',
        'late_claim' => 'Klaim lembur terlambat',
        'offline_sign_in' => 'Masuk tanpa internet',
        'short' => 'Hari pendek',
        'report_due' => 'Laporan lembur belum ditulis',
    ],

    'footer' => [
        'period' => 'Periode: :month (tanggal kerja menurut waktu Jakarta).',
        'generated' => 'Dibuat :time WIB.',
        'running_month' => 'Bulan ini masih berjalan. Angka bisa berubah sampai bulan selesai.',
        'idle' => 'PC diam hanya konteks: tidak mengurangi jam reguler atau lembur.',
        'rejected' => 'Lembur ditolak tetap tercatat, tapi bukan jam dibayar. Lembur menunggu belum diputuskan.',
        'shared' => 'Orang di lebih dari satu tim muncul di setiap timnya. Total menghitung setiap orang sekali.',
    ],
];
