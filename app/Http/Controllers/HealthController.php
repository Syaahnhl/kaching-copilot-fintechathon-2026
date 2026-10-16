<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Services\HealthCheckService;

class HealthController extends Controller
{
    protected HealthCheckService $healthService;

    public function __construct(HealthCheckService $healthService)
    {
        $this->healthService = $healthService;
    }

    public function check(): JsonResponse
    {
        $result = $this->healthService->runChecks();
        
        $statusCode = $result['status'] === 'healthy' ? 200 : 503;
        
        return response()->json($result, $statusCode);
    }
}
