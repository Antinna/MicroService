<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\PayoutCalculationService;
use Antinna\MultiVendor\Services\Logger;
use PHPUnit\Framework\TestCase;

class PayoutCalculationServiceTest extends TestCase
{
    private PayoutCalculationService $payoutCalculationService;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();
        $this->payoutCalculationService = new PayoutCalculationService();
    }

    public function testCalculateVendorPayout()
    {
        $vendorId = 999; // Non-existent vendor for testing
        $startDate = '2024-01-01';
        $endDate = '2024-01-31';
        $options = [
            'include_delivery_fees' => true,
            'apply_promotional_discount' => false
        ];

        $result = $this->payoutCalculationService->calculateVendorPayout($vendorId, $startDate, $endDate, $options);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because vendor doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Vendor not found', $result['error']);
    }

    public function testCalculateBulkPayouts()
    {
        $vendorIds = [1, 2, 3, 999]; // Mix of potentially existing and non-existing vendors
        $startDate = '2024-01-01';
        $endDate = '2024-01-31';
        $options = [
            'batch_size' => 10,
            'include_fees_breakdown' => true
        ];

        $result = $this->payoutCalculationService->calculateBulkPayouts($vendorIds, $startDate, $endDate, $options);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('total_vendors', $result);
        $this->assertArrayHasKey('successful_calculations', $result);
        $this->assertArrayHasKey('failed_calculations', $result);
        $this->assertArrayHasKey('total_payout_amount', $result);
        $this->assertArrayHasKey('results', $result);

        $this->assertEquals(count($vendorIds), $result['total_vendors']);
        $this->assertEquals(['start' => $startDate, 'end' => $endDate], $result['period']);
        $this->assertIsArray($result['results']);
        $this->assertCount(count($vendorIds), $result['results']);

        // Check individual results structure
        foreach ($result['results'] as $vendorResult) {
            $this->assertArrayHasKey('vendor_id', $vendorResult);
            $this->assertArrayHasKey('result', $vendorResult);
            $this->assertIsArray($vendorResult['result']);
            $this->assertArrayHasKey('success', $vendorResult['result']);
        }
    }

    public function testProcessPayoutPayment()
    {
        $payoutId = 999; // Non-existent payout for testing
        $paymentOptions = [
            'payment_method' => 'bank_transfer',
            'priority' => 'high',
            'notify_vendor' => true
        ];

        $result = $this->payoutCalculationService->processPayoutPayment($payoutId, $paymentOptions);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Should fail because payout doesn't exist
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Payout not found', $result['error']);
    }

    public function testGetVendorPayoutHistory()
    {
        $vendorId = 1;
        $months = 12;
        $filters = [
            'status' => PayoutCalculationService::STATUS_COMPLETED,
            'min_amount' => 100
        ];

        $result = $this->payoutCalculationService->getVendorPayoutHistory($vendorId, $months, $filters);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('vendor_id', $result);
            $this->assertArrayHasKey('period_months', $result);
            $this->assertArrayHasKey('payouts', $result);
            $this->assertArrayHasKey('summary', $result);
            
            $this->assertEquals($vendorId, $result['vendor_id']);
            $this->assertEquals($months, $result['period_months']);
            $this->assertIsArray($result['payouts']);
            $this->assertIsArray($result['summary']);
            
            // Check summary structure
            $summary = $result['summary'];
            $this->assertArrayHasKey('total_payouts', $summary);
            $this->assertArrayHasKey('completed_payouts', $summary);
            $this->assertArrayHasKey('total_amount', $summary);
            $this->assertArrayHasKey('completed_amount', $summary);
            $this->assertArrayHasKey('average_payout', $summary);
            $this->assertArrayHasKey('completion_rate', $summary);
        }

        // Test without filters
        $result = $this->payoutCalculationService->getVendorPayoutHistory($vendorId, $months);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testGetPayoutAnalytics()
    {
        $filters = [
            'vendor_id' => 1,
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31'
        ];

        $result = $this->payoutCalculationService->getPayoutAnalytics($filters);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('statistics', $result);
            $this->assertArrayHasKey('monthly_trends', $result);
            $this->assertArrayHasKey('fee_analysis', $result);
            $this->assertArrayHasKey('filters_applied', $result);
            
            $this->assertIsArray($result['statistics']);
            $this->assertIsArray($result['monthly_trends']);
            $this->assertIsArray($result['fee_analysis']);
            $this->assertEquals($filters, $result['filters_applied']);
            
            // Check statistics structure
            $stats = $result['statistics'];
            $this->assertArrayHasKey('total_payouts', $stats);
            $this->assertArrayHasKey('total_gross_revenue', $stats);
            $this->assertArrayHasKey('total_fees', $stats);
            $this->assertArrayHasKey('total_net_payout', $stats);
            $this->assertArrayHasKey('average_payout', $stats);
            $this->assertArrayHasKey('min_payout', $stats);
            $this->assertArrayHasKey('max_payout', $stats);
            $this->assertArrayHasKey('unique_vendors', $stats);
            
            // Check fee analysis structure
            $feeAnalysis = $result['fee_analysis'];
            $this->assertArrayHasKey('total_platform_fees', $feeAnalysis);
            $this->assertArrayHasKey('total_payment_fees', $feeAnalysis);
            $this->assertArrayHasKey('total_delivery_fees', $feeAnalysis);
        }

        // Test without filters
        $result = $this->payoutCalculationService->getPayoutAnalytics();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testUpdateVendorFeeStructure()
    {
        $vendorId = 1;
        $feeStructure = [
            'platform_fee_percentage' => 5.0,
            'payment_processing_percentage' => 2.5,
            'payment_fixed_fee' => 0.30,
            'delivery_fee_percentage' => 1.0,
            'marketing_fee_percentage' => 0.5,
            'monthly_subscription_fee' => 29.99,
            'effective_from' => '2024-02-01'
        ];

        $result = $this->payoutCalculationService->updateVendorFeeStructure($vendorId, $feeStructure);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('message', $result);
            $this->assertEquals('Vendor fee structure updated successfully', $result['message']);
        }
    }

    public function testUpdateVendorFeeStructureWithInvalidData()
    {
        $vendorId = 1;
        $invalidFeeStructure = [
            'platform_fee_percentage' => 150, // Invalid: > 100%
            'payment_processing_percentage' => -5, // Invalid: negative
            'payment_fixed_fee' => 'invalid', // Invalid: not numeric
            'delivery_fee_percentage' => 'abc', // Invalid: not numeric
            'monthly_subscription_fee' => -10 // Invalid: negative
        ];

        $result = $this->payoutCalculationService->updateVendorFeeStructure($vendorId, $invalidFeeStructure);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        
        $errors = $result['errors'];
        $this->assertArrayHasKey('platform_fee_percentage', $errors);
        $this->assertArrayHasKey('payment_processing_percentage', $errors);
        $this->assertArrayHasKey('payment_fixed_fee', $errors);
        $this->assertArrayHasKey('delivery_fee_percentage', $errors);
        $this->assertArrayHasKey('monthly_subscription_fee', $errors);
    }

    public function testFeeTypeConstants()
    {
        // Test that all fee type constants are defined
        $this->assertEquals('platform', PayoutCalculationService::FEE_TYPE_PLATFORM);
        $this->assertEquals('payment_processing', PayoutCalculationService::FEE_TYPE_PAYMENT_PROCESSING);
        $this->assertEquals('delivery', PayoutCalculationService::FEE_TYPE_DELIVERY);
        $this->assertEquals('marketing', PayoutCalculationService::FEE_TYPE_MARKETING);
        $this->assertEquals('subscription', PayoutCalculationService::FEE_TYPE_SUBSCRIPTION);
        $this->assertEquals('penalty', PayoutCalculationService::FEE_TYPE_PENALTY);
    }

    public function testPayoutStatusConstants()
    {
        // Test that all payout status constants are defined
        $this->assertEquals('pending', PayoutCalculationService::STATUS_PENDING);
        $this->assertEquals('calculated', PayoutCalculationService::STATUS_CALCULATED);
        $this->assertEquals('approved', PayoutCalculationService::STATUS_APPROVED);
        $this->assertEquals('processing', PayoutCalculationService::STATUS_PROCESSING);
        $this->assertEquals('completed', PayoutCalculationService::STATUS_COMPLETED);
        $this->assertEquals('failed', PayoutCalculationService::STATUS_FAILED);
        $this->assertEquals('disputed', PayoutCalculationService::STATUS_DISPUTED);
    }

    public function testPayoutFrequencyConstants()
    {
        // Test that all payout frequency constants are defined
        $this->assertEquals('daily', PayoutCalculationService::FREQUENCY_DAILY);
        $this->assertEquals('weekly', PayoutCalculationService::FREQUENCY_WEEKLY);
        $this->assertEquals('biweekly', PayoutCalculationService::FREQUENCY_BIWEEKLY);
        $this->assertEquals('monthly', PayoutCalculationService::FREQUENCY_MONTHLY);
    }

    public function testCalculateVendorPayoutWithDifferentDateRanges()
    {
        $vendorId = 999;
        $dateRanges = [
            ['2024-01-01', '2024-01-07'], // 1 week
            ['2024-01-01', '2024-01-31'], // 1 month
            ['2024-01-01', '2024-03-31'], // 3 months
            ['2024-01-01', '2024-12-31']  // 1 year
        ];

        foreach ($dateRanges as [$startDate, $endDate]) {
            $result = $this->payoutCalculationService->calculateVendorPayout($vendorId, $startDate, $endDate);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            // Should fail because vendor doesn't exist, but test that all date ranges are handled
            $this->assertFalse($result['success']);
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testCalculateBulkPayoutsWithEmptyVendorList()
    {
        $vendorIds = [];
        $startDate = '2024-01-01';
        $endDate = '2024-01-31';

        $result = $this->payoutCalculationService->calculateBulkPayouts($vendorIds, $startDate, $endDate);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('total_vendors', $result);
        $this->assertArrayHasKey('results', $result);

        $this->assertEquals(0, $result['total_vendors']);
        $this->assertEmpty($result['results']);
    }

    public function testGetVendorPayoutHistoryWithDifferentTimeRanges()
    {
        $vendorId = 1;
        $timeRanges = [1, 3, 6, 12, 24]; // months

        foreach ($timeRanges as $months) {
            $result = $this->payoutCalculationService->getVendorPayoutHistory($vendorId, $months);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            if ($result['success']) {
                $this->assertEquals($months, $result['period_months']);
            }
        }
    }

    public function testGetPayoutAnalyticsWithDifferentFilters()
    {
        $filterVariations = [
            [], // No filters
            ['vendor_id' => 1],
            ['start_date' => '2024-01-01'],
            ['end_date' => '2024-12-31'],
            ['vendor_id' => 1, 'start_date' => '2024-01-01', 'end_date' => '2024-12-31']
        ];

        foreach ($filterVariations as $filters) {
            $result = $this->payoutCalculationService->getPayoutAnalytics($filters);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            
            if ($result['success']) {
                $this->assertEquals($filters, $result['filters_applied']);
            }
        }
    }

    public function testFeeStructureValidation()
    {
        $vendorId = 1;
        
        // Test valid fee structure
        $validFeeStructure = [
            'platform_fee_percentage' => 5.0,
            'payment_processing_percentage' => 2.5,
            'payment_fixed_fee' => 0.30,
            'delivery_fee_percentage' => 0,
            'marketing_fee_percentage' => 0,
            'monthly_subscription_fee' => 0
        ];

        $result = $this->payoutCalculationService->updateVendorFeeStructure($vendorId, $validFeeStructure);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // Test boundary values
        $boundaryFeeStructure = [
            'platform_fee_percentage' => 0, // Minimum
            'payment_processing_percentage' => 100, // Maximum
            'payment_fixed_fee' => 0, // Minimum
            'monthly_subscription_fee' => 0 // Minimum
        ];

        $result = $this->payoutCalculationService->updateVendorFeeStructure($vendorId, $boundaryFeeStructure);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testProcessPayoutPaymentWithInvalidPayoutId()
    {
        $invalidPayoutIds = [0, -1, 'invalid', null];

        foreach ($invalidPayoutIds as $payoutId) {
            $result = $this->payoutCalculationService->processPayoutPayment($payoutId);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            $this->assertFalse($result['success']);
            $this->assertArrayHasKey('error', $result);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}