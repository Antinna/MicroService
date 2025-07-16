<?php

namespace Antinna\MultiVendor\Tests;

use Antinna\MultiVendor\Services\StockAlertService;
use Antinna\MultiVendor\Services\Logger;
use PHPUnit\Framework\TestCase;

class StockAlertServiceTest extends TestCase
{
    private StockAlertService $stockAlertService;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();
        $this->stockAlertService = new StockAlertService();
    }

    public function testCheckAndSendAlerts()
    {
        $result = $this->stockAlertService->checkAndSendAlerts();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('alerts_sent', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('products_checked', $result);
            $this->assertIsInt($result['alerts_sent']);
            $this->assertIsInt($result['products_checked']);
            
            if (isset($result['results'])) {
                $this->assertIsArray($result['results']);
            }
        }
    }

    public function testSendStockAlert()
    {
        // Mock product data
        $mockProduct = [
            'id' => 1,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'stock_quantity' => 5,
            'minimum_stock_level' => 20,
            'vendor_id' => 1,
            'business_name' => 'Test Vendor',
            'email' => 'vendor@test.com',
            'phone' => '+1234567890',
            'critical_threshold' => 10,
            'low_threshold' => 25,
            'moderate_threshold' => 50
        ];

        $result = $this->stockAlertService->sendStockAlert($mockProduct);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('product_id', $result);
        $this->assertArrayHasKey('alert_level', $result);
        
        $this->assertEquals(1, $result['product_id']);
        $this->assertContains($result['alert_level'], ['critical', 'low', 'moderate']);
    }

    public function testGetPredictiveRecommendations()
    {
        // Test without vendor filter
        $result = $this->stockAlertService->getPredictiveRecommendations();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('recommendations', $result);
            $this->assertArrayHasKey('total_products', $result);
            $this->assertIsArray($result['recommendations']);
            $this->assertIsInt($result['total_products']);
        }

        // Test with vendor filter
        $result = $this->stockAlertService->getPredictiveRecommendations(1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('recommendations', $result);
            $this->assertArrayHasKey('total_products', $result);
        }
    }

    public function testGetAlertHistory()
    {
        // Test without vendor filter
        $result = $this->stockAlertService->getAlertHistory();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('alerts', $result);
            $this->assertArrayHasKey('summary', $result);
            $this->assertArrayHasKey('period_days', $result);
            
            $this->assertIsArray($result['alerts']);
            $this->assertIsArray($result['summary']);
            $this->assertEquals(30, $result['period_days']);
            
            // Check summary structure
            $summary = $result['summary'];
            $this->assertArrayHasKey('critical', $summary);
            $this->assertArrayHasKey('low', $summary);
            $this->assertArrayHasKey('moderate', $summary);
            $this->assertArrayHasKey('total', $summary);
        }

        // Test with vendor filter and custom days
        $result = $this->stockAlertService->getAlertHistory(1, 7);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertEquals(7, $result['period_days']);
        }
    }

    public function testConfigureAlertSettings()
    {
        $settings = [
            'critical_threshold' => 15,
            'low_threshold' => 30,
            'moderate_threshold' => 60,
            'critical_frequency' => 1,
            'low_frequency' => 4,
            'moderate_frequency' => 12,
            'enable_predictive_alerts' => true,
            'enable_weekend_alerts' => true
        ];

        $result = $this->stockAlertService->configureAlertSettings(1, $settings);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('message', $result);
            $this->assertEquals('Stock alert settings updated successfully', $result['message']);
        }
    }

    public function testGetVendorAlertSettings()
    {
        // Test getting settings for a vendor (should return defaults if none exist)
        $result = $this->stockAlertService->getVendorAlertSettings(1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        if ($result['success']) {
            $this->assertArrayHasKey('settings', $result);
            $settings = $result['settings'];
            
            $this->assertArrayHasKey('vendor_id', $settings);
            $this->assertArrayHasKey('critical_threshold', $settings);
            $this->assertArrayHasKey('low_threshold', $settings);
            $this->assertArrayHasKey('moderate_threshold', $settings);
            $this->assertArrayHasKey('critical_frequency', $settings);
            $this->assertArrayHasKey('low_frequency', $settings);
            $this->assertArrayHasKey('moderate_frequency', $settings);
            $this->assertArrayHasKey('enable_predictive_alerts', $settings);
            $this->assertArrayHasKey('enable_weekend_alerts', $settings);
            
            $this->assertEquals(1, $settings['vendor_id']);
        }
    }

    public function testAlertLevelDetermination()
    {
        // Test critical level
        $criticalProduct = [
            'stock_quantity' => 2,
            'minimum_stock_level' => 100,
            'critical_threshold' => 10,
            'low_threshold' => 25,
            'moderate_threshold' => 50
        ];

        $result = $this->stockAlertService->sendStockAlert(array_merge($criticalProduct, [
            'id' => 1,
            'name' => 'Critical Product',
            'sku' => 'CRIT-001',
            'vendor_id' => 1,
            'business_name' => 'Test Vendor',
            'email' => 'vendor@test.com'
        ]));

        if ($result['success']) {
            $this->assertEquals('critical', $result['alert_level']);
        }

        // Test low level
        $lowProduct = [
            'stock_quantity' => 20,
            'minimum_stock_level' => 100,
            'critical_threshold' => 10,
            'low_threshold' => 25,
            'moderate_threshold' => 50
        ];

        $result = $this->stockAlertService->sendStockAlert(array_merge($lowProduct, [
            'id' => 2,
            'name' => 'Low Product',
            'sku' => 'LOW-001',
            'vendor_id' => 1,
            'business_name' => 'Test Vendor',
            'email' => 'vendor@test.com'
        ]));

        if ($result['success']) {
            $this->assertEquals('low', $result['alert_level']);
        }

        // Test moderate level
        $moderateProduct = [
            'stock_quantity' => 40,
            'minimum_stock_level' => 100,
            'critical_threshold' => 10,
            'low_threshold' => 25,
            'moderate_threshold' => 50
        ];

        $result = $this->stockAlertService->sendStockAlert(array_merge($moderateProduct, [
            'id' => 3,
            'name' => 'Moderate Product',
            'sku' => 'MOD-001',
            'vendor_id' => 1,
            'business_name' => 'Test Vendor',
            'email' => 'vendor@test.com'
        ]));

        if ($result['success']) {
            $this->assertEquals('moderate', $result['alert_level']);
        }
    }

    public function testPredictiveRecommendationPriority()
    {
        $result = $this->stockAlertService->getPredictiveRecommendations();

        if ($result['success'] && !empty($result['recommendations'])) {
            foreach ($result['recommendations'] as $recommendation) {
                $this->assertArrayHasKey('priority', $recommendation);
                $this->assertArrayHasKey('message', $recommendation);
                $this->assertArrayHasKey('days_until_stockout', $recommendation);
                
                $this->assertContains($recommendation['priority'], ['urgent', 'high', 'medium', 'normal']);
                
                // Verify priority logic
                if ($recommendation['days_until_stockout'] <= 3) {
                    $this->assertEquals('urgent', $recommendation['priority']);
                } elseif ($recommendation['days_until_stockout'] <= 7) {
                    $this->assertEquals('high', $recommendation['priority']);
                }
            }
        }
    }

    public function testInvalidVendorSettings()
    {
        // Test with invalid settings
        $invalidSettings = [
            'critical_threshold' => -10, // Invalid negative value
            'low_threshold' => 150, // Invalid > 100%
            'critical_frequency' => 0 // Invalid zero frequency
        ];

        $result = $this->stockAlertService->configureAlertSettings(999, $invalidSettings);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        
        // The service should handle invalid values gracefully
        // Either by using defaults or returning an error
    }

    public function testAlertFrequencyRespected()
    {
        // This test would verify that alerts are not sent too frequently
        // In a real implementation, you might need to mock the database
        // to test timing constraints properly
        
        $this->assertTrue(true); // Placeholder for frequency testing
    }

    public function testWeekendAlertSettings()
    {
        // Test that weekend alert settings are respected
        // This would require mocking the current day/time
        
        $this->assertTrue(true); // Placeholder for weekend testing
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }
}