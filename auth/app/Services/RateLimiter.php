<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\App;
use Antinna\Auth\Database\Connection;
use Antinna\Auth\Services\AuditLogger;
use Exception;
use PDO;

/**
 * Rate Limiter for API protection and abuse prevention
 */
class RateLimiter
{
    private PDO $db;
    private App $config;
    private AuditLogger $auditLogger;
    
    // Rate limit types
    public const LIMIT_TYPE_IP = 'ip';
    public const LIMIT_TYPE_USER = 'user';
    public const LIMIT_TYPE_SERVICE = 'service';
    public const LIMIT_TYPE_ENDPOINT = 'endpoint';
    public const LIMIT_TYPE_GLOBAL = 'global';
    
    // Time windows
    public const WINDOW_MINUTE = 60;
    public const WINDOW_HOUR = 3600;
    public const WINDOW_DAY = 86400;
    
    // Default limits
    private array $defaultLimits = [
        'login_attempts' => ['limit' => 5, 'window' => self::WINDOW_MINUTE],
        'magic_link_requests' => ['limit' => 3, 'window' => self::WINDOW_MINUTE],
        'password_reset' => ['limit' => 3, 'window' => self::WINDOW_HOUR],
        'api_requests' => ['limit' => 100, 'window' => self::WINDOW_MINUTE],
        'registration' => ['limit' => 5, 'window' => self::WINDOW_HOUR],
        'mfa_attempts' => ['limit' => 10, 'window' => self::WINDOW_MINUTE],
        'passkey_operations' => ['limit' => 20, 'window' => self::WINDOW_MINUTE],
        'global_requests' => ['limit' => 1000, 'window' => self::WINDOW_MINUTE]
    ];
    
    // Bypass protection levels
    public const PROTECTION_NONE = 0;
    public const PROTECTION_LOG = 1;
    public const PROTECTION_WARN = 2;
    public const PROTECTION_BLOCK = 3;
    public const PROTECTION_BAN = 4;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->config = App::getInstance();
        $this->auditLogger = new AuditLogger();
        
        // Load custom limits from config
        $this->loadCustomLimits();
    }

    /**
     * Check if request is within rate limits
     */
    public function checkLimit(
        string $identifier,
        string $limitType,
        string $action,
        ?int $customLimit = null,
        ?int $customWindow = null
    ): array {
        try {
            // Get limit configuration
            $limitConfig = $this->getLimitConfig($action, $customLimit, $customWindow);
            
            // Check if identifier is whitelisted
            if ($this->isWhitelisted($identifier, $limitType)) {
                return [
                    'allowed' => true,
                    'limit' => $limitConfig['limit'],
                    'remaining' => $limitConfig['limit'],
                    'reset_time' => time() + $limitConfig['window'],
                    'whitelisted' => true
                ];
            }
            
            // Check if identifier is banned
            if ($this->isBanned($identifier, $limitType)) {
                $this->logRateLimitViolation($identifier, $limitType, $action, 'banned');
                return [
                    'allowed' => false,
                    'limit' => 0,
                    'remaining' => 0,
                    'reset_time' => time() + 86400, // 24 hours
                    'banned' => true,
                    'reason' => 'Identifier is banned'
                ];
            }
            
            // Get current usage
            $currentUsage = $this->getCurrentUsage($identifier, $limitType, $action, $limitConfig['window']);
            
            // Calculate remaining requests
            $remaining = max(0, $limitConfig['limit'] - $currentUsage);
            $allowed = $currentUsage < $limitConfig['limit'];
            
            // Record this request
            if ($allowed) {
                $this->recordRequest($identifier, $limitType, $action);
            } else {
                $this->logRateLimitViolation($identifier, $limitType, $action, 'limit_exceeded');
                $this->handleRateLimitExceeded($identifier, $limitType, $action, $currentUsage);
            }
            
            return [
                'allowed' => $allowed,
                'limit' => $limitConfig['limit'],
                'remaining' => $remaining,
                'reset_time' => $this->getResetTime($identifier, $limitType, $action, $limitConfig['window']),
                'current_usage' => $currentUsage
            ];
            
        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'rate_limiter_error',
                'Rate limiter error: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR,
                ['identifier' => $identifier, 'action' => $action]
            );
            
            // Fail open - allow request if rate limiter fails
            return [
                'allowed' => true,
                'limit' => 0,
                'remaining' => 0,
                'reset_time' => time() + 3600,
                'error' => 'Rate limiter unavailable'
            ];
        }
    }

    /**
     * Check multiple rate limits at once
     */
    public function checkMultipleLimits(string $identifier, array $checks): array
    {
        $results = [];
        $overallAllowed = true;
        
        foreach ($checks as $check) {
            $result = $this->checkLimit(
                $identifier,
                $check['type'],
                $check['action'],
                $check['limit'] ?? null,
                $check['window'] ?? null
            );
            
            $results[$check['action']] = $result;
            
            if (!$result['allowed']) {
                $overallAllowed = false;
            }
        }
        
        return [
            'allowed' => $overallAllowed,
            'results' => $results
        ];
    }

    /**
     * Add identifier to whitelist
     */
    public function addToWhitelist(string $identifier, string $limitType, ?string $reason = null): bool
    {
        try {
            $sql = "
                INSERT INTO rate_limit_whitelist (identifier, limit_type, reason, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE reason = VALUES(reason), updated_at = NOW()
            ";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$identifier, $limitType, $reason]);
            
            if ($success) {
                $this->auditLogger->logAdminAction(
                    'whitelist_add',
                    "Added $identifier to rate limit whitelist",
                    $this->getCurrentUserId(),
                    null,
                    ['identifier' => $identifier, 'limit_type' => $limitType, 'reason' => $reason]
                );
            }
            
            return $success;
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Remove identifier from whitelist
     */
    public function removeFromWhitelist(string $identifier, string $limitType): bool
    {
        try {
            $sql = "DELETE FROM rate_limit_whitelist WHERE identifier = ? AND limit_type = ?";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$identifier, $limitType]);
            
            if ($success && $stmt->rowCount() > 0) {
                $this->auditLogger->logAdminAction(
                    'whitelist_remove',
                    "Removed $identifier from rate limit whitelist",
                    $this->getCurrentUserId(),
                    null,
                    ['identifier' => $identifier, 'limit_type' => $limitType]
                );
            }
            
            return $success;
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Ban identifier temporarily or permanently
     */
    public function banIdentifier(
        string $identifier,
        string $limitType,
        ?int $duration = null,
        ?string $reason = null
    ): bool {
        try {
            $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
            
            $sql = "
                INSERT INTO rate_limit_bans (identifier, limit_type, reason, expires_at, created_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    reason = VALUES(reason), 
                    expires_at = VALUES(expires_at), 
                    updated_at = NOW()
            ";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$identifier, $limitType, $reason, $expiresAt]);
            
            if ($success) {
                $this->auditLogger->logSecurityIncident(
                    'rate_limit_ban',
                    "Banned $identifier for rate limit violations",
                    null,
                    AuditLogger::SEVERITY_WARNING,
                    [
                        'identifier' => $identifier,
                        'limit_type' => $limitType,
                        'duration' => $duration,
                        'reason' => $reason
                    ]
                );
            }
            
            return $success;
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Unban identifier
     */
    public function unbanIdentifier(string $identifier, string $limitType): bool
    {
        try {
            $sql = "DELETE FROM rate_limit_bans WHERE identifier = ? AND limit_type = ?";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$identifier, $limitType]);
            
            if ($success && $stmt->rowCount() > 0) {
                $this->auditLogger->logAdminAction(
                    'rate_limit_unban',
                    "Unbanned $identifier from rate limiting",
                    $this->getCurrentUserId(),
                    null,
                    ['identifier' => $identifier, 'limit_type' => $limitType]
                );
            }
            
            return $success;
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get rate limit statistics
     */
    public function getStatistics(string $timeframe = '1 hour'): array
    {
        try {
            $stats = [
                'timeframe' => $timeframe,
                'total_requests' => $this->getTotalRequests($timeframe),
                'blocked_requests' => $this->getBlockedRequests($timeframe),
                'top_violators' => $this->getTopViolators($timeframe),
                'most_limited_actions' => $this->getMostLimitedActions($timeframe),
                'whitelist_count' => $this->getWhitelistCount(),
                'ban_count' => $this->getBanCount(),
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
            return [
                'success' => true,
                'statistics' => $stats
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to generate statistics'
            ];
        }
    }

    /**
     * Clean up old rate limit records
     */
    public function cleanup(int $daysToKeep = 7): array
    {
        try {
            $results = [];
            
            // Clean up old request records
            $sql = "DELETE FROM rate_limit_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$daysToKeep]);
            $results['requests_cleaned'] = $stmt->rowCount();
            
            // Clean up expired bans
            $sql = "DELETE FROM rate_limit_bans WHERE expires_at IS NOT NULL AND expires_at < NOW()";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $results['expired_bans_cleaned'] = $stmt->rowCount();
            
            return [
                'success' => true,
                'results' => $results
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Cleanup failed'
            ];
        }
    }

    /**
     * Get current usage for identifier
     */
    private function getCurrentUsage(string $identifier, string $limitType, string $action, int $window): int
    {
        try {
            $sql = "
                SELECT COUNT(*) 
                FROM rate_limit_requests 
                WHERE identifier = ? 
                AND limit_type = ? 
                AND action = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$identifier, $limitType, $action, $window]);
            
            return (int)$stmt->fetchColumn();
            
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Record a request
     */
    private function recordRequest(string $identifier, string $limitType, string $action): bool
    {
        try {
            $sql = "
                INSERT INTO rate_limit_requests (identifier, limit_type, action, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                $identifier,
                $limitType,
                $action,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if identifier is whitelisted
     */
    private function isWhitelisted(string $identifier, string $limitType): bool
    {
        try {
            $sql = "
                SELECT COUNT(*) 
                FROM rate_limit_whitelist 
                WHERE identifier = ? AND limit_type = ?
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$identifier, $limitType]);
            
            return $stmt->fetchColumn() > 0;
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if identifier is banned
     */
    private function isBanned(string $identifier, string $limitType): bool
    {
        try {
            $sql = "
                SELECT COUNT(*) 
                FROM rate_limit_bans 
                WHERE identifier = ? 
                AND limit_type = ? 
                AND (expires_at IS NULL OR expires_at > NOW())
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$identifier, $limitType]);
            
            return $stmt->fetchColumn() > 0;
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get limit configuration for action
     */
    private function getLimitConfig(string $action, ?int $customLimit, ?int $customWindow): array
    {
        if ($customLimit !== null && $customWindow !== null) {
            return ['limit' => $customLimit, 'window' => $customWindow];
        }
        
        return $this->defaultLimits[$action] ?? $this->defaultLimits['api_requests'];
    }

    /**
     * Get reset time for rate limit window
     */
    private function getResetTime(string $identifier, string $limitType, string $action, int $window): int
    {
        try {
            $sql = "
                SELECT MIN(created_at) 
                FROM rate_limit_requests 
                WHERE identifier = ? 
                AND limit_type = ? 
                AND action = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$identifier, $limitType, $action, $window]);
            
            $firstRequest = $stmt->fetchColumn();
            
            if ($firstRequest) {
                return strtotime($firstRequest) + $window;
            }
            
            return time() + $window;
            
        } catch (Exception $e) {
            return time() + $window;
        }
    }

    /**
     * Handle rate limit exceeded
     */
    private function handleRateLimitExceeded(string $identifier, string $limitType, string $action, int $currentUsage): void
    {
        // Check if this is a severe violation
        $limitConfig = $this->getLimitConfig($action, null, null);
        $violationSeverity = $currentUsage / $limitConfig['limit'];
        
        if ($violationSeverity >= 3.0) {
            // Severe violation - consider temporary ban
            $this->banIdentifier($identifier, $limitType, 3600, 'Severe rate limit violation');
        } elseif ($violationSeverity >= 2.0) {
            // Moderate violation - log as security incident
            $this->auditLogger->logSecurityIncident(
                'rate_limit_abuse',
                "Moderate rate limit violation detected",
                null,
                AuditLogger::SEVERITY_WARNING,
                [
                    'identifier' => $identifier,
                    'limit_type' => $limitType,
                    'action' => $action,
                    'current_usage' => $currentUsage,
                    'limit' => $limitConfig['limit']
                ]
            );
        }
    }

    /**
     * Log rate limit violation
     */
    private function logRateLimitViolation(string $identifier, string $limitType, string $action, string $reason): void
    {
        $this->auditLogger->log(
            AuditLogger::EVENT_RATE_LIMIT_EXCEEDED,
            "Rate limit exceeded: $reason",
            null,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            AuditLogger::SEVERITY_WARNING,
            [
                'identifier' => $identifier,
                'limit_type' => $limitType,
                'action' => $action,
                'reason' => $reason
            ]
        );
    }

    /**
     * Load custom limits from configuration
     */
    private function loadCustomLimits(): void
    {
        $customLimits = $this->config->get('rate_limiting.limits', []);
        $this->defaultLimits = array_merge($this->defaultLimits, $customLimits);
    }

    /**
     * Get current user ID (if available)
     */
    private function getCurrentUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    // Statistics helper methods
    private function getTotalRequests(string $timeframe): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM rate_limit_requests WHERE created_at > DATE_SUB(NOW(), INTERVAL $timeframe)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getBlockedRequests(string $timeframe): int
    {
        try {
            $sql = "
                SELECT COUNT(*) 
                FROM audit_logs 
                WHERE event_type = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL $timeframe)
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([AuditLogger::EVENT_RATE_LIMIT_EXCEEDED]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getTopViolators(string $timeframe, int $limit = 10): array
    {
        try {
            $sql = "
                SELECT identifier, COUNT(*) as violations
                FROM audit_logs 
                WHERE event_type = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL $timeframe)
                GROUP BY identifier
                ORDER BY violations DESC
                LIMIT ?
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([AuditLogger::EVENT_RATE_LIMIT_EXCEEDED, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    private function getMostLimitedActions(string $timeframe, int $limit = 10): array
    {
        try {
            $sql = "
                SELECT JSON_EXTRACT(metadata, '$.action') as action, COUNT(*) as violations
                FROM audit_logs 
                WHERE event_type = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL $timeframe)
                GROUP BY action
                ORDER BY violations DESC
                LIMIT ?
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([AuditLogger::EVENT_RATE_LIMIT_EXCEEDED, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    private function getWhitelistCount(): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM rate_limit_whitelist";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getBanCount(): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM rate_limit_bans WHERE expires_at IS NULL OR expires_at > NOW()";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}