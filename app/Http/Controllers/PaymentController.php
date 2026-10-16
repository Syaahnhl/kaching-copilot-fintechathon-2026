<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function createSubscription(Request $request)
    {
        try {
            $user = Auth::user();
            $packageName = strtoupper($request->package_name);

            // 1. Tentukan Harga Asli Paket Kaching
            $prices = [
                'BASIC' => 129000,
                'PRO' => 249000,
                'ULTIMATE' => 349000
            ];

            if (!isset($prices[$packageName])) {
                return response()->json(['status' => 'error', 'message' => 'Paket tidak valid.'], 400);
            }
            $grossAmount = $prices[$packageName];

            // 2. Buat Order ID Khusus Berlangganan (Prefix: SUB-)
            $orderId = 'SUB-' . $user->id . '-' . time();

            // 3. Konfigurasi Midtrans Murni Milik Kaching POS (.env)
            \Midtrans\Config::$serverKey = config('services.midtrans.server_key');
            \Midtrans\Config::$isProduction = filter_var(config('services.midtrans.is_production', false), FILTER_VALIDATE_BOOLEAN);
            \Midtrans\Config::$isSanitized = true;
            \Midtrans\Config::$is3ds = true;

            // Bypass notifikasi agar ke webhook milik kita
            \Midtrans\Config::$overrideNotifUrl = url('/api/midtrans/webhook');

            // [BARU] Filter Metode Pembayaran Midtrans sesuai pilihan dari Android
            $paymentMethod = $request->payment_method ?? 'qris'; // fallback ke qris
            $enabledPayments = [];

            if ($paymentMethod === 'qris') {
                $enabledPayments = ['other_qris', 'gopay', 'shopeepay'];
            } elseif ($paymentMethod === 'bank_transfer') {
                $enabledPayments = ['bca_va', 'bni_va', 'bri_va', 'mandiri_va', 'permata_va', 'bank_transfer'];
            }

            // 4. Siapkan Data Transaksi Midtrans
            $params = [
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => $grossAmount,
                ],
                'customer_details' => [
                    'first_name' => $user->name,
                    'email' => $user->email,
                    'phone' => '080000000000' // Default / bisa dikosongi
                ],
                'item_details' => [
                    [
                        'id' => 'PKG-' . $packageName,
                        'price' => $grossAmount,
                        'quantity' => 1,
                        'name' => "Langganan Paket " . $packageName
                    ]
                ],
                'enabled_payments' => $enabledPayments, // <--- [BARU] Masukkan array filter ke sini
                // Redirect user kembali ke halaman web jika dibuka via browser
                'callbacks' => [
                    'finish' => url('https://kachingpos.com')
                ]
            ];

            // 5. Minta Link Checkout ke Midtrans
            $snapToken = \Midtrans\Snap::getSnapToken($params);
            $checkoutUrl = "https://app.midtrans.com/snap/v3/redirection/" . $snapToken;

            // (Opsional) Jika di Sandbox, URL checkout sedikit berbeda:
            if (!\Midtrans\Config::$isProduction) {
                $checkoutUrl = "https://app.sandbox.midtrans.com/snap/v3/redirection/" . $snapToken;
            }

            // 6. Simpan niat berlangganan ke database (Bisa bikin tabel 'subscriptions' atau simpan sementara)
            // Untuk mempermudah saat webhook masuk, order_id ini mengandung pola: SUB-{User_ID}-{Time}

            Log::info("Tagihan {$packageName} dibuat untuk Mitra: " . $user->email);

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil membuat tagihan.',
                'data' => [
                    'order_id' => $orderId,
                    'checkout_url' => $checkoutUrl,
                    'snap_token' => $snapToken // <--- [BARU] Ini "nyawa" buat SDK Android Mas!
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error("Gagal Buat Subscribe Midtrans: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Gagal membuat pembayaran.'], 500);
        }
    }
}
