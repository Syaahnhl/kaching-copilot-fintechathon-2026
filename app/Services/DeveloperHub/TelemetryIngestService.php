<?php

namespace App\Services\DeveloperHub;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class TelemetryIngestService
{
    protected string $redisKey = 'kaching_telemetry_buffer';
    protected int $maxQueueSize = 50000;

    /**
     * Push raw request telemetry payload onto Redis List buffer.
     */
    public function pushToBuffer(array $data): bool
    {
        try {
            // Cek panjang queue saat ini demi memitigasi risiko OOM
            $queueLength = Redis::llen($this->redisKey);
            if ($queueLength >= $this->maxQueueSize) {
                // Drop request sukses, hanya prioritaskan error (status_code >= 400)
                if (isset($data['status_code']) && $data['status_code'] < 400) {
                    return false;
                }
            }

            Redis::rpush($this->redisKey, json_encode($data));
            return true;
        } catch (\Throwable $e) {
            // Silent fallback agar core API POS tidak down karena masalah Redis
            Log::warning("Telemetry capture skipped. Redis connection error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Pop a batch of records from Redis list buffer.
     */
    public function popBatch(int $batchSize = 500): array
    {
        $records = [];
        try {
            for ($i = 0; $i < $batchSize; $i++) {
                $item = Redis::lpop($this->redisKey);
                if (!$item) {
                    break;
                }
                $decoded = json_decode($item, true);
                if ($decoded) {
                    $records[] = $decoded;
                }
            }
        } catch (\Throwable $e) {
            Log::error("Failed to pop telemetry batch from Redis: " . $e->getMessage());
        }
        return $records;
    }

    /**
     * Get current buffer length.
     */
    public function getQueueLength(): int
    {
        try {
            return (int) Redis::llen($this->redisKey);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
