<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\App;
use Antinna\Auth\Database\Connection;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\EmailService;
use Exception;
use PDO;

/**
 * Security Monitor for real-time threat detection and alerting
 */
class SecurityMonitor
{
    private PDO $db;
    private App $config;
    private AuditLogger $auditLogger;
    private EmailService $emailService;
    
    // Threat levels
    public const THREAT_LEVEL_LOW = 'low';
    public const THREAT_LEVEL_MEDIUM = 'medium';
    public const THREAT_LEVEL_HIGH = 'high';
    public const THREAT_LEVEL_CRITICAL = 'critical';
    
    // Alert types
    public const ALERT_BRUTE_FORCE = 'brute_force_attack';
    public const ALERT_SUSPICIOUS_LOGIN = 'suspicious_login';
    public const ALERT_ACCOUNT_TAKEOVER = 'account_takeover';
    public const ALERT_RATE_LIMIT_ABUSE = 'rate_limit_abuse';
    public const ALERT_UNUSUAL_ACTIVITY = 'unusual_activity';
    public const ALERT_SYSTEM_ANOMALY = 'system_anomaly';
    public const ALERT_DATA_BREACH = 'data_breach_attempt';
    public const ALERT_PRIVILEGE_ESCALATION = 'privilege_escalation';
    
    // Monitoring thresholds
    private array $thresholds = [
        'failed_login_attempts' => 5,
        'failed_login_window' => 300, // 5 minutes
        'rate_limit_threshold' => 100,
        'rate_limit_window' => 3600, // 1 hour
        'suspicious_ip_threshold' => 10,
        'unusual_location_threshold' => 3,
        'session_anomaly_threshold' => 5
    ];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->config = App::getInstance();
        $this->auditLogger = new AuditLogger();
        $this->emailService = new EmailService();
        
        // Load custom thresholds from config
        $this->loadThresholds();
    }

    /**
     * Monitor authentication events for suspicious patterns
     */
    public function monitorAuthentication(string $eventType, array $eventData): array
    {
        try {
            $alerts = [];
            
            // Monitor for brute force attacks
            if ($eventType === AuditLogger::EVENT_LOGIN_FAILED) {
                $bruteForceAlert = $this->detectBruteForceAttack($eventData);
                if ($bruteForceAlert) {
                    $alerts[] = $bruteForceAlert;
                }
            }
            
            // Monitor for suspicious login patterns
            if ($eventType === AuditLogger::EVENT_LOGIN_SUCCESS) {
                $suspiciousLoginAlert = $this->detectSuspiciousLogin($eventData);
                if ($suspiciousLoginAlert) {
                    $alerts[] = $suspiciousLoginAlert;
                }
            }
            
            // Monitor for account takeover indicators
            $takeoverAlert = $this->detectAccountTakeover($eventType, $eventData);
            if ($takeoverAlert) {
                $alerts[] = $takeoverAlert;
            }
            
            // Process any alerts found
            foreach ($alerts as $alert) {
                $this->processAlert($alert);
            }
            
            return [
                'success' => true,
                'alerts_generated' => count($alerts),
                'alerts' => $alerts
            ];
            
        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'security_monitor_error',
                'Security monitoring error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['event_type' => $eventType, 'error' => $e->getMessage()]
            );
            
            return [
                'success' => false,
                'error' => 'Security monitoring failed',
                'code' => 'MONITOR_ERROR'
            ];
        }
    }

    /**
     * Monitor system events for anomalies
     */
    public function monitorSystemEvents(): array
    {
        try {
            $alerts = [];
            
            // Check for unusual error rates
            $errorRateAlert = $this->detectUnusualErrorRates();
            if ($errorRateAlert) {
                $alerts[] = $errorRateAlert;
            }
            
            // Check for system performance anomalies
            $performanceAlert = $this->detectPerformanceAnomalies();
            if ($performanceAlert) {
                $alerts[] = $performanceAlert;
            }
            
            // Check for unusual traffic patterns
            $trafficAlert = $this->detectUnusualTrafficPatterns();
            if ($trafficAlert) {
                $alerts[] = $trafficAlert;
            }
            
            // Process alerts
            foreach ($alerts as $alert) {
                $this->processAlert($alert);
            }
            
            return [
                'success' => true,
                'alerts_generated' => count($alerts),
                'alerts' => $alerts,
                'monitored_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'system_monitor_error',
                'System monitoring error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            
            return [
                'success' => false,
                'error' => 'System monitoring failed'
            ];
        }
    }

    /**
     * Get real-time security dashboard
     */
    public function getSecurityDashboard(): array
    {
        try {
            $dashboard = [
                'timestamp' => date('Y-m-d H:i:s'),
                'threat_level' => $this->calculateOverallThreatLevel(),
                'active_alerts' => $this->getActiveAlerts(),
                'recent_incidents' => $this->getRecentIncidents(),
                'security_metrics' => $this->getSecurityMetrics(),
                'system_health' => $this->getSystemHealthStatus(),
                'threat_intelligence' => $this->getThreatIntelligence()
            ];
            
            return [
                'success' => true,
                'dashboard' => $dashboard
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to generate security dashboard'
            ];
        }
    }

    /**
     * Analyze IP address for threats
     */
    public function analyzeIPAddress(string $ipAddress): array
    {
        try {
            $analysis = [
                'ip_address' => $ipAddress,
                'threat_score' => 0,
                'threat_level' => self::THREAT_LEVEL_LOW,
                'indicators' => [],
                'recommendations' => []
            ];
            
            // Check failed login attempts from this IP
            $failedLogins = $this->getFailedLoginsByIP($ipAddress, 3600); // Last hour
            if ($failedLogins > $this->thresholds['failed_login_attempts']) {
                $analysis['threat_score'] += 30;
                $analysis['indicators'][] = "High failed login attempts: $failedLogins";
            }
            
            // Check for rate limiting violations
            $rateLimitViolations = $this->getRateLimitViolationsByIP($ipAddress, 3600);
            if ($rateLimitViolations > 0) {
                $analysis['threat_score'] += 20;
                $analysis['indicators'][] = "Rate limit violations: $rateLimitViolations";
            }
            
            // Check for suspicious patterns
            $suspiciousPatterns = $this->getSuspiciousPatternsByIP($ipAddress);
            if (!empty($suspiciousPatterns)) {
                $analysis['threat_score'] += 25;
                $analysis['indicators'][] = "Suspicious patterns detected";
            }
            
            // Check geolocation anomalies
            $locationAnomalies = $this->getLocationAnomaliesByIP($ipAddress);
            if ($locationAnomalies > 0) {
                $analysis['threat_score'] += 15;
                $analysis['indicators'][] = "Unusual geographic locations";
            }
            
            // Determine threat level
            $analysis['threat_level'] = $this->calculateThreatLevel($analysis['threat_score']);
            
            // Generate recommendations
            $analysis['recommendations'] = $this->generateIPRecommendations($analysis);
            
            return [
                'success' => true,
                'analysis' => $analysis
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'IP analysis failed'
            ];
        }
    }

    /**
     * Monitor user behavior for anomalies
     */
    public function monitorUserBehavior(int $userId): array
    {
        try {
            $analysis = [
                'user_id' => $userId,
                'risk_score' => 0,
                'anomalies' => [],
                'behavioral_patterns' => [],
                'recommendations' => []
            ];
            
            // Analyze login patterns
            $loginPatterns = $this->analyzeLoginPatterns($userId);
            if ($loginPatterns['anomaly_score'] > 50) {
                $analysis['risk_score'] += $loginPatterns['anomaly_score'];
                $analysis['anomalies'][] = 'Unusual login patterns detected';
            }
            
            // Analyze session behavior
            $sessionBehavior = $this->analyzeSessionBehavior($userId);
            if ($sessionBehavior['anomaly_score'] > 30) {
                $analysis['risk_score'] += $sessionBehavior['anomaly_score'];
                $analysis['anomalies'][] = 'Unusual session behavior';
            }
            
            // Analyze device usage
            $deviceUsage = $this->analyzeDeviceUsage($userId);
            if ($deviceUsage['new_devices'] > 2) {
                $analysis['risk_score'] += 20;
                $analysis['anomalies'][] = 'Multiple new devices detected';
            }
            
            // Generate behavioral profile
            $analysis['behavioral_patterns'] = $this->generateBehavioralProfile($userId);
            
            // Generate recommendations
            $analysis['recommendations'] = $this->generateUserRecommendations($analysis);
            
            return [
                'success' => true,
                'analysis' => $analysis
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'User behavior analysis failed'
            ];
        }
    }

    /**
     * Generate security alerts based on patterns
     */
    public function generateAlert(string $alertType, array $alertData): array
    {
        try {
            $alert = [
                'id' => uniqid('alert_', true),
                'type' => $alertType,
                'severity' => $this->getAlertSeverity($alertType),
                'threat_level' => $this->getAlertThreatLevel($alertType),
                'title' => $this->getAlertTitle($alertType),
                'description' => $this->getAlertDescription($alertType, $alertData),
                'data' => $alertData,
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 'active',
                'recommendations' => $this->getAlertRecommendations($alertType, $alertData)
            ];
            
            // Store alert
            $this->storeAlert($alert);
            
            // Send notifications if critical
            if ($alert['severity'] === AuditLogger::SEVERITY_CRITICAL) {
                $this->sendCriticalAlert($alert);
            }
            
            // Log the alert
            $this->auditLogger->logSecurityIncident(
                $alertType,
                $alert['description'],
                $alertData['user_id'] ?? null,
                $alert['severity'],
                $alertData
            );
            
            return [
                'success' => true,
                'alert' => $alert
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to generate alert'
            ];
        }
    }

    /**
     * Detect brute force attacks
     */
    private function detectBruteForceAttack(array $eventData): ?array
    {
        $ipAddress = $eventData['ip_address'] ?? 'unknown';
        $userId = $eventData['user_id'] ?? null;
        
        // Count failed attempts from this IP in the last window
        $failedAttempts = $this->getFailedLoginsByIP($ipAddress, $this->thresholds['failed_login_window']);
        
        if ($failedAttempts >= $this->thresholds['failed_login_attempts']) {
            return [
                'type' => self::ALERT_BRUTE_FORCE,
                'severity' => AuditLogger::SEVERITY_CRITICAL,
                'data' => [
                    'ip_address' => $ipAddress,
                    'user_id' => $userId,
                    'failed_attempts' => $failedAttempts,
                    'time_window' => $this->thresholds['failed_login_window'],
                    'detection_time' => microtime(true)
                ]
            ];
        }
        
        return null;
    }

    /**
     * Detect suspicious login patterns
     */
    private function detectSuspiciousLogin(array $eventData): ?array
    {
        $userId = $eventData['user_id'] ?? null;
        $ipAddress = $eventData['ip_address'] ?? 'unknown';
        
        if (!$userId) {
            return null;
        }
        
        // Check for unusual login times
        $unusualTime = $this->isUnusualLoginTime($userId);
        
        // Check for new location
        $newLocation = $this->isNewLocation($userId, $ipAddress);
        
        // Check for rapid location changes
        $rapidLocationChange = $this->hasRapidLocationChange($userId, $ipAddress);
        
        if ($unusualTime || $newLocation || $rapidLocationChange) {
            return [
                'type' => self::ALERT_SUSPICIOUS_LOGIN,
                'severity' => AuditLogger::SEVERITY_WARNING,
                'data' => [
                    'user_id' => $userId,
                    'ip_address' => $ipAddress,
                    'unusual_time' => $unusualTime,
                    'new_location' => $newLocation,
                    'rapid_location_change' => $rapidLocationChange,
                    'detection_time' => microtime(true)
                ]
            ];
        }
        
        return null;
    }

    /**
     * Detect account takeover indicators
     */
    private function detectAccountTakeover(string $eventType, array $eventData): ?array
    {
        $userId = $eventData['user_id'] ?? null;
        
        if (!$userId) {
            return null;
        }
        
        $indicators = [];
        
        // Check for password changes after suspicious activity
        if ($eventType === AuditLogger::EVENT_PASSWORD_CHANGE) {
            $recentSuspiciousActivity = $this->hasRecentSuspiciousActivity($userId, 3600);
            if ($recentSuspiciousActivity) {
                $indicators[] = 'Password changed after suspicious activity';
            }
        }
        
        // Check for new device registrations
        if ($eventType === AuditLogger::EVENT_PASSKEY_REGISTERED) {
            $recentFailedLogins = $this->getRecentFailedLogins($userId, 1800); // 30 minutes
            if ($recentFailedLogins > 3) {
                $indicators[] = 'New passkey registered after failed login attempts';
            }
        }
        
        // Check for unusual account activity
        $unusualActivity = $this->detectUnusualAccountActivity($userId);
        if (!empty($unusualActivity)) {
            $indicators = array_merge($indicators, $unusualActivity);
        }
        
        if (!empty($indicators)) {
            return [
                'type' => self::ALERT_ACCOUNT_TAKEOVER,
                'severity' => AuditLogger::SEVERITY_CRITICAL,
                'data' => [
                    'user_id' => $userId,
                    'event_type' => $eventType,
                    'indicators' => $indicators,
                    'detection_time' => microtime(true)
                ]
            ];
        }
        
        return null;
    }

    /**
     * Process security alert
     */
    private function processAlert(array $alert): void
    {
        // Store the alert
        $this->storeAlert($alert);
        
        // Send notifications based on severity
        if ($alert['severity'] === AuditLogger::SEVERITY_CRITICAL) {
            $this->sendCriticalAlert($alert);
        } elseif ($alert['severity'] === AuditLogger::SEVERITY_ERROR) {
            $this->sendHighPriorityAlert($alert);
        }
        
        // Take automatic actions if configured
        $this->takeAutomaticActions($alert);
    }

    /**
     * Store alert in database
     */
    private function storeAlert(array $alert): bool
    {
        try {
            $sql = "
                INSERT INTO security_alerts (
                    alert_id, alert_type, severity, threat_level, title, description,
                    alert_data, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                $alert['id'],
                $alert['type'],
                $alert['severity'],
                $alert['threat_level'],
                $alert['title'],
                $alert['description'],
                json_encode($alert['data']),
                $alert['status']
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to store security alert: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send critical alert notifications
     */
    private function sendCriticalAlert(array $alert): void
    {
        try {
            $adminEmails = $this->config->get('security.admin_emails', []);
            
            foreach ($adminEmails as $email) {
                $this->emailService->sendSecurityAlertEmail(
                    $email,
                    $alert['type'],
                    [
                        'alert_title' => $alert['title'],
                        'alert_description' => $alert['description'],
                        'severity' => $alert['severity'],
                        'threat_level' => $alert['threat_level'],
                        'alert_data' => $alert['data']
                    ]
                );
            }
            
        } catch (Exception $e) {
            error_log("Failed to send critical alert: " . $e->getMessage());
        }
    }

    /**
     * Take automatic security actions
     */
    private function takeAutomaticActions(array $alert): void
    {
        try {
            switch ($alert['type']) {
                case self::ALERT_BRUTE_FORCE:
                    $this->handleBruteForceAction($alert);
                    break;
                case self::ALERT_ACCOUNT_TAKEOVER:
                    $this->handleAccountTakeoverAction($alert);
                    break;
                case self::ALERT_RATE_LIMIT_ABUSE:
                    $this->handleRateLimitAction($alert);
                    break;
            }
            
        } catch (Exception $e) {
            error_log("Failed to take automatic action: " . $e->getMessage());
        }
    }

    // Helper methods for threat detection
    private function getFailedLoginsByIP(string $ipAddress, int $timeWindow): int
    {
        try {
            $sql = "
                SELECT COUNT(*) 
                FROM audit_logs 
                WHERE ip_address = ? 
                AND event_type = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$ipAddress, AuditLogger::EVENT_LOGIN_FAILED, $timeWindow]);
            
            return (int)$stmt->fetchColumn();
            
        } catch (Exception $e) {
            return 0;
        }
    }

    private function calculateThreatLevel(int $threatScore): string
    {
        if ($threatScore >= 80) {
            return self::THREAT_LEVEL_CRITICAL;
        } elseif ($threatScore >= 60) {
            return self::THREAT_LEVEL_HIGH;
        } elseif ($threatScore >= 30) {
            return self::THREAT_LEVEL_MEDIUM;
        } else {
            return self::THREAT_LEVEL_LOW;
        }
    }

    private function loadThresholds(): void
    {
        $configThresholds = $this->config->get('security.monitoring_thresholds', []);
        $this->thresholds = array_merge($this->thresholds, $configThresholds);
    }

    // Placeholder methods for complex analysis (would be implemented based on specific requirements)
    private function detectUnusualErrorRates(): ?array { return null; }
    private function detectPerformanceAnomalies(): ?array { return null; }
    private function detectUnusualTrafficPatterns(): ?array { return null; }
    private function calculateOverallThreatLevel(): string { return self::THREAT_LEVEL_LOW; }
    private function getActiveAlerts(): array { return []; }
    private function getRecentIncidents(): array { return []; }
    private function getSecurityMetrics(): array { return []; }
    private function getSystemHealthStatus(): array { return []; }
    private function getThreatIntelligence(): array { return []; }
    private function getRateLimitViolationsByIP(string $ip, int $window): int { return 0; }
    private function getSuspiciousPatternsByIP(string $ip): array { return []; }
    private function getLocationAnomaliesByIP(string $ip): int { return 0; }
    private function generateIPRecommendations(array $analysis): array { return []; }
    private function analyzeLoginPatterns(int $userId): array { return ['anomaly_score' => 0]; }
    private function analyzeSessionBehavior(int $userId): array { return ['anomaly_score' => 0]; }
    private function analyzeDeviceUsage(int $userId): array { return ['new_devices' => 0]; }
    private function generateBehavioralProfile(int $userId): array { return []; }
    private function generateUserRecommendations(array $analysis): array { return []; }
    private function getAlertSeverity(string $type): string { return AuditLogger::SEVERITY_WARNING; }
    private function getAlertThreatLevel(string $type): string { return self::THREAT_LEVEL_MEDIUM; }
    private function getAlertTitle(string $type): string { return ucwords(str_replace('_', ' ', $type)); }
    private function getAlertDescription(string $type, array $data): string { return "Security alert: $type"; }
    private function getAlertRecommendations(string $type, array $data): array { return []; }
    private function sendHighPriorityAlert(array $alert): void { }
    private function handleBruteForceAction(array $alert): void { }
    private function handleAccountTakeoverAction(array $alert): void { }
    private function handleRateLimitAction(array $alert): void { }
    private function isUnusualLoginTime(int $userId): bool { return false; }
    private function isNewLocation(int $userId, string $ip): bool { return false; }
    private function hasRapidLocationChange(int $userId, string $ip): bool { return false; }
    private function hasRecentSuspiciousActivity(int $userId, int $window): bool { return false; }
    private function getRecentFailedLogins(int $userId, int $window): int { return 0; }
    private function detectUnusualAccountActivity(int $userId): array { return []; }
}