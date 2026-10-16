<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\HowToArticle;

class HowToArticleSeeder extends Seeder
{
    public function run(): void
    {
        $articles = [
            [
                'title' => 'Lupa PIN Kasir? Begini Cara Reset',
                'slug' => 'lupa-pin-kasir',
                'category' => 'kasir',
                'icon' => 'lock_reset',
                'sort_order' => 10,
                'content' => "**Cara reset PIN kasir yang lupa:**\n\n1. Buka menu **Staff** di web dashboard (kaching.id/staff)\n2. Cari nama kasir yang lupa PIN\n3. Klik tombol **Reset PIN**\n4. Masukkan PIN baru 6 digit\n5. Minta kasir login ulang dengan PIN baru\n\n**Tips:** Ajari kasir pakai PIN yang mudah diingat tapi sulit ditebak (jangan 123456 atau tanggal lahir).",
            ],
            [
                'title' => 'Cara Refund / Batalkan Transaksi',
                'slug' => 'cara-refund',
                'category' => 'kasir',
                'icon' => 'undo',
                'sort_order' => 20,
                'content' => "**Cara refund transaksi yang salah input:**\n\n1. Buka menu **Riwayat Transaksi** di dashboard\n2. Cari transaksi yang akan di-refund (pakai filter tanggal/nomor struk)\n3. Klik tombol **Detail** lalu **Batalkan**\n4. Pilih alasan pembatalan\n5. Stok menu akan otomatis di-restore\n6. Customer mendapat refund sesuai metode bayar\n\n**Catatan:** Pembatalan hanya bisa dalam 24 jam. Lebih dari itu, hubungi owner.",
            ],
            [
                'title' => 'Cara Tutup Shift dengan Benar',
                'slug' => 'tutup-shift',
                'category' => 'shift',
                'icon' => 'logout',
                'sort_order' => 30,
                'content' => "**Prosedur tutup shift kasir:**\n\n1. Klik menu **Shift** di APK → **Tutup Shift**\n2. Hitung uang cash di laci (sesuai hitungan fisik)\n3. Masukkan nominal cash aktual di kolom **Kas Aktual**\n4. Sistem akan hitung otomatis selisih (jika ada)\n5. Klik **Konfirmasi Tutup Shift**\n6. Ambil screenshot laporan shift untuk arsip\n\n**Penting:** Selisih kas >Rp 50.000 wajib lapor ke manager sebelum tutup shift.",
            ],
            [
                'title' => 'Stok Menu Minus? Cara Stok Opname',
                'slug' => 'stok-opname',
                'category' => 'stok',
                'icon' => 'inventory_2',
                'sort_order' => 40,
                'content' => "**Cara stock opname bahan baku:**\n\n1. Buka menu **Stok** → **Stock Opname** di web\n2. Pilih gudang/outlet yang akan di-opname\n3. Sistem tampilkan stok sistem vs kosong (input fisik)\n4. Hitung fisik bahan baku, masukkan angka di kolom **Stok Aktual**\n5. Klik **Simpan** — sistem otomatis hitung selisih\n6. Selisih jadi **Stock Movement** (audit trail)\n\n**Waktu terbaik:** Tutup resto / sebelum buka, saat tidak ada TX.",
            ],
            [
                'title' => 'APK Offline? Begini Cara Sync Ulang',
                'slug' => 'sync-offline',
                'category' => 'offline',
                'icon' => 'cloud_sync',
                'sort_order' => 50,
                'content' => "**Cara sync ulang saat APK offline:**\n\n1. Pastikan WiFi/data seluler aktif dan stabil\n2. Buka APK Kaching POS, lihat indicator di pojok kanan atas\n3. Jika status **Offline** (merah) → tunggu 30 detik, sistem auto-retry sync\n4. Jika masih offline → tarik layar dari atas ke bawah (pull-to-refresh)\n5. Atau buka menu **Settings** → **Sync Manual** → klik **Sync Sekarang**\n\n**Masih gagal?** Restart APK (close & buka lagi). Data TX offline aman tersimpan di lokal, tidak akan hilang.",
            ],
            [
                'title' => 'Cara Setup KDS di Tablet Dapur',
                'slug' => 'setup-kds',
                'category' => 'kds',
                'icon' => 'kitchen',
                'sort_order' => 60,
                'content' => "**Cara setup KDS tablet di dapur:**\n\n1. Install APK KDS di tablet dapur (Android 7+)\n2. Login pakai akun OWNER/MANAGER\n3. Pilih outlet & station (Dapur/Bar)\n4. Aktifkan mode **layar penuh** (no auto-sleep)\n5. Aktifkan **sound alert** untuk notifikasi order baru\n6. Tap **Selesai** di setiap orderan untuk konfirmasi ke kasir\n\n**Tips:** Taruh tablet di tempat kering, jauh dari kompor. Charge semalam.",
            ],
            [
                'title' => 'Cara Tambah Menu Baru dengan Foto',
                'slug' => 'tambah-menu-foto',
                'category' => 'kasir',
                'icon' => 'add_a_photo',
                'sort_order' => 15,
                'content' => "**Cara input menu baru + foto:**\n\n1. Buka menu **Master Menu** di web (kaching.id/menu)\n2. Klik **+ Tambah Menu**\n3. Isi: nama, kategori, harga jual, HPP (opsional), PPN/SC\n4. Upload foto menu (format JPG/PNG, max 2MB)\n5. Set **stok** (jika menu pakai sistem stok)\n6. Klik **Simpan** — menu langsung muncul di APK & web order\n\n**Rekomendasi foto:** Resolusi 800x800px, pencahayaan bagus, background polos.",
            ],
            [
                'title' => 'Laporan Omzet Tidak Muncul? Cek Ini',
                'slug' => 'laporan-omzet-kosong',
                'category' => 'umum',
                'icon' => 'assessment',
                'sort_order' => 5,
                'content' => "**Laporan omzet kosong? Troubleshoot:**\n\n1. Cek **filter tanggal** — pastikan range tanggal yang dipilih ada TX-nya\n2. Cek **filter outlet** — mungkin Anda cek outlet yang salah\n3. Pastikan TX status **Selesai** (TX Batal tidak masuk omzet)\n4. Cek **timezone** dashboard (default WIB)\n5. Hard refresh browser (Ctrl+Shift+R)\n\n**Masih kosong?** Kemungkinan data di-outlet lain. Hubungi CS via WA 082323548482.",
            ],
        ];

        foreach ($articles as $a) {
            HowToArticle::updateOrCreate(
                ['slug' => $a['slug']],
                $a + ['is_active' => true, 'view_count' => 0]
            );
        }

        $this->command->info('HowToArticleSeeder: ' . count($articles) . ' artikel di-seed.');
    }
}
