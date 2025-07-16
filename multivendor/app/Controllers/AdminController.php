<?php

namespace Antinna\Multivendor\Controllers;

use Antinna\Multivendor\Services\AdminAuthenticator;
use Antinna\Multivendor\Services\Logger;
use Antinna\Multivendor\Helpers\ErrorHandlingHelper;
use Antinna\Multivendor\Exceptions\ValidationException;
use Antinna\Multivendor\Exceptions\UnauthorizedException;

class AdminController
{
    private AdminAuthenticator $authenticator;
    private Logger $logger;

    public function __construct()
    {
        $this->authenticator = new AdminAuthenticator();
        $this->logger = ErrorHandlingHelper::getLogger();
    }

    /**
     * Handle admin login
     */
    public function login(): void
    {
        ErrorHandlingHelper::handleApiResponse(function() {
            $input = $this->getJsonInput();
            
            // Validate input
            $this->validateLoginInput($input);
            
            $username = $input['username'];
            $password = $input['password'];
            
            // Authenticate
            $result = $this->authenticator->authenticate($username, $password);
            
            return $result;
        });
    }

    /**
     * Handle admin logout
     */
    public function logout(): void
    {
        ErrorHandlingHelper::handleApiResponse(function() {
            $result = $this->authenticator->logout();
            return $result;
        });
    }

    /**
     * Get current session info
     */
    public function sessionInfo(): void
    {
        ErrorHandlingHelper::handleApiResponse(function() {
            $sessionInfo = $this->authenticator->getSessionInfo();
            
            if ($sessionInfo === null) {
                return [
                    'authenticated' => false,
                    'message' => 'No active session'
                ];
            }
            
            return [
                'authenticated' => true,
                'session' => $sessionInfo
            ];
        });
    }

    /**
     * Extend current session
     */
    public function extendSession(): void
    {
        ErrorHandlingHelper::handleApiResponse(function() {
            $this->authenticator->requireAuthentication();
            $result = $this->authenticator->extendSession();
            return $result;
        });
    }

    /**
     * Get admin credentials info (for setup/debugging)
     */
    public function credentialsInfo(): void
    {
        ErrorHandlingHelper::handleApiResponse(function() {
            $info = $this->authenticator->getCredentialsInfo();
            return $info;
        });
    }

    /**
     * Admin dashboard endpoint
     */
    public function dashboard(): void
    {
        ErrorHandlingHelper::handleApiResponse(function() {
            $this->authenticator->requireAuthentication();
            
            $sessionInfo = $this->authenticator->getSessionInfo();
            
            return [
                'message' => 'Welcome to Admin Dashboard',
                'session' => $sessionInfo,
                'features' => [
                    'migration_management' => 'Available',
                    'service_monitoring' => 'Available',
                    'system_health' => 'Available'
                ]
            ];
        });
    }

    /**
     * Validate admin authentication middleware
     */
    public function validateAuth(): void
    {
        ErrorHandlingHelper::handleApiResponse(function() {
            $this->authenticator->requireAuthentication();
            
            return [
                'authenticated' => true,
                'message' => 'Authentication valid'
            ];
        });
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        
        if (empty($input)) {
            throw new ValidationException(['request' => 'Request body is required']);
        }
        
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidationException(['request' => 'Invalid JSON format']);
        }
        
        return $data;
    }

    /**
     * Validate login input
     */
    private function validateLoginInput(array $input): void
    {
        $errors = [];
        
        if (empty($input['username'])) {
            $errors['username'] = 'Username is required';
        }
        
        if (empty($input['password'])) {
            $errors['password'] = 'Password is required';
        }
        
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Start migration orchestration
     */
    public function startMigration(): void
    {
        ErrorHandlingHelper::handleApiResponse(function() {
            $this->authenticator->requireAuthentication();
            
            $input = $this->getJsonInput();
            $options = $input['options'] ?? [];
            
            $orchestrator = new \Antinna\Multivendor\Services\MigrationOrchestrator($this->logger);
            $result = $orchestrator->startMigration($options);
            
            return $result;
        });
    }

    /**
     * Get migration progress
     */
    public function getMigrationProgress(string $migrationId): void
    {
        ErrorHandlingHelper::handleApiResponse(function() use ($migrationId) {
            $this->authenticator->requireAuthentication();
            
            $orchestrator = new \Antinna\Multivendor\Services\MigrationOrchestrator($this->logger);
            $progress = $orchestrator->getProgress($migrationId);
            
            if ($progress === null) {
                http_response_code(404);
                return [
                    'error' => 'Migration not found',
                    'migration_id' => $migrationId
                ];
            }
            
            return $progress;
        });
    }

    /**
     * Get migration history
     */
    public function getMigrationHistory(): void
    {
        ErrorHandlingHelper::handleApiResponse(function() {
            $this->authenticator->requireAuthentication();
            
            $orchestrator = new \Antinna\Multivendor\Services\MigrationOrchestrator($this->logger);
            $history = $orchestrator->getHistory();
            
            return [
                'migrations' => $history,
                'total_count' => count($history)
            ];
        });
    }

    /**
     * Cancel migration
     */
    public function cancelMigration(string $migrationId): void
    {
        ErrorHandlingHelper::handleApiResponse(function() use ($migrationId) {
            $this->authenticator->requireAuthentication();
            
            $orchestrator = new \Antinna\Multivendor\Services\MigrationOrchestrator($this->logger);
            $result = $orchestrator->cancelMigration($migrationId);
            
            return $result;
        });
    }

    /**
     * Check service health
     */
    public function checkServiceHealth(): void
    {
        ErrorHandlingHelper::handleApiResponse(function() {
            $this->authenticator->requireAuthentication();
            
            $orchestrator = new \Antinna\Multivendor\Services\MigrationOrchestrator($this->logger);
            $health = $orchestrator->checkAllServicesHealth();
            
            $healthyCount = count(array_filter($health, fn($s) => $s['status'] === 'healthy'));
            
            return [
                'services' => $health,
                'summary' => [
                    'total_services' => count($health),
                    'healthy_services' => $healthyCount,
                    'unhealthy_services' => count($health) - $healthyCount,
                    'overall_status' => $healthyCount === count($health) ? 'healthy' : 'degraded'
                ]
            ];
        });
    }

    /**
     * WebSocket stream for migration progress
     */
    public function migrationProgressStream(string $migrationId): void
    {
        $this->authenticator->requireAuthentication();
        
        $webSocketHandler = new \Antinna\Multivendor\Services\WebSocketHandler($this->logger);
        $webSocketHandler->handleProgressConnection($migrationId);
    }

    /**
     * WebSocket stream for health monitoring
     */
    public function healthMonitoringStream(): void
    {
        $this->authenticator->requireAuthentication();
        
        $webSocketHandler = new \Antinna\Multivendor\Services\WebSocketHandler($this->logger);
        $webSocketHandler->handleHealthMonitoring();
    }

    /**
     * Handle admin panel HTML (basic implementation)
     */
    public function panel(): void
    {
        // Set HTML content type
        header('Content-Type: text/html');
        
        $sessionInfo = $this->authenticator->getSessionInfo();
        $isAuthenticated = $sessionInfo !== null;
        
        echo $this->renderAdminPanel($isAuthenticated, $sessionInfo);
    }

    /**
     * Render basic admin panel HTML
     */
    private function renderAdminPanel(bool $isAuthenticated, ?array $sessionInfo): string
    {
        if (!$isAuthenticated) {
            return $this->renderLoginPage();
        }
        
        return $this->renderDashboardPage($sessionInfo);
    }

    /**
     * Render login page
     */
    private function renderLoginPage(): string
    {
        $credentialsInfo = $this->authenticator->getCredentialsInfo();
        
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multivendor Admin Panel - Login</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-container { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .login-header { text-align: center; margin-bottom: 30px; }
        .login-header h1 { color: #333; margin-bottom: 8px; font-size: 28px; }
        .login-header p { color: #666; font-size: 14px; }
        .credentials-info { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 8px; margin-bottom: 25px; font-size: 13px; }
        .credentials-info h3 { color: #856404; margin-bottom: 10px; font-size: 14px; }
        .credentials-info p { margin: 5px 0; color: #856404; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input { width: 100%; padding: 12px 16px; border: 2px solid #e1e5e9; border-radius: 8px; font-size: 16px; transition: border-color 0.3s; }
        .form-group input:focus { outline: none; border-color: #667eea; }
        .btn { width: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 14px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.2s; }
        .btn:hover { transform: translateY(-2px); }
        .btn:active { transform: translateY(0); }
        .error { color: #dc3545; margin-top: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; font-size: 14px; }
        .loading { display: none; }
        .loading.show { display: inline-block; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #ffffff; border-radius: 50%; border-top-color: transparent; animation: spin 1s ease-in-out infinite; margin-right: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1><i class="fas fa-cogs"></i> Admin Panel</h1>
            <p>Multivendor Migration Management</p>
        </div>
        
        <div class="credentials-info">
            <h3><i class="fas fa-info-circle"></i> Default Credentials</h3>
            <p><strong>Username:</strong> ' . htmlspecialchars($credentialsInfo['default_credentials']['username']) . '</p>
            <p><strong>Password:</strong> ' . htmlspecialchars($credentialsInfo['default_credentials']['password']) . '</p>
            <p><small>Source: ' . htmlspecialchars($credentialsInfo['username_source']) . '</small></p>
        </div>
        
        <form id="loginForm">
            <div class="form-group">
                <label for="username"><i class="fas fa-user"></i> Username</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn" id="loginBtn">
                <span class="loading"><span class="spinner"></span>Signing in...</span>
                <span class="normal">Sign In</span>
            </button>
        </form>
        
        <div id="error" class="error" style="display: none;"></div>
    </div>
    
    <script>
        document.getElementById("loginForm").addEventListener("submit", async function(e) {
            e.preventDefault();
            await login();
        });
        
        async function login() {
            const username = document.getElementById("username").value;
            const password = document.getElementById("password").value;
            const errorDiv = document.getElementById("error");
            const loginBtn = document.getElementById("loginBtn");
            
            // Show loading state
            loginBtn.querySelector(".loading").classList.add("show");
            loginBtn.querySelector(".normal").style.display = "none";
            loginBtn.disabled = true;
            errorDiv.style.display = "none";
            
            try {
                const response = await fetch("/admin/login", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ username, password })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.location.href = "/admin";
                } else {
                    errorDiv.textContent = result.message || "Login failed";
                    errorDiv.style.display = "block";
                }
            } catch (error) {
                errorDiv.textContent = "Network error: " + error.message;
                errorDiv.style.display = "block";
            } finally {
                // Hide loading state
                loginBtn.querySelector(".loading").classList.remove("show");
                loginBtn.querySelector(".normal").style.display = "inline";
                loginBtn.disabled = false;
            }
        }
    </script>
</body>
</html>';
    }

    /**
     * Render dashboard page
     */
    private function renderDashboardPage(array $sessionInfo): string
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multivendor Admin Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8f9fa; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px 0; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header-content { max-width: 1200px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 24px; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-info span { font-size: 14px; opacity: 0.9; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-primary:hover { background: #0056b3; }
        .btn-secondary { background: rgba(255,255,255,0.2); color: white; }
        .btn-secondary:hover { background: rgba(255,255,255,0.3); }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #1e7e34; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border: 1px solid #e9ecef; }
        .card h3 { margin-bottom: 15px; color: #333; display: flex; align-items: center; gap: 10px; }
        .card h3 i { color: #667eea; }
        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px; }
        .status-item { padding: 15px; border-radius: 8px; text-align: center; }
        .status-healthy { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-unhealthy { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .status-unknown { background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db; }
        .migration-controls { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px; }
        .progress-container { margin-top: 20px; }
        .progress-bar { width: 100%; height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #667eea, #764ba2); transition: width 0.3s ease; }
        .migration-history { max-height: 300px; overflow-y: auto; }
        .history-item { padding: 10px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; }
        .history-item:last-child { border-bottom: none; }
        .status-badge { padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .status-running { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .status-cancelled { background: #e2e3e5; color: #383d41; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 5% auto; padding: 30px; width: 90%; max-width: 600px; border-radius: 12px; position: relative; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #666; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 2px solid #e1e5e9; border-radius: 6px; font-size: 14px; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #667eea; }
        .checkbox-group { display: flex; align-items: center; gap: 8px; }
        .checkbox-group input[type="checkbox"] { width: auto; }
        .loading { display: none; text-align: center; padding: 20px; }
        .loading.show { display: block; }
        .spinner { display: inline-block; width: 20px; height: 20px; border: 3px solid #f3f3f3; border-radius: 50%; border-top-color: #667eea; animation: spin 1s ease-in-out infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        @media (max-width: 768px) {
            .header-content { flex-direction: column; gap: 15px; text-align: center; }
            .user-info { flex-direction: column; gap: 10px; }
            .migration-controls { justify-content: center; }
            .dashboard-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1><i class="fas fa-cogs"></i> Multivendor Admin Dashboard</h1>
            <div class="user-info">
                <span><i class="fas fa-user"></i> ' . htmlspecialchars($sessionInfo['username']) . '</span>
                <span><i class="fas fa-clock"></i> ' . gmdate('H:i:s', $sessionInfo['time_remaining']) . ' remaining</span>
                <button class="btn btn-secondary" onclick="extendSession()">
                    <i class="fas fa-clock"></i> Extend
                </button>
                <button class="btn btn-secondary" onclick="logout()">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>
    </div>

    <div class="container">
        <div id="alerts"></div>
        
        <div class="dashboard-grid">
            <!-- Service Health Card -->
            <div class="card">
                <h3><i class="fas fa-heartbeat"></i> Service Health</h3>
                <div id="serviceHealth">
                    <div class="loading show">
                        <div class="spinner"></div>
                        <p>Loading service health...</p>
                    </div>
                </div>
                <button class="btn btn-primary" onclick="refreshHealth()" style="margin-top: 15px;">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>

            <!-- Migration Management Card -->
            <div class="card">
                <h3><i class="fas fa-database"></i> Migration Management</h3>
                <p>Coordinate database migrations across all microservices</p>
                <div class="migration-controls">
                    <button class="btn btn-success" onclick="showMigrationModal()">
                        <i class="fas fa-play"></i> Start Migration
                    </button>
                    <button class="btn btn-primary" onclick="showSetupModal()">
                        <i class="fas fa-magic"></i> One-Click Setup
                    </button>
                    <button class="btn btn-warning" onclick="refreshHistory()">
                        <i class="fas fa-history"></i> Refresh History
                    </button>
                </div>
            </div>

            <!-- System Information Card -->
            <div class="card">
                <h3><i class="fas fa-info-circle"></i> System Information</h3>
                <div id="systemInfo">
                    <div class="loading show">
                        <div class="spinner"></div>
                        <p>Loading system information...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Migration History -->
        <div class="card">
            <h3><i class="fas fa-history"></i> Migration History</h3>
            <div id="migrationHistory">
                <div class="loading show">
                    <div class="spinner"></div>
                    <p>Loading migration history...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Migration Modal -->
    <div id="migrationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-database"></i> Start Migration</h3>
                <button class="modal-close" onclick="closeMigrationModal()">&times;</button>
            </div>
            <form id="migrationForm">
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="parallel" name="parallel">
                        <label for="parallel">Run migrations in parallel</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="continueOnError" name="continueOnError">
                        <label for="continueOnError">Continue on error</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="migrationNotes">Notes (optional)</label>
                    <input type="text" id="migrationNotes" name="notes" placeholder="Migration description or notes">
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeMigrationModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-play"></i> Start Migration
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Setup Modal -->
    <div id="setupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-magic"></i> One-Click System Setup</h3>
                <button class="modal-close" onclick="closeSetupModal()">&times;</button>
            </div>
            <div class="alert alert-warning">
                <strong>Warning:</strong> This will initialize all database schemas across all microservices. Only use this for initial system setup.
            </div>
            <p>This will perform the following actions:</p>
            <ul style="margin: 15px 0; padding-left: 20px;">
                <li>Initialize database schemas for all services</li>
                <li>Create default admin user</li>
                <li>Set up initial configuration</li>
                <li>Verify service connectivity</li>
            </ul>
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="closeSetupModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="performSetup()">
                    <i class="fas fa-magic"></i> Initialize System
                </button>
            </div>
        </div>
    </div>

    <script>
        let healthMonitoringActive = false;
        let migrationProgressActive = false;
        
        // Initialize dashboard
        document.addEventListener("DOMContentLoaded", function() {
            refreshHealth();
            refreshHistory();
            loadSystemInfo();
            startHealthMonitoring();
        });

        // Authentication functions
        async function logout() {
            try {
                await fetch("/admin/logout", { method: "POST" });
                window.location.href = "/admin";
            } catch (error) {
                showAlert("Logout failed: " + error.message, "danger");
            }
        }

        async function extendSession() {
            try {
                const response = await fetch("/admin/extend-session", { method: "POST" });
                const result = await response.json();
                
                if (result.success) {
                    showAlert("Session extended successfully", "success");
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert("Failed to extend session", "danger");
                }
            } catch (error) {
                showAlert("Network error: " + error.message, "danger");
            }
        }

        // Health monitoring functions
        async function refreshHealth() {
            const healthDiv = document.getElementById("serviceHealth");
            healthDiv.innerHTML = `<div class="loading show"><div class="spinner"></div><p>Checking service health...</p></div>`;
            
            try {
                const response = await fetch("/admin/health");
                const result = await response.json();
                
                if (result.services) {
                    displayHealthStatus(result);
                } else {
                    healthDiv.innerHTML = `<div class="alert alert-danger">Failed to load health status</div>`;
                }
            } catch (error) {
                healthDiv.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
            }
        }

        function displayHealthStatus(healthData) {
            const healthDiv = document.getElementById("serviceHealth");
            const services = healthData.services;
            const summary = healthData.summary;
            
            let html = "<div style=\"margin-bottom: 15px;\">" +
                "<strong>Overall Status:</strong> " +
                "<span class=\"status-badge " + (summary.overall_status === "healthy" ? "status-completed" : "status-failed") + "\">" +
                summary.overall_status.toUpperCase() +
                "</span>" +
                "<br><small>" + summary.healthy_services + "/" + summary.total_services + " services healthy</small>" +
                "</div>" +
                "<div class=\"status-grid\">";
            
            for (const [serviceKey, service] of Object.entries(services)) {
                const statusClass = service.status === "healthy" ? "status-healthy" : "status-unhealthy";
                html += "<div class=\"status-item " + statusClass + "\">" +
                    "<strong>" + service.name + "</strong><br>" +
                    "<small>" + service.status + "</small>" +
                    (service.response_time ? "<br><small>" + service.response_time + "ms</small>" : "") +
                    "</div>";
            }
            
            html += "</div>";
            healthDiv.innerHTML = html;
        }

        function startHealthMonitoring() {
            if (healthMonitoringActive) return;
            
            healthMonitoringActive = true;
            const eventSource = new EventSource("/admin/health/stream");
            
            eventSource.addEventListener("health_update", function(event) {
                const data = JSON.parse(event.data);
                displayHealthStatus(data);
            });
            
            eventSource.onerror = function() {
                healthMonitoringActive = false;
                console.log("Health monitoring connection lost, will retry...");
                setTimeout(startHealthMonitoring, 5000);
            };
        }

        // Migration functions
        function showMigrationModal() {
            document.getElementById("migrationModal").style.display = "block";
        }

        function closeMigrationModal() {
            document.getElementById("migrationModal").style.display = "none";
        }

        function showSetupModal() {
            document.getElementById("setupModal").style.display = "block";
        }

        function closeSetupModal() {
            document.getElementById("setupModal").style.display = "none";
        }

        document.getElementById("migrationForm").addEventListener("submit", async function(e) {
            e.preventDefault();
            await startMigration();
        });

        async function startMigration() {
            const form = document.getElementById("migrationForm");
            const formData = new FormData(form);
            
            const options = {
                parallel: formData.get("parallel") === "on",
                continue_on_error: formData.get("continueOnError") === "on",
                notes: formData.get("notes") || ""
            };
            
            try {
                const response = await fetch("/admin/migration/start", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ options })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert(`Migration started successfully! ID: ${result.migration_id}`, "success");
                    closeMigrationModal();
                    refreshHistory();
                    startMigrationProgress(result.migration_id);
                } else {
                    showAlert("Failed to start migration: " + (result.error || "Unknown error"), "danger");
                }
            } catch (error) {
                showAlert("Network error: " + error.message, "danger");
            }
        }

        async function performSetup() {
            const setupOptions = {
                parallel: false,
                continue_on_error: false,
                setup_mode: true,
                notes: "Initial system setup"
            };
            
            try {
                showAlert("Starting system setup...", "info");
                closeSetupModal();
                
                const response = await fetch("/admin/migration/start", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ options: setupOptions })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert(`System setup started! Migration ID: ${result.migration_id}`, "success");
                    refreshHistory();
                    startMigrationProgress(result.migration_id);
                } else {
                    showAlert("Setup failed: " + (result.error || "Unknown error"), "danger");
                }
            } catch (error) {
                showAlert("Setup error: " + error.message, "danger");
            }
        }

        function startMigrationProgress(migrationId) {
            if (migrationProgressActive) return;
            
            migrationProgressActive = true;
            const eventSource = new EventSource(`/admin/migration/progress/${migrationId}/stream`);
            
            eventSource.addEventListener("progress", function(event) {
                const data = JSON.parse(event.data);
                updateMigrationProgress(data);
            });
            
            eventSource.addEventListener("finished", function(event) {
                const data = JSON.parse(event.data);
                migrationProgressActive = false;
                eventSource.close();
                showAlert(`Migration ${data.status}: ${data.message}`, data.status === "completed" ? "success" : "warning");
                refreshHistory();
            });
            
            eventSource.onerror = function() {
                migrationProgressActive = false;
                console.log("Migration progress connection lost");
            };
        }

        function updateMigrationProgress(progressData) {
            // Update progress display in the migration history
            refreshHistory();
        }

        async function refreshHistory() {
            const historyDiv = document.getElementById("migrationHistory");
            historyDiv.innerHTML = `<div class="loading show"><div class="spinner"></div><p>Loading migration history...</p></div>`;
            
            try {
                const response = await fetch("/admin/migration/history");
                const result = await response.json();
                
                if (result.migrations) {
                    displayMigrationHistory(result.migrations);
                } else {
                    historyDiv.innerHTML = `<div class="alert alert-warning">No migration history found</div>`;
                }
            } catch (error) {
                historyDiv.innerHTML = `<div class="alert alert-danger">Error loading history: ${error.message}</div>`;
            }
        }

        function displayMigrationHistory(migrations) {
            const historyDiv = document.getElementById("migrationHistory");
            
            if (migrations.length === 0) {
                historyDiv.innerHTML = "<div class=\\"alert alert-info\\">No migrations have been run yet</div>";
                return;
            }
            
            let html = "<div class=\\"migration-history\\">";
            
            migrations.slice(0, 10).forEach(function(migration) {
                const statusClass = "status-" + migration.status;
                const date = new Date(migration.started_at * 1000).toLocaleString();
                const notesHtml = migration.options && migration.options.notes ? "<br><small>" + migration.options.notes + "</small>" : "";
                const progressHtml = migration.status === "running" ? "<br><small>" + migration.completed_services + "/" + migration.total_services + " completed</small>" : "";
                
                html += 
                    "<div class=\\"history-item\\">" +
                    "<div>" +
                    "<strong>Migration " + migration.id.substring(0, 8) + "...</strong><br>" +
                    "<small>" + date + "</small>" +
                    notesHtml +
                    "</div>" +
                    "<div>" +
                    "<span class=\\"status-badge " + statusClass + "\\">" + migration.status.toUpperCase() + "</span>" +
                    progressHtml +
                    "</div>" +
                    "</div>";
            });
            
            html += "</div>";
            historyDiv.innerHTML = html;
        }

        async function loadSystemInfo() {
            // This would load system information - for now just show placeholder
            const systemInfoDiv = document.getElementById("systemInfo");
            systemInfoDiv.innerHTML = 
                "<p><strong>PHP Version:</strong> " + (navigator.userAgent.includes(\\"PHP\\") ? \\"PHP 8.1+\\" : \\"Unknown\\") + "</p>" +
                "<p><strong>Platform:</strong> Wasmer.io</p>" +
                "<p><strong>Services:</strong> 5 microservices</p>" +
                "<p><strong>Status:</strong> Operational</p>";
        }

        // Utility functions
        function showAlert(message, type) {
            const alertsDiv = document.getElementById("alerts");
            const alertId = "alert-" + Date.now();
            
            const iconClass = type === "success" ? "check-circle" : 
                             type === "danger" ? "exclamation-circle" : 
                             type === "warning" ? "exclamation-triangle" : "info-circle";
            
            const alertHtml = 
                "<div id=\\"" + alertId + "\\" class=\\"alert alert-" + type + "\\"> " +
                "<i class=\\"fas fa-" + iconClass + "\\"></i> " +
                message +
                "</div>";
            
            alertsDiv.innerHTML = alertHtml + alertsDiv.innerHTML;
            
            // Auto-remove after 5 seconds
            setTimeout(function() {
                const alertElement = document.getElementById(alertId);
                if (alertElement) {
                    alertElement.remove();
                }
            }, 5000);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const migrationModal = document.getElementById("migrationModal");
            const setupModal = document.getElementById("setupModal");
            
            if (event.target === migrationModal) {
                closeMigrationModal();
            }
            if (event.target === setupModal) {
                closeSetupModal();
            }
        }
    </script>
</body>
</html>';
    }
}