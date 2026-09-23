<?php

return [
    'requested' => 'Pengajuan :type untuk :days hari kerja dikirim. Kamu melihat keputusannya di halaman ini.',
    'approved' => 'Cuti :name disetujui.',
    'rejected' => 'Cuti :name ditolak.',
    'cancelled_own' => 'Pengajuan cuti dibatalkan.',
    'cancelled_other' => 'Cuti :name dibatalkan.',
    'quota_saved' => 'Kuota :name untuk :year jadi :days hari.',
    'type_created' => 'Jenis cuti :name ditambahkan.',
    'type_updated' => 'Jenis cuti :name disimpan.',

    'type_required' => 'Pilih jenis cuti.',
    'type_inactive' => 'Jenis cuti ini sudah tidak dipakai. Pilih jenis lain.',
    'start_required' => 'Isi tanggal mulai.',
    'end_required' => 'Isi tanggal selesai.',
    'end_after_start' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
    'same_year' => 'Tanggal mulai dan selesai harus di tahun yang sama. Ajukan terpisah untuk tiap tahun.',
    'too_long' => 'Satu pengajuan paling panjang :days hari kalender.',
    'too_early' => 'Tanggal mulai paling awal :days hari yang lalu.',
    'too_late' => 'Tanggal selesai paling jauh akhir tahun depan.',
    'no_workdays' => 'Tidak ada hari kerja di tanggal ini. Libur dan akhir pekan tidak perlu diajukan.',
    'overlap' => 'Tanggal ini bertabrakan dengan pengajuanmu yang lain (:type, :from sampai :until).',
    'reason_required' => 'Jenis cuti ini butuh alasan.',
    'reason_max' => 'Alasan paling panjang 2000 karakter.',
    'attachment_type' => 'Lampiran harus PDF atau gambar (JPG, PNG, WebP).',
    'attachment_size' => 'Lampiran maksimal :mb MB.',

    'own_decision' => 'Pengajuan cuti sendiri tidak bisa diputuskan sendiri.',
    'not_decider' => 'Kamu tidak bisa memutuskan cuti orang ini.',
    'already_closed' => 'Pengajuan ini sudah :status. Muat ulang halaman untuk melihat keadaannya.',
    'reject_note' => 'Tulis alasan penolakan supaya orangnya tahu.',
    'note_max' => 'Catatan paling panjang 2000 karakter.',
    'cancel_started' => 'Cuti yang sudah dimulai tidak bisa dibatalkan sendiri. Minta Superadmin.',
    'cancel_not_allowed' => 'Kamu tidak bisa membatalkan pengajuan ini.',
    'cancel_note' => 'Tulis alasan pembatalan supaya orangnya tahu.',

    'status' => [
        'pending' => 'menunggu',
        'approved' => 'disetujui',
        'rejected' => 'ditolak',
        'cancelled' => 'dibatalkan',
    ],

    'quota_days' => 'Kuota diisi 0 sampai 365 hari.',
    'quota_year' => 'Tahun tidak valid.',
    'type_name_required' => 'Nama jenis cuti wajib diisi.',
    'type_name_taken' => 'Nama jenis cuti ini sudah ada.',
];
