<?php

namespace App\Models\DeveloperHub;

use Illuminate\Database\Eloquent\Model;

class ApiTelemetryRaw extends Model
{
    protected $table = 'api_telemetry_raw';

    public $timestamps = false; // Memakai kolom created_at secara manual

    protected $fillable = [
        'request_id',
        'user_id',
        'outlet_id',
        'endpoint',
        'method',
        'status_code',
        'latency_ms',
        'client_ip',
        'user_agent',
        'request_headers',
        'request_payload',
        'response_headers',
        'response_payload',
        'created_at',
    ];

    protected $casts = [
        'request_headers' => 'array',
        'request_payload' => 'array',
        'response_headers' => 'array',
        'response_payload' => 'array',
        'created_at' => 'datetime',
        'latency_ms' => 'integer',
        'status_code' => 'integer',
    ];
}
