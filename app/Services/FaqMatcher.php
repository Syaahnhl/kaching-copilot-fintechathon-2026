<?php

namespace App\Services;

/**
 * FaqMatcher - Layer 0 penghematan token.
 *
 * Strategi: Pertanyaan berulang (FAQ) dijawab dari kamus statis
 * tanpa panggil AI. Hanya pertanyaan yang tidak match dilempar ke Groq.
 *
 * - Match: keyword sederhana pada teks yang sudah dinormalkan
 *   (lowercase, hapus tanda baca, spasi tunggal). Tidak pakai regex
 *   berat, tidak panggil AI untuk introspeksi.
 * - Tidak match: kembalikan null. Pemanggil (controller) yang
 *   meneruskan ke LLM.
 *
 * Untuk Tuan: tambah FAQ baru dengan pola:
 *   'kunci1|kunci2' => ['reply' => '...', 'route' => null, 'cta' => null]
 */
class FaqMatcher
{
    /**
     * Daftar FAQ. Struktur:
     *   keywords   : string dipisah '|' - dicocokkan dengan str_contains
     *   reply      : jawaban teks polos (Markdown OK)
     *   route      : opsional, jika diisi -> frontend bisa navigate ke route
     *   cta_label  : opsional, label tombol CTA di landing/weborder
     *   cta_href   : opsional, URL tombol
     *   category   : hanya untuk analytics/log
     */
    public const FAQS = [
        // ===== ABOUT KACHING =====
        'apa itu kaching|kaching itu apa|kaching pos apa|kaching pos itu apa' => [
            'reply' => "**Kaching POS** adalah aplikasi kasir pintar khusus usaha F&B (resto, cafe, warung).\n\nFitur utama: Kelola menu, meja, shift kasir, KDS (Kitchen Display), laporan omzet, multi-outlet, dan QRIS.\n\nCocok untuk: Cafe, Resto, Warung, Food Truck, Catering.",
            'cta_label' => 'Cek Fitur Lengkap',
            'cta_href' => '/#fitur',
            'category' => 'about',
        ],
        'siapa yang buat|dibikin sama siapa|pembuat kaching|developer kaching' => [
            'reply' => "Kaching POS dikembangkan oleh tim lokal Indonesia khusus untuk UMKM F&B di tanah air.",
            'category' => 'about',
        ],
        'asal dari mana|kaching dari mana|berbasis di mana' => [
            'reply' => "Kaching POS berbasis di Indonesia, dibangun oleh Kaching FinTech Team untuk UMKM F&B Indonesia.",
            'category' => 'about',
        ],

        // ===== HARGA / LANGGANAN =====
        'berapa harga|harga berapa|biaya berapa|berapa biaya|berapa langganan|paket harga|harga paket|skema harga|mulai dari berapa|berapa duit|berapa rupiah|biaya bulanan|harga bulanan' => [
            'reply' => "**Paket Kaching POS** mulai dari **Rp 39.000/bulan** untuk paket Basic. Tersedia paket Pro dan Ultimate untuk fitur lanjutan seperti analitik bisnis dan multi-outlet.\n\nLihat detail lengkap di halaman harga.",
            'route' => 'harga',
            'cta_label' => 'Lihat Detail Harga',
            'cta_href' => '/harga',
            'category' => 'pricing',
        ],
        'ada free|ada gratis|free trial|coba gratis|free|trial|coba dulu|14 hari|free 14 hari' => [
            'reply' => "Ya! Bisa **coba gratis 14 hari** tanpa kartu kredit. Daftar di kaching.id/register, langsung aktif.",
            'route' => 'register',
            'cta_label' => 'Daftar Gratis',
            'cta_href' => '/register',
            'category' => 'pricing',
        ],

        // ===== CARA DAFTAR / LOGIN =====
        'cara daftar|daftar gimana|gimana cara daftar|registrasi|registrasi gimana|gimana registrasi|sign up|signup|buatkan akun|buat akun|bikin akun' => [
            'reply' => "Cara daftar Kaching POS:\n1. Buka kaching.id/register\n2. Isi nama, email, no HP\n3. Verifikasi OTP\n4. Setup outlet dan langsung pakai\n\nButuh waktu kurang dari 1 menit.",
            'route' => 'register',
            'cta_label' => 'Daftar Sekarang',
            'cta_href' => '/register',
            'category' => 'onboarding',
        ],
        'cara login|login gimana|gimana login|gimana masuk' => [
            'reply' => "Login di kaching.id/login pakai email dan password. Lupa password? Klik Lupa Password di halaman login.",
            'route' => 'login',
            'cta_label' => 'Ke Halaman Login',
            'cta_href' => '/login',
            'category' => 'onboarding',
        ],
        'lupa password|forgot password|reset password|password lupa|lupa sandi|ganti password' => [
            'reply' => "Reset password:\n1. Buka kaching.id/login\n2. Klik Lupa Password\n3. Masukkan email Anda\n4. Cek inbox untuk link reset\n\nBelum dapat email? Cek folder spam, atau hubungi CS.",
            'route' => 'login',
            'cta_label' => 'Reset Password',
            'cta_href' => '/login',
            'category' => 'onboarding',
        ],
        'ganti email|ganti no hp|ubah profil|update profil' => [
            'reply' => "Profil bisa diubah via:\n- Web: menu Settings -> Profil\n- Android: Profile -> Edit Profil",
            'category' => 'onboarding',
        ],

        // ===== FITUR =====
        'fitur|fitur unggulan|fitur apa saja|apa saja fitur|fitur fitur|apa saja yang bisa' => [
            'reply' => "**Fitur Kaching POS:**\n- Kasir dan order (web + Android)\n- KDS (Kitchen Display System)\n- Multi-outlet (pusat dan cabang)\n- QRIS, Tunai, Transfer, Bayar Nanti\n- Diskon dan promo\n- Laporan omzet harian/mingguan/bulanan\n- Cash flow dan expenses\n- Inventory bahan baku\n- Reservasi meja\n- Export Excel\n- Backup otomatis\n- Offline-First (jalan tanpa internet)",
            'route' => 'fitur',
            'cta_label' => 'Detail Fitur',
            'cta_href' => '/#fitur',
            'category' => 'features',
        ],
        'kds|kitchen display|dapur|layar dapur|kitchen display system' => [
            'reply' => "**KDS (Kitchen Display System)** adalah layar di dapur yang menampilkan pesanan masuk secara real-time.\n\nChef tinggal tap Selesai saat pesanan siap. Otomatis sinkron ke kasir dan laporan.\n\nBisa pakai Android tablet di dapur.",
            'category' => 'features',
        ],
        'offline|offline first|tanpa internet|gak ada internet|internet mati|lost connection' => [
            'reply' => "**Offline-First** - Kaching POS tetap jalan walau internet mati. Data tersimpan lokal, sync otomatis saat online lagi.\n\nCocok untuk resto di daerah dengan koneksi tidak stabil.",
            'category' => 'features',
        ],
        'qris|metode pembayaran|bayar pake apa|pembayaran|cara bayar' => [
            'reply' => "**Metode pembayaran** yang didukung:\n- Tunai\n- QRIS (via Midtrans)\n- Transfer Bank\n- Bayar Nanti (Piutang)\n- Mix (gabungan Tunai + QRIS, dll)",
            'category' => 'features',
        ],
        'laporan|report|omzet|rekap|analitik|analytics|dashboard' => [
            'reply' => "**Laporan Kaching POS:**\n- Dashboard: total omzet, nota, payment breakdown (Tunai/QRIS/Transfer)\n- Filter: hari ini, 7 hari, 30 hari, semua waktu\n- Per outlet / per user\n- Export ke Excel\n- Laba bersih (omzet - HPP - expenses)\n- Cash flow (kas masuk/keluar)",
            'route' => 'reports',
            'cta_label' => 'Lihat Laporan',
            'cta_href' => '/reports',
            'category' => 'features',
        ],
        'shift|kasir|buka kasir|tutup kasir|rekap kas' => [
            'reply' => "**Manajemen Shift:**\n- Buka shift: catat kas awal (modal)\n- Setiap transaksi auto-track ke shift\n- Tutup shift: rekonsiliasi kas otomatis (expected vs actual)\n- Selisih kas (jika ada) tercatat\n- History shift per kasir",
            'category' => 'features',
        ],
        'stok|inventory|bahan baku|stock management|manajemen stok' => [
            'reply' => "**Inventory dan Stok:**\n- Manajemen bahan baku (item, supplier, UoM)\n- Procurement (penerimaan barang)\n- Stock opname\n- Stock movement audit trail\n- Auto-decrement saat ada transaksi\n- HPP (Harga Pokok Penjualan) otomatis",
            'category' => 'features',
        ],
        'multi outlet|cabang|beberapa toko|multi cabang' => [
            'reply' => "**Multi-Outlet** - Kelola banyak cabang dari 1 akun pusat.\n\nSetiap outlet punya:\n- Menu sendiri\n- Staff sendiri\n- Laporan sendiri\n- KDS sendiri\n\nLaporan digabung di level pusat.",
            'category' => 'features',
        ],
        'staff|kasir tambahan|multi user|tambah karyawan|pegawai|waiter' => [
            'reply' => "**Manajemen Staff:**\n- Role: OWNER, MANAGER, KASIR, WAITER, KITCHEN\n- PIN login untuk staff\n- Gaji pokok, hourly rate, tunjangan\n- Schedule kerja\n- Attendance (absensi)\n- Performance tracking per kasir",
            'category' => 'features',
        ],
        'diskon|potongan harga|discount|promo' => [
            'reply' => "**Diskon dan Promo:**\n- Diskon per item atau per transaksi\n- Diskon nominal (Rp) atau persentase (%)\n- Kode promo\n- Minimum purchase\n- Masa berlaku (expired)\n- Tipe promo: percent atau nominal",
            'category' => 'features',
        ],
        'reservasi|booking|booking meja|reservasi meja' => [
            'reply' => "**Reservasi Meja:**\n- Customer booking via link khusus\n- Pilih tanggal, jam, jumlah orang\n- Pre-order menu (opsional)\n- Status: Pending, Confirmed, CheckedIn, Cancelled\n- Reminder ke customer (opsional)",
            'category' => 'features',
        ],
        'export|excel|download laporan|backup data' => [
            'reply' => "**Export dan Backup:**\n- Export laporan ke Excel\n- Backup otomatis harian (tersimpan di server)\n- Backup mingguan ke GitHub private repo\n- Data retention 7 hari\n- Bisa restore dari backup kapan saja",
            'category' => 'features',
        ],

        // ===== DOWNLOAD / AKSES =====
        'android|download apk|apk|play store|ios|hp|mobile' => [
            'reply' => "**Kaching POS Android** - Download APK di kaching.id/download.\n\nBelum tersedia di Play Store. APK install manual, update dari website.\n\niOS belum tersedia - pakai web dulu.",
            'cta_label' => 'Download APK',
            'cta_href' => '/download',
            'category' => 'download',
        ],

        // ===== CS / KONTAK =====
        'kontak|customer service|cs|hubungi|whatsapp|admin|help|bantuan|bantuan manusia|ngobrol sama orang' => [
            'reply' => "**Hubungi Tim Kaching POS:**\n- WhatsApp: 0823235484822\n- Email: support@kaching.id\n- Response time: < 24 jam (Senin-Sabtu)\n\nAtau klik tombol di bawah untuk langsung chat WhatsApp:",
            'cta_label' => 'Chat WhatsApp',
            'cta_href' => 'https://wa.me/6282323548482?text=Halo%20Tim%20Kaching%20POS',
            'category' => 'support',
        ],
        'demo|masih ragu|belum yakin|coba dulu|testimoni' => [
            'reply' => "**Bisa coba gratis 14 hari** - tanpa kartu kredit, tanpa komitmen.\n\nAtau jadwalkan demo 1-on-1 via WhatsApp untuk konsultasi setup Kaching POS sesuai usaha Anda.",
            'cta_label' => 'Chat WhatsApp untuk Demo',
            'cta_href' => 'https://wa.me/6282323548482?text=Halo%20saya%20mau%20demo%20Kaching%20POS',
            'category' => 'support',
        ],

        // ===== UMUM =====
        'aman|security|keamanan|enkripsi|encrypted' => [
            'reply' => "**Keamanan Data Kaching POS:**\n- SSL/HTTPS untuk semua koneksi\n- Password di-hash (bcrypt)\n- Backup harian otomatis\n- Database encrypted at-rest\n- Sanctum token untuk API (rotatable)\n- CSRF protection di web\n- 2FA untuk login (coming soon)",
            'category' => 'security',
        ],
        'syarat|requirements|specifikasi|butuh apa|minimum' => [
            'reply' => "**Kebutuhan sistem:**\n- Web: Chrome/Edge/Firefox/Safari (terbaru)\n- Android: 5.0+ (API 21+), RAM 2GB+\n- Internet: stabil (untuk sync), tapi bisa offline\n- Server-side: tidak perlu setup - sudah managed",
            'category' => 'specs',
        ],
    ];

    /**
     * Cek apakah pesan cocok dengan salah satu FAQ.
     *
     * @param  string  $message
     * @param  array|null  $allowedCategories  Kategori yang diizinkan (null = semua kategori).
     * @return array|null  Payload FAQ jika match, null jika tidak.
     *                    Key: reply, route?, cta_label?, cta_href?, category, matched_keyword, source
     */
    public static function match(string $message, ?array $allowedCategories = null): ?array
    {
        $normalized = self::normalize($message);
        if ($normalized === '') {
            return null;
        }

        foreach (self::FAQS as $keywordSet => $payload) {
            if ($allowedCategories !== null && !in_array($payload['category'] ?? '', $allowedCategories, true)) {
                continue;
            }
            $keywords = array_filter(array_map('trim', explode('|', $keywordSet)));
            foreach ($keywords as $kw) {
                if ($kw === '' || mb_strlen($kw) < 3) {
                    // Abaikan keyword yang terlalu pendek (1-2 char) untuk
                    // menghindari false positive seperti 'wa' di 'Modal awal'.
                    continue;
                }
                // Word-boundary matching: keyword harus berupa kata utuh,
                // bukan substring dari kata lain.
                $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}])/u';
                if (preg_match($pattern, $normalized) === 1) {
                    $payload['matched_keyword'] = $kw;
                    $payload['source'] = 'faq';
                    return $payload;
                }
            }
        }

        return null;
    }

    /**
     * Normalisasi pesan: lowercase, hapus tanda baca, spasi tunggal.
     */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        // Ganti semua karakter non-huruf/non-angka dengan spasi
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        // Lipat spasi berulang jadi satu
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /**
     * Untuk test/debug: jalankan pesan arbitrary dan lihat hasilnya.
     */
    public static function debug(string $message): array
    {
        $result = self::match($message);
        return [
            'input' => $message,
            'normalized' => self::normalize($message),
            'matched' => $result !== null,
            'result' => $result,
        ];
    }
}
