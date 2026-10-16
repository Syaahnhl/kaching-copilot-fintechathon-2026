<?php

namespace App\Repositories\DeveloperHub;

use App\Models\DeveloperHub\ApiTelemetryAggregate;
use App\Models\DeveloperHub\ApiTelemetryRaw;
use Illuminate\Support\Facades\DB;

class TelemetryRepository
{
    public function insertRawBatch(array $records): bool
    {
        if (empty($records)) {
            return true;
        }

        return ApiTelemetryRaw::insert($records);
    }

    public function upsertAggregates(array $aggregates): void
    {
        if (empty($aggregates)) {
            return;
        }

        foreach ($aggregates as $agg) {
            DB::statement("
                INSERT INTO api_telemetry_aggregates 
                (minute_bucket, total_requests, error_count, p50_ms, p90_ms, p95_ms, p99_ms, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                total_requests = total_requests + VALUES(total_requests),
                error_count = error_count + VALUES(error_count),
                p50_ms = VALUES(p50_ms),
                p90_ms = VALUES(p90_ms),
                p95_ms = VALUES(p95_ms),
                p99_ms = VALUES(p99_ms),
                updated_at = NOW()
            ", [
                $agg['minute_bucket'],
                $agg['total_requests'],
                $agg['error_count'],
                $agg['p50_ms'],
                $agg['p90_ms'],
                $agg['p95_ms'],
                $agg['p99_ms']
            ]);
        }
    }

    public function getOverview(string $start, string $end): array
    {
        return ApiTelemetryAggregate::whereBetween('minute_bucket', [$start, $end])
            ->orderBy('minute_bucket', 'asc')
            ->get()
            ->toArray();
    }

    public function getTopEndpoints(string $start, string $end, int $limit = 10): array
    {
        // Mendapatkan ringkasan per endpoint dari data raw
        return ApiTelemetryRaw::select('endpoint', 'method', DB::raw('count(*) as hits'), DB::raw('sum(case when status_code >= 400 then 1 else 0 end) as error_count'), DB::raw('avg(latency_ms) as avg_latency'), DB::raw('max(latency_ms) as max_latency'))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('endpoint', 'method')
            ->orderBy('hits', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function getRawRequests(array $filters, int $limit = 50): array
    {
        $query = ApiTelemetryRaw::query();

        if (!empty($filters['endpoint'])) {
            $query->where('endpoint', 'like', '%' . $filters['endpoint'] . '%');
        }

        if (!empty($filters['status_code'])) {
            $query->where('status_code', $filters['status_code']);
        }

        if (!empty($filters['method'])) {
            $query->where('method', $filters['method']);
        }

        return $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function getRequestDetail(string $requestId): ?ApiTelemetryRaw
    {
        return ApiTelemetryRaw::where('request_id', $requestId)->first();
    }

    public function pruneRawLogs(int $hoursAgo): int
    {
        return ApiTelemetryRaw::where('created_at', '<', now()->subHours($hoursAgo))->delete();
    }
}
