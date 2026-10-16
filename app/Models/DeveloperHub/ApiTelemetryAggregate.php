<?php

namespace App\Models\DeveloperHub;

use Illuminate\Database\Eloquent\Model;

class ApiTelemetryAggregate extends Model
{
    protected $table = 'api_telemetry_aggregates';

    protected $fillable = [
        'minute_bucket',
        'total_requests',
        'error_count',
        'p50_ms',
        'p90_ms',
        'p95_ms',
        'p99_ms',
    ];

    protected $casts = [
        'minute_bucket' => 'datetime',
        'total_requests' => 'integer',
        'error_count' => 'integer',
        'p50_ms' => 'integer',
        'p90_ms' => 'integer',
        'p95_ms' => 'integer',
        'p99_ms' => 'integer',
    ];
}
