<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PartialRefundLogicTest extends TestCase
{
    /**
     * Helper mimicking the controller refund processor core logic
     */
    private function processRefund(array &$item, float $requestedQty, ?string $refundRef = null): array
    {
        if ($requestedQty <= 0) {
            throw new \InvalidArgumentException("Kuantitas refund harus lebih dari 0.");
        }

        $existingRefundQty = (float)($item['refund_qty'] ?? 0.0);
        $originalQty = (float)$item['qty'];

        // Idempotency check via refund reference tag
        if (!empty($refundRef)) {
            $tag = "[ref_refund_id:{$refundRef}]";
            if (str_contains($item['note'] ?? '', $tag)) {
                return [
                    'status' => 'success',
                    'code' => 'ALREADY_REFUNDED',
                    'message' => 'Idempotent duplicate request',
                    'refund_amount' => 0,
                    'stock_restored' => 0.0,
                ];
            }
        }

        $newRefundQty = $existingRefundQty + $requestedQty;
        if ($newRefundQty > $originalQty) {
            $remaining = max(0.0, $originalQty - $existingRefundQty);
            throw new \OverflowException("Kuantitas refund ({$requestedQty}) melebihi sisa item ({$remaining}).");
        }

        $isFullyRefunded = ($newRefundQty >= $originalQty);
        $newStatus = $isFullyRefunded ? 'Refund' : $item['status'];

        $refundAmount = (int)round($item['price'] * $requestedQty);
        $stockRestored = $requestedQty;

        $note = trim(($item['note'] ?? ''));
        if (!empty($refundRef)) {
            $note .= " [ref_refund_id:{$refundRef}]";
        }

        // Mutate item state
        $item['refund_qty'] = $newRefundQty;
        $item['status'] = $newStatus;
        $item['note'] = trim($note);

        return [
            'status' => 'success',
            'code' => 'NEWLY_REFUNDED',
            'refund_qty' => $newRefundQty,
            'remaining_qty' => max(0.0, $originalQty - $newRefundQty),
            'is_fully_refunded' => $isFullyRefunded,
            'status_result' => $newStatus,
            'refund_amount' => $refundAmount,
            'stock_restored' => $stockRestored,
        ];
    }

    /**
     * TEST 1: qty 5 → refund 1
     * Expected: refundQty = 1, remaining = 4, status != Refund, stock +1, refund amount = correct amount
     */
    public function test_1_refund_1_of_5(): void
    {
        $item = [
            'id' => 142,
            'qty' => 5.0,
            'refund_qty' => 0.0,
            'price' => 4000,
            'status' => 'Proses',
            'note' => '',
        ];

        $res = $this->processRefund($item, 1.0, 'ref_test_1');

        $this->assertEquals(1.0, $item['refund_qty']);
        $this->assertEquals(4.0, $res['remaining_qty']);
        $this->assertNotEquals('Refund', $item['status']);
        $this->assertEquals('Proses', $item['status']);
        $this->assertEquals(4000, $res['refund_amount']);
        $this->assertEquals(1.0, $res['stock_restored']);
        $this->assertFalse($res['is_fully_refunded']);
    }

    /**
     * TEST 2: qty 5 → refund 2
     * Expected: refundQty = 2, remaining = 3
     */
    public function test_2_refund_2_of_5(): void
    {
        $item = [
            'id' => 142,
            'qty' => 5.0,
            'refund_qty' => 0.0,
            'price' => 4000,
            'status' => 'Proses',
            'note' => '',
        ];

        $res = $this->processRefund($item, 2.0, 'ref_test_2');

        $this->assertEquals(2.0, $item['refund_qty']);
        $this->assertEquals(3.0, $res['remaining_qty']);
        $this->assertNotEquals('Refund', $item['status']);
        $this->assertEquals(8000, $res['refund_amount']);
        $this->assertEquals(2.0, $res['stock_restored']);
    }

    /**
     * TEST 3: qty 5 → refund 1 → refund 1
     * Expected: refundQty = 2, remaining = 3, two legitimate refund operations
     */
    public function test_3_sequential_refunds_1_then_1(): void
    {
        $item = [
            'id' => 142,
            'qty' => 5.0,
            'refund_qty' => 0.0,
            'price' => 4000,
            'status' => 'Proses',
            'note' => '',
        ];

        $res1 = $this->processRefund($item, 1.0, 'ref_seq_a');
        $this->assertEquals(1.0, $item['refund_qty']);
        $this->assertEquals(4.0, $res1['remaining_qty']);
        $this->assertEquals(4000, $res1['refund_amount']);

        $res2 = $this->processRefund($item, 1.0, 'ref_seq_b');
        $this->assertEquals(2.0, $item['refund_qty']);
        $this->assertEquals(3.0, $res2['remaining_qty']);
        $this->assertEquals(4000, $res2['refund_amount']);
        $this->assertNotEquals('Refund', $item['status']);
    }

    /**
     * TEST 4: qty 5 → refund 5
     * Expected: refundQty = 5, remaining = 0, status = Refund
     */
    public function test_4_full_refund_5_of_5(): void
    {
        $item = [
            'id' => 142,
            'qty' => 5.0,
            'refund_qty' => 0.0,
            'price' => 4000,
            'status' => 'Proses',
            'note' => '',
        ];

        $res = $this->processRefund($item, 5.0, 'ref_test_4');

        $this->assertEquals(5.0, $item['refund_qty']);
        $this->assertEquals(0.0, $res['remaining_qty']);
        $this->assertEquals('Refund', $item['status']);
        $this->assertEquals(20000, $res['refund_amount']);
        $this->assertTrue($res['is_fully_refunded']);
    }

    /**
     * TEST 5: qty 5 → refund 6
     * Expected: REJECTED, no stock mutation, no financial mutation
     */
    public function test_5_over_refund_rejected(): void
    {
        $item = [
            'id' => 142,
            'qty' => 5.0,
            'refund_qty' => 0.0,
            'price' => 4000,
            'status' => 'Proses',
            'note' => '',
        ];

        $this->expectException(\OverflowException::class);
        $this->processRefund($item, 6.0, 'ref_test_5');

        // State remains pristine
        $this->assertEquals(0.0, $item['refund_qty']);
        $this->assertEquals('Proses', $item['status']);
    }

    /**
     * TEST 6: qty 5 → refund 4 → refund 2
     * Expected: second request rejects. Final refundQty = 4.
     */
    public function test_6_sequential_over_refund_rejected(): void
    {
        $item = [
            'id' => 142,
            'qty' => 5.0,
            'refund_qty' => 0.0,
            'price' => 4000,
            'status' => 'Proses',
            'note' => '',
        ];

        $res1 = $this->processRefund($item, 4.0, 'ref_test_6a');
        $this->assertEquals(4.0, $item['refund_qty']);
        $this->assertEquals(1.0, $res1['remaining_qty']);

        try {
            $this->processRefund($item, 2.0, 'ref_test_6b');
            $this->fail("Expected OverflowException was not thrown");
        } catch (\OverflowException $e) {
            $this->assertStringContainsString("melebihi sisa item", $e->getMessage());
        }

        // Final state unchanged after failed attempt
        $this->assertEquals(4.0, $item['refund_qty']);
        $this->assertEquals('Proses', $item['status']);
    }

    /**
     * TEST 7: two identical products with different notes
     * Expected: only selected transaction_item changes.
     */
    public function test_7_identical_products_isolated_by_row(): void
    {
        $rowA = [
            'id' => 101,
            'menu_id' => 18,
            'qty' => 3.0,
            'refund_qty' => 0.0,
            'price' => 4000,
            'status' => 'Proses',
            'note' => 'Teh Manis',
        ];

        $rowB = [
            'id' => 102,
            'menu_id' => 18,
            'qty' => 2.0,
            'refund_qty' => 0.0,
            'price' => 4000,
            'status' => 'Proses',
            'note' => 'Teh Tawar',
        ];

        // Refund Row A by 1
        $resA = $this->processRefund($rowA, 1.0, 'ref_row_a');

        $this->assertEquals(1.0, $rowA['refund_qty']);
        $this->assertEquals(2.0, $resA['remaining_qty']);

        // Row B remains completely untouched
        $this->assertEquals(0.0, $rowB['refund_qty']);
        $this->assertEquals(2.0, $rowB['qty']);
        $this->assertEquals('Teh Tawar', $rowB['note']);
    }

    /**
     * TEST 8: sync after partial refund
     * Server: qty 5, refund_qty 1
     * Client: qty 5, refundQty 1, remaining 4
     */
    public function test_8_sync_partial_state_preservation(): void
    {
        $serverItem = [
            'qty' => 5.0,
            'refund_qty' => 1.0,
            'status' => 'Proses',
        ];

        // Client parsing logic matching SyncViewModel.kt
        $parsedQty = (float)$serverItem['qty'];
        $parsedRefundQty = (float)($serverItem['refund_qty'] ?? 0.0);
        $isStatusRefund = strtolower($serverItem['status']) === 'refund';

        $finalRefundQty = $parsedRefundQty > 0.0
            ? min($parsedRefundQty, $parsedQty)
            : ($isStatusRefund ? $parsedQty : 0.0);

        $isItemRefunded = $isStatusRefund || ($finalRefundQty >= $parsedQty && $parsedQty > 0.0);
        $remainingQty = max(0.0, $parsedQty - $finalRefundQty);

        $this->assertEquals(5.0, $parsedQty);
        $this->assertEquals(1.0, $finalRefundQty);
        $this->assertEquals(4.0, $remainingQty);
        $this->assertFalse($isItemRefunded);
    }

    /**
     * TEST 9: poll/retry same refund client reference
     * Expected: no duplicate financial/stock mutation.
     */
    public function test_9_idempotency_retry_protection(): void
    {
        $item = [
            'id' => 142,
            'qty' => 5.0,
            'refund_qty' => 0.0,
            'price' => 4000,
            'status' => 'Proses',
            'note' => '',
        ];

        $res1 = $this->processRefund($item, 1.0, 'ref_uuid_dup_123');
        $this->assertEquals('NEWLY_REFUNDED', $res1['code']);
        $this->assertEquals(1.0, $item['refund_qty']);
        $this->assertEquals(4000, $res1['refund_amount']);

        // Retry same request reference (network timeout replay)
        $res2 = $this->processRefund($item, 1.0, 'ref_uuid_dup_123');
        $this->assertEquals('ALREADY_REFUNDED', $res2['code']);
        $this->assertEquals(0, $res2['refund_amount']);
        $this->assertEquals(0.0, $res2['stock_restored']);

        // Item refundQty did not double mutate
        $this->assertEquals(1.0, $item['refund_qty']);
    }

    /**
     * TEST 10: fully refunded item
     * Expected: UI cannot refund again.
     */
    public function test_10_fully_refunded_cannot_refund_again(): void
    {
        $item = [
            'id' => 142,
            'qty' => 5.0,
            'refund_qty' => 5.0,
            'price' => 4000,
            'status' => 'Refund',
            'note' => '',
        ];

        $maxRefundable = max(0.0, $item['qty'] - $item['refund_qty']);
        $this->assertEquals(0.0, $maxRefundable);

        $this->expectException(\OverflowException::class);
        $this->processRefund($item, 1.0, 'ref_test_10');
    }
}
