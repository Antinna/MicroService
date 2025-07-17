<?php

namespace Antinna\Auth\Routes;

use Antinna\Auth\Controllers\HealthController;

class HealthRoutes
{
    private $healthController;

    public function __construct()
    {
        $this->healthController = new HealthController();
    }

    public function register(): void
    {
        // Basic health check - for load balancers and simple monitoring
        $this->route('GET', '/health', [$this->healthController, 'basic']);
        
        // Detailed health check - for comprehensive monitoring dashboards
        $this->route('GET', '/health/detailed', [$this->healthController, 'detailed']);
        
        // Kubernetes/Docker readiness probe
        $this->route('GET', '/health/ready', [$this->healthController, 'readiness']);
        
        // Kubernetes/Docker liveness probe
        $this->route('GET', '/health/live', [$this->healthController, 'liveness']);
        
        // Metrics endpoint for monitoring systems (Prometheus, etc.)
        $this->route('GET', '/health/metrics', [$this->healthController, 'metrics']);
        
        // Service information endpoint
        $this->route('GET', '/health/info', [$this->healthController, 'info']);

        // Alternative endpoints for different monitoring systems
        $this->route('GET', '/healthz', [$this->healthController, 'basic']); // Common Kubernetes convention
        $this->route('GET', '/status', [$this->healthController, 'basic']); // Alternative status endpoint
        $this->route('GET', '/ping', [$this, 'ping']); // Simple ping endpoint
    }

    /**
     * Simple ping endpoint for basic connectivity tests
     */
    public function ping(): void
    {
        http_response_code(200);
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        
        echo json_encode([
            'message' => 'pong',
            'timestamp' => date('c'),
            'service' => 'auth-service'
        ]);
    }

    private function route(string $method, string $path, callable $handler): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        
        if ($requestMethod === $method && $requestPath === $path) {
            call_user_func($handler);
            exit;
        }
    }
}

// Auto-register health routes if this file is accessed directly
if (basename($_SERVER['SCRIPT_NAME']) === basename(__FILE__) || 
    strpos($_SERVER['REQUEST_URI'], '/health') !== false ||
    strpos($_SERVER['REQUEST_URI'], '/status') !== false ||
    strpos($_SERVER['REQUEST_URI'], '/ping') !== false ||
    strpos($_SERVER['REQUEST_URI'], '/healthz') !== false) {
    
    require_once __DIR__ . '/../bootstrap/app.php';
    
    $healthRoutes = new HealthRoutes();
    $healthRoutes->register();
}