<?php

namespace Tests\Unit;

use App\Services\Financial\CashFlowForecastService;
use App\Services\Financial\FinancialCalculationService;
use App\Services\Financial\FinancialScenarioService;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class SimulatePurchaseTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test amount <= 0 returns validation error.
     */
    public function test_amount_zero_or_negative_returns_error(): void
    {
        $calcMock = Mockery::mock(FinancialCalculationService::class);
        $forecastMock = Mockery::mock(CashFlowForecastService::class);

        $service = new FinancialScenarioService($calcMock, $forecastMock);

        // Case 1: amount = 0
        $resultZero = $service->simulatePurchase(1, null, [
            'amount' => 0,
            'date' => Carbon::tomorrow('Asia/Jakarta')->toDateString(),
            'minimum_buffer' => 1500000,
        ]);
        $this->assertEquals('ERROR', $resultZero['status']);
        $this->assertEquals('INVALID_AMOUNT', $resultZero['code']);

        // Case 2: amount < 0
        $resultNegative = $service->simulatePurchase(1, null, [
            'amount' => -100000,
            'date' => Carbon::tomorrow('Asia/Jakarta')->toDateString(),
            'minimum_buffer' => 1500000,
        ]);
        $this->assertEquals('ERROR', $resultNegative['status']);
        $this->assertEquals('INVALID_AMOUNT', $resultNegative['code']);
    }

    /**
     * Test invalid date formats and invalid calendar dates.
     */
    public function test_invalid_date_returns_error(): void
    {
        $calcMock = Mockery::mock(FinancialCalculationService::class);
        $forecastMock = Mockery::mock(CashFlowForecastService::class);

        $service = new FinancialScenarioService($calcMock, $forecastMock);

        $resultBadFormat = $service->simulatePurchase(1, null, [
            'amount' => 500000,
            'date' => 'not-a-date',
        ]);
        $this->assertEquals('ERROR', $resultBadFormat['status']);
        $this->assertEquals('INVALID_DATE', $resultBadFormat['code']);

        $resultBadCalendar = $service->simulatePurchase(1, null, [
            'amount' => 500000,
            'date' => '2026-02-31',
        ]);
        $this->assertEquals('ERROR', $resultBadCalendar['status']);
        $this->assertEquals('INVALID_DATE', $resultBadCalendar['code']);
    }

    /**
     * Test cash sufficient: cash after purchase exceeds minimum buffer (WITHIN_BUFFER).
     */
    public function test_cash_sufficient_within_buffer(): void
    {
        $targetDate = Carbon::tomorrow('Asia/Jakarta')->toDateString();

        $calcMock = Mockery::mock(FinancialCalculationService::class);
        $calcMock->shouldReceive('getCashPosition')
            ->with(1, null)
            ->andReturn(['current_cash_position' => 5000000.0]);

        $forecastMock = Mockery::mock(CashFlowForecastService::class);
        $forecastMock->shouldReceive('forecast')
            ->andReturn([
                'status' => 'SUCCESS',
                'daily_forecast' => [
                    [
                        'date' => $targetDate,
                        'projected_inflow' => 1300000.0,
                    ],
                ],
            ]);

        $service = new FinancialScenarioService($calcMock, $forecastMock);

        $result = $service->simulatePurchase(1, null, [
            'amount' => 3000000,
            'date' => $targetDate,
            'minimum_buffer' => 1500000,
        ]);

        $this->assertEquals('WITHIN_BUFFER', $result['status']);
        $this->assertEquals(3000000.0, $result['purchase_amount']);
        $this->assertEquals(5000000.0, $result['current_cash']);
        $this->assertEquals(1300000.0, $result['projected_inflow']);
        $this->assertEquals(3300000.0, $result['projected_cash_after_purchase']);
        $this->assertEquals(1500000.0, $result['minimum_operating_buffer']);
        $this->assertEquals(1800000.0, $result['buffer_surplus']);
        $this->assertTrue($result['projection_available']);
    }

    /**
     * Test cash exact: cash after purchase equals minimum buffer (EXACT_BUFFER).
     */
    public function test_cash_exact_on_buffer(): void
    {
        $targetDate = Carbon::tomorrow('Asia/Jakarta')->toDateString();

        $calcMock = Mockery::mock(FinancialCalculationService::class);
        $calcMock->shouldReceive('getCashPosition')
            ->with(1, null)
            ->andReturn(['current_cash_position' => 3000000.0]);

        $forecastMock = Mockery::mock(CashFlowForecastService::class);
        $forecastMock->shouldReceive('forecast')
            ->andReturn([
                'status' => 'SUCCESS',
                'daily_forecast' => [
                    [
                        'date' => $targetDate,
                        'projected_inflow' => 500000.0,
                    ],
                ],
            ]);

        $service = new FinancialScenarioService($calcMock, $forecastMock);

        $result = $service->simulatePurchase(1, null, [
            'amount' => 2000000,
            'date' => $targetDate,
            'minimum_buffer' => 1500000,
        ]);

        $this->assertEquals('EXACT_BUFFER', $result['status']);
        $this->assertEquals(1500000.0, $result['projected_cash_after_purchase']);
        $this->assertEquals(0.0, $result['buffer_surplus']);
    }

    /**
     * Test cash below buffer (BELOW_BUFFER) and deficit (DEFICIT).
     */
    public function test_cash_below_buffer_and_deficit(): void
    {
        $targetDate = Carbon::tomorrow('Asia/Jakarta')->toDateString();

        $calcMock = Mockery::mock(FinancialCalculationService::class);
        $calcMock->shouldReceive('getCashPosition')
            ->with(1, null)
            ->andReturn(['current_cash_position' => 2000000.0]);

        $forecastMock = Mockery::mock(CashFlowForecastService::class);
        $forecastMock->shouldReceive('forecast')
            ->andReturn([
                'status' => 'SUCCESS',
                'daily_forecast' => [
                    [
                        'date' => $targetDate,
                        'projected_inflow' => 0.0,
                    ],
                ],
            ]);

        $service = new FinancialScenarioService($calcMock, $forecastMock);

        // Case 1: Cash is positive, but below safety buffer -> BELOW_BUFFER
        $resultBelow = $service->simulatePurchase(1, null, [
            'amount' => 1000000,
            'date' => $targetDate,
            'minimum_buffer' => 1500000,
        ]);
        $this->assertEquals('BELOW_BUFFER', $resultBelow['status']);
        $this->assertEquals(1000000.0, $resultBelow['projected_cash_after_purchase']);
        $this->assertEquals(-500000.0, $resultBelow['buffer_surplus']);

        // Case 2: Cash becomes negative -> DEFICIT
        $resultDeficit = $service->simulatePurchase(1, null, [
            'amount' => 2500000,
            'date' => $targetDate,
            'minimum_buffer' => 1500000,
        ]);
        $this->assertEquals('DEFICIT', $resultDeficit['status']);
        $this->assertEquals(-500000.0, $resultDeficit['projected_cash_after_purchase']);
        $this->assertEquals(-2000000.0, $resultDeficit['buffer_surplus']);
    }

    /**
     * Test projection unavailable / insufficient data falls back cleanly.
     */
    public function test_projection_unavailable_falls_back_cleanly(): void
    {
        $targetDate = Carbon::tomorrow('Asia/Jakarta')->toDateString();

        $calcMock = Mockery::mock(FinancialCalculationService::class);
        $calcMock->shouldReceive('getCashPosition')
            ->with(1, null)
            ->andReturn(['current_cash_position' => 4000000.0]);

        $forecastMock = Mockery::mock(CashFlowForecastService::class);
        $forecastMock->shouldReceive('forecast')
            ->andReturn([
                'status' => 'FAIL_CLOSED',
                'message' => 'Insufficient history for DOW seasonal median.',
            ]);

        $service = new FinancialScenarioService($calcMock, $forecastMock);

        $result = $service->simulatePurchase(1, null, [
            'amount' => 2000000,
            'date' => $targetDate,
            'minimum_buffer' => 1500000,
        ]);

        $this->assertFalse($result['projection_available']);
        $this->assertEquals(0.0, $result['projected_inflow']);
        $this->assertEquals(2000000.0, $result['projected_cash_after_purchase']);
        $this->assertEquals(500000.0, $result['buffer_surplus']);
        $this->assertEquals('WITHIN_BUFFER', $result['status']);
        $this->assertNotEmpty($result['limitations']);
    }
}
