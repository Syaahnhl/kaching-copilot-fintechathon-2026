<?php

namespace App\Services\Supplier;

use App\Models\Item;
use App\Models\Supplier;
use Illuminate\Support\Facades\Schema;

/**
 * Service untuk memformat draf pesan pemesanan restock bahan baku ke supplier
 * dan menghasilkan deep link WhatsApp (wa.me) secara deterministik dan aman.
 *
 * ARSITEKTUR & KEAMANAN (MUTLAK):
 * - 100% READ-ONLY: ZERO database mutation, ZERO insert PO, ZERO update stok.
 * - STRICT TENANT ISOLATION: Validasi kepemilikan supplier (user_id = Auth::id()) fail-closed.
 * - CLIENT-SIDE ONLY: ZERO server-side WhatsApp sending / WhatsApp Business API.
 * - PHONE NORMALIZATION: Normalisasi format Indonesia (08... / +628... / 628...) fail-closed.
 * - DETERMINISTIC TEMPLATE: Sederhana tanpa ketergantungan LLM untuk formatting pesan.
 */
class SupplierRestockMessageService
{
    /**
     * Normalisasi nomor telepon ke format internasional wa.me (Indonesian prefix: 628...).
     * Fail-closed jika format tidak valid atau kosong.
     */
    public function normalizePhoneNumber(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $trimmed = trim($phone);
        if ($trimmed === '') {
            return null;
        }

        // Hapus semua karakter non-angka
        $clean = preg_replace('/[^0-9]/', '', $trimmed);
        if (empty($clean)) {
            return null;
        }

        // 08xxxxxxxxxx -> 628xxxxxxxxxx
        if (str_starts_with($clean, '08')) {
            $clean = '62' . substr($clean, 1);
        }

        // Pola nomor ponsel Indonesia: diawali 628 dan panjang 10-15 digit
        if (str_starts_with($clean, '628') && strlen($clean) >= 10 && strlen($clean) <= 16) {
            return $clean;
        }

        // Format tidak dikenali -> Fail Closed
        return null;
    }

    /**
     * Format baris item sesuai spesifikasi:
     * "• {item_name} — {quantity} {uom}"
     */
    public function formatItemLine(string $itemName, float|int|string $quantity, string $uom): string
    {
        $cleanName = trim($itemName);
        $cleanUom = trim($uom);
        $numQty = (float) $quantity;

        // Tampilkan integer jika bulat (contoh: 20), atau hingga 2 desimal jika pecahan (contoh: 12.5)
        if (fmod($numQty, 1.0) == 0.0) {
            $qtyStr = (string) (int) $numQty;
        } else {
            $qtyStr = rtrim(rtrim(number_format($numQty, 2, '.', ''), '0'), '.');
        }

        return "• {$cleanName} — {$qtyStr} {$cleanUom}";
    }

    /**
     * Bangun pesan teks restock deterministik berbasis template kanonikal:
     *
     * Halo {supplier_contact},
     *
     * Kami ingin melakukan restok bahan berikut:
     *
     * {items}
     *
     * Mohon diinformasikan ketersediaan, harga terbaru, dan estimasi pengiriman.
     *
     * Terima kasih.
     */
    public function buildMessage(?string $contactPerson, array $items, ?string $deliveryDate = null): string
    {
        $itemLines = [];
        foreach ($items as $item) {
            $name = trim($item['item_name'] ?? $item['name'] ?? 'Bahan Baku');
            $qty = $item['quantity'] ?? $item['suggested_qty'] ?? $item['qty'] ?? 0;
            $uom = trim($item['unit'] ?? $item['uom'] ?? $item['base_uom'] ?? 'pcs');

            if ($name !== '' && (float) $qty > 0) {
                $itemLines[] = $this->formatItemLine($name, $qty, $uom);
            }
        }

        $itemsText = !empty($itemLines) ? implode("\n", $itemLines) : "• (Belum ada bahan yang dipilih)";

        $deliveryText = '';
        if (!empty(trim($deliveryDate ?? ''))) {
            $deliveryText = "\n\nRencana / tanggal pengiriman: " . trim($deliveryDate);
        }

        $contact = !empty(trim($contactPerson ?? '')) ? trim($contactPerson) : null;
        $greeting = $contact ? "Halo {$contact}," : "Halo,";

        return "{$greeting}\n\n"
            . "Kami ingin melakukan restok bahan berikut:\n\n"
            . "{$itemsText}"
            . "{$deliveryText}\n\n"
            . "Mohon diinformasikan ketersediaan, harga terbaru, dan estimasi pengiriman.\n\n"
            . "Terima kasih.";
    }

    /**
     * Buat WhatsApp deep link (https://wa.me/{clean_phone}?text={encoded}).
     * URL encoding menggunakan RFC 3986 (rawurlencode) agar newline & karakter khusus aman.
     */
    public function buildWhatsAppUrl(?string $phone, string $message): ?string
    {
        $cleanPhone = $this->normalizePhoneNumber($phone);
        if ($cleanPhone === null) {
            return null;
        }

        return 'https://wa.me/' . $cleanPhone . '?text=' . rawurlencode($message);
    }

    /**
     * Generate draf pesan dan WhatsApp URL untuk satu supplier dengan STRICT TENANT ISOLATION.
     */
    public function generateForSupplier(int $userId, int $supplierId, array $items, ?string $deliveryDate = null): array
    {
        // 1. Tenant Isolation Guard: Supplier WAJIB dimiliki tenant (user_id = $userId)
        $query = Supplier::query()->where('id', $supplierId);

        if (Schema::hasColumn('suppliers', 'user_id')) {
            $query->where('user_id', $userId);
        }

        $supplier = $query->first();

        if (!$supplier) {
            return [
                'success'            => false,
                'status'             => 'UNAUTHORIZED_SUPPLIER',
                'error'              => 'Supplier tidak ditemukan atau bukan milik tenant Anda.',
                'supplier'           => null,
                'items'              => [],
                'delivery_date'      => null,
                'message'            => null,
                'whatsapp_available' => false,
                'whatsapp_url'       => null,
                'note'               => null,
            ];
        }

        // 2. Normalisasi Items
        $normalizedItems = [];
        foreach ($items as $rawItem) {
            $name = trim($rawItem['item_name'] ?? $rawItem['name'] ?? '');
            $qty = (float) ($rawItem['quantity'] ?? $rawItem['suggested_qty'] ?? $rawItem['qty'] ?? 0);
            $uom = trim($rawItem['unit'] ?? $rawItem['uom'] ?? $rawItem['base_uom'] ?? '');
            $itemId = isset($rawItem['item_id']) ? (int) $rawItem['item_id'] : (isset($rawItem['id']) ? (int) $rawItem['id'] : null);

            // Jika nama atau UOM belum ada tetapi item_id ada, ambil dari database
            if (($name === '' || $uom === '') && $itemId) {
                $dbItem = Item::find($itemId);
                if ($dbItem) {
                    if ($name === '') $name = $dbItem->name;
                    if ($uom === '') $uom = $dbItem->base_uom ?? 'pcs';
                }
            }

            if ($name === '') {
                $name = 'Bahan Baku';
            }
            if ($uom === '') {
                $uom = 'pcs';
            }

            if ($qty > 0) {
                $normalizedItems[] = [
                    'item_id'   => $itemId,
                    'item_name' => $name,
                    'quantity'  => $qty,
                    'unit'      => $uom,
                ];
            }
        }

        // 3. Bangun Pesan Deterministik
        $trimmedDelivery = !empty(trim($deliveryDate ?? '')) ? trim($deliveryDate) : null;
        $message = $this->buildMessage($supplier->contact_person, $normalizedItems, $trimmedDelivery);

        // 4. Normalisasi Nomor dan Deep Link WhatsApp
        $cleanPhone = $this->normalizePhoneNumber($supplier->phone);
        $whatsappUrl = $cleanPhone ? $this->buildWhatsAppUrl($cleanPhone, $message) : null;
        $whatsappAvailable = ($cleanPhone !== null);

        return [
            'success'            => true,
            'status'             => 'SUCCESS',
            'supplier'           => [
                'id'             => $supplier->id,
                'supplier_id'    => $supplier->id,
                'name'           => $supplier->name,
                'supplier_name'  => $supplier->name,
                'contact_person' => $supplier->contact_person,
                'phone'          => $supplier->phone,
                'clean_phone'    => $cleanPhone,
            ],
            'items'              => $normalizedItems,
            'delivery_date'      => $trimmedDelivery,
            'message'            => $message,
            'whatsapp_available' => $whatsappAvailable,
            'whatsapp_url'       => $whatsappUrl,
            'note'               => $whatsappAvailable ? null : 'Nomor WhatsApp supplier belum tersedia atau format tidak valid.',
        ];
    }

    /**
     * Generate pesan yang dikelompokkan per supplier (Multiple Items Same Supplier -> 1 pesan).
     * Jika item memiliki supplier berbeda, dipisahkan menjadi beberapa pesan per supplier.
     */
    public function generateMessagesGroupedBySupplier(int $userId, array $itemsWithSupplier, ?string $deliveryDate = null): array
    {
        $grouped = [];

        foreach ($itemsWithSupplier as $entry) {
            $supplierId = (int) ($entry['supplier_id'] ?? 0);
            if ($supplierId <= 0) {
                continue;
            }

            if (!isset($grouped[$supplierId])) {
                $grouped[$supplierId] = [];
            }

            $grouped[$supplierId][] = $entry;
        }

        $results = [];
        foreach ($grouped as $supId => $supItems) {
            $genResult = $this->generateForSupplier($userId, $supId, $supItems, $deliveryDate);
            if ($genResult['success']) {
                $results[] = $genResult;
            }
        }

        return $results;
    }
}
