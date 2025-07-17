<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Database\Connection;
use Antinna\Auth\Config\Environment;
use PDO;
use Exception;

/**
 * Comprehensive audit logging service for security events
 */
class AuditLogger
{
    private PDO $db;

    // Event categories
    public const CATEGORY_AUTHENTICATION = 'authentication';
    public const CATEGORY_AUTHORIZATION = 'authorization';
    public const CATEGORY_DATA_ACCESS = 'data_access';
    public const CATEGORY_SYSTEM = 'system';
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_USER_MANAGEMENT = 'user_management';

    // Severity levels
    public const SEVERITY_DEBUG = 'debug';
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_NOTICE = 'notice';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_ALERT = 'alert';
    public const SEVERITY_EMERGENCY = 'emergency';

    // Event types
    public const EVENT_LOGIN_SUCCESS = 'login_success';
    public const EVENT_LOGIN_FAILED = 'login_failed';
    public const EVENT_LOGOUT = 'logout';
    public const EVENT_PASSWORD_CHANGE = 'password_change';
    public const EVENT_ACCOUNT_LOCKED = 'account_locked';
    public const EVENT_ACCOUNT_UNLOCKED = 'account_unlocked';
    public const EVENT_MFA_ENABLED = 'mfa_enabled';
    public const EVENT_MFA_DISABLED = 'mfa_disabled';
    public const EVENT_PASSKEY_REGISTERED = 'passkey_registered';
    public const EVENT_PASSKEY_REMOVED = 'passkey_removed';
    public const EVENT_MAGIC_LINK_GENERATED = 'magic_link_generated';
    public const EVENT_MAGIC_LINK_USED = 'magic_link_used';
    public const EVENT_SUSPICIOUS_ACTIVITY = 'suspicious_activity';
    public const EVENT_RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';
    public const EVENT_UNAUTHORIZED_ACCESS = 'unauthorized_access';
    public const EVENT_DATA_EXPORT = 'data_export';
    public const EVENT_ADMIN_ACTION = 'admin_action';
    public const EVENT_SYSTEM_ERROR = 'system_error';

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
    }

    /**
     * Enhanced security event logging with structured data
     */
    public function log(
        string $eventType,
        string $description,
        ?int $userId = null,
        string $ipAddress = 'unknown',
        string $severity = self::SEVERITY_INFO,
        array $metadata = []
    ): bool {
        try {
            // Enrich metadata with context information
            $enrichedMetadata = $this->enrichMetadata($metadata, $eventType, $userId);

            // Determine event category
            $category = $this->determineEventCategory($eventType);

            // Get real IP address
            $realIpAddress = $this->getRealIpAddress($ipAddress);

            $sql = "
                INSERT INTO audit_logs (
                    user_id, event_type, event_category, event_description, 
                    ip_address, user_agent, metadata, severity, 
                    session_id, request_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                $userId,
                $eventType,
                $category,
                $description,
                $realIpAddress,
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                json_encode($enrichedMetadata),
                $severity,
                session_id() ?: null,
                $this->generateRequestId()
            ]);

        } catch (Exception $e) {
            // Fallback logging to system error log
            $this->fallbackLog($eventType, $description, $userId, $e);
            return false;
        }
    }

    /**
     * Log authentication events with specific context
     */
    public function logAuthentication(
        string $eventType,
        string $description,
        ?int $userId = null,
        string $authMethod = 'unknown',
        bool $success = true,
        array $additionalData = []
    ): bool {
        $severity = $success ? self::SEVERITY_INFO : self::SEVERITY_WARNING;

        $metadata = array_merge($additionalData, [
            'auth_method' => $authMethod,
            'success' => $success,
            'timestamp' => microtime(true),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
        ]);

        return $this->log($eventType, $description, $userId, 'auto', $severity, $metadata);
    }

    /**
     * Log security incidents with high priority
     */
    public function logSecurityIncident(
        string $eventType,
        string $description,
        ?int $userId = null,
        string $severity = self::SEVERITY_CRITICAL,
        array $evidenceData = []
    ): bool {
        $metadata = array_merge($evidenceData, [
            'incident_id' => uniqid('incident_', true),
            'detection_time' => microtime(true),
            'server_info' => [
                'hostname' => gethostname(),
                'server_addr' => $_SERVER['SERVER_ADDR'] ?? 'unknown',
                'server_port' => $_SERVER['SERVER_PORT'] ?? 'unknown'
            ],
            'request_headers' => $this->getSafeHeaders(),
            'threat_level' => $this->calculateThreatLevel($eventType, $severity)
        ]);

        // Also log to system for immediate attention
        error_log("SECURITY INCIDENT: $eventType - $description");

        return $this->log($eventType, $description, $userId, 'auto', $severity, $metadata);
    }

    /**
     * Log data access events for compliance
     */
    public function logDataAccess(
        string $resource,
        string $action,
        ?int $userId = null,
        bool $authorized = true,
        array $resourceData = []
    ): bool {
        $eventType = $authorized ? 'data_access_authorized' : 'data_access_unauthorized';
        $severity = $authorized ? self::SEVERITY_INFO : self::SEVERITY_WARNING;

        $metadata = array_merge($resourceData, [
            'resource' => $resource,
            'action' => $action,
            'authorized' => $authorized,
            'access_time' => microtime(true),
            'referrer' => $_SERVER['HTTP_REFERER'] ?? null
        ]);

        $description = $authorized
            ? "Authorized access to $resource ($action)"
            : "Unauthorized access attempt to $resource ($action)";

        return $this->log($eventType, $description, $userId, 'auto', $severity, $metadata);
    }

    /**
     * Log admin actions for accountability
     */
    public function logAdminAction(
        string $action,
        string $description,
        int $adminUserId,
        ?int $targetUserId = null,
        array $actionData = []
    ): bool {
        $metadata = array_merge($actionData, [
            'admin_user_id' => $adminUserId,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'admin_session' => session_id() ?: null,
            'execution_time' => microtime(true)
        ]);

        return $this->log(
            self::EVENT_ADMIN_ACTION,
            $description,
            $adminUserId,
            'auto',
            self::SEVERITY_NOTICE,
            $metadata
        );
    }

    /**
     * Log system events and errors
     */
    public function logSystemEvent(
        string $eventType,
        string $description,
        string $severity = self::SEVERITY_INFO,
        array $systemData = []
    ): bool {
        $metadata = array_merge($systemData, [
            'system_time' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'process_id' => getmypid(),
            'php_version' => PHP_VERSION
        ]);

        return $this->log($eventType, $description, null, 'system', $severity, $metadata);
    }

    /**
     * Batch log multiple events efficiently
     */
    public function logBatch(array $events): array
    {
        $results = [];
        $this->db->beginTransaction();

        try {
            foreach ($events as $index => $event) {
                $result = $this->log(
                    $event['event_type'],
                    $event['description'],
                    $event['user_id'] ?? null,
                    $event['ip_address'] ?? 'auto',
                    $event['severity'] ?? self::SEVERITY_INFO,
                    $event['metadata'] ?? []
                );
                $results[$index] = $result;
            }

            $this->db->commit();
            return ['success' => true, 'results' => $results];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get audit logs for user (deprecated - use getUserLogs with pagination)
     */
    public function getUserLogsLegacy(int $userId, int $limit = 50, int $offset = 0): array
    {
        try {
            $sql = "
                SELECT * FROM audit_logs 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get recent security events
     */
    public function getRecentSecurityEvents(int $limit = 100): array
    {
        try {
            $sql = "
                SELECT * FROM audit_logs 
                WHERE severity IN ('warning', 'error', 'critical')
                ORDER BY created_at DESC 
                LIMIT ?
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get audit statistics
     */
    public function getAuditStatistics(string $timeframe = '24 hours'): array
    {
        try {
            // Validate and sanitize timeframe input
            $allowedTimeframes = [
                '1 hour' => '1 HOUR',
                '24 hours' => '24 HOUR',
                '7 days' => '7 DAY',
                '30 days' => '30 DAY',
                '90 days' => '90 DAY'
            ];

            $sqlTimeframe = $allowedTimeframes[$timeframe] ?? '24 HOUR';

            $sql = "
                SELECT 
                    event_type,
                    severity,
                    COUNT(*) as count
                FROM audit_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$sqlTimeframe})
                GROUP BY event_type, severity
                ORDER BY count DESC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([1]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Search audit logs
     */
    public function searchLogs(array $criteria, int $limit = 100): array
    {
        try {
            $conditions = [];
            $params = [];

            if (!empty($criteria['user_id'])) {
                $conditions[] = 'user_id = ?';
                $params[] = $criteria['user_id'];
            }

            if (!empty($criteria['event_type'])) {
                $conditions[] = 'event_type = ?';
                $params[] = $criteria['event_type'];
            }

            if (!empty($criteria['severity'])) {
                $conditions[] = 'severity = ?';
                $params[] = $criteria['severity'];
            }

            if (!empty($criteria['ip_address'])) {
                $conditions[] = 'ip_address = ?';
                $params[] = $criteria['ip_address'];
            }

            if (!empty($criteria['date_from'])) {
                $conditions[] = 'created_at >= ?';
                $params[] = $criteria['date_from'];
            }

            if (!empty($criteria['date_to'])) {
                $conditions[] = 'created_at <= ?';
                $params[] = $criteria['date_to'];
            }

            $whereClause = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
            $sql = "SELECT * FROM audit_logs {$whereClause} ORDER BY created_at DESC LIMIT ?";
            $params[] = $limit;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Clean up old audit logs
     */
    public function cleanupOldLogs(int $daysToKeep = 90): int
    {
        try {
            $sql = "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$daysToKeep]);
            return $stmt->rowCount();

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get security dashboard data
     */
    public function getSecurityDashboard(string $timeframe = '24 hours'): array
    {
        try {
            // Get event counts by severity
            $severityStats = $this->getEventCountsBySeverity($timeframe);

            // Get top event types
            $topEvents = $this->getTopEventTypes($timeframe, 10);

            // Get suspicious activities
            $suspiciousActivities = $this->getSuspiciousActivities($timeframe);

            // Get failed authentication attempts
            $failedLogins = $this->getFailedAuthenticationAttempts($timeframe);

            // Get system health indicators
            $systemHealth = $this->getSystemHealthIndicators($timeframe);

            return [
                'timeframe' => $timeframe,
                'severity_stats' => $severityStats,
                'top_events' => $topEvents,
                'suspicious_activities' => $suspiciousActivities,
                'failed_logins' => $failedLogins,
                'system_health' => $systemHealth,
                'generated_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to generate security dashboard: ' . $e->getMessage()];
        }
    }

    /**
     * Export audit logs for compliance
     */
    public function exportLogs(array $criteria, string $format = 'json'): array
    {
        try {
            $logs = $this->searchLogs($criteria, 10000); // Large limit for export

            switch ($format) {
                case 'csv':
                    return $this->exportToCsv($logs);
                case 'xml':
                    return $this->exportToXml($logs);
                case 'json':
                default:
                    return [
                        'success' => true,
                        'format' => 'json',
                        'count' => count($logs),
                        'data' => $logs,
                        'exported_at' => date('Y-m-d H:i:s')
                    ];
            }

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Detect anomalous patterns in audit logs
     */
    public function detectAnomalies(string $timeframe = '24 hours'): array
    {
        try {
            $anomalies = [];

            // Detect unusual login patterns
            $unusualLogins = $this->detectUnusualLoginPatterns($timeframe);
            if (!empty($unusualLogins)) {
                $anomalies['unusual_logins'] = $unusualLogins;
            }

            // Detect rate limit violations
            $rateLimitViolations = $this->detectRateLimitViolations($timeframe);
            if (!empty($rateLimitViolations)) {
                $anomalies['rate_limit_violations'] = $rateLimitViolations;
            }

            // Detect suspicious IP addresses
            $suspiciousIPs = $this->detectSuspiciousIPs($timeframe);
            if (!empty($suspiciousIPs)) {
                $anomalies['suspicious_ips'] = $suspiciousIPs;
            }

            // Detect privilege escalation attempts
            $privilegeEscalation = $this->detectPrivilegeEscalation($timeframe);
            if (!empty($privilegeEscalation)) {
                $anomalies['privilege_escalation'] = $privilegeEscalation;
            }

            return [
                'timeframe' => $timeframe,
                'anomalies_detected' => count($anomalies),
                'anomalies' => $anomalies,
                'analyzed_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return ['error' => 'Anomaly detection failed: ' . $e->getMessage()];
        }
    }

    /**
     * Generate compliance report
     */
    public function generateComplianceReport(string $startDate, string $endDate): array
    {
        try {
            $criteria = [
                'date_from' => $startDate,
                'date_to' => $endDate
            ];

            $allLogs = $this->searchLogs($criteria, 50000);

            $report = [
                'report_period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'summary' => [
                    'total_events' => count($allLogs),
                    'authentication_events' => $this->countEventsByCategory($allLogs, self::CATEGORY_AUTHENTICATION),
                    'authorization_events' => $this->countEventsByCategory($allLogs, self::CATEGORY_AUTHORIZATION),
                    'data_access_events' => $this->countEventsByCategory($allLogs, self::CATEGORY_DATA_ACCESS),
                    'security_incidents' => $this->countEventsBySeverity($allLogs, [self::SEVERITY_WARNING, self::SEVERITY_ERROR, self::SEVERITY_CRITICAL]),
                    'admin_actions' => $this->countEventsByType($allLogs, self::EVENT_ADMIN_ACTION)
                ],
                'security_metrics' => [
                    'failed_logins' => $this->countEventsByType($allLogs, self::EVENT_LOGIN_FAILED),
                    'successful_logins' => $this->countEventsByType($allLogs, self::EVENT_LOGIN_SUCCESS),
                    'account_lockouts' => $this->countEventsByType($allLogs, self::EVENT_ACCOUNT_LOCKED),
                    'suspicious_activities' => $this->countEventsByType($allLogs, self::EVENT_SUSPICIOUS_ACTIVITY)
                ],
                'top_users_by_activity' => $this->getTopUsersByActivity($allLogs, 10),
                'top_ip_addresses' => $this->getTopIPAddresses($allLogs, 10),
                'generated_at' => date('Y-m-d H:i:s')
            ];

            return $report;

        } catch (Exception $e) {
            return ['error' => 'Compliance report generation failed: ' . $e->getMessage()];
        }
    }

    // Private helper methods

    /**
     * Enrich metadata with additional context
     */
    private function enrichMetadata(array $metadata, string $eventType, ?int $userId): array
    {
        return array_merge($metadata, [
            'timestamp' => microtime(true),
            'php_sapi' => PHP_SAPI,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'N/A',
            'http_host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'user_id' => $userId,
            'event_type' => $eventType
        ]);
    }

    /**
     * Determine event category based on event type
     */
    private function determineEventCategory(string $eventType): string
    {
        $authEvents = [
            self::EVENT_LOGIN_SUCCESS,
            self::EVENT_LOGIN_FAILED,
            self::EVENT_LOGOUT,
            self::EVENT_PASSWORD_CHANGE,
            self::EVENT_MFA_ENABLED,
            self::EVENT_MFA_DISABLED,
            self::EVENT_PASSKEY_REGISTERED,
            self::EVENT_PASSKEY_REMOVED,
            self::EVENT_MAGIC_LINK_GENERATED,
            self::EVENT_MAGIC_LINK_USED
        ];

        $securityEvents = [
            self::EVENT_SUSPICIOUS_ACTIVITY,
            self::EVENT_RATE_LIMIT_EXCEEDED,
            self::EVENT_UNAUTHORIZED_ACCESS,
            self::EVENT_ACCOUNT_LOCKED,
            self::EVENT_ACCOUNT_UNLOCKED
        ];

        $systemEvents = [
            self::EVENT_SYSTEM_ERROR
        ];

        if (in_array($eventType, $authEvents)) {
            return self::CATEGORY_AUTHENTICATION;
        } elseif (in_array($eventType, $securityEvents)) {
            return self::CATEGORY_SECURITY;
        } elseif (in_array($eventType, $systemEvents)) {
            return self::CATEGORY_SYSTEM;
        } elseif ($eventType === self::EVENT_ADMIN_ACTION) {
            return self::CATEGORY_USER_MANAGEMENT;
        } elseif ($eventType === self::EVENT_DATA_EXPORT) {
            return self::CATEGORY_DATA_ACCESS;
        } else {
            return self::CATEGORY_SYSTEM;
        }
    }

    /**
     * Get real IP address considering proxies
     */
    private function getRealIpAddress(string $fallback = 'unknown'): string
    {
        if ($fallback !== 'auto' && $fallback !== 'unknown') {
            return $fallback;
        }

        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Generate unique request ID
     */
    private function generateRequestId(): string
    {
        return uniqid('req_', true);
    }

    /**
     * Get safe HTTP headers (excluding sensitive data)
     */
    private function getSafeHeaders(): array
    {
        $safeHeaders = [];
        $allowedHeaders = [
            'HTTP_ACCEPT',
            'HTTP_ACCEPT_LANGUAGE',
            'HTTP_ACCEPT_ENCODING',
            'HTTP_USER_AGENT',
            'HTTP_REFERER',
            'HTTP_HOST',
            'HTTP_CONNECTION'
        ];

        foreach ($allowedHeaders as $header) {
            if (isset($_SERVER[$header])) {
                $safeHeaders[$header] = $_SERVER[$header];
            }
        }

        return $safeHeaders;
    }

    /**
     * Calculate threat level based on event type and severity
     */
    private function calculateThreatLevel(string $eventType, string $severity): string
    {
        $highThreatEvents = [
            self::EVENT_SUSPICIOUS_ACTIVITY,
            self::EVENT_UNAUTHORIZED_ACCESS,
            self::EVENT_RATE_LIMIT_EXCEEDED
        ];

        if (in_array($eventType, $highThreatEvents)) {
            return 'HIGH';
        }

        switch ($severity) {
            case self::SEVERITY_CRITICAL:
            case self::SEVERITY_ALERT:
            case self::SEVERITY_EMERGENCY:
                return 'CRITICAL';
            case self::SEVERITY_ERROR:
                return 'HIGH';
            case self::SEVERITY_WARNING:
                return 'MEDIUM';
            default:
                return 'LOW';
        }
    }

    /**
     * Fallback logging when database fails
     */
    private function fallbackLog(string $eventType, string $description, ?int $userId, Exception $e): void
    {
        $logMessage = sprintf(
            "[AUDIT_LOG_FAILED] Type: %s, User: %s, Description: %s, Error: %s",
            $eventType,
            $userId ?? 'N/A',
            $description,
            $e->getMessage()
        );

        error_log($logMessage);

        // Also try to write to a fallback file
        $fallbackFile = sys_get_temp_dir() . '/auth_audit_fallback.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($fallbackFile, "[$timestamp] $logMessage\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Get event counts by severity
     */
    private function getEventCountsBySeverity(string $timeframe): array
    {
        try {
            // Validate and sanitize timeframe input
            $allowedTimeframes = [
                '1 hour' => '1 HOUR',
                '24 hours' => '24 HOUR',
                '7 days' => '7 DAY',
                '30 days' => '30 DAY',
                '90 days' => '90 DAY'
            ];

            $sqlTimeframe = $allowedTimeframes[$timeframe] ?? '24 HOUR';

            $sql = "
                SELECT severity, COUNT(*) as count
                FROM audit_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$sqlTimeframe})
                GROUP BY severity
                ORDER BY count DESC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([1]);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get top event types
     */
    private function getTopEventTypes(string $timeframe, int $limit): array
    {
        try {
            // Validate and sanitize timeframe input
            $allowedTimeframes = [
                '1 hour' => '1 HOUR',
                '24 hours' => '24 HOUR',
                '7 days' => '7 DAY',
                '30 days' => '30 DAY',
                '90 days' => '90 DAY'
            ];

            $sqlTimeframe = $allowedTimeframes[$timeframe] ?? '24 HOUR';

            $sql = "
                SELECT event_type, COUNT(*) as count
                FROM audit_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$sqlTimeframe})
                GROUP BY event_type
                ORDER BY count DESC
                LIMIT ?
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([1, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get suspicious activities
     */
    private function getSuspiciousActivities(string $timeframe): array
    {
        try {
            // Validate and sanitize timeframe input
            $allowedTimeframes = [
                '1 hour' => '1 HOUR',
                '24 hours' => '24 HOUR',
                '7 days' => '7 DAY',
                '30 days' => '30 DAY',
                '90 days' => '90 DAY'
            ];

            $sqlTimeframe = $allowedTimeframes[$timeframe] ?? '24 HOUR';

            $sql = "
                SELECT *
                FROM audit_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$sqlTimeframe})
                AND (event_type = ? OR severity IN (?, ?, ?))
                ORDER BY created_at DESC
                LIMIT 50
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                1,
                self::EVENT_SUSPICIOUS_ACTIVITY,
                self::SEVERITY_WARNING,
                self::SEVERITY_ERROR,
                self::SEVERITY_CRITICAL
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get failed authentication attempts
     */
    private function getFailedAuthenticationAttempts(string $timeframe): array
    {
        try {
            // Validate and sanitize timeframe input
            $allowedTimeframes = [
                '1 hour' => '1 HOUR',
                '24 hours' => '24 HOUR',
                '7 days' => '7 DAY',
                '30 days' => '30 DAY',
                '90 days' => '90 DAY'
            ];

            $sqlTimeframe = $allowedTimeframes[$timeframe] ?? '24 HOUR';

            $sql = "
                SELECT ip_address, COUNT(*) as attempts, MAX(created_at) as last_attempt
                FROM audit_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$sqlTimeframe})
                AND event_type = ?
                GROUP BY ip_address
                HAVING attempts > 3
                ORDER BY attempts DESC
                LIMIT 20
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([1, self::EVENT_LOGIN_FAILED]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get system health indicators
     */
    private function getSystemHealthIndicators(string $timeframe): array
    {
        try {
            // Validate and sanitize timeframe input
            $allowedTimeframes = [
                '1 hour' => '1 HOUR',
                '24 hours' => '24 HOUR',
                '7 days' => '7 DAY',
                '30 days' => '30 DAY',
                '90 days' => '90 DAY'
            ];

            $sqlTimeframe = $allowedTimeframes[$timeframe] ?? '24 HOUR';

            $sql = "
                SELECT 
                    COUNT(*) as total_events,
                    COUNT(CASE WHEN severity = 'error' THEN 1 END) as error_count,
                    COUNT(CASE WHEN severity = 'critical' THEN 1 END) as critical_count,
                    COUNT(DISTINCT user_id) as active_users,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM audit_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$sqlTimeframe})
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([1]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        } catch (Exception $e) {
            return [];
        }
    }

    // Additional helper methods for compliance and analysis
    private function countEventsByCategory(array $logs, string $category): int
    {
        return count(array_filter($logs, fn($log) => ($log['event_category'] ?? '') === $category));
    }

    private function countEventsBySeverity(array $logs, array $severities): int
    {
        return count(array_filter($logs, fn($log) => in_array($log['severity'] ?? '', $severities)));
    }

    private function countEventsByType(array $logs, string $eventType): int
    {
        return count(array_filter($logs, fn($log) => ($log['event_type'] ?? '') === $eventType));
    }

    private function getTopUsersByActivity(array $logs, int $limit): array
    {
        $userCounts = [];
        foreach ($logs as $log) {
            if ($log['user_id']) {
                $userCounts[$log['user_id']] = ($userCounts[$log['user_id']] ?? 0) + 1;
            }
        }
        arsort($userCounts);
        return array_slice($userCounts, 0, $limit, true);
    }

    private function getTopIPAddresses(array $logs, int $limit): array
    {
        $ipCounts = [];
        foreach ($logs as $log) {
            if ($log['ip_address']) {
                $ipCounts[$log['ip_address']] = ($ipCounts[$log['ip_address']] ?? 0) + 1;
            }
        }
        arsort($ipCounts);
        return array_slice($ipCounts, 0, $limit, true);
    }

    private function detectUnusualLoginPatterns(string $timeframe): array
    {
        try {
            // Validate and sanitize timeframe input
            $allowedTimeframes = [
                '1 hour' => '1 HOUR',
                '24 hours' => '24 HOUR',
                '7 days' => '7 DAY',
                '30 days' => '30 DAY',
                '90 days' => '90 DAY'
            ];

            $sqlTimeframe = $allowedTimeframes[$timeframe] ?? '24 HOUR';

            // Detect logins outside normal hours (e.g., 2 AM - 6 AM)
            $sql = "
                SELECT user_id, ip_address, COUNT(*) as unusual_logins
                FROM audit_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$sqlTimeframe})
                AND event_type = ?
                AND HOUR(created_at) BETWEEN 2 AND 6
                GROUP BY user_id, ip_address
                HAVING unusual_logins > 2
                ORDER BY unusual_logins DESC
                LIMIT 10
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([1, self::EVENT_LOGIN_SUCCESS]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    private function detectRateLimitViolations(string $timeframe): array
    {
        try {
            // Validate and sanitize timeframe input
            $allowedTimeframes = [
                '1 hour' => '1 HOUR',
                '24 hours' => '24 HOUR',
                '7 days' => '7 DAY',
                '30 days' => '30 DAY',
                '90 days' => '90 DAY'
            ];

            $sqlTimeframe = $allowedTimeframes[$timeframe] ?? '24 HOUR';

            $sql = "
                SELECT ip_address, COUNT(*) as violations
                FROM audit_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$sqlTimeframe})
                AND event_type = ?
                GROUP BY ip_address
                HAVING violations > 5
                ORDER BY violations DESC
                LIMIT 20
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([1, self::EVENT_RATE_LIMIT_EXCEEDED]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    private function detectSuspiciousIPs(string $timeframe): array
    {
        try {
            // Validate and sanitize timeframe input
            $allowedTimeframes = [
                '1 hour' => '1 HOUR',
                '24 hours' => '24 HOUR',
                '7 days' => '7 DAY',
                '30 days' => '30 DAY',
                '90 days' => '90 DAY'
            ];

            $sqlTimeframe = $allowedTimeframes[$timeframe] ?? '24 HOUR';

            // Detect IPs with multiple failed login attempts
            $sql = "
                SELECT ip_address, 
                       COUNT(*) as total_attempts,
                       COUNT(CASE WHEN event_type = ? THEN 1 END) as failed_attempts,
                       COUNT(DISTINCT user_id) as targeted_users
                FROM audit_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$sqlTimeframe})
                AND event_type IN (?, ?)
                GROUP BY ip_address
                HAVING failed_attempts > 10 OR targeted_users > 5
                ORDER BY failed_attempts DESC
                LIMIT 15
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                self::EVENT_LOGIN_FAILED,
                1,
                self::EVENT_LOGIN_FAILED,
                self::EVENT_LOGIN_SUCCESS
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    private function detectPrivilegeEscalation(string $timeframe): array
    {
        try {
            // Validate and sanitize timeframe input
            $allowedTimeframes = [
                '1 hour' => '1 HOUR',
                '24 hours' => '24 HOUR',
                '7 days' => '7 DAY',
                '30 days' => '30 DAY',
                '90 days' => '90 DAY'
            ];

            $sqlTimeframe = $allowedTimeframes[$timeframe] ?? '24 HOUR';

            // Detect admin actions by non-admin users or unusual admin activity
            $sql = "
                SELECT user_id, COUNT(*) as admin_actions, 
                       GROUP_CONCAT(DISTINCT event_description) as actions
                FROM audit_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$sqlTimeframe})
                AND event_type = ?
                GROUP BY user_id
                HAVING admin_actions > 20
                ORDER BY admin_actions DESC
                LIMIT 10
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([1, self::EVENT_ADMIN_ACTION]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    private function exportToCsv(array $logs): array
    {
        try {
            $csvData = [];
            $csvData[] = [
                'ID',
                'User ID',
                'Event Type',
                'Category',
                'Description',
                'IP Address',
                'User Agent',
                'Severity',
                'Created At'
            ];

            foreach ($logs as $log) {
                $csvData[] = [
                    $log['id'] ?? '',
                    $log['user_id'] ?? '',
                    $log['event_type'] ?? '',
                    $log['event_category'] ?? '',
                    $log['event_description'] ?? '',
                    $log['ip_address'] ?? '',
                    $log['user_agent'] ?? '',
                    $log['severity'] ?? '',
                    $log['created_at'] ?? ''
                ];
            }

            return [
                'success' => true,
                'format' => 'csv',
                'count' => count($logs),
                'data' => $csvData,
                'exported_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function exportToXml(array $logs): array
    {
        try {
            $xml = new \SimpleXMLElement('<audit_logs/>');

            foreach ($logs as $log) {
                $logElement = $xml->addChild('log');
                foreach ($log as $key => $value) {
                    $logElement->addChild($key, htmlspecialchars($value ?? ''));
                }
            }

            return [
                'success' => true,
                'format' => 'xml',
                'count' => count($logs),
                'data' => $xml->asXML(),
                'exported_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get user security events
     */
    public function getUserSecurityEvents(int $userId, int $limit = 10): array
    {
        try {
            $sql = "
                SELECT * FROM audit_logs 
                WHERE user_id = ? 
                AND event_category IN (?, ?) 
                ORDER BY created_at DESC 
                LIMIT ?
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $userId,
                self::CATEGORY_SECURITY,
                self::CATEGORY_AUTHENTICATION,
                $limit
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get user logs count
     */
    public function getUserLogsCount(int $userId): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM audit_logs WHERE user_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            return (int) $stmt->fetchColumn();

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get user logs with pagination
     */
    public function getUserLogs(int $userId, int $limit = 50, int $page = 1): array
    {
        try {
            $offset = ($page - 1) * $limit;
            $sql = "
                SELECT * FROM audit_logs 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [];
        }
    }
}