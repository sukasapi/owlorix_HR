export default {
    title: 'Aturan',
    intro: 'Angka yang dipakai mesin absen. Setiap aturan menyebut nomor bagiannya di dokumen aturan absen.',
    applies_title: 'Kapan perubahan berlaku',
    applies_body: 'Batas jam reguler disalin ke setiap shift saat absen masuk, jadi perubahannya hanya berlaku untuk shift baru. Nilai lain dibaca setiap kali shift dihitung, jadi juga berlaku untuk shift yang sedang berjalan. Aplikasi desktop memakai nilai baru setelah mengambil pengaturan lagi dari server.',
    groups: {
        work_hours: 'Jam kerja',
        overtime: 'Lembur',
        idle: 'PC diam',
        desktop_sync: 'Sinkron desktop',
        web: 'Presensi web',
        leave: 'Cuti',
        monitoring: 'Monitor aktivitas',
    },
    group_intro: {
        work_hours: 'Batas harian dan pengingat 8 jam.',
        overtime: 'Pertanyaan masih lembur dan klaim susulan.',
        idle: 'Kapan aplikasi desktop mencatat PC sedang diam.',
        desktop_sync: 'Tanda aktif, shift terputus, masuk tanpa internet, dan jam PC.',
        web: 'Absen dari browser, termasuk dari HP.',
        leave: 'Kuota cuti tahunan bawaan.',
        monitoring: 'Berapa lama catatan akses disimpan.',
    },
    units: {
        minutes: 'menit',
        hours: 'jam',
        seconds: 'detik',
        days: 'hari',
    },
    rule: 'Aturan :rule',
    range: 'Antara :min dan :max :unit.',
    default: 'Bawaan: :value',
    reset: 'Kembali ke bawaan',
    reset_label: 'Kembalikan :label ke bawaan, :value',
    changed_by: 'Terakhir diubah :name, :time',
    changed_at: 'Terakhir diubah :time',
    never_changed: 'Belum pernah diubah',
    applies: {
        new_shifts: 'Hanya shift baru',
        calculation: 'Juga shift berjalan',
        desktop: 'Dipakai aplikasi desktop',
        web: 'Langsung berlaku',
        next_request: 'Dipakai saat saldo cuti dihitung',
        daily: 'Berlaku di pembersihan malam berikutnya',
    },
    new_shifts_note: 'Shift yang sedang berjalan tetap memakai :value.',
    on: 'Nyala',
    off: 'Mati',
    timezone: {
        label: 'Zona waktu studio',
        help: 'Semua jam ditampilkan dalam zona ini. Tidak bisa diubah dari halaman ini.',
    },
    fields: {
        attendance: {
            regular_limit_minutes: {
                label: 'Batas jam reguler per hari',
                help: 'Jam kerja reguler per tanggal kerja, semua shift di tanggal itu dijumlahkan, tanpa potongan istirahat. Saat batas ini tercapai, pengingat 8 jam muncul.',
            },
            prompt_repeat_minutes: {
                label: 'Ulangi pengingat 8 jam setiap',
                help: 'Selama pengingat 8 jam belum dijawab, pengingat muncul lagi setiap selang ini. Tidak boleh lebih lama dari batas menjawab pengingat.',
            },
            prompt_auto_close_minutes: {
                label: 'Batas menjawab pengingat 8 jam',
                help: 'Tanpa jawaban selama ini, shift ditutup di tanda 8 jam. Orangnya masih bisa mengajukan klaim lembur susulan.',
            },
            overtime_idle_check_minutes: {
                label: 'Tanya masih lembur setelah',
                help: 'Saat lembur di PC, pertanyaan Masih lembur? muncul setelah PC diam selama ini tanpa tag. Di browser, pertanyaan muncul setiap selang ini sejak lembur mulai atau sejak jawaban terakhir.',
            },
            overtime_idle_answer_minutes: {
                label: 'Batas menjawab masih lembur',
                help: 'Tanpa jawaban selama ini, lembur berhenti dihitung di awal waktu diam (di browser, saat pertanyaan muncul), lalu shift menunggu laporan kerja.',
            },
            idle_threshold_minutes: {
                label: 'PC dianggap diam setelah',
                help: 'Tanpa ketikan atau gerakan mouse selama ini, aplikasi desktop mencatat waktu diam. Waktu diam tidak mengurangi jam kerja.',
            },
            resume_window_minutes: {
                label: 'Batas melanjutkan shift terputus',
                help: 'Kalau PC mati atau tanda aktif berhenti, orangnya bisa melanjutkan shift dalam waktu ini sejak tanda aktif terakhir, dan jedanya dicatat. Lewat dari itu, shift ditutup dan ditandai perlu dicek.',
            },
            offline_sign_in_days: {
                label: 'Boleh masuk tanpa internet selama',
                help: 'Masuk tanpa internet di aplikasi desktop hanya untuk orang yang pernah masuk dengan internet di PC itu dalam waktu ini.',
            },
            clock_mismatch_seconds: {
                label: 'Selisih jam PC yang ditandai',
                help: 'Kalau jam PC berbeda dari jam server lebih dari ini, shift ditandai jam PC berbeda supaya dicek.',
            },
            web_clock_in: {
                label: 'Absen dari web',
                help: 'Kalau dimatikan, Hari ini tidak menampilkan tombol absen dan absen dari browser ditolak. Absen dari aplikasi desktop tetap jalan. Shift yang sedang berjalan di browser akan terputus lalu ditutup untuk dicek.',
            },
        },
        overtime: {
            late_claim_hours: {
                label: 'Batas klaim lembur susulan',
                help: 'Setelah shift atau lembur ditutup otomatis, orangnya bisa mengajukan klaim lembur susulan selama waktu ini.',
            },
        },
        leave: {
            annual_quota_days: {
                label: 'Kuota cuti tahunan',
                help: 'Jatah hari cuti tahunan per orang per tahun bila Superadmin belum mengatur kuota khusus orang itu di Admin cuti. Hanya jenis cuti yang memotong kuota yang dihitung.',
            },
        },
        monitoring: {
            access_log_days: {
                label: 'Simpan catatan akses selama',
                help: 'Catatan masuk, halaman yang dibuka, aksi, dan akses ditolak di Monitor aktivitas. Yang lebih lama dihapus setiap malam pukul 02.30. Log audit dan data absensi tidak ikut dihapus.',
            },
        },
        sync: {
            heartbeat_local_seconds: {
                label: 'Tanda aktif dicatat setiap',
                help: 'Aplikasi desktop mencatat tanda aktif di PC, dan halaman Hari ini mengirim tanda aktif dari browser, setiap selang ini.',
            },
            heartbeat_upload_seconds: {
                label: 'Tanda aktif dikirim ke server setiap',
                help: 'Selang terlama antar kiriman dari PC selama shift terbuka. Shift dianggap terputus setelah dua kali selang ini tanpa kabar. Tidak boleh lebih pendek dari selang pencatatan.',
            },
        },
    },
    save: 'Simpan aturan',
    saving: 'Menyimpan...',
    discard: 'Batalkan perubahan',
    unsaved_one: ':count perubahan belum disimpan',
    unsaved_other: ':count perubahan belum disimpan',
    saved: 'Aturan disimpan.',
    nothing_changed: 'Tidak ada yang berubah.',
    failed: 'Aturan belum tersimpan. Periksa koneksi ke server studio, lalu coba lagi.',
    has_errors: 'Ada nilai yang perlu dibetulkan. Lihat pesan merah di bawah kolomnya.',
    errors: {
        integer: 'Isi dengan angka bulat.',
        boolean: 'Pilih nyala atau mati.',
        range: 'Isi antara :min dan :max :unit.',
        upload_below_local: 'Tidak boleh lebih pendek dari selang pencatatan tanda aktif (:value detik).',
        repeat_above_close: 'Tidak boleh lebih lama dari batas menjawab pengingat 8 jam (:value menit).',
    },
};
