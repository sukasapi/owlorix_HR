import type { Article, Chapter, Flow, Term } from './types';

/**
 * Isi Panduan, dari docs/panduan/panduan-pengguna-owlorix-hr.md dan docs/13, ditulis ulang untuk layar.
 * Setiap perubahan perilaku aplikasi juga mengubah halaman di sini. Gambar desktop adalah pratinjau dengan data contoh.
 */

const LEAD_TASKS = ['projects.manage', 'projects.oversee'];

export const chapters: Chapter[] = [
    { id: 'mulai', title: 'Mulai', lead: 'Apa yang dicatat, masuk pertama, dan profilmu.' },
    { id: 'absen', title: 'Absen harian', lead: 'Masuk, pulang, terputus, dan pindah perangkat, dari aplikasi desktop maupun web.' },
    { id: 'lembur', title: 'Lembur', lead: 'Pengingat 8 jam, aturan perintah lembur, laporan, dan klaim susulan.' },
    { id: 'cuti', title: 'Cuti dan izin', lead: 'Mengajukan cuti, sakit, atau izin, dan melihat sisa kuota.' },
    { id: 'proyek', title: 'Proyek dan tugas', lead: 'Sub proyek, usulan tugas, timer, bukti, dan log kerja.' },
    { id: 'manajemen', title: 'Untuk Management', lead: 'Team Lead, Project Manager, dan Project Director.' },
    { id: 'admin', title: 'Untuk Superadmin', lead: 'Akun, tim, kalender, perangkat, aturan, dan pengaturan aplikasi.' },
    { id: 'masalah', title: 'Masalah umum', lead: 'Pesan yang sering muncul dan siapa yang dihubungi.' },
];

export const articles: Article[] = [
    // Mulai
    {
        id: 'yang-dicatat',
        chapter: 'mulai',
        title: 'Apa yang dicatat aplikasi',
        summary: 'Jam masuk, pulang, lembur, dan lama PC diam. Tidak pernah ketikan, layar, kamera, atau lokasi.',
        keywords: ['privasi', 'data', 'rekam', 'pantau', 'mata-mata', 'masa simpan', 'keyboard', 'layar'],
        questions: ['Apakah aplikasi merekam layar saya?', 'Siapa yang bisa melihat data saya?', 'Berapa lama data disimpan?'],
        blocks: [
            { type: 'p', text: 'Owlorix HR mencatat kapan kamu mulai dan selesai kerja, berapa lembur, dan alasannya. Tujuannya rekap jam kerja dan lembur yang bisa dicek bersama, bukan mengawasi isi pekerjaanmu.' },
            {
                type: 'table',
                head: ['Yang dicatat', 'Yang tidak pernah dicatat'],
                rows: [
                    ['Jam masuk, jam pulang, dan lembur', 'Ketikan keyboard'],
                    ['Nama PC yang dipakai', 'Isi layar atau tangkapan layar'],
                    ['Lama waktu sejak keyboard atau mouse terakhir dipakai', 'Aplikasi atau situs yang dibuka'],
                    ['Waktu PC mati, tidur, dikunci, dan menyala lagi', 'Kamera dan mikrofon'],
                    ['Alasan lembur, laporan kerja, dan tanda PC diam yang kamu tulis', 'Lokasi'],
                    ['Masuk dan keluar web, halaman Owlorix HR yang kamu buka, dan tombol yang kamu tekan', 'Isi form yang kamu ketik'],
                ],
            },
            { type: 'p', text: '**PC diam** hanya membaca lamanya tidak ada input, bukan tombol yang ditekan. Kalau tidak ada input selama 10 menit, waktu itu dicatat sebagai PC diam. PC diam tidak mengurangi jam kerja atau lembur. Absen dari browser tidak mencatat PC diam sama sekali.' },
            { type: 'p', text: '**Yang melihat datamu:** kamu, Team Lead timmu, Project Manager, Project Director, dan Superadmin studio.' },
            {
                type: 'table',
                head: ['Data', 'Disimpan'],
                rows: [
                    ['Ringkasan shift, lembur, dan koreksi', '10 tahun setelah akhir tahun pajak'],
                    ['Catatan absen mentah dari PC dan web', '2 tahun'],
                    ['Detail PC diam beserta catatannya', '12 bulan'],
                    ['Log masuk dan keamanan', '12 bulan'],
                ],
            },
            { type: 'p', text: 'Mau salinan data atau koreksi? Hubungi Team Lead timmu atau Superadmin. Setiap koreksi disimpan bersama alasannya, dan kamu diberi tahu.' },
            { type: 'image', src: '/img/panduan/d-privasi.webp', alt: 'Layar Apa yang dicatat Owlorix HR di aplikasi desktop', caption: 'Layar privasi di aplikasi desktop (pratinjau dengan data contoh).', wide: true },
        ],
    },
    {
        id: 'masuk-pertama',
        chapter: 'mulai',
        title: 'Masuk pertama dan kata sandi',
        summary: 'Kata sandi sementara dari Superadmin diganti saat masuk pertama. Lupa kata sandi: minta Superadmin.',
        keywords: ['login', 'sign in', 'password', 'sandi', 'lupa', 'reset', 'username', 'email', 'akun'],
        questions: ['Lupa kata sandi', 'Bagaimana cara ganti password?', 'Bisa masuk pakai email?', 'Kata sandi sementara saya tidak jalan'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Superadmin membuat akunmu dan memberimu **username** dan **kata sandi sementara**.',
                    'Masuk dari web atau aplikasi desktop. Username dan kata sandinya sama untuk keduanya. Kalau akunmu punya email, email itu juga bisa dipakai sebagai ganti username.',
                    'Kamu langsung diminta membuat kata sandi sendiri: isi kata sandi sementara, kata sandi baru (minimal 12 karakter), dan ulangi, lalu **Simpan kata sandi baru**.',
                    'Pakai kata sandi baru di web dan di aplikasi desktop.',
                ],
            },
            { type: 'note', title: 'Lupa kata sandi', text: 'Tidak ada tombol reset mandiri. Minta Superadmin membuat kata sandi sementara baru, lalu ganti lagi saat masuk.' },
            { type: 'p', text: 'Ganti kata sandi kapan saja di web: klik foto atau inisialmu di pojok kanan atas, lalu **Ganti kata sandi**.' },
            { type: 'image', src: '/img/panduan/d-ganti-sandi.webp', alt: 'Layar ganti kata sandi wajib di aplikasi desktop', caption: 'Ganti kata sandi wajib di aplikasi desktop (pratinjau dengan data contoh).', wide: true },
        ],
    },
    {
        id: 'profil',
        chapter: 'mulai',
        title: 'Profil, foto, dan CV',
        summary: 'Isi nama panggilan, data diri, kontak darurat, foto, dan CV di menu akun, Profil saya.',
        keywords: ['profil', 'foto', 'avatar', 'cv', 'nama panggilan', 'nickname', 'kontak darurat', 'alamat', 'nomor hp', 'bio', 'portofolio'],
        questions: ['Cara ganti foto profil', 'Cara upload CV', 'Kenapa nama saya di laporan tidak berubah?', 'Siapa yang bisa melihat CV saya?'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Klik foto atau inisialmu di pojok kanan atas, lalu **Profil saya**.',
                    'Isi yang perlu: nama lengkap, **nama panggilan**, jabatan, nomor HP, tempat dan tanggal lahir, alamat, kontak darurat, bio, dan tautan portofolio.',
                    'Tekan **Simpan profil**.',
                    'Foto: **Unggah foto** (JPG, PNG, atau WebP, maksimal 2 MB). CV: **Unggah CV** (PDF, maksimal 5 MB).',
                ],
            },
            {
                type: 'table',
                head: ['Data', 'Tampil di mana', 'Siapa yang melihat'],
                rows: [
                    ['Nama panggilan', 'Menu akun, daftar tugas, anggota proyek', 'Semua rekan kerja'],
                    ['Nama lengkap', 'Laporan dan ekspor Excel', 'Management dan Superadmin'],
                    ['Foto', 'Menu akun, anggota proyek, tugas', 'Semua rekan kerja'],
                    ['CV', 'Halaman Profil', 'Kamu dan Superadmin saja'],
                ],
            },
            { type: 'p', text: 'Username, email, kode karyawan, jenis karyawan, peran, dan tim diatur Superadmin. Profil menampilkannya supaya kamu bisa mengecek. Aplikasi tidak menyimpan NIK, NPWP, atau rekening bank.' },
        ],
    },

    // Absen harian
    {
        id: 'absen-desktop',
        chapter: 'absen',
        title: 'Absen dari aplikasi desktop',
        summary: 'Di aplikasi desktop, masuk sama dengan absen masuk. Batalkan dalam 2 menit kalau salah.',
        audience: ['attendance.clock_in'],
        keywords: ['absen masuk', 'clock in', 'pc', 'desktop', 'tray', 'batal', 'absen pulang', 'clock out', 'windows'],
        questions: ['Cara absen masuk di PC', 'Salah PC saat absen', 'Cara absen pulang', 'Menutup jendela aplikasi apakah absen pulang?'],
        blocks: [
            { type: 'p', text: 'Aplikasi terbuka sendiri saat kamu masuk ke Windows. Ikon mata burung hantu ada di tray Windows (pojok kanan bawah).' },
            {
                type: 'steps',
                items: [
                    'Di layar **Masuk untuk absen**, isi username dan kata sandi.',
                    'Tekan **Masuk dan absen**. Jam kerja dihitung sejak saat itu.',
                    'Salah PC atau salah orang? Tekan **Batalkan absen masuk** dalam 2 menit.',
                    'Mau lanjut kerja di browser? Tekan **Mulai kerja** di kotak **Lanjut kerja di web**. Browser membuka Hari ini sudah masuk.',
                    'Selesai kerja: tekan **Absen pulang**, lalu **Ya, absen pulang**.',
                ],
            },
            { type: 'flow', flow: 'alur-absen' },
            { type: 'note', title: 'Tombol X bukan absen pulang', text: 'Menutup jendela hanya menyembunyikannya ke tray. **Keluar dari aplikasi** di menu Lainnya juga tidak mengabsen pulang. Untuk berhenti kerja, pakai **Absen pulang**.' },
            { type: 'p', text: 'Pulang sebelum 8 jam boleh. Tanggal itu ditandai **Kurang dari 8 jam** di Riwayat, dan kekurangannya terlihat di **Minggu ini** (lihat Target kerja mingguan).' },
            { type: 'image', src: '/img/panduan/d-masuk.webp', alt: 'Layar masuk aplikasi desktop', caption: 'Layar masuk: nama PC di bawah judul, status koneksi di bawah tombol (pratinjau dengan data contoh).', wide: true },
            { type: 'image', src: '/img/panduan/d-status.webp', alt: 'Layar status aplikasi desktop setelah absen masuk', caption: 'Layar status: jam kerja hari ini, bukti tercatat, dan tombol Absen pulang (pratinjau dengan data contoh).' },
        ],
    },
    {
        id: 'absen-web',
        chapter: 'absen',
        title: 'Absen dari web (Hari ini)',
        summary: 'Tekan Absen masuk di Hari ini dan biarkan tabnya terbuka. Masuk ke web tidak otomatis absen.',
        audience: ['attendance.clock_in'],
        keywords: ['web', 'browser', 'hari ini', 'hp', 'ponsel', 'tab', 'tanda aktif', 'heartbeat', 'pengingat', 'notifikasi'],
        questions: ['Cara absen dari HP', 'Kenapa saya belum absen padahal sudah login web?', 'Apakah tab harus tetap terbuka?'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Buka **Hari ini** dan tekan **Absen masuk**. Salah tekan? **Batalkan** dalam 2 menit.',
                    '**Biarkan tab Hari ini terbuka** selama kerja. Halaman mengirim tanda aktif tiap menit, juga saat tab ada di belakang.',
                    'Tekan **Nyalakan pengingat** supaya browser menampilkan notifikasi pengingat 8 jam.',
                    'Setelah 8 jam, jawab kartu pengingat: **Absen pulang** atau **Lanjut kerja (lembur)**.',
                    'Selesai: **Absen pulang**.',
                ],
            },
            { type: 'note', title: 'Masuk web bukan absen', text: 'Masuk ke web hanya membuka akunmu. Absen dari web selalu lewat tombol **Absen masuk** di Hari ini.' },
            { type: 'p', text: 'Web tidak mencatat PC diam, jadi tanda Render, Rapat, dan Istirahat tidak ada di web. Saat lembur di web, **Masih lembur?** ditanyakan tiap 60 menit. Kalau Superadmin mematikan absen web, Hari ini tidak menampilkan tombol absen.' },
            { type: 'p', text: 'Angka besar di Hari ini adalah total jam reguler tanggal itu, dijumlah dari semua shift di tanggal yang sama. Angkanya bertambah tiap menit selama shift berjalan.' },
        ],
    },
    {
        id: 'terputus',
        chapter: 'absen',
        title: 'Browser ditutup, PC mati: shift terputus',
        summary: 'Tanpa tanda aktif 4 menit shift terputus. Lanjutkan dalam 90 menit; lewat itu shift ditutup di tanda terakhir.',
        audience: ['attendance.clock_in'],
        keywords: ['terputus', 'reset', 'mulai dari nol', 'mengulang', 'hilang', 'tutup browser', 'tab tertutup', 'listrik padam', 'mati lampu', 'lanjutkan shift', '90 menit', 'nol'],
        questions: [
            'Kenapa waktu saya mengulang dari nol?',
            'Saya menutup browser lalu membuka lagi, jam kerja hilang',
            'Listrik padam saat absen masuk',
            'Apa itu Lanjutkan shift?',
        ],
        blocks: [
            { type: 'p', text: 'Aplikasi hanya menghitung waktu yang bisa dibuktikan: selama tab Hari ini terbuka atau aplikasi desktop menyala. Waktu saat browser tertutup atau PC mati tidak dihitung kerja.' },
            { type: 'flow', flow: 'alur-terputus' },
            {
                type: 'table',
                head: ['Yang terjadi', 'Yang tampil', 'Yang kamu lakukan'],
                rows: [
                    ['Tab tertutup kurang dari 4 menit', 'Shift jalan terus', 'Tidak ada'],
                    ['Tab tertutup lebih dari 4 menit, kembali dalam 90 menit', '**Browser ini berhenti mengirim tanda aktif.**', 'Tekan **Lanjutkan shift**. Jeda tercatat, tidak dihitung kerja'],
                    ['Kembali setelah 90 menit', '**Kamu sudah absen pulang.**', 'Absen masuk lagi. Shift lama ditutup di tanda aktif terakhir dan bertanda perlu dicek'],
                    ['Buka di browser atau PC lain', 'Nama perangkat lain', '**Pindahkan ke sini** dulu'],
                    ['Hari berikutnya', 'Mulai dari 0', 'Normal: shift milik tanggal ia dimulai'],
                ],
            },
            { type: 'note', title: 'Angka tidak benar-benar hilang', text: 'Saat shift baru dimulai, angka besar di Hari ini tetap menjumlah shift sebelumnya di tanggal yang sama. Hanya baris shift baru yang mulai dari 0. Kalau jam pulang yang tercatat salah, minta koreksi ke Team Lead.' },
        ],
    },
    {
        id: 'pindah-perangkat',
        chapter: 'absen',
        title: 'Pindah PC atau pindah ke web',
        summary: 'Satu orang hanya punya satu shift berjalan. Perangkat baru bertanya dulu sebelum memindahkan shift.',
        audience: ['attendance.clock_in'],
        keywords: ['pindah', 'pc lain', 'browser lain', 'perangkat', 'move', 'ganti pc', 'dua pc'],
        questions: ['Absen di PC lain', 'Shift saya terbuka di perangkat lain', 'Pindah dari PC ke laptop'],
        blocks: [
            { type: 'p', text: 'Kalau shiftmu masih terbuka di PC lain atau di browser, aplikasi bertanya **Kamu sedang absen masuk di ...**.' },
            {
                type: 'list',
                items: [
                    '**Pindahkan ke sini**: shift lanjut di perangkat ini, jam kerja tidak terputus.',
                    '**Jangan, batalkan**: shift di perangkat lama tetap berjalan.',
                ],
            },
            { type: 'p', text: 'Orang lain masuk di PC yang sedang kamu pakai tidak mengabsen pulang dirimu. Kamu hanya dikeluarkan dari aplikasi di PC itu, dan shiftmu mengikuti aturan terputus.' },
            { type: 'image', src: '/img/panduan/d-pindah-shift.webp', alt: 'Pertanyaan pindah shift di aplikasi desktop', caption: 'Pertanyaan pindah shift (pratinjau dengan data contoh).', wide: true },
        ],
    },
    {
        id: 'pc-diam',
        chapter: 'absen',
        title: 'PC diam dan tandanya',
        summary: 'Waktu tanpa input 10 menit dicatat PC diam. Menandainya tidak wajib dan tidak mengurangi jam.',
        audience: ['attendance.clock_in'],
        keywords: ['idle', 'diam', 'render', 'rapat', 'istirahat', 'aku tinggal dulu', 'tandai', 'afk'],
        questions: ['Apakah PC diam mengurangi jam kerja?', 'Cara menandai render', 'Ditinggal render saat lembur'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Setelah kembali ke PC, waktu diam muncul di daftar **PC diam** sebagai **Belum ditandai**.',
                    'Tekan **Tandai** lalu pilih **Render**, **Rapat**, **Istirahat**, atau **Lainnya** (dengan catatan singkat).',
                ],
            },
            { type: 'p', text: '**Aku tinggal dulu** menandai waktu diam berikutnya sebelum kamu pergi, misalnya saat PC ditinggal render. Dengan tanda Render, lembur tetap jalan walau PC diam.' },
            { type: 'image', src: '/img/panduan/d-tandai.webp', alt: 'Pilihan tanda PC diam', caption: 'Pilihan tanda PC diam (pratinjau dengan data contoh).' },
        ],
    },
    {
        id: 'bukan-hari-kerja',
        chapter: 'absen',
        title: 'Masuk di hari libur atau akhir pekan',
        summary: 'Di hari yang bukan hari kerja, semua jam dihitung lembur dan alasan diminta sebelum mulai.',
        audience: ['attendance.clock_in'],
        keywords: ['sabtu', 'minggu', 'libur', 'akhir pekan', 'weekend', 'tanggal merah', 'cuti bersama'],
        questions: ['Kerja hari Sabtu dihitung apa?', 'Masuk di hari libur'],
        blocks: [
            { type: 'p', text: 'Masuk di akhir pekan, hari libur, atau libur studio memunculkan **Hari ini bukan hari kerja. Semua jam dihitung lembur.** Isi alasan (minimal 10 karakter), lalu **Mulai lembur**. Laporan kerja diminta saat absen pulang.' },
            { type: 'p', text: 'Kalau tim memang harus kerja di hari itu dengan aturan 8 jam biasa, Management bisa membuka tanggal itu sebagai hari kerja lebih dulu (lihat **Membuka hari kerja tambahan**).' },
            { type: 'image', src: '/img/panduan/d-bukan-hari-kerja.webp', alt: 'Layar bukan hari kerja di aplikasi desktop', caption: 'Masuk di hari yang bukan hari kerja (pratinjau dengan data contoh).', wide: true },
        ],
    },
    {
        id: 'tanpa-internet',
        chapter: 'absen',
        title: 'Tanpa internet',
        summary: 'Absen tersimpan dulu di PC lalu terkirim sendiri saat koneksi kembali.',
        audience: ['attendance.clock_in'],
        keywords: ['offline', 'internet mati', 'koneksi', 'wifi', 'server', 'kirim sekarang'],
        questions: ['Internet mati saat absen', 'Tidak ada koneksi', 'Bisa masuk tanpa internet?'],
        blocks: [
            {
                type: 'list',
                items: [
                    'Setiap absen disimpan dulu di PC lalu dikirim ke server. Saat koneksi putus, layar status menulis **Tidak ada koneksi.** dan jumlah catatan yang menunggu. **Coba sekarang** mencoba lagi.',
                    'Masuk tanpa internet hanya bisa kalau kamu pernah masuk di PC itu dengan internet dalam 14 hari terakhir.',
                    'Pengingat 8 jam, cek hari kerja, Masih lembur?, dan PC diam tetap jalan tanpa internet.',
                    'Klaim lembur susulan butuh koneksi.',
                ],
            },
            { type: 'image', src: '/img/panduan/d-status-offline.webp', alt: 'Layar status tanpa koneksi', caption: 'Tanpa koneksi: catatan tersimpan di PC dan menunggu terkirim (pratinjau dengan data contoh).' },
        ],
    },
    {
        id: 'riwayat',
        chapter: 'absen',
        title: 'Riwayat',
        summary: 'Kalender shift per bulan, rekap jam, dan urutan kejadian tiap tanggal.',
        audience: ['attendance.clock_in'],
        keywords: ['riwayat', 'history', 'kalender', 'rekap', 'bulan', 'perlu dicek', 'kurang dari 8 jam'],
        questions: ['Melihat jam kerja bulan lalu', 'Apa arti perlu dicek?'],
        blocks: [
            { type: 'p', text: 'Menu **Riwayat** menampilkan shift per bulan dalam bentuk kalender. **Rekap** di atas berisi jam reguler, lembur per status, PC diam, hari kurang dari 8 jam, dan kerja di hari bukan hari kerja.' },
            { type: 'p', text: 'Pilih tanggal untuk melihat **Urutan kejadian**: absen masuk, PC diam, tanda 8 jam, mulai lembur, absen pulang, beserta alasan dan keputusan lembur. Shift yang lewat tengah malam masuk ke tanggal absen masuknya.' },
        ],
    },
    {
        id: 'target-mingguan',
        chapter: 'absen',
        title: 'Target kerja mingguan',
        summary: 'Target jam per minggu menurut jenis karyawan: tetap dan kontrak 40 jam, magang sesuai hari dan jamnya, freelance tanpa target.',
        audience: ['attendance.clock_in'],
        keywords: ['target', '40 jam', 'mingguan', 'minggu ini', 'kurang jam', 'magang', 'intern', 'freelance', 'kontrak', 'karyawan tetap', 'pulang cepat'],
        questions: ['Berapa target jam saya minggu ini?', 'Pulang sebelum 8 jam, bagaimana?', 'Target magang berapa?', 'Apakah lembur mengisi target?'],
        blocks: [
            { type: 'p', text: 'Kotak **Minggu ini** di Hari ini menunjukkan jam reguler minggu ini (Senin sampai Minggu) dibanding targetmu, dan berapa yang kurang. Jam dari PC dan dari web sama-sama dihitung.' },
            {
                type: 'table',
                head: ['Jenis karyawan', 'Target'],
                rows: [
                    ['Tetap dan kontrak', '40 jam per minggu (diatur Superadmin). Berkurang 8 jam untuk setiap hari libur atau cuti yang disetujui di hari kerja'],
                    ['Magang', 'Jumlah hari hadir per minggu × jam per hari, bawaan 2 hari × 8 jam. Superadmin bisa mengubahnya per orang'],
                    ['Freelance', 'Tanpa target'],
                ],
            },
            {
                type: 'list',
                items: [
                    'Hanya jam reguler yang dihitung. Lembur diajukan dan dibayar terpisah, jadi tidak mengisi target.',
                    'Pulang sebelum 8 jam boleh. Hari itu tetap ditandai **Kurang dari 8 jam**, dan kekurangannya bisa ditutup di hari lain minggu yang sama.',
                    'Shift terputus yang tidak dilanjutkan sampai hari selesai membuat jam hari itu kurang, dan ikut terlihat sebagai kekurangan minggu itu.',
                    'Target hanya ditampilkan. Tidak memotong jam, tidak mengubah pengingat 8 jam, dan tidak mengubah lembur.',
                ],
            },
            { type: 'p', text: '**Riwayat** menampilkan setiap minggu di bulan itu dengan target dan kekurangannya. Team Lead melihat baris **Minggu ini** di setiap kartu Tim hari ini.' },
        ],
    },
    {
        id: 'jam-salah',
        chapter: 'absen',
        title: 'Kalau jam tercatat salah',
        summary: 'Sampaikan ke Team Lead atau Superadmin: tanggal, jam yang salah, jam yang benar, dan alasannya.',
        audience: ['attendance.clock_in'],
        keywords: ['koreksi', 'salah jam', 'ubah jam', 'lupa absen', 'revisi absen', 'perbaiki'],
        questions: ['Lupa absen pulang', 'Jam pulang saya salah', 'Cara mengoreksi jam masuk'],
        blocks: [
            { type: 'p', text: 'Karyawan tidak punya menu Koreksi. Sampaikan ke **Team Lead timmu** (dia bisa mengajukan koreksi) atau ke **Superadmin** (dia bisa koreksi langsung). Sebutkan tanggal, jam yang salah, jam yang benar, dan alasannya.' },
            { type: 'p', text: 'Setelah koreksi diterapkan, kamu diberi tahu, dan perubahan tercatat di Log audit bersama alasannya.' },
            { type: 'flow', flow: 'alur-koreksi' },
        ],
    },

    // Lembur
    {
        id: 'pengingat-8-jam',
        chapter: 'lembur',
        title: 'Pengingat 8 jam',
        summary: 'Setelah 8 jam reguler, pilih Absen pulang atau Lanjut lembur dengan alasan.',
        audience: ['attendance.clock_in'],
        keywords: ['8 jam', 'delapan jam', 'pengingat', 'prompt', 'lanjut lembur', 'kartu', 'otomatis pulang', 'jam reguler'],
        questions: ['Cara mulai lembur', 'Apa yang terjadi kalau pengingat 8 jam tidak dijawab?', 'Kenapa saya otomatis absen pulang?'],
        blocks: [
            { type: 'p', text: 'Jam reguler 8 jam per tanggal kerja, dihitung dari absen masuk, tanpa potongan istirahat. Beberapa shift di tanggal yang sama dijumlahkan.' },
            {
                type: 'steps',
                items: [
                    'Saat jam reguler mencapai 8 jam, kartu **Sudah 8 jam hari ini.** muncul. Klik **Jawab**.',
                    'Tombol aktif sebentar kemudian, supaya tidak terpencet saat mengetik.',
                    '**Absen pulang**: shift ditutup saat itu. Menit setelah tanda 8 jam tidak dihitung lembur.',
                    '**Lanjut lembur**: tulis alasan (minimal 10 karakter), lalu **Mulai lembur**. Lembur dihitung mulai tanda 8 jam.',
                ],
            },
            { type: 'flow', flow: 'alur-lembur' },
            { type: 'note', title: 'Tidak dijawab', text: 'Kartu muncul lagi tiap 10 menit. Setelah 30 menit tanpa jawaban, kamu otomatis diabsen pulang dan jamnya dihitung sampai tanda 8 jam. Kalau sebenarnya masih bekerja, ajukan klaim lembur susulan.' },
            { type: 'image', src: '/img/panduan/p-8jam-buka.webp', alt: 'Kartu pengingat 8 jam yang sudah dibuka', caption: 'Kartu setelah Jawab: Absen pulang atau Lanjut lembur (pratinjau dengan data contoh).' },
        ],
    },
    {
        id: 'perintah-lembur',
        chapter: 'lembur',
        title: 'Aturan perintah lembur',
        summary: 'Lembur hanya sah kalau diperintah Team Lead, PM, atau PD sebelum dimulai. Yang disetujui menjadi dasar bayar.',
        keywords: ['perintah lembur', 'sah', 'dibayar', 'bayar lembur', 'gaji', 'tolak', 'ditolak', 'aturan lembur', 'd19', 'd20'],
        questions: ['Apakah lembur saya dibayar?', 'Kenapa lembur saya ditolak?', 'Siapa yang boleh memerintah lembur?'],
        blocks: [
            { type: 'p', text: 'Lembur hanya sah kalau **diperintah** Team Lead, Project Manager, atau Project Director **sebelum** kamu mulai. Kamu tidak mengajukan lembur sendiri.' },
            {
                type: 'steps',
                items: [
                    'Perintah lembur diberikan sebelum lembur dimulai, lewat chat tim, dan dicatat.',
                    'Kamu tetap menekan **Lanjut lembur** dan menulis alasan, supaya jamnya tercatat. Sebutkan siapa yang memerintah di alasan.',
                    'Atasan menyetujui hanya lembur yang memang diperintah. Lembur tanpa perintah tetap tercatat, tapi ditolak dengan catatan dan tidak dibayar.',
                ],
            },
            { type: 'note', title: 'Dasar bayar', text: 'Rekap lembur yang **disetujui** menjadi dasar pembayaran lembur. Lembur menunggu dan ditolak tidak dibayar.' },
        ],
    },
    {
        id: 'masih-lembur',
        chapter: 'lembur',
        title: 'Masih lembur?',
        summary: 'Pertanyaan saat lembur kalau PC diam 60 menit (desktop) atau tiap 60 menit (web).',
        audience: ['attendance.clock_in'],
        keywords: ['masih lembur', 'presence', 'kehadiran', 'lembur berhenti', 'pertanyaan lembur'],
        questions: ['Kenapa lembur saya berhenti sendiri?', 'Apa itu Masih lembur?'],
        blocks: [
            { type: 'p', text: 'Di aplikasi desktop, kalau PC diam 60 menit tanpa tanda saat lembur, kartu **Masih lembur?** muncul. Di web, pertanyaan ini muncul tiap 60 menit karena browser tidak bisa melihat keyboard dan mouse.' },
            {
                type: 'list',
                items: [
                    '**Masih lembur**: lembur jalan terus.',
                    'Tidak dijawab dalam 30 menit: lembur berhenti dihitung di awal waktu diam (web: saat pertanyaan muncul), dan shift menunggu laporan kerja. Masih bekerja setelah itu? Ajukan klaim lembur susulan.',
                ],
            },
            { type: 'image', src: '/img/panduan/p-masih-lembur.webp', alt: 'Kartu Masih lembur', caption: 'Kartu Masih lembur? (pratinjau dengan data contoh).' },
        ],
    },
    {
        id: 'laporan-lembur',
        chapter: 'lembur',
        title: 'Laporan lembur dan klaim susulan',
        summary: 'Laporan diminta saat absen pulang dari lembur. Klaim susulan untuk shift yang ditutup otomatis, dalam 24 jam.',
        audience: ['attendance.clock_in'],
        keywords: ['laporan', 'laporan kerja', 'klaim', 'susulan', 'tunggakan', 'perlu kamu isi', 'report', '24 jam'],
        questions: ['Cara menulis laporan lembur', 'Cara klaim lembur susulan', 'Laporan lembur belum ditulis'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Absen pulang dari lembur memunculkan layar **Laporan lembur** berisi alasanmu tadi.',
                    'Isi **Apa yang selesai?**. Management membaca ini saat memutuskan lemburmu.',
                    'Tekan **Kirim laporan dan absen pulang**. Jam pulang tetap saat kamu menekan Absen pulang.',
                ],
            },
            { type: 'p', text: 'Laporan yang belum ditulis dan shift yang ditutup otomatis muncul di **Perlu kamu isi** (aplikasi desktop dan Hari ini). Lembur baru bisa diputuskan setelah laporannya ada.' },
            { type: 'p', text: '**Klaim lembur susulan** hanya untuk shift yang ditutup otomatis oleh aturan (pengingat 8 jam atau Masih lembur? tidak dijawab). Isi alasan, apa yang dikerjakan, dan jam selesai kerja. Batasnya 24 jam sejak shift ditutup otomatis.' },
            { type: 'image', src: '/img/panduan/d-tunggakan.webp', alt: 'Bagian Perlu kamu isi di aplikasi desktop', caption: 'Bagian Perlu kamu isi (pratinjau dengan data contoh).' },
        ],
    },
    {
        id: 'halaman-lembur',
        chapter: 'lembur',
        title: 'Halaman Lembur',
        summary: 'Semua lemburmu dengan status Menunggu, Disetujui, atau Ditolak, beserta catatan keputusan.',
        audience: ['attendance.clock_in'],
        keywords: ['status lembur', 'menunggu persetujuan', 'disetujui', 'ditolak', 'catatan penolakan'],
        questions: ['Lembur saya sudah disetujui belum?', 'Melihat alasan lembur ditolak'],
        blocks: [
            { type: 'p', text: 'Menu **Lembur** berisi semua lemburmu dengan alasan, laporan kerja, status, dan riwayat keputusan. Lembur yang ditolak menampilkan catatan dari yang menolak. Saring dengan **Status** dan **Bulan**.' },
            { type: 'p', text: 'Tidak setuju dengan keputusan? Baca catatannya, bicarakan dengan yang menolak, lalu Team Lead dan HR.' },
        ],
    },

    // Cuti dan izin
    {
        id: 'ajukan-cuti',
        chapter: 'cuti',
        title: 'Mengajukan cuti, sakit, atau izin',
        summary: 'Pilih jenis dan tanggal, kirim, tunggu keputusan. Hanya hari kerja yang dihitung.',
        audience: ['leave.request'],
        keywords: ['cuti', 'izin', 'sakit', 'libur', 'kuota', 'sisa cuti', 'cuti tahunan', 'surat dokter', 'batal cuti', 'leave'],
        questions: ['Cara mengajukan cuti', 'Berapa sisa cuti saya?', 'Cara membatalkan cuti', 'Apakah hari libur memotong cuti?'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Buka **Cuti & izin**, pilih jenis, tanggal mulai, dan tanggal selesai. Jumlah hari kerja yang terhitung tampil sebelum kamu mengirim.',
                    'Tulis alasan. Sakit, izin, cuti khusus, dan cuti tanpa upah wajib beralasan. Lampiran (surat dokter, undangan) boleh PDF atau gambar, maksimal 5 MB.',
                    'Kirim pengajuannya. Team Lead timmu, Project Manager, Project Director, atau Superadmin yang memutuskan.',
                ],
            },
            {
                type: 'table',
                head: ['Aturan', 'Artinya'],
                rows: [
                    ['Hanya hari kerja', 'Akhir pekan dan hari libur di Kalender tidak dihitung. Kalau libur ditambahkan belakangan, harinya kembali ke kuota.'],
                    ['Kuota', 'Cuti tahunan paling banyak 12 hari kerja per tahun, di luar hari libur dan cuti bersama. Sakit, izin, dan jenis lain tidak memotong kuota. Sisa kuota dicatat Superadmin; tanyakan ke Superadmin bila perlu tahu.'],
                    ['Tanggal', 'Mulai dan selesai di tahun yang sama, paling lama 120 hari, mulai paling jauh 30 hari ke belakang (untuk sakit yang dilaporkan setelahnya).'],
                    ['Bentrok', 'Tanggal tidak boleh menimpa pengajuanmu yang menunggu atau sudah disetujui.'],
                ],
            },
            { type: 'p', text: '**Batal**: pengajuan yang menunggu bisa dibatalkan kapan saja, yang sudah disetujui sampai tanggal mulainya. Setelah itu minta Superadmin.' },
            { type: 'note', title: 'Cuti tidak mengubah absen', text: 'Jam kerja dan lembur tetap dihitung dari absen. Kalau kamu absen masuk di hari cuti, absennya tetap tercatat seperti biasa.' },
        ],
    },
    {
        id: 'setujui-cuti',
        chapter: 'cuti',
        title: 'Menyetujui cuti',
        summary: 'Pengajuan tertua di atas. Menolak wajib dengan catatan. Keputusan tidak bisa diubah.',
        audience: ['leave.approve', 'leave.manage'],
        keywords: ['persetujuan cuti', 'setujui cuti', 'tolak cuti', 'approve leave'],
        questions: ['Cara menyetujui cuti tim', 'Siapa yang menyetujui cuti?'],
        blocks: [
            { type: 'p', text: 'Buka **Persetujuan cuti**. Setiap pengajuan menampilkan anggota tim yang sudah cuti di tanggal yang sama, supaya kamu bisa menimbang jadwal produksi. Sisa kuota cuti hanya dilihat Superadmin.' },
            {
                type: 'table',
                head: ['Siapa', 'Memutuskan cuti'],
                rows: [
                    ['Team Lead', 'Anggota tim yang dia pimpin'],
                    ['Project Manager, Project Director', 'Siapa saja'],
                    ['Superadmin', 'Siapa saja, lewat **Admin > Cuti & izin**'],
                ],
            },
            { type: 'p', text: 'Tidak ada yang memutuskan pengajuannya sendiri. Team Lead mengajukan ke Project Manager, Project Director, atau Superadmin. Orang yang cuti hari ini tampil **Cuti** di **Tim hari ini**, dan jumlah hari cutinya masuk **Laporan**.' },
        ],
    },

    // Proyek dan tugas
    {
        id: 'proyek-struktur',
        chapter: 'proyek',
        title: 'Proyek, sub proyek, dan tugas',
        summary: 'Proyek dipecah menjadi sub proyek yang punya lead. Tugas ada di dalam sub proyek.',
        audience: ['projects.view', 'projects.manage'],
        keywords: ['proyek', 'project', 'sub proyek', 'episode', 'sequence', 'aset', 'lead', 'anggota', 'tugas saya'],
        questions: ['Apa itu sub proyek?', 'Siapa lead sub proyek?', 'Cara menjadi anggota proyek'],
        blocks: [
            { type: 'p', text: 'Contoh di studio: proyek **Film Pendek**, sub proyek **Episode 1** dengan lead Team Lead animasi, lalu tugas seperti **Rigging wajah Nara** atau **Lighting shot 010_040**.' },
            {
                type: 'table',
                head: ['Bagian', 'Isi', 'Dibuat oleh'],
                rows: [
                    ['Proyek', 'Nama, kode, status, anggota', 'Team Lead, PM, PD, Superadmin'],
                    ['Sub proyek', 'Nama, lead, tenggat, status', 'Team Lead, PM, PD, Superadmin'],
                    ['Tugas', 'Judul, detail, prioritas, pengerja (satu orang atau lebih), tenggat, estimasi, wajib bukti', 'Lead langsung, atau anggota lewat usulan'],
                ],
            },
            { type: 'p', text: 'Anggota proyek ditugaskan di halaman proyek. Hanya anggota yang bisa mengusulkan dan mengambil tugas. Menu **Tugas saya** berisi timer yang berjalan, tugasmu, usulanmu, dan proyek yang ditugaskan kepadamu.' },
        ],
    },
    {
        id: 'usul-tugas',
        chapter: 'proyek',
        title: 'Mengusulkan tugas',
        summary: 'Anggota proyek mengusulkan tugas di sub proyek. Lead menyetujui atau menolak dengan alasan.',
        audience: ['activity.log'],
        keywords: ['usul', 'usulan', 'propose', 'tugas baru', 'buat tugas', 'ditolak', 'disetujui', 'task', 'pengerja', 'ambil tugas'],
        questions: ['Cara membuat tugas', 'Kenapa tugas saya harus disetujui?', 'Usulan tugas saya ditolak'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Buka proyek, pilih sub proyek.',
                    'Tekan **Usulkan tugas**. Tulis judul berupa hasil yang dituju, detail, prioritas, tenggat, dan estimasi jam kalau ada.',
                    'Tekan **Kirim usulan**. Usulan masuk ke lead sub proyek, dan kamu otomatis jadi pengerjanya.',
                    'Lead menyetujui (tugas masuk daftar **Belum mulai**, lead boleh menambah atau mengganti pengerja) atau menolak dengan alasan. Alasannya tampil di **Tugas saya**, bagian **Usulanmu**.',
                ],
            },
            { type: 'flow', flow: 'alur-tugas' },
            { type: 'p', text: 'Tugas yang dibuat lead langsung masuk daftar, dengan satu pengerja atau lebih, atau tanpa pengerja. Tugas tanpa pengerja bisa diambil anggota proyek dengan **Ambil tugas ini**; yang mengambil jadi pengerjanya.' },
        ],
    },
    {
        id: 'urutan-tugas',
        chapter: 'proyek',
        title: 'Urutan tugas',
        summary: 'Lead sub proyek menentukan tugas mana yang dikerjakan dulu, dengan menyeret atau tombol panah.',
        audience: ['projects.manage'],
        keywords: ['urutan', 'urut', 'geser', 'seret', 'drag', 'prioritas', 'atur urutan', 'susun', 'pindah tugas'],
        questions: ['Cara mengubah urutan tugas', 'Kenapa tugas baru ada di paling bawah?', 'Siapa yang bisa mengatur urutan tugas?'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Buka sub proyek, lalu tekan **Atur urutan**.',
                    'Seret tugas lewat pegangan titik-titik di kirinya, di komputer maupun di HP. Dengan keyboard, pakai tombol panah naik dan turun.',
                    'Urutan tersimpan setiap kali tugas dipindah. Tekan **Selesai mengatur** untuk kembali ke daftar biasa.',
                ],
            },
            {
                type: 'list',
                items: [
                    'Yang bisa mengatur: lead sub proyek, serta PM, PD, dan Superadmin. Kalau sub proyek belum punya lead, semua Team Lead bisa.',
                    'Urutan berlaku di tiap kelompok status, misalnya **Belum mulai**, dan terlihat oleh seluruh tim.',
                    'Tugas baru masuk paling bawah. Pindahkan ke atas kalau perlu dikerjakan lebih dulu.',
                ],
            },
        ],
    },
    {
        id: 'timer-bukti',
        chapter: 'proyek',
        title: 'Timer, bukti, dan review',
        summary: 'Nyalakan timer saat mengerjakan, kirim bukti untuk review. Sesi timer otomatis masuk Log kerja.',
        audience: ['activity.log'],
        keywords: ['timer', 'mulai timer', 'jeda', 'bukti', 'evidence', 'kirim bukti', 'review', 'revisi', 'selesai', 'upload', 'file', 'tautan', 'pengerja', 'bagian', 'tugas bersama', 'belum kirim'],
        questions: ['Cara kirim bukti tugas', 'Tugas saya diminta revisi', 'Apakah timer tugas sama dengan absen?', 'File bukti terlalu besar', 'Satu tugas dikerjakan beberapa orang'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Buka tugasmu dan tekan **Mulai timer**. Timer tampil di bilah atas semua halaman. Satu orang satu timer: mulai di tugas lain menjeda yang berjalan.',
                    '**Jeda timer** saat berhenti. Setiap mulai sampai jeda adalah satu sesi.',
                    'Selesai? **Kirim bukti**: tulis apa yang dikerjakan, isi tautan bukti atau unggah file (gambar, PDF, video pendek, ZIP, maksimal 20 MB). File render besar kirim lewat tautan.',
                    'Lead mereview bagianmu: **Setujui bagian ini** atau **Minta revisi** dengan catatan.',
                    'Diminta revisi? Catatannya tampil di atas tugas. Nyalakan timer lagi, perbaiki, dan kirim bukti lagi.',
                ],
            },
            {
                type: 'p',
                text: 'Satu tugas bisa dikerjakan beberapa orang. Setiap pengerja punya **bagian** sendiri: timer sendiri, bukti sendiri, dan review sendiri. Sesi timer orang lain tidak ikut masuk Log kerjamu.',
            },
            {
                type: 'table',
                head: ['Status bagian', 'Artinya'],
                rows: [
                    ['Belum kirim', 'Pengerja belum mengirim bukti di putaran ini'],
                    ['Menunggu review', 'Bukti sudah dikirim. Review dimulai setelah semua pengerja mengirim'],
                    ['Perlu revisi', 'Lead minta perbaikan. Pengerja itu memperbaiki dan mengirim lagi'],
                    ['Disetujui', 'Bagian itu selesai. Tugas selesai setelah semua bagian disetujui'],
                ],
            },
            { type: 'note', title: 'Timer tugas bukan absen', text: 'Timer tugas mencatat waktu per tugas untuk Log kerja. Absen masuk dan pulang tetap lewat Hari ini atau aplikasi desktop.' },
            { type: 'p', text: 'Status bagianmu tampil di bagian **Pengerja** di halaman tugas dan di **Tugas saya**. Tugas yang wajib bukti tidak bisa dikirim tanpa tautan atau file. Lead bisa mematikan **wajib bukti** untuk tugas tanpa file, misalnya rapat.' },
        ],
    },
    {
        id: 'log-kerja',
        chapter: 'proyek',
        title: 'Log kerja',
        summary: 'Catatan apa yang dikerjakan per proyek. Terisi otomatis dari sesi timer, atau dicatat manual.',
        audience: ['activity.log'],
        keywords: ['log kerja', 'logbook', 'aktivitas', 'catat aktivitas', 'otomatis', 'jurnal', 'timesheet'],
        questions: ['Bagaimana tugas jadi logbook?', 'Cara mencatat aktivitas manual', 'Log kerja dari tugas'],
        blocks: [
            { type: 'p', text: 'Saat kamu mengirim bukti, **setiap sesi timer milikmu** di tugas itu yang belum tercatat menjadi satu baris Log kerja: proyek, jam mulai dan selesai, deskripsi berisi judul tugas dan catatanmu, dan tautan bukti. Sesi pengerja lain masuk Log kerja mereka saat mereka mengirim bukti.' },
            {
                type: 'list',
                items: [
                    'Sesi di bawah 1 menit dilewati.',
                    'Tidak pakai timer? Form kirim bukti menawarkan jam mulai dan selesai kerja; kalau diisi, dibuat satu baris log.',
                    'Baris dari tugas bertanda **Dari tugas: ...** dan bisa diubah seperti log biasa.',
                    'Revisi berikutnya hanya membawa sesi baru, tidak menggandakan yang lama.',
                ],
            },
            { type: 'p', text: 'Log manual: menu **Log kerja**, **Catat aktivitas**. Proyek wajib, deskripsi minimal 10 karakter, tautan bukti wajib. Interval waktu boleh saling overlap.' },
        ],
    },

    {
        id: 'tahap-milestone',
        chapter: 'proyek',
        title: 'Tahap produksi, milestone, dan anggaran jam',
        summary: 'Tugas ditandai tahap pipeline. Proyek punya milestone. PM dan PD mengatur anggaran jam.',
        audience: ['projects.view', 'projects.manage'],
        keywords: ['tahap', 'pipeline', 'storyboard', 'animasi', 'render', 'milestone', 'deadline', 'delivery', 'klien', 'anggaran', 'budget', 'jam'],
        questions: ['Apa itu tahap di tugas?', 'Cara menambah milestone', 'Cara mengatur anggaran jam proyek'],
        blocks: [
            { type: 'p', text: '**Tahap** menunjukkan di bagian pipeline mana sebuah tugas berada, misalnya Storyboard, Animasi, atau Compositing. Pilih tahap saat membuat atau mengubah tugas. Di halaman sub proyek, daftar tugas bisa disaring per tahap.' },
            { type: 'p', text: '**Milestone** ada di halaman proyek: nama, jenis (internal, review klien, delivery), dan tanggal. Statusnya dihitung otomatis: **Terjadwal**, **Segera** (7 hari lagi atau kurang), **Terlambat**, atau **Selesai**. Team Lead, PM, PD, dan Superadmin bisa menambah, mengubah, dan menandai selesai.' },
            { type: 'p', text: '**Anggaran jam** diisi dalam jam di form proyek dan sub proyek, hanya oleh Project Manager, Project Director, dan Superadmin, dan hanya mereka yang melihat angkanya. Jam terpakai dihitung dari **Log kerja**, jadi sesi timer baru terhitung setelah bukti dikirim. Meter berubah emas mulai 80 persen dan merah lewat 100 persen.' },
        ],
    },
    {
        id: 'dokumen-proyek',
        chapter: 'proyek',
        title: 'Dokumen proyek',
        summary: 'Tautan folder dan file Google Drive per tahap, langsung di halaman proyek.',
        audience: ['projects.view', 'projects.manage'],
        keywords: ['dokumen', 'drive', 'google drive', 'folder', 'tautan', 'link', 'linktree', 'skenario', 'storyboard', 'aset karakter', 'sound', 'voice over', 'final', 'project tracker'],
        questions: ['Di mana folder Drive proyek?', 'Cara menambah tautan dokumen', 'Kenapa saya tidak melihat dokumen proyek?'],
        blocks: [
            { type: 'p', text: 'Bagian **Dokumen** di halaman proyek berisi tautan ke folder dan file proyek, misalnya **Skenario**, **Storyboard**, **Aset karakter**, **Sound**, **Voice over**, **Animasi**, **Final**, dan **Project tracker**. Klik tautannya untuk membuka Drive di tab baru, atau tombol salin untuk mengirimnya ke chat.' },
            {
                type: 'list',
                items: [
                    'Hanya orang yang terlibat di proyek yang melihat tautannya: anggota proyek, lead sub proyek, dan pengerja tugas di proyek itu. Team Lead, PM, PD, dan Superadmin melihat semuanya.',
                    'File tetap di Google Drive. Kalau Drive meminta **Minta akses**, hubungi pemilik folder: izin buka file diatur di Drive, bukan di aplikasi ini.',
                    'Tautan bertanda **Hanya pengelola** tidak terlihat oleh anggota biasa.',
                ],
            },
            {
                type: 'steps',
                items: [
                    'Team Lead, PM, PD, atau Superadmin membuka halaman proyek, lalu **Tambah tautan**.',
                    'Pilih jenisnya. Untuk yang tidak ada di daftar, pilih **Lainnya** dan isi namanya.',
                    'Di Google Drive, klik **Bagikan**, lalu **Salin link**, dan tempel di **Alamat tautan**. Alamat harus diawali https://.',
                    'Urutan diatur dengan **Atur urutan**, lalu tombol naik dan turun di tiap tautan.',
                ],
            },
        ],
    },
    // Management
    {
        id: 'persetujuan',
        chapter: 'manajemen',
        title: 'Persetujuan lembur',
        summary: 'Cocokkan dengan perintah lembur, lalu Setujui atau Tolak dengan catatan.',
        audience: ['overtime.approve', 'overtime.change_decisions'],
        keywords: ['approve', 'setujui', 'tolak lembur', 'persetujuan', 'approval', 'klaim susulan', 'setujui sekaligus', 'ubah keputusan'],
        questions: ['Cara menyetujui lembur', 'Cara menolak lembur', 'Setujui banyak lembur sekaligus'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Buka **Persetujuan**. Angka emas di menu adalah jumlah yang menunggu, paling lama di atas.',
                    'Pilih lembur. Baca alasan, laporan, garis waktu, dan PC diam.',
                    'Cocokkan dengan perintah lembur di chat tim.',
                    '**Setujui**, atau **Tolak** dengan catatan wajib yang dibaca orangnya.',
                ],
            },
            { type: 'flow', flow: 'alur-persetujuan' },
            {
                type: 'table',
                head: ['Tanda', 'Arti'],
                rows: [
                    ['Klaim susulan', 'Shift ditutup otomatis, lalu lembur diajukan belakangan'],
                    ['Shift perlu dicek', 'Shift ditutup saat terakhir server melihat perangkatnya, bukan saat absen pulang'],
                    ['Jam PC berbeda', 'Jam PC berbeda lebih dari 2 menit dari server; jam server yang dipakai'],
                    ['Jeda belum pasti', 'Ada jeda yang tidak bisa dipastikan server dan tidak dihitung'],
                ],
            },
            { type: 'p', text: 'Tidak ada yang bisa memutuskan lemburnya sendiri. **Pilih yang tanpa tanda** tidak memeriksa perintah lembur, jadi pastikan semua yang terpilih sudah diperintah. Project Director bisa **Ubah keputusan** dengan alasan wajib.' },
        ],
    },
    {
        id: 'lead-tugas',
        chapter: 'manajemen',
        title: 'Memimpin sub proyek: usulan dan review',
        summary: 'Lead memutuskan usulan tugas dan mereview bukti. Badge Tugas saya menunjukkan yang menunggu.',
        audience: LEAD_TASKS,
        keywords: ['lead', 'keputusan', 'setujui usulan', 'tolak usulan', 'review bukti', 'minta revisi', 'sub proyek baru', 'tim lead', 'pengerja', 'beberapa pengerja', 'tugaskan'],
        questions: ['Cara menyetujui usulan tugas', 'Cara review bukti tugas', 'Siapa yang bisa menolak usulan?', 'Cara menugaskan beberapa orang ke satu tugas'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Buat sub proyek di halaman proyek: **Sub proyek baru**, isi nama dan pilih **Lead**.',
                    'Lihat **Tugas saya**, bagian **Menunggu keputusanmu**. Angka di menu adalah jumlahnya.',
                    'Usulan: centang atau ganti pengerja kalau perlu, lalu **Setujui** atau **Tolak** dengan alasan minimal 10 karakter.',
                    'Bukti: setiap pengerja punya kartu review sendiri. Buka tautan atau file, lalu **Setujui bagian ini** atau **Minta revisi** dengan catatan. Tugas selesai setelah semua bagian disetujui.',
                ],
            },
            {
                type: 'p',
                text: 'Pengerja dipilih di form tugas (**Tambah tugas** atau **Ubah**): centang satu orang atau lebih dari anggota proyek, paling banyak 20. Review baru bisa dimulai setelah semua pengerja mengirim bukti; sebelum itu tampil "Menunggu N orang lagi mengirim bukti". Pengerja yang diminta revisi tidak menahan review pengerja lain.',
            },
            {
                type: 'p',
                text: 'Menambah pengerja ke tugas yang sudah selesai membuat tugas itu berjalan lagi. Mengeluarkan pengerja menghapus bagiannya dan menghentikan timernya di tugas itu; sesi yang belum masuk Log kerja tetap tersimpan.',
            },
            {
                type: 'table',
                head: ['Siapa', 'Boleh memutuskan dan mereview'],
                rows: [
                    ['Lead sub proyek', 'Sub proyek yang dia pimpin'],
                    ['Team Lead lain', 'Sub proyek tanpa lead'],
                    ['Project Manager, Project Director, Superadmin', 'Semua sub proyek'],
                ],
            },
            { type: 'p', text: 'Lead menambah tugas langsung ke daftar dengan **Tambah tugas**. Tidak ada yang mereview buktinya sendiri, kecuali PM, PD, dan Superadmin.' },
        ],
    },
    {
        id: 'tim-hari-ini',
        chapter: 'manajemen',
        title: 'Tim hari ini',
        summary: 'Papan status tim yang diperbarui tiap 30 detik.',
        audience: ['team_board.view'],
        keywords: ['papan', 'board', 'status tim', 'siapa masuk', 'siapa lembur'],
        questions: ['Melihat siapa yang sudah absen', 'Siapa yang sedang lembur?'],
        blocks: [
            { type: 'p', text: 'Orang dikelompokkan: **Perlu jawaban**, **Lembur**, **Masuk**, **PC diam atau terputus**, **Sudah pulang**, dan **Belum absen hari ini**. Team Lead hanya melihat anggota tim yang dia pimpin.' },
        ],
    },
    {
        id: 'monitor-kerja',
        chapter: 'manajemen',
        title: 'Monitor kerja',
        summary: 'Tugas yang terlambat, kerja masuk dan selesai per minggu, jam per proyek, kualitas review, dan milestone.',
        audience: ['monitoring.work'],
        keywords: ['monitor kerja', 'grafik', 'progres', 'dashboard', 'terlambat', 'deadline', 'retake', 'revisi', 'throughput', 'anggaran', 'jam proyek'],
        questions: ['Tugas mana yang terlambat?', 'Apakah tim menyelesaikan tugas tepat waktu?', 'Berapa jam yang habis per proyek?', 'Seberapa sering hasil kerja perlu revisi?'],
        blocks: [
            { type: 'p', text: 'Pilih proyek (semua atau satu) dan periode 4, 8, atau 12 minggu. Minggu dihitung Senin sampai Minggu. Usulan tugas yang belum diputuskan dan yang ditolak tidak pernah dihitung.' },
            {
                type: 'table',
                head: ['Bagian', 'Menjawab'],
                rows: [
                    ['Angka utama', 'Berapa tugas lewat tenggat, terbuka, menunggu review, dan selesai dalam periode'],
                    ['Tugas lewat atau dekat tenggat', 'Tugas terbuka yang sudah lewat atau jatuh tempo 7 hari lagi'],
                    ['Milestone 30 hari ke depan', 'Milestone yang terlambat atau segera jatuh tempo'],
                    ['Tugas dibuat dan selesai per minggu', 'Apakah kerja selesai secepat kerja masuk'],
                    ['Seberapa sering hasil kerja perlu revisi', 'Rasio minta revisi dari semua review, dan median waktu tunggu review'],
                    ['Di mana tugas menumpuk', 'Status tugas per proyek, atau per sub proyek bila satu proyek dipilih'],
                    ['Progres per tahap', 'Status tugas per tahap pipeline, bila satu proyek dipilih'],
                    ['Ke mana jam kerja habis', 'Jam di Log kerja per proyek dalam periode'],
                    ['Anggaran jam', 'Jam terpakai sejak proyek mulai dibanding anggaran. Hanya PM, PD, dan Superadmin'],
                ],
            },
            { type: 'p', text: 'Team Lead melihat tugas yang salah satu pengerjanya anggota tim yang dia pimpin, dan semua tugas di sub proyek yang dia pimpin. Project Manager, Project Director, dan Superadmin melihat seluruh studio. Setiap grafik punya **Lihat sebagai tabel** untuk angka lengkapnya.' },
        ],
    },
    {
        id: 'beban-kerja',
        chapter: 'manajemen',
        title: 'Beban kerja',
        summary: 'Rencana kerja tiap orang dibandingkan kapasitasnya dalam satu minggu.',
        audience: ['monitoring.work'],
        keywords: ['beban kerja', 'kapasitas', 'workload', 'overload', 'kelebihan', 'estimasi', 'jadwal', 'siapa kosong'],
        questions: ['Siapa yang kelebihan tugas minggu ini?', 'Siapa yang masih bisa diberi tugas?', 'Kenapa kapasitas seseorang berkurang?'],
        blocks: [
            {
                type: 'table',
                head: ['Angka', 'Cara menghitung'],
                rows: [
                    ['Kapasitas', 'Target kerja mingguan orang itu, sudah dikurangi libur dan cuti yang disetujui (lihat Target kerja mingguan). Freelance: hari kerja minggu itu dikurangi cuti, dikali 8 jam'],
                    ['Rencana', 'Bagiannya dari sisa estimasi tugas yang bagiannya belum dikirim atau perlu revisi, dengan tenggat sampai akhir minggu itu (termasuk yang sudah lewat). Sisa = estimasi dikurangi semua menit timer di tugas itu, lalu dibagi rata ke semua pengerja. Contoh: tugas 10 jam dengan 2 pengerja masuk 5 jam ke rencana masing-masing'],
                    ['Tanpa estimasi', 'Tugas di kelompok yang sama yang belum punya estimasi. Isi estimasinya supaya rencana akurat'],
                    ['Log kerja dan jam reguler', 'Jam yang tercatat di Log kerja dan jam reguler absen minggu itu'],
                ],
            },
            { type: 'p', text: 'Status: **Longgar** di bawah 50 persen kapasitas, **Pas** 50 sampai 79 persen, **Penuh** 80 sampai 100 persen, **Lebih** di atas 100 persen. Orang yang **Lebih** tampil paling atas. Bagian yang menunggu review dan tugas tanpa tenggat tidak masuk rencana.' },
            { type: 'p', text: 'Pindah minggu dengan tombol panah atau **Minggu ini**. Team Lead melihat anggota tim yang dia pimpin; PM, PD, dan Superadmin melihat semua karyawan aktif. Manajerial (Team Lead, PM, PD) dan Superadmin tidak masuk daftar Beban kerja.' },
        ],
    },
    {
        id: 'buka-hari-kerja',
        chapter: 'manajemen',
        title: 'Membuka hari kerja tambahan',
        summary: 'Buka hari libur sebagai hari kerja untuk tim atau orang, supaya dihitung aturan 8 jam biasa.',
        audience: ['calendar.open_workdays', 'calendar.manage'],
        keywords: ['kalender', 'buka hari kerja', 'sabtu kerja', 'hari tambahan', 'tutup lagi'],
        questions: ['Tim harus kerja hari Sabtu', 'Cara membuka hari kerja'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Buka **Kalender**, pilih tanggalnya.',
                    'Tekan **Buka untuk tim atau orang**, pilih tim atau orangnya, isi catatan kalau perlu.',
                    'Tekan **Buka hari kerja**.',
                ],
            },
            { type: 'p', text: 'Menutup lagi: pilih tanggal, **Tutup lagi**. Kalau tanggalnya sudah lewat, shift yang tercatat dihitung ulang.' },
        ],
    },
    {
        id: 'laporan',
        chapter: 'manajemen',
        title: 'Laporan bulanan',
        summary: 'Rekap per orang: hari kerja, jam reguler, lembur per status, PC diam, dan rincian harian.',
        audience: ['reports.view_team', 'reports.view_all'],
        keywords: ['laporan', 'rekap', 'report', 'excel', 'ekspor', 'unduh'],
        questions: ['Melihat rekap tim', 'Cara unduh Excel'],
        blocks: [
            { type: 'p', text: 'Pilih nama untuk **rincian harian**. **Arti kolom** menjelaskan tiap kolom. PC diam hanya konteks, tidak mengurangi jam. Hanya lembur disetujui yang dihitung bayar. Bulan berjalan bisa berubah angkanya.' },
            { type: 'p', text: '**Unduh Excel** hanya untuk Superadmin. Ambil ekspor setelah semua lembur bulan itu diputuskan, simpan di folder dengan akses terbatas.' },
        ],
    },
    {
        id: 'ajukan-koreksi',
        chapter: 'manajemen',
        title: 'Mengajukan koreksi jam',
        summary: 'Management mengajukan, Superadmin menerapkan. Alasan wajib dan dikirim ke orangnya.',
        audience: ['corrections.propose', 'corrections.apply'],
        keywords: ['koreksi', 'ajukan koreksi', 'correction', 'jam masuk', 'jam pulang', 'selesai lembur'],
        questions: ['Cara mengajukan koreksi', 'Anggota tim lupa absen pulang'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Buka **Koreksi**, tekan **Ajukan koreksi**.',
                    'Pilih orang, tanggal kerja, dan shift.',
                    'Pilih jam yang dikoreksi (Jam masuk, Jam pulang, Mulai lembur, Selesai lembur) dan isi **Jam seharusnya** dalam WIB.',
                    'Isi alasan minimal 10 karakter. Cek **Hasilnya di tanggal ini**.',
                    'Tekan **Kirim ke Superadmin**.',
                ],
            },
            { type: 'flow', flow: 'alur-koreksi' },
            { type: 'p', text: 'Batasan: shift yang masih berjalan baru bisa dikoreksi setelah selesai, hari tanpa absen belum bisa ditambahkan, dan tidak ada yang bisa mengoreksi catatannya sendiri.' },
        ],
    },

    // Superadmin
    {
        id: 'orang',
        chapter: 'admin',
        title: 'Orang: akun, status, kata sandi',
        summary: 'Tambah orang, atur peran dan tim, ubah status, dan buat kata sandi sementara.',
        audience: ['users.manage'],
        keywords: ['tambah orang', 'akun baru', 'user', 'status akun', 'nonaktif', 'keluar', 'reset password', 'kata sandi sementara', 'peran', 'role'],
        questions: ['Cara menambah karyawan', 'Reset kata sandi karyawan', 'Menonaktifkan akun'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Buka **Orang**, **Tambah orang**. Isi nama lengkap dan username (tidak bisa diganti nanti).',
                    'Pilih jenis karyawan, peran (minimal satu), dan tim.',
                    '**Buat akun**. Kata sandi sementara tampil sekali. **Salin pesan** dan kirim lewat jalur pribadi.',
                ],
            },
            {
                type: 'table',
                head: ['Status', 'Arti'],
                rows: [
                    ['Aktif', 'Bisa masuk di web dan aplikasi desktop'],
                    ['Dinonaktifkan', 'Tidak bisa masuk. Riwayat tersimpan, bisa diaktifkan lagi'],
                    ['Sudah keluar', 'Sudah tidak bekerja di studio. Riwayat tersimpan'],
                ],
            },
            { type: 'p', text: 'Lupa kata sandi: di dialog Ubah, **Buat kata sandi sementara baru**. Reset kata sandi tidak mengeluarkan orang itu dari PC yang sedang dipakai; pakai status Dinonaktifkan kalau aksesnya harus berhenti.' },
        ],
    },
    {
        id: 'tim-kalender',
        chapter: 'admin',
        title: 'Tim dan kalender studio',
        summary: 'Kelola tim dan Team Lead-nya, minggu kerja, hari libur, dan libur studio.',
        audience: ['teams.manage', 'calendar.manage'],
        keywords: ['tim', 'team lead', 'kalender', 'hari libur', 'libur studio', 'minggu kerja', 'cuti bersama'],
        questions: ['Menambah hari libur', 'Mengganti Team Lead'],
        blocks: [
            { type: 'p', text: '**Tim**: buat tim, lalu **Kelola** untuk mengganti nama, memilih Team Lead (harus berperan Team Lead), dan menambah atau mengeluarkan anggota.' },
            {
                type: 'list',
                items: [
                    '**Minggu kerja studio**: centang hari kerja, lalu **Simpan minggu kerja**. Shift yang sudah tercatat dihitung ulang.',
                    '**Hari libur** dan **Libur studio**: semua jam di tanggal itu dihitung lembur.',
                    '**Hari kerja studio**: tanggal yang biasanya libur menjadi hari kerja untuk semua.',
                ],
            },
        ],
    },
    {
        id: 'perangkat',
        chapter: 'admin',
        title: 'Perangkat dan cabut akses',
        summary: 'Cabut akses PC atau browser yang hilang atau tidak dipercaya. Jangan cabut PC studio bersama.',
        audience: ['devices.manage'],
        keywords: ['perangkat', 'device', 'cabut akses', 'revoke', 'pc hilang', 'browser', 'pulihkan'],
        questions: ['PC hilang', 'Mencabut akses browser'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Cari perangkatnya di **Perangkat**, tekan **Cabut akses**.',
                    'Isi alasan. Kalau ada shift berjalan di sana, baca peringatannya dan centang persetujuan.',
                    'Tekan **Cabut akses**. **Pulihkan** mengembalikannya.',
                ],
            },
            { type: 'note', title: 'PC bersama', text: 'PC desktop yang dicabut tidak bisa dipakai masuk oleh siapa pun sampai dipulihkan. Jangan cabut PC studio hanya karena satu orang keluar.' },
        ],
    },
    {
        id: 'aturan',
        chapter: 'admin',
        title: 'Aturan absen',
        summary: 'Angka yang dipakai mesin absen: batas jam reguler, pengingat, terputus, dan absen web.',
        audience: ['settings.manage'],
        keywords: ['aturan', 'setelan', 'settings', 'batas jam', '480 menit', 'absen web', 'matikan absen web', 'target', '40 jam', 'magang'],
        questions: ['Mengubah batas 8 jam', 'Mematikan absen dari web'],
        blocks: [
            {
                type: 'table',
                head: ['Aturan', 'Bawaan'],
                rows: [
                    ['Batas jam reguler per hari', '480 menit'],
                    ['Ulangi pengingat 8 jam setiap', '10 menit'],
                    ['Batas menjawab pengingat 8 jam', '30 menit'],
                    ['Tanya masih lembur setelah', '60 menit'],
                    ['Batas menjawab masih lembur', '30 menit'],
                    ['Batas klaim lembur susulan', '24 jam'],
                    ['PC dianggap diam setelah', '10 menit'],
                    ['Batas melanjutkan shift terputus', '90 menit'],
                    ['Boleh masuk tanpa internet selama', '14 hari'],
                    ['Absen dari web', 'Nyala'],
                    ['Target karyawan tetap dan kontrak', '40 jam per minggu'],
                    ['Kehadiran magang per minggu', '2 hari'],
                    ['Jam kerja magang per hari', '480 menit'],
                ],
            },
            { type: 'p', text: 'Batas jam reguler hanya berlaku untuk shift baru. Ubah hanya atas keputusan owner. Setiap perubahan masuk Log audit.' },
            { type: 'p', text: 'Target magang bisa diganti per orang di form **Orang**, bagian **Target magang**, yang muncul saat jenis karyawan Magang dipilih. Kosongkan untuk memakai bawaan.' },
        ],
    },
    {
        id: 'pengaturan-aplikasi',
        chapter: 'admin',
        title: 'Pengaturan aplikasi: nama, logo, footer',
        summary: 'Ganti nama aplikasi, nama studio, logo, teks dan tautan footer, serta email kontak.',
        audience: ['settings.manage'],
        keywords: ['logo', 'nama aplikasi', 'footer', 'branding', 'email kontak', 'ganti logo', 'favicon'],
        questions: ['Cara mengganti logo', 'Mengubah nama aplikasi', 'Mengubah tulisan footer'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Buka **Pengaturan aplikasi** di grup Admin.',
                    'Logo: **Unggah logo** (PNG, JPG, atau WebP, maksimal 1 MB, persegi paling rapi). **Kembali ke logo bawaan** untuk logo Owlorix.',
                    'Isi nama aplikasi, nama studio, teks footer, tautan footer (teks dan alamat diisi berdua), dan email kontak. Cek **Pratinjau footer**.',
                    'Tekan **Simpan pengaturan**.',
                ],
            },
            { type: 'p', text: 'Logo tampil di menu, halaman masuk, dan ikon tab browser. Judul tab berganti setelah halaman dimuat ulang.' },
        ],
    },
    {
        id: 'audit-koreksi-ekspor',
        chapter: 'admin',
        title: 'Log audit, koreksi, dan ekspor',
        summary: 'Semua perubahan tercatat. Superadmin menerapkan koreksi dan mengambil ekspor Excel bulanan.',
        audience: ['audit.view', 'corrections.apply', 'reports.export'],
        keywords: ['log audit', 'audit', 'terapkan koreksi', 'koreksi langsung', 'excel', 'ekspor'],
        questions: ['Melihat siapa yang mengubah data', 'Cara menerapkan koreksi'],
        blocks: [
            { type: 'p', text: '**Log audit** mencatat setiap perubahan: kalender, orang, tim, keputusan lembur, ekspor, perangkat, aturan, koreksi, profil, pengaturan aplikasi, dan tugas. **Rincian** menampilkan nilai sebelum dan sesudah.' },
            { type: 'p', text: '**Koreksi**: tab Menunggu berisi ajuan Management; **Terapkan koreksi** atau **Tolak** dengan catatan. **Koreksi langsung** membuat dan menerapkan sekaligus.' },
            { type: 'p', text: '**Laporan**, **Unduh Excel (bulan)**: lembar Ringkasan dan Harian. Lembur disetujui menjadi dasar bayar. Setiap ekspor tercatat di Log audit.' },
        ],
    },
    {
        id: 'monitor-aktivitas',
        chapter: 'admin',
        title: 'Monitor aktivitas',
        summary: 'Gagal masuk beruntun, akses ditolak, siapa yang online, dan linimasa semua aktivitas.',
        audience: ['monitoring.activity'],
        keywords: ['monitor', 'aktivitas', 'log', 'login', 'gagal masuk', 'keamanan', 'online', 'akses ditolak', 'linimasa'],
        questions: ['Siapa yang gagal masuk?', 'Siapa yang sedang online?', 'Melihat semua aktivitas seseorang'],
        blocks: [
            { type: 'p', text: '**Perlu dicek** ada di paling atas: username dengan 5 kali atau lebih gagal masuk dalam 24 jam, dan orang yang ditolak aksesnya (403). Kosong berarti tidak ada yang perlu ditindak.' },
            { type: 'p', text: '**Sedang online** menampilkan sesi web dan PC desktop yang aktif 5 menit terakhir, saat halaman dibuka. **Linimasa** menggabungkan akses (masuk, halaman, aksi, unduhan), perubahan data dari Log audit, dan absensi. Saring per orang, jenis, dan tanggal.' },
            {
                type: 'table',
                head: ['Dicatat', 'Tidak dicatat'],
                rows: [
                    ['Masuk, gagal masuk, keluar, terkunci, di web dan desktop', 'Kata sandi dan isi form'],
                    ['Halaman yang dibuka dan tombol yang ditekan, dengan statusnya', 'Query pencarian di alamat halaman'],
                    ['Akses ditolak dan unduhan bukti, CV, lampiran cuti, ekspor', 'Apa pun di PC di luar Owlorix HR'],
                ],
            },
            { type: 'p', text: 'Catatan akses disimpan sesuai **Aturan > Monitor aktivitas** (bawaan 365 hari) dan dihapus otomatis tiap malam pukul 02.30. Log audit dan data absensi tidak ikut terhapus.' },
        ],
    },
    {
        id: 'pipeline-admin',
        chapter: 'admin',
        title: 'Mengatur pipeline produksi',
        summary: 'Tahap pra-produksi, produksi, dan pasca-produksi yang bisa dipilih di tugas.',
        audience: ['pipeline.manage'],
        keywords: ['pipeline', 'tahap', 'urutan tahap', 'nonaktif', 'pra-produksi', 'pasca-produksi'],
        questions: ['Cara menambah tahap pipeline', 'Cara mengubah urutan tahap'],
        blocks: [
            { type: 'p', text: 'Buka **Admin > Pipeline produksi**. Tahap bawaan adalah usulan untuk studio animasi dan boleh diubah. Tambah tahap per fase, ganti nama, dan ubah urutan dengan tombol naik dan turun (bisa dengan keyboard).' },
            { type: 'p', text: 'Tahap yang sudah dipakai tugas tidak bisa dihapus, hanya dinonaktifkan. Tahap nonaktif tidak bisa dipilih untuk tugas baru, tapi tetap tampil di tugas lama. Fase tahap tidak bisa diubah setelah dibuat.' },
        ],
    },
    {
        id: 'admin-cuti',
        chapter: 'admin',
        title: 'Mengatur cuti: jenis, kuota, dan semua pengajuan',
        summary: 'Tiga tab: Pengajuan, Kuota per tahun, dan Jenis cuti.',
        audience: ['leave.manage'],
        keywords: ['kuota cuti', 'jenis cuti', 'admin cuti', 'cuti tahunan', '12 hari'],
        questions: ['Cara mengubah kuota cuti seseorang', 'Cara menambah jenis cuti'],
        blocks: [
            { type: 'p', text: '**Pengajuan**: semua pengajuan dengan saringan status, orang, dan bulan. Superadmin bisa memutuskan siapa saja dan membatalkan cuti yang disetujui dengan catatan.' },
            { type: 'p', text: '**Kuota**: jatah per orang per tahun. Tanpa isian khusus, kuota mengikuti **Aturan > Cuti** (bawaan 12 hari). Pengajuan yang melebihi kuota tetap bisa dikirim dan diputuskan; sisa kuota adalah catatan untuk Superadmin dan tidak ditampilkan ke karyawan atau Team Lead. Saat memutuskan cuti tahunan, dialognya menunjukkan sisa kuota orang itu, merah bila melebihi. Setiap perubahan kuota tercatat di Log audit.' },
            { type: 'p', text: '**Jenis cuti**: tambah, ganti nama, atur apakah memotong kuota dan wajib alasan, atau nonaktifkan. Jenis tidak pernah dihapus. Mengubah **memotong kuota** ikut mengubah saldo pengajuan yang sudah ada.' },
        ],
    },
    {
        id: 'sop-keluar',
        chapter: 'admin',
        title: 'Karyawan keluar',
        summary: 'Urutan: tunggakan beres, Team Lead pengganti, keputusan tuntas, status Sudah keluar, cabut perangkat pribadi.',
        audience: ['users.manage'],
        keywords: ['resign', 'keluar', 'berhenti kerja', 'offboarding', 'sop'],
        questions: ['Karyawan resign', 'Apa yang dilakukan kalau karyawan keluar?'],
        blocks: [
            {
                type: 'steps',
                items: [
                    'Minta orangnya absen pulang dan menulis laporan lembur yang tertunggak.',
                    'Kalau dia Team Lead, pilih Team Lead pengganti di **Tim**.',
                    'Putuskan lembur dan koreksi yang masih menunggu untuk orang itu.',
                    'Ubah **Status akun** menjadi **Sudah keluar** di **Orang**. Dia langsung keluar dari web dan semua PC.',
                    'Cabut akses browser atau perangkat pribadi yang hanya dia pakai. Jangan cabut PC studio bersama.',
                    'Cek Log audit bahwa semuanya tercatat.',
                ],
            },
        ],
    },

    // Masalah umum
    {
        id: 'masalah-umum',
        chapter: 'masalah',
        title: 'Pesan yang sering muncul',
        summary: 'Arti pesan error dan siapa yang dihubungi.',
        keywords: ['error', 'gagal', 'tidak bisa masuk', 'terlalu banyak percobaan', 'akun tidak aktif', 'akses dicabut', 'bantuan', 'hubungi', 'kontak', 'it'],
        questions: ['Tidak bisa login', 'Terlalu banyak percobaan', 'Akun ini tidak aktif', 'Siapa yang harus saya hubungi?'],
        blocks: [
            {
                type: 'table',
                head: ['Pesan atau masalah', 'Yang kamu lakukan', 'Hubungi'],
                rows: [
                    ['Username, email, atau kata sandi salah', 'Cek Caps Lock, coba lagi', 'Superadmin, untuk kata sandi sementara baru'],
                    ['Terlalu banyak percobaan', 'Tunggu sesuai waktu yang disebut', 'Superadmin bila terus terjadi'],
                    ['Akun ini tidak aktif', 'Tidak ada', 'Superadmin'],
                    ['Akses PC ini sudah dicabut', 'Pakai PC lain atau absen dari web', 'Superadmin'],
                    ['Tidak ada koneksi', 'Kerja jalan terus, catatan terkirim sendiri', 'Tim IT bila lebih dari setengah hari'],
                    ['Lupa absen pulang', 'Klaim lembur susulan dalam 24 jam kalau masih bekerja', 'Team Lead bila lewat batas'],
                    ['Shift web terputus', '**Lanjutkan shift** dalam 90 menit', 'Team Lead untuk koreksi bila perlu'],
                    ['Riwayat bertanda Perlu dicek', 'Sampaikan jam pulang yang benar', 'Team Lead atau Superadmin'],
                    ['Jam PC berbeda dengan server', 'Laporkan nama PC-nya', 'Tim IT'],
                    ['Aplikasi error, installer atau update gagal', 'Catat pesan error dan waktunya', 'Tim IT'],
                ],
            },
            { type: 'p', text: '**Superadmin**: akun, kata sandi, koreksi, kalender, aturan. **Team Lead**: lembur tim, koreksi. **HR**: aturan presensi, privasi, keberatan atas keputusan. **Tim IT**: aplikasi, server, PC studio, jaringan. Email kontak studio ada di footer bila Superadmin mengisinya.' },
        ],
    },
];

export const flows: Flow[] = [
    {
        id: 'alur-absen',
        title: 'Satu hari kerja',
        article: 'absen-desktop',
        steps: [
            { kind: 'start', text: 'Absen masuk (desktop: Masuk dan absen, web: Absen masuk)' },
            {
                kind: 'decision',
                text: 'Salah PC atau salah orang?',
                branches: [
                    { label: 'Ya, dalam 2 menit', steps: [{ kind: 'end', text: 'Batalkan absen masuk. Tidak ada yang tercatat' }] },
                    { label: 'Tidak', steps: [{ kind: 'step', text: 'Kerja. Jam reguler dihitung, PC diam dicatat (desktop)' }] },
                ],
            },
            {
                kind: 'decision',
                text: 'Sudah 8 jam reguler hari ini?',
                branches: [
                    { label: 'Belum', steps: [{ kind: 'end', text: 'Absen pulang. Ditandai Kurang dari 8 jam' }] },
                    { label: 'Sudah', steps: [{ kind: 'step', text: 'Kartu pengingat 8 jam. Lanjut ke alur lembur' }] },
                ],
            },
        ],
    },
    {
        id: 'alur-terputus',
        title: 'Browser tertutup atau PC mati',
        article: 'terputus',
        steps: [
            { kind: 'start', text: 'Shift berjalan, tanda aktif terkirim tiap menit' },
            { kind: 'step', text: 'Tab ditutup, HP terkunci, atau PC mati' },
            {
                kind: 'decision',
                text: 'Berapa lama tanpa tanda aktif?',
                branches: [
                    { label: 'Kurang dari 4 menit', steps: [{ kind: 'end', text: 'Shift jalan terus, tidak ada jeda' }] },
                    {
                        label: 'Lebih dari 4 menit',
                        steps: [
                            { kind: 'step', text: 'Shift terputus. Menit tanpa tanda aktif tidak dihitung' },
                            {
                                kind: 'decision',
                                text: 'Kembali ke perangkat yang sama dalam 90 menit?',
                                branches: [
                                    { label: 'Ya', steps: [{ kind: 'end', text: 'Lanjutkan shift. Jeda tercatat sebagai terputus' }] },
                                    { label: 'Tidak', steps: [{ kind: 'end', text: 'Shift ditutup di tanda aktif terakhir, perlu dicek. Absen masuk lagi untuk shift baru' }] },
                                ],
                            },
                        ],
                    },
                ],
            },
        ],
    },
    {
        id: 'alur-lembur',
        title: 'Lembur dari tanda 8 jam sampai dibayar',
        article: 'pengingat-8-jam',
        steps: [
            { kind: 'start', text: 'Perintah lembur dari Team Lead, PM, atau PD, sebelum mulai' },
            { kind: 'step', text: 'Kartu Sudah 8 jam hari ini. Tekan Jawab' },
            {
                kind: 'decision',
                text: 'Pilihanmu',
                branches: [
                    { label: 'Absen pulang', steps: [{ kind: 'end', text: 'Shift ditutup, tanpa lembur' }] },
                    { label: 'Tidak dijawab 30 menit', steps: [{ kind: 'end', text: 'Otomatis pulang di tanda 8 jam. Masih kerja? Klaim susulan dalam 24 jam' }] },
                    {
                        label: 'Lanjut lembur',
                        steps: [
                            { kind: 'step', text: 'Tulis alasan. Lembur dihitung dari tanda 8 jam' },
                            { kind: 'step', text: 'Masih lembur? dijawab selama lembur' },
                            { kind: 'step', text: 'Absen pulang dan tulis laporan lembur' },
                            { kind: 'step', text: 'Management mencocokkan dengan perintah lembur' },
                            {
                                kind: 'decision',
                                text: 'Keputusan',
                                branches: [
                                    { label: 'Disetujui', steps: [{ kind: 'end', text: 'Masuk rekap bayar lembur' }] },
                                    { label: 'Ditolak', steps: [{ kind: 'end', text: 'Tercatat, tidak dibayar. Catatan tampil di halaman Lembur' }] },
                                ],
                            },
                        ],
                    },
                ],
            },
        ],
    },
    {
        id: 'alur-persetujuan',
        title: 'Memutuskan lembur',
        article: 'persetujuan',
        steps: [
            { kind: 'start', text: 'Lembur selesai dan laporan kerja sudah ditulis' },
            { kind: 'step', text: 'Muncul di Persetujuan, paling lama di atas' },
            {
                kind: 'decision',
                text: 'Ada perintah lembur untuk waktu ini?',
                branches: [
                    { label: 'Ya', steps: [{ kind: 'end', text: 'Setujui. Catatan boleh kosong' }] },
                    { label: 'Tidak', steps: [{ kind: 'end', text: 'Tolak dengan catatan, misalnya: Tidak ada perintah lembur untuk tanggal ini' }] },
                    { label: 'Sebagian', steps: [{ kind: 'step', text: 'Ajukan koreksi Selesai lembur' }, { kind: 'end', text: 'Putuskan setelah Superadmin menerapkan koreksi' }] },
                ],
            },
        ],
    },
    {
        id: 'alur-koreksi',
        title: 'Koreksi jam tercatat',
        article: 'ajukan-koreksi',
        steps: [
            { kind: 'start', text: 'Karyawan melapor: tanggal, jam salah, jam benar, alasan' },
            { kind: 'step', text: 'Team Lead mengajukan koreksi dan melihat hasilnya di tanggal itu' },
            {
                kind: 'decision',
                text: 'Superadmin memeriksa',
                branches: [
                    { label: 'Terapkan', steps: [{ kind: 'end', text: 'Shift dihitung ulang, orangnya diberi tahu, tercatat di Log audit' }] },
                    { label: 'Tolak', steps: [{ kind: 'end', text: 'Tidak ada perubahan, catatan penolakan tersimpan' }] },
                ],
            },
        ],
    },
    {
        id: 'alur-tugas',
        title: 'Tugas dari usulan sampai log kerja',
        article: 'usul-tugas',
        steps: [
            {
                kind: 'decision',
                text: 'Siapa yang membuat tugas?',
                branches: [
                    { label: 'Lead sub proyek', steps: [{ kind: 'step', text: 'Tugas langsung masuk daftar Belum mulai' }] },
                    {
                        label: 'Anggota proyek',
                        steps: [
                            { kind: 'step', text: 'Usulan dikirim ke lead' },
                            {
                                kind: 'decision',
                                text: 'Keputusan lead',
                                branches: [
                                    { label: 'Setuju', steps: [{ kind: 'step', text: 'Masuk daftar Belum mulai' }] },
                                    { label: 'Tolak', steps: [{ kind: 'end', text: 'Ditolak dengan alasan, tampil di Usulanmu' }] },
                                ],
                            },
                        ],
                    },
                ],
            },
            { kind: 'step', text: 'Setiap pengerja menyalakan timer sendiri: Dikerjakan' },
            { kind: 'step', text: 'Setiap pengerja kirim bukti sendiri (tautan atau file): sesi timernya menjadi baris Log kerja' },
            { kind: 'step', text: 'Semua pengerja sudah kirim: review dimulai' },
            {
                kind: 'decision',
                text: 'Review lead, per pengerja',
                branches: [
                    { label: 'Setujui', steps: [{ kind: 'end', text: 'Bagian disetujui. Semua bagian disetujui: tugas selesai' }] },
                    { label: 'Minta revisi', steps: [{ kind: 'end', text: 'Perlu revisi: pengerja itu memperbaiki dan kirim bukti lagi' }] },
                ],
            },
        ],
    },
];

export const terms: Term[] = [
    { term: 'Absen masuk', definition: 'Awal shift. Di aplikasi desktop terjadi saat Masuk dan absen; di web lewat tombol Absen masuk di Hari ini.', article: 'absen-desktop' },
    { term: 'Absen pulang', definition: 'Akhir shift. Menutup jendela atau keluar dari aplikasi bukan absen pulang.', article: 'absen-desktop' },
    { term: 'Aku tinggal dulu', definition: 'Tanda untuk waktu PC diam berikutnya, dipasang sebelum meninggalkan PC. Tanda Render menjaga lembur tetap jalan.', article: 'pc-diam' },
    { term: 'Anggaran jam', definition: 'Batas jam kerja untuk proyek atau sub proyek, dibandingkan dengan jam di Log kerja. Hanya PM, PD, dan Superadmin yang melihatnya.', article: 'tahap-milestone' },
    { term: 'Bagian tugas', definition: 'Kerja satu pengerja di sebuah tugas: timer, bukti, dan review sendiri. Statusnya Belum kirim, Menunggu review, Perlu revisi, atau Disetujui.', article: 'timer-bukti' },
    { term: 'Bukti', definition: 'Tautan atau file hasil kerja yang dikirim untuk review tugas. Setiap pengerja mengirim buktinya sendiri. Wajib kecuali lead mematikannya.', article: 'timer-bukti' },
    { term: 'Cuti', definition: 'Hari kerja yang tidak dipakai bekerja, dengan pengajuan dan persetujuan. Cuti tahunan paling banyak 12 hari kerja per tahun; sisa kuotanya dicatat Superadmin.', article: 'ajukan-cuti' },
    { term: 'Dokumen proyek', definition: 'Tautan folder dan file Google Drive di halaman proyek, hanya untuk orang yang terlibat di proyek itu.', article: 'dokumen-proyek' },
    { term: 'Hari bukan hari kerja', definition: 'Akhir pekan, hari libur, atau libur studio. Semua jam di hari itu dihitung lembur, kecuali tanggalnya dibuka sebagai hari kerja.', article: 'bukan-hari-kerja' },
    { term: 'Jam PC berbeda', definition: 'Tanda saat jam Windows di PC berbeda lebih dari 2 menit dari server. Jam server yang dipakai.', article: 'persetujuan' },
    { term: 'Jam reguler', definition: 'Jam kerja sampai batas 8 jam per tanggal kerja, tanpa potongan istirahat. Beberapa shift di tanggal yang sama dijumlah.', article: 'pengingat-8-jam' },
    { term: 'Jeda belum pasti', definition: 'Jeda yang tidak bisa dipastikan server. Jeda itu tidak dihitung kerja.', article: 'persetujuan' },
    { term: 'Kapasitas', definition: 'Jam kerja yang tersedia bagi seseorang dalam satu minggu: hari kerja dikurangi cuti, dikali 8 jam.', article: 'beban-kerja' },
    { term: 'Kata sandi sementara', definition: 'Kata sandi dari Superadmin untuk masuk pertama atau setelah lupa. Wajib diganti saat masuk.', article: 'masuk-pertama' },
    { term: 'Klaim lembur susulan', definition: 'Pengajuan lembur untuk shift yang ditutup otomatis padahal kamu masih bekerja. Batasnya 24 jam.', article: 'laporan-lembur' },
    { term: 'Koreksi', definition: 'Perubahan jam tercatat. Diajukan Management, diterapkan Superadmin, alasannya dikirim ke orangnya.', article: 'ajukan-koreksi' },
    { term: 'Kurang dari 8 jam', definition: 'Tanda di Riwayat untuk tanggal yang jam regulernya belum sampai 8 jam. Pulang lebih awal boleh.', article: 'riwayat' },
    { term: 'Lanjutkan shift', definition: 'Tombol untuk melanjutkan shift yang terputus, dalam 90 menit, di perangkat yang sama. Jedanya tidak dihitung kerja.', article: 'terputus' },
    { term: 'Laporan lembur', definition: 'Tulisan apa yang selesai selama lembur, diminta saat absen pulang. Lembur baru bisa diputuskan setelah laporannya ada.', article: 'laporan-lembur' },
    { term: 'Lead sub proyek', definition: 'Orang yang memutuskan usulan tugas dan mereview bukti di satu sub proyek.', article: 'lead-tugas' },
    { term: 'Log kerja', definition: 'Catatan apa yang dikerjakan per proyek dengan jam dan bukti. Terisi otomatis dari sesi timer tugas atau dicatat manual.', article: 'log-kerja' },
    { term: 'Masih lembur?', definition: 'Pertanyaan saat lembur untuk memastikan kamu masih bekerja. Tidak dijawab 30 menit: lembur berhenti dihitung.', article: 'masih-lembur' },
    { term: 'Milestone', definition: 'Tanggal penting proyek, misalnya review klien atau delivery, dengan status yang dihitung otomatis.', article: 'tahap-milestone' },
    { term: 'Nama panggilan', definition: 'Nama yang tampil di menu dan daftar tugas. Laporan tetap memakai nama lengkap.', article: 'profil' },
    { term: 'PC diam', definition: 'Waktu tanpa input keyboard atau mouse selama 10 menit atau lebih. Tidak mengurangi jam kerja.', article: 'pc-diam' },
    { term: 'Pengerja', definition: 'Orang yang ditugaskan ke sebuah tugas. Satu tugas bisa punya beberapa pengerja, paling banyak 20.', article: 'timer-bukti' },
    { term: 'Perintah lembur', definition: 'Perintah dari Team Lead, PM, atau PD sebelum lembur dimulai. Tanpa perintah, lembur ditolak dan tidak dibayar.', article: 'perintah-lembur' },
    { term: 'Perlu dicek', definition: 'Shift yang ditutup di tanda aktif terakhir, bukan saat absen pulang, misalnya karena tidak kembali dalam 90 menit.', article: 'terputus' },
    { term: 'Retake', definition: 'Hasil kerja yang diminta revisi saat review. Rasionya tampil di Monitor kerja.', article: 'monitor-kerja' },
    { term: 'Sesi timer', definition: 'Satu kali mulai sampai jeda timer tugas. Setiap sesi menjadi satu baris Log kerja saat bukti dikirim.', article: 'log-kerja' },
    { term: 'Shift', definition: 'Satu rentang kerja dari absen masuk sampai absen pulang. Shift milik tanggal ia dimulai.', article: 'absen-desktop' },
    { term: 'Sub proyek', definition: 'Bagian dari proyek, misalnya episode atau kelompok aset, dengan lead dan daftar tugas sendiri.', article: 'proyek-struktur' },
    { term: 'Tahap', definition: 'Bagian pipeline produksi tempat sebuah tugas berada, misalnya Storyboard atau Compositing.', article: 'tahap-milestone' },
    { term: 'Tanda 8 jam', definition: 'Saat jam reguler tanggal itu mencapai batas. Lembur dihitung mulai dari sini.', article: 'pengingat-8-jam' },
    { term: 'Tanda aktif', definition: 'Sinyal tiap menit dari tab Hari ini atau aplikasi desktop yang membuktikan shift masih berjalan.', article: 'terputus' },
    { term: 'Target kerja mingguan', definition: 'Jam reguler yang diharapkan per minggu: tetap dan kontrak 40 jam, magang hari hadir × jam per hari, freelance tanpa target. Hanya ditampilkan, tidak memotong jam.', article: 'target-mingguan' },
    { term: 'Terputus', definition: 'Status shift setelah 4 menit tanpa tanda aktif. Lanjutkan dalam 90 menit supaya shift tidak ditutup.', article: 'terputus' },
    { term: 'Usulan tugas', definition: 'Tugas yang dibuat anggota proyek dan menunggu keputusan lead.', article: 'usul-tugas' },
];

export const exampleQuestions = ['Kenapa waktu saya mulai dari nol?', 'Lupa kata sandi', 'Cara mulai lembur', 'Cara kirim bukti tugas', 'Apakah lembur saya dibayar?'];
