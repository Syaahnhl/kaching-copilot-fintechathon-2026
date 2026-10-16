<?php

namespace App\Jobs\DeveloperHub;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\DeveloperHub\TelemetryIngestService;
use App\Repositories\DeveloperHub\TelemetryRepository;
use Carbon\Carbon;

class ProcessTelemetryBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180; // Timeout 3 menit

    /**
     * Execute the job.
     */
    public function handle(TelemetryIngestService $ingestService, TelemetryRepository $repository): void
    {
        ini_set('memory_limit', '512M');
        $batch = $ingestService->popBatch(200); // Tarik maks 1000 request per ticks
        if (empty($batch)) {
            return;
        }

        $rawRecords = [];
        $buckets = [];

        foreach ($batch as $record) {
            // 1. Kumpulkan data raw
            $rawRecords[] = [
                'request_id' => $record['request_id'],
                'user_id' => $record['user_id'],
                'outlet_id' => $record['outlet_id'],
                'endpoint' => $record['endpoint'],
                'method' => $record['method'],
                'status_code' => $record['status_code'],
                'latency_ms' => $record['latency_ms'],
                'client_ip' => $record['client_ip'],
                'user_agent' => $record['user_agent'],
                'request_headers' => $this->ensureValidJson($record['request_headers']),
                'response_headers' => $this->ensureValidJson($record['response_headers']),
                'request_payload' => $this->ensureValidJson($record['request_payload']),
                'response_payload' => $this->ensureValidJson($record['response_payload']),
                'created_at' => $record['created_at'],
            ];

            // 2. Kelompokkan berdasarkan menit untuk kalkulasi persentil
            $time = Carbon::parse($record['created_at']);
            $minuteBucket = $time->format('Y-m-d H:i:00');
            $bucketKey = "{$minuteBucket}_{$record['endpoint']}_{$record['method']}";

            if (!isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'minute_bucket' => $minuteBucket,
                    'endpoint' => $record['endpoint'],
                    'method' => $record['method'],
                    'total_requests' => 0,
                    'error_count' => 0,
                    'latencies' => [],
                ];
            }

            $buckets[$bucketKey]['total_requests']++;
            if ($record['status_code'] >= 400) {
                $buckets[$bucketKey]['error_count']++;
            }
            $buckets[$bucketKey]['latencies'][] = $record['latency_ms'];
        }

        // 3. Simpan data raw ke database
        $repository->insertRawBatch($rawRecords);

        // 4. Kalkulasi persentil untuk setiap bucket menit
        $aggregatesToUpsert = [];
        foreach ($buckets as $key => $bucket) {
            $latencies = $bucket['latencies'];
            sort($latencies);
            $count = count($latencies);

            $p50 = $latencies[(int) floor($count * 0.50)];
            $p90 = $latencies[(int) floor($count * 0.90)] ?? $p50;
            $p95 = $latencies[(int) floor($count * 0.95)] ?? $p90;
            $p99 = $latencies[(int) floor($count * 0.99)] ?? $p95;

            $aggregatesToUpsert[] = [
                'minute_bucket' => $bucket['minute_bucket'],
                'total_requests' => $bucket['total_requests'],
                'error_count' => $bucket['error_count'],
                'p50_ms' => $p50,
                'p90_ms' => $p90,
                'p95_ms' => $p95,
                'p99_ms' => $p99,
            ];
        }

        // 5. Simpan data agregasi menggunakan UPSERT
        $repository->upsertAggregates($aggregatesToUpsert);
    }

    /**
     * Pastikan data yang dimasukkan ke database adalah JSON valid.
     */
    private function ensureValidJson($value): ?string
    {
        if (is_null($value)) {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        if (is_string($value)) {
            json_decode($value);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
            return json_encode($value);
        }
        return json_encode($value);
    }
}
