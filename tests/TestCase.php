<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $viewCompiledPath = storage_path('framework/views');
        if (! is_dir($viewCompiledPath)) {
            @mkdir($viewCompiledPath, 0777, true);
        }
        config(['view.compiled' => $viewCompiledPath]);

        // Mock TelemetryIngestService to bypass Redis class checks in testing environment
        $mock = $this->createMock(\App\Services\DeveloperHub\TelemetryIngestService::class);
        $mock->method('pushToBuffer')->willReturn(true);
        $this->app->instance(\App\Services\DeveloperHub\TelemetryIngestService::class, $mock);
    }
}
