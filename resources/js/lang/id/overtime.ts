export default {
    title: 'Lembur',
    lead: 'Semua lembur kamu, dengan alasan, laporan kerja, dan keputusan Management. Keputusan hanya menentukan apakah menit lembur dihitung, tidak pernah menghentikan kerja.',
    timezone_note: 'Jam mengikuti waktu Jakarta (WIB).',

    filters: {
        label: 'Saring lembur',
        status: 'Status',
        status_all: 'Semua status',
        month: 'Bulan',
        month_all: 'Semua bulan',
        clear: 'Tampilkan semua lembur',
    },

    count: ':from sampai :to dari :total lembur',

    states: {
        loading: 'Memuat lembur...',
        error_title: 'Daftar lembur gagal dimuat.',
        error_body: 'Periksa koneksi internet, lalu coba lagi. Jam yang sudah tercatat tidak hilang.',
        retry: 'Coba lagi',
        empty_title: 'Belum ada lembur.',
        empty_body:
            'Di hari kerja, lembur dimulai setelah :limit, saat kamu memilih lanjut lembur, bukan absen pulang. Di hari yang bukan hari kerja, semua jam dihitung lembur sejak absen masuk. Keduanya bisa dari halaman Hari ini atau aplikasi desktop Owlorix HR, lalu lemburnya muncul di sini.',
        empty_body_desktop:
            'Lembur dimulai di aplikasi desktop Owlorix HR, karena absen dari browser sedang dimatikan. Di hari kerja, setelah :limit aplikasi bertanya apakah kamu absen pulang atau lanjut lembur. Di hari yang bukan hari kerja, semua jam dihitung lembur sejak absen masuk. Setelah itu lemburnya muncul di sini.',
        filtered_title: 'Tidak ada lembur yang cocok.',
        filtered_body: 'Tidak ada lembur dengan status dan bulan ini. Pilih status atau bulan lain.',
    },

    pagination: {
        label: 'Halaman daftar lembur',
        previous: 'Sebelumnya',
        next: 'Berikutnya',
        page: 'Halaman :current dari :last',
    },

    status: {
        pending: 'Menunggu persetujuan',
        approved: 'Disetujui',
        rejected: 'Ditolak',
    },

    item: {
        range: ':start sampai :end',
        running_range: 'Sejak :start, masih berjalan',
        late_claim: 'Klaim terlambat',
        reason: 'Alasan',
        no_reason: 'Tidak ada alasan tercatat.',
        report: 'Laporan kerja',
        report_due_title: 'Laporan kerja belum ditulis',
        report_due: 'Tulis di aplikasi desktop Owlorix HR: aplikasi menanyakannya lagi saat kamu masuk berikutnya. Management baru bisa memutuskan setelah laporannya ada.',
        report_due_web: 'Tulis di halaman Hari ini atau di aplikasi desktop Owlorix HR. Management baru bisa memutuskan setelah laporannya ada.',
        open_my_day: 'Tulis laporan di Hari ini',
        running: 'Lembur masih berjalan. Laporan kerja ditanyakan saat kamu absen pulang.',
        zero_minutes: 'Shift ini sekarang tidak punya menit lembur setelah dihitung ulang, misalnya karena koreksi atau perubahan kalender. Riwayat keputusannya tetap disimpan.',
        rejected_by: 'Ditolak oleh :name, :time',
        rejected_at: 'Ditolak, :time',
        rejected_no_note: 'Tanpa catatan.',
        decisions: 'Riwayat keputusan',
        earlier_decisions: 'Keputusan sebelumnya',
        no_decision: 'Belum diputuskan oleh Management.',
        waiting_report: 'Belum diputuskan. Management baru bisa memutuskan setelah laporan kerja ditulis.',
        decision_by: ':decision oleh :name',
        decision: { approved: 'Disetujui', rejected: 'Ditolak' },
        reset: 'Kembali menunggu persetujuan karena menit lembur berubah setelah diputuskan.',
        open_history: 'Lihat shift di Riwayat',
    },

    claims: {
        heading: 'Klaim lembur yang masih bisa diajukan',
        prompt: 'Shift :date ditutup otomatis pukul :time karena pengingat jam reguler tidak dijawab dalam :answer.',
        presence_check: 'Lembur :date berhenti otomatis pukul :time karena pertanyaan Masih lembur? tidak dijawab dalam :answer.',
        deadline: 'Batas klaim: :deadline',
        latest_end: 'Jam selesai yang bisa diklaim paling lambat :time, aktivitas terakhir yang tercatat di shift itu.',
        where: 'Kalau kamu masih bekerja setelah itu, ajukan klaim di aplikasi desktop Owlorix HR dengan alasan dan laporan kerja.',
        where_web: 'Kalau kamu masih bekerja setelah itu, ajukan klaim dengan alasan dan laporan kerja di halaman Hari ini atau di aplikasi desktop Owlorix HR.',
        open_my_day: 'Ajukan klaim di Hari ini',
    },
};
