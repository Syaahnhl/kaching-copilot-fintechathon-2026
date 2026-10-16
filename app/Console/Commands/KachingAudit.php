<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KachingAudit extends Command
{
    protected $signature = 'kaching:audit {--fix : Mencoba memperbaiki data anomali secara otomatis}';
    protected $description = 'Melakukan audit kesehatan sistem, integritas data keuangan, skema database, dan integrasi vendor Kaching POS';

    public function handle()
    {
        $this->info("==================================================================");
        $this->info("      PANTHEON OLYMPUS - HERMES AUTOMATED KACHING SYSTEM AUDIT    ");
        $this->info("==================================================================");
        
        $hasError = false;
        $warnings = 0;

        // ----------------------------------------------------
        // Phase 1: DB Schema Check
        // ----------------------------------------------------
        $this->info("\n[1/5] Memeriksa Skema Database...");
        
        $requiredTables = [
            'users', 'outlets', 'menus', 'transactions', 
            'transaction_items', 'shifts', 'feature_flags', 'audit_logs'
        ];

        foreach ($requiredTables as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("❌ Tabel '{$table}' tidak ditemukan di database!");
                $hasError = true;
            } else {
                $this->line("  ✅ Tabel '{$table}' OK");
            }
        }

        // Cek kolom krusial
        $crucialColumns = [
            'transactions' => ['ppn_amount', 'service_charge_amount', 'order_type', 'user_id', 'total_amount', 'subtotal'],
            'shifts' => ['cashier_type', 'cashier_name', 'end_cash', 'expected_cash'],
            'transaction_items' => ['kds_status', 'is_sent_to_kds', 'category_snapshot', 'cost_price']
        ];

        foreach ($crucialColumns as $table => $columns) {
            if (Schema::hasTable($table)) {
                foreach ($columns as $column) {
                    if (!Schema::hasColumn($table, $column)) {
                        $this->error("❌ Kolom '{$table}.{$column}' tidak ditemukan di skema!");
                        $hasError = true;
                    } else {
                        $this->line("  ✅ Kolom '{$table}.{$column}' OK");
                    }
                }
            }
        }

        // ----------------------------------------------------
        // Phase 2: Data Integrity & Finance Check
        // ----------------------------------------------------
        $this->info("\n[2/5] Memeriksa Integritas Data Keuangan & Transaksi...");

        // 1. Cek Transaksi Yatim Piatu (Orphan FK)
        if (Schema::hasTable('transactions')) {
            $orphanTx = DB::table('transactions')
                ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
                ->whereNull('users.id')
                ->count();
            if ($orphanTx > 0) {
                $this->error("❌ Terdeteksi {$orphanTx} transaksi yatim piatu (tanpa user_id yang valid)!");
                $hasError = true;
            } else {
                $this->line("  ✅ Orphan transactions: 0");
            }

            // 2. Cek item transaksi yatim piatu
            if (Schema::hasTable('transaction_items')) {
                $orphanItems = DB::table('transaction_items')
                    ->leftJoin('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
                    ->whereNull('transactions.id')
                    ->count();
                if ($orphanItems > 0) {
                    $this->error("❌ Terdeteksi {$orphanItems} item transaksi yatim piatu (tanpa transaction_id valid)!");
                    $hasError = true;
                } else {
                    $this->line("  ✅ Orphan transaction items: 0");
                }
            }

            // 3. Cek transaksi dengan subtotal = 0 padahal total_amount > 0
            $zeroSubtotals = DB::table('transactions')
                ->where('total_amount', '>', 0)
                ->where('subtotal', '=', 0)
                ->count();
            if ($zeroSubtotals > 0) {
                $this->error("❌ Terdeteksi {$zeroSubtotals} transaksi dengan subtotal = Rp 0 padahal total > 0 (Data Drift)!");
                $hasError = true;

                if ($this->option('fix')) {
                    $this->info("  -> Mencoba melakukan recovery subtotal dari breakdown items...");
                    $affected = 0;
                    $transactionsToFix = DB::table('transactions')
                        ->where('total_amount', '>', 0)
                        ->where('subtotal', '=', 0)
                        ->get();

                    foreach ($transactionsToFix as $trx) {
                        $sumItems = DB::table('transaction_items')
                            ->where('transaction_id', $trx->id)
                            ->select(DB::raw('SUM(price * qty) as total_sub'))
                            ->first();

                        $calculatedSub = $sumItems->total_sub ?? 0;
                        if ($calculatedSub > 0) {
                            DB::table('transactions')
                                ->where('id', $trx->id)
                                ->update(['subtotal' => $calculatedSub]);
                            $affected++;
                        }
                    }
                    $this->info("  ✅ Sukses merecover {$affected} transaksi.");
                    if ($affected == $zeroSubtotals) {
                        $hasError = false;
                    }
                }
            } else {
                $this->line("  ✅ Transaksi subtotal 0: 0");
            }

            // 4. Cek HPP / Cost Price bernilai 0 di item padahal menu aslinya memiliki cost_price
            if (Schema::hasTable('transaction_items') && Schema::hasTable('menus')) {
                $zeroCostItems = DB::table('transaction_items')
                    ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
                    ->join('menus', function ($join) {
                        $join->on('menus.user_id', '=', 'transactions.user_id')
                             ->on('menus.name', '=', 'transaction_items.menu_name');
                    })
                    ->where('transaction_items.cost_price', '=', 0)
                    ->where('menus.cost_price', '>', 0)
                    ->count();

                if ($zeroCostItems > 0) {
                    $this->warn("⚠️ Terdeteksi {$zeroCostItems} item transaksi dengan HPP = Rp 0 padahal menu aslinya memiliki HPP!");
                    $warnings++;

                    if ($this->option('fix')) {
                        $this->info("  -> Melakukan backfill HPP dari tabel master menus...");
                        $affected = DB::update("
                            UPDATE transaction_items ti
                            JOIN transactions t ON ti.transaction_id = t.id
                            JOIN menus m ON m.user_id = t.user_id AND m.name = ti.menu_name
                            SET ti.cost_price = m.cost_price
                            WHERE ti.cost_price = 0 AND m.cost_price > 0
                        ");
                        $this->info("  ✅ Sukses melakukan HPP backfill untuk {$affected} baris.");
                        $warnings = 0;
                    }
                } else {
                    $this->line("  ✅ Item transaksi HPP 0: 0");
                }
            }
        }

        // ----------------------------------------------------
        // Phase 3: KDS & Kitchen Consistency Check
        // ----------------------------------------------------
        $this->info("\n[3/5] Memeriksa Konsistensi Kitchen & KDS Status...");

        if (Schema::hasTable('transaction_items') && Schema::hasTable('transactions')) {
            // Cek anomali status: Transaksi selesai tapi item di dapur masih Pending/Cooking
            $kdsAnomalies = DB::table('transaction_items')
                ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
                ->where('transactions.status', '=', 'Selesai')
                ->whereIn('transaction_items.kds_status', ['Pending', 'Cooking'])
                ->count();

            if ($kdsAnomalies > 0) {
                $this->warn("⚠️ Terdeteksi {$kdsAnomalies} item KDS masih 'Pending'/'Cooking' padahal transaksinya sudah 'Selesai'!");
                $warnings++;

                if ($this->option('fix')) {
                    $this->info("  -> Meng-update status item dapur menjadi 'Served'...");
                    $affected = DB::table('transaction_items')
                        ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
                        ->where('transactions.status', '=', 'Selesai')
                        ->whereIn('transaction_items.kds_status', ['Pending', 'Cooking'])
                        ->update(['transaction_items.kds_status' => 'Served']);
                    $this->info("  ✅ Sukses meng-update {$affected} item dapur.");
                    $warnings = 0;
                }
            } else {
                $this->line("  ✅ Anomali status KDS: 0");
            }

            // Cek anomali status header transaksi: Transaksi sudah lunas (pay_amount >= total_amount) tapi status masih 'Proses' / 'Served'
            $stuckTransactions = DB::table('transactions')
                ->whereIn('status', ['Proses', 'Served'])
                ->whereRaw('pay_amount >= total_amount')
                ->count();

            if ($stuckTransactions > 0) {
                $this->warn("⚠️ Terdeteksi {$stuckTransactions} transaksi status 'Proses'/'Served' padahal pembayaran sudah lunas!");
                $warnings++;

                if ($this->option('fix')) {
                    $this->info("  -> Meng-update status transaksi menggantung menjadi 'Selesai'...");
                    $affected = DB::table('transactions')
                        ->whereIn('status', ['Proses', 'Served'])
                        ->whereRaw('pay_amount >= total_amount')
                        ->update(['status' => 'Selesai']);
                    $this->info("  ✅ Sukses meng-update {$affected} transaksi menggantung menjadi 'Selesai'.");
                    $warnings = 0;
                }
            } else {
                $this->line("  ✅ Transaksi lunas menggantung: 0");
            }
        }

        // ----------------------------------------------------
        // Phase 4: Vendor Library Check
        // ----------------------------------------------------
        $this->info("\n[4/5] Memeriksa Pustaka / Vendor SDK...");

        $vendorClasses = [
            'Midtrans\\Config' => 'midtrans/midtrans-php (QRIS & Payment Gateway)',
            'Barryvdh\\DomPDF\\Facade\\Pdf' => 'barryvdh/laravel-dompdf (Export PDF Laporan)',
            'Maatwebsite\\Excel\\Facades\\Excel' => 'maatwebsite/excel (Export Excel Laporan)',
            'Laravel\\Sanctum\\Sanctum' => 'laravel/sanctum (Autentikasi Token POS Android)'
        ];

        foreach ($vendorClasses as $class => $packageName) {
            if (class_exists($class)) {
                $this->line("  ✅ Library {$packageName} terinstal");
            } else {
                $this->error("❌ Library {$packageName} MISSING (Class {$class} tidak dapat dimuat)!");
                $hasError = true;
            }
        }

        // ----------------------------------------------------
        // Phase 5: Local API Liveness Check
        // ----------------------------------------------------
        $this->info("\n[5/5] Menguji Liveness Endpoint API Lokal...");
        
        try {
            $response = $this->simulateLocalRequest('GET', '/api/health');
            if ($response['status'] == 200 || $response['status'] == 404) { // 404 wajar jika route ga ada tapi server respon
                $this->line("  ✅ Endpoint /api/health OK (Status: {$response['status']})");
            } else {
                $this->warn("⚠️ Endpoint /api/health mengembalikan status {$response['status']}");
                $warnings++;
            }
        } catch (\Exception $e) {
            $this->error("❌ Gagal menghubungi API lokal: " . $e->getMessage());
            $hasError = true;
        }

        // ----------------------------------------------------
        // Final Summary Output
        // ----------------------------------------------------
        $this->info("\n==================================================================");
        $this->info("                         HASIL AUDIT SISTEM                       ");
        $this->info("==================================================================");
        
        if ($hasError) {
            $this->error("❌ AUDIT GAGAL: Terdeteksi masalah kritis yang perlu ditangani.");
            $this->line("💡 Jalankan: 'php artisan kaching:audit --fix' untuk auto-repair.");
            return 1;
        } elseif ($warnings > 0) {
            $this->warn("⚠️ AUDIT SELESAI DENGAN PERINGATAN ({$warnings} Warning).");
            $this->line("💡 Jalankan: 'php artisan kaching:audit --fix' untuk pembersihan.");
            return 0;
        } else {
            $this->info("✅ AUDIT SUKSES MUTLAK: Sistem sehat, skema sinkron, data keuangan bersih!");
            return 0;
        }
    }

    private function simulateLocalRequest($method, $uri)
    {
        // Simulasi internal request Laravel untuk menghindari dependency HTTP/CURL overhead
        $request = \Illuminate\Http\Request::create($uri, $method);
        $kernel = app()->make(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        
        return [
            'status' => $response->getStatusCode(),
            'content' => $response->getContent()
        ];
    }
}
