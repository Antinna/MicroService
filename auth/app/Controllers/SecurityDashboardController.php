<?php

namespace Antinna\Auth\Controllers;

use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Services\RateLimiter;
use Antinna\Auth\Services\SecurityMonitor;
use Antinna\Auth\Services\JWTManager;
use Exception;

/**
 * Security Dashboard API Controller
 */
class SecurityDashboardController
{
    private UserRepository $userRepository;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;
    private SecurityMonitor $securityMonitor;
    private JWTManager $jwtManager;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->auditLogger = new AuditLogger();
        $this->rateLimiter = new RateLimiter();
        $this->securityMonitor = new SecurityMonitor();
        $this->jwtManager = new JWTManager();
    }

    /**
     * Get security dashboard overview
     * GET /api/security/dashboard
     */
    public function getDashboard(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $userId,
                RateLimiter::LIMIT_TYPE_USER,
                'api_requests'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Get dashboard data
            $dashboardData = [
                'user_info' => $this->getUserSecurityInfo($userId),
                'login_history' => $this->getLoginHistory($userId),
                'active_sessions' => $this->getActiveSessions($userId),
                'security_events' => $this->getSecurityEvents($userId),
                'security_metrics' => $this->getSecurityMetrics($userId),
                'threat_analysis' => $this->getThreatAnalysis($userId)
            ];

            // Log dashboard access
            $this->auditLogger->logDataAccess(
                'security_dashboard',
                'read',
                $userId,
                true,
                ['dashboard_sections' => array_keys($dashboardData)]
            );

            $this->sendSuccess($dashboardData);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'security_dashboard_error',
                'Error retrieving security dashboard: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Failed to retrieve security dashboard', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Get detailed login history
     * GET /api/security/login-history
     */
    public function getLoginHistory(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $userId,
                RateLimiter::LIMIT_TYPE_USER,
                'api_requests'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Get pagination parameters
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
            $days = isset($_GET['days']) ? min(365, max(1, (int)$_GET['days'])) : 30;

            // Get login history
            $loginHistory = $this->auditLogger->searchLogs([
                'user_id' => $userId,
                'event_type' => [AuditLogger::EVENT_LOGIN_SUCCESS, AuditLogger::EVENT_LOGIN_FAILED],
                'date_from' => date('Y-m-d H:i:s', strtotime("-{$days} days"))
            ], $limit * $page);

            // Paginate results
            $offset = ($page - 1) * $limit;
            $paginatedHistory = array_slice($loginHistory, $offset, $limit);

            // Enhance login data with location and device info
            $enhancedHistory = array_map(function($login) {
                $metadata = json_decode($login['metadata'] ?? '{}', true);
                return [
                    'id' => $login['id'],
                    'event_type' => $login['event_type'],
                    'success' => $login['event_type'] === AuditLogger::EVENT_LOGIN_SUCCESS,
                    'ip_address' => $login['ip_address'],
                    'user_agent' => $login['user_agent'],
                    'location' => $this->getLocationFromIP($login['ip_address']),
                    'device_info' => $this->parseUserAgent($login['user_agent']),
                    'auth_method' => $metadata['auth_method'] ?? 'password',
                    'created_at' => $login['created_at'],
                    'risk_score' => $this->calculateLoginRiskScore($login)
                ];
            }, $paginatedHistory);

            // Log data access
            $this->auditLogger->logDataAccess(
                'login_history',
                'read',
                $userId,
                true,
                ['days' => $days, 'page' => $page, 'limit' => $limit]
            );

            $this->sendSuccess([
                'login_history' => $enhancedHistory,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total_count' => count($loginHistory),
                    'total_pages' => ceil(count($loginHistory) / $limit)
                ],
                'summary' => [
                    'total_logins' => count($loginHistory),
                    'successful_logins' => count(array_filter($loginHistory, fn($l) => $l['event_type'] === AuditLogger::EVENT_LOGIN_SUCCESS)),
                    'failed_logins' => count(array_filter($loginHistory, fn($l) => $l['event_type'] === AuditLogger::EVENT_LOGIN_FAILED)),
                    'unique_ips' => count(array_unique(array_column($loginHistory, 'ip_address'))),
                    'date_range' => [
                        'from' => date('Y-m-d H:i:s', strtotime("-{$days} days")),
                        'to' => date('Y-m-d H:i:s')
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'login_history_error',
                'Error retrieving login history: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Failed to retrieve login history', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Get active sessions with detailed information
     * GET /api/security/sessions
     */
    public function getActiveSessions(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $userId,
                RateLimiter::LIMIT_TYPE_USER,
                'api_requests'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Get active sessions
            $sessions = $this->userRepository->getActiveSessions($userId);
            $currentSessionId = $_SESSION['session_id'] ?? null;

            // Enhance session data
            $enhancedSessions = array_map(function($session) use ($currentSessionId) {
                return [
                    'id' => $session['id'],
                    'ip_address' => $session['ip_address'],
                    'user_agent' => $session['user_agent'],
                    'location' => $this->getLocationFromIP($session['ip_address']),
                    'device_info' => $this->parseUserAgent($session['user_agent']),
                    'created_at' => $session['created_at'],
                    'last_activity' => $session['last_activity'],
                    'expires_at' => $session['expires_at'],
                    'is_current' => $session['id'] === $currentSessionId,
                    'risk_score' => $this->calculateSessionRiskScore($session),
                    'activity_summary' => $this->getSessionActivitySummary($session['id'])
                ];
            }, $sessions);

            // Sort by last activity (most recent first)
            usort($enhancedSessions, function($a, $b) {
                return strtotime($b['last_activity']) - strtotime($a['last_activity']);
            });

            // Log data access
            $this->auditLogger->logDataAccess(
                'active_sessions',
                'read',
                $userId,
                true,
                ['session_count' => count($sessions)]
            );

            $this->sendSuccess([
                'sessions' => $enhancedSessions,
                'summary' => [
                    'total_sessions' => count($sessions),
                    'current_session_id' => $currentSessionId,
                    'unique_ips' => count(array_unique(array_column($sessions, 'ip_address'))),
                    'unique_devices' => count(array_unique(array_column($enhancedSessions, 'device_info')))
                ]
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'active_sessions_error',
                'Error retrieving active sessions: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Failed to retrieve active sessions', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Revoke a specific session
     * DELETE /api/security/sessions/{sessionId}
     */
    public function revokeSession(string $sessionId): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $userId,
                RateLimiter::LIMIT_TYPE_USER,
                'session_management'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Verify session belongs to user
            $session = $this->userRepository->getSessionById($sessionId);
            if (!$session || $session['user_id'] !== $userId) {
                $this->sendError('Session not found', 404, 'SESSION_NOT_FOUND');
                return;
            }

            // Prevent revoking current session
            $currentSessionId = $_SESSION['session_id'] ?? null;
            if ($currentSessionId === $sessionId) {
                $this->sendError('Cannot revoke current session', 400, 'CANNOT_REVOKE_CURRENT_SESSION');
                return;
            }

            // Revoke session
            $success = $this->userRepository->revokeSession($sessionId);
            if (!$success) {
                $this->sendError('Failed to revoke session', 500, 'REVOKE_FAILED');
                return;
            }

            // Log session revocation
            $this->auditLogger->log(
                'session_revoked_dashboard',
                'Session revoked via security dashboard',
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                AuditLogger::SEVERITY_INFO,
                [
                    'revoked_session_id' => $sessionId,
                    'revoked_session_ip' => $session['ip_address'],
                    'revocation_method' => 'security_dashboard'
                ]
            );

            $this->sendSuccess([
                'message' => 'Session revoked successfully',
                'revoked_session' => [
                    'id' => $sessionId,
                    'ip_address' => $session['ip_address'],
                    'revoked_at' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'session_revoke_error',
                'Error revoking session: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Failed to revoke session', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Revoke all sessions except current
     * DELETE /api/security/sessions
     */
    public function revokeAllSessions(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $userId,
                RateLimiter::LIMIT_TYPE_USER,
                'session_management'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            $currentSessionId = $_SESSION['session_id'] ?? null;
            if (!$currentSessionId) {
                $this->sendError('Current session not found', 400, 'CURRENT_SESSION_NOT_FOUND');
                return;
            }

            // Get sessions before revoking for logging
            $sessionsBeforeRevoke = $this->userRepository->getActiveSessions($userId);
            $sessionsToRevoke = array_filter($sessionsBeforeRevoke, fn($s) => $s['id'] !== $currentSessionId);

            // Revoke all sessions except current
            $revokedCount = $this->userRepository->revokeAllSessionsExcept($userId, $currentSessionId);

            // Log mass session revocation
            $this->auditLogger->log(
                'all_sessions_revoked_dashboard',
                "Revoked $revokedCount sessions via security dashboard",
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                AuditLogger::SEVERITY_WARNING,
                [
                    'revoked_count' => $revokedCount,
                    'current_session_id' => $currentSessionId,
                    'revocation_method' => 'security_dashboard',
                    'revoked_sessions' => array_map(fn($s) => [
                        'id' => $s['id'],
                        'ip_address' => $s['ip_address'],
                        'last_activity' => $s['last_activity']
                    ], $sessionsToRevoke)
                ]
            );

            $this->sendSuccess([
                'message' => 'All other sessions revoked successfully',
                'revoked_count' => $revokedCount,
                'current_session_preserved' => $currentSessionId,
                'revoked_at' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'revoke_all_sessions_error',
                'Error revoking all sessions: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Failed to revoke sessions', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Get security events and alerts
     * GET /api/security/events
     */
    public function getSecurityEvents(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $userId,
                RateLimiter::LIMIT_TYPE_USER,
                'api_requests'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            // Get parameters
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
            $severity = $_GET['severity'] ?? null;
            $days = isset($_GET['days']) ? min(365, max(1, (int)$_GET['days'])) : 7;

            // Build search criteria
            $criteria = [
                'user_id' => $userId,
                'date_from' => date('Y-m-d H:i:s', strtotime("-{$days} days"))
            ];

            if ($severity) {
                $criteria['severity'] = $severity;
            }

            // Get security events
            $events = $this->auditLogger->searchLogs($criteria, $limit * $page);

            // Filter for security-relevant events
            $securityEvents = array_filter($events, function($event) {
                return in_array($event['event_category'], [
                    AuditLogger::CATEGORY_SECURITY,
                    AuditLogger::CATEGORY_AUTHENTICATION
                ]) || in_array($event['severity'], [
                    AuditLogger::SEVERITY_WARNING,
                    AuditLogger::SEVERITY_ERROR,
                    AuditLogger::SEVERITY_CRITICAL
                ]);
            });

            // Paginate results
            $offset = ($page - 1) * $limit;
            $paginatedEvents = array_slice($securityEvents, $offset, $limit);

            // Enhance event data
            $enhancedEvents = array_map(function($event) {
                $metadata = json_decode($event['metadata'] ?? '{}', true);
                return [
                    'id' => $event['id'],
                    'event_type' => $event['event_type'],
                    'event_category' => $event['event_category'],
                    'description' => $event['event_description'],
                    'severity' => $event['severity'],
                    'ip_address' => $event['ip_address'],
                    'user_agent' => $event['user_agent'],
                    'location' => $this->getLocationFromIP($event['ip_address']),
                    'threat_level' => $metadata['threat_level'] ?? 'LOW',
                    'created_at' => $event['created_at'],
                    'metadata' => $metadata
                ];
            }, $paginatedEvents);

            // Log data access
            $this->auditLogger->logDataAccess(
                'security_events',
                'read',
                $userId,
                true,
                ['days' => $days, 'severity' => $severity, 'page' => $page]
            );

            $this->sendSuccess([
                'events' => $enhancedEvents,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total_count' => count($securityEvents),
                    'total_pages' => ceil(count($securityEvents) / $limit)
                ],
                'summary' => [
                    'total_events' => count($securityEvents),
                    'by_severity' => $this->groupEventsBySeverity($securityEvents),
                    'by_category' => $this->groupEventsByCategory($securityEvents),
                    'date_range' => [
                        'from' => date('Y-m-d H:i:s', strtotime("-{$days} days")),
                        'to' => date('Y-m-d H:i:s')
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'security_events_error',
                'Error retrieving security events: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Failed to retrieve security events', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * Get security metrics and analytics
     * GET /api/security/metrics
     */
    public function getSecurityMetrics(): void
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            if (!$userId) {
                $this->sendError('Authentication required', 401, 'AUTH_REQUIRED');
                return;
            }

            // Rate limiting
            $rateLimitResult = $this->rateLimiter->checkLimit(
                $userId,
                RateLimiter::LIMIT_TYPE_USER,
                'api_requests'
            );

            if (!$rateLimitResult['allowed']) {
                $this->sendError('Rate limit exceeded', 429, 'RATE_LIMIT_EXCEEDED');
                return;
            }

            $days = isset($_GET['days']) ? min(365, max(1, (int)$_GET['days'])) : 30;

            // Get comprehensive security metrics
            $metrics = [
                'account_security' => $this->getAccountSecurityMetrics($userId),
                'login_analytics' => $this->getLoginAnalytics($userId, $days),
                'session_analytics' => $this->getSessionAnalytics($userId, $days),
                'threat_analysis' => $this->getThreatAnalysis($userId, $days),
                'device_analysis' => $this->getDeviceAnalysis($userId, $days),
                'location_analysis' => $this->getLocationAnalysis($userId, $days)
            ];

            // Log data access
            $this->auditLogger->logDataAccess(
                'security_metrics',
                'read',
                $userId,
                true,
                ['days' => $days, 'metrics_requested' => array_keys($metrics)]
            );

            $this->sendSuccess([
                'metrics' => $metrics,
                'generated_at' => date('Y-m-d H:i:s'),
                'period' => [
                    'days' => $days,
                    'from' => date('Y-m-d H:i:s', strtotime("-{$days} days")),
                    'to' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (Exception $e) {
            $this->auditLogger->logSystemEvent(
                'security_metrics_error',
                'Error retrieving security metrics: ' . $e->getMessage(),
                AuditLogger::SEVERITY_ERROR
            );
            $this->sendError('Failed to retrieve security metrics', 500, 'INTERNAL_ERROR');
        }
    }

    // Private helper methods

    /**
     * Get authenticated user ID from session or JWT
     */
    private function getAuthenticatedUserId(): ?int
    {
        // Try to get from session first
        if (isset($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }

        // Try to get from JWT token
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            $tokenResult = $this->jwtManager->validateToken($token);
            
            if ($tokenResult['success'] && isset($tokenResult['payload']['user_id'])) {
                return (int)$tokenResult['payload']['user_id'];
            }
        }

        return null;
    }

    /**
     * Get user security information
     */
    private function getUserSecurityInfo(int $userId): array
    {
        $user = $this->userRepository->find($userId);
        $securitySettings = $this->userRepository->getSecuritySettings($userId);
        
        return [
            'user_id' => $userId,
            'email' => $user['email'] ?? '',
            'mfa_enabled' => $securitySettings['mfa_enabled'] ?? false,
            'mfa_method' => $securitySettings['mfa_method'] ?? null,
            'email_verified' => $user['email_verified'] ?? false,
            'phone_verified' => $user['phone_verified'] ?? false,
            'account_created' => $user['created_at'] ?? null,
            'last_login' => $user['last_login'] ?? null,
            'security_score' => $this->calculateSecurityScore($user, $securitySettings)
        ];
    }

    /**
     * Get login history for dashboard
     */
    private function getLoginHistory(int $userId, int $limit = 10): array
    {
        $criteria = [
            'user_id' => $userId,
            'event_type' => [AuditLogger::EVENT_LOGIN_SUCCESS, AuditLogger::EVENT_LOGIN_FAILED],
            'date_from' => date('Y-m-d H:i:s', strtotime('-30 days'))
        ];

        $loginHistory = $this->auditLogger->searchLogs($criteria, $limit);
        
        return array_map(function($login) {
            return [
                'event_type' => $login['event_type'],
                'success' => $login['event_type'] === AuditLogger::EVENT_LOGIN_SUCCESS,
                'ip_address' => $login['ip_address'],
                'location' => $this->getLocationFromIP($login['ip_address']),
                'created_at' => $login['created_at']
            ];
        }, $loginHistory);
    }

    /**
     * Get active sessions for dashboard
     */
    private function getActiveSessions(int $userId): array
    {
        $sessions = $this->userRepository->getActiveSessions($userId);
        $currentSessionId = $_SESSION['session_id'] ?? null;

        return array_map(function($session) use ($currentSessionId) {
            return [
                'id' => $session['id'],
                'ip_address' => $session['ip_address'],
                'location' => $this->getLocationFromIP($session['ip_address']),
                'device_info' => $this->parseUserAgent($session['user_agent']),
                'last_activity' => $session['last_activity'],
                'is_current' => $session['id'] === $currentSessionId
            ];
        }, $sessions);
    }

    /**
     * Get security events for dashboard
     */
    private function getSecurityEvents(int $userId, int $limit = 5): array
    {
        return $this->auditLogger->getUserSecurityEvents($userId, $limit);
    }

    /**
     * Get security metrics for dashboard
     */
    private function getSecurityMetrics(int $userId): array
    {
        $criteria = [
            'user_id' => $userId,
            'date_from' => date('Y-m-d H:i:s', strtotime('-30 days'))
        ];

        $allEvents = $this->auditLogger->searchLogs($criteria, 1000);
        
        return [
            'total_events' => count($allEvents),
            'login_attempts' => count(array_filter($allEvents, fn($e) => in_array($e['event_type'], [AuditLogger::EVENT_LOGIN_SUCCESS, AuditLogger::EVENT_LOGIN_FAILED]))),
            'failed_logins' => count(array_filter($allEvents, fn($e) => $e['event_type'] === AuditLogger::EVENT_LOGIN_FAILED)),
            'security_alerts' => count(array_filter($allEvents, fn($e) => in_array($e['severity'], [AuditLogger::SEVERITY_WARNING, AuditLogger::SEVERITY_ERROR, AuditLogger::SEVERITY_CRITICAL]))),
            'unique_ips' => count(array_unique(array_column($allEvents, 'ip_address')))
        ];
    }

    /**
     * Get threat analysis for user
     */
    private function getThreatAnalysis(int $userId, int $days = 30): array
    {
        $criteria = [
            'user_id' => $userId,
            'date_from' => date('Y-m-d H:i:s', strtotime("-{$days} days"))
        ];

        $events = $this->auditLogger->searchLogs($criteria, 1000);
        
        // Analyze threats
        $suspiciousIPs = $this->identifySuspiciousIPs($events);
        $unusualLocations = $this->identifyUnusualLocations($events);
        $failedLoginPatterns = $this->analyzeFailedLoginPatterns($events);
        
        return [
            'threat_level' => $this->calculateOverallThreatLevel($suspiciousIPs, $unusualLocations, $failedLoginPatterns),
            'suspicious_ips' => $suspiciousIPs,
            'unusual_locations' => $unusualLocations,
            'failed_login_patterns' => $failedLoginPatterns,
            'recommendations' => $this->generateSecurityRecommendations($userId, $events)
        ];
    }

    // Additional helper methods for data processing

    private function getLocationFromIP(string $ipAddress): array
    {
        // Simplified location detection - in production, use a proper GeoIP service
        if ($ipAddress === '127.0.0.1' || $ipAddress === 'localhost') {
            return ['country' => 'Local', 'city' => 'Local', 'region' => 'Local'];
        }
        
        // Mock location data
        return ['country' => 'Unknown', 'city' => 'Unknown', 'region' => 'Unknown'];
    }

    private function parseUserAgent(string $userAgent): array
    {
        // Simplified user agent parsing
        $deviceInfo = [
            'browser' => 'Unknown',
            'os' => 'Unknown',
            'device_type' => 'Unknown'
        ];

        if (strpos($userAgent, 'Chrome') !== false) {
            $deviceInfo['browser'] = 'Chrome';
        } elseif (strpos($userAgent, 'Firefox') !== false) {
            $deviceInfo['browser'] = 'Firefox';
        } elseif (strpos($userAgent, 'Safari') !== false) {
            $deviceInfo['browser'] = 'Safari';
        }

        if (strpos($userAgent, 'Windows') !== false) {
            $deviceInfo['os'] = 'Windows';
        } elseif (strpos($userAgent, 'Mac') !== false) {
            $deviceInfo['os'] = 'macOS';
        } elseif (strpos($userAgent, 'Linux') !== false) {
            $deviceInfo['os'] = 'Linux';
        }

        if (strpos($userAgent, 'Mobile') !== false) {
            $deviceInfo['device_type'] = 'Mobile';
        } else {
            $deviceInfo['device_type'] = 'Desktop';
        }

        return $deviceInfo;
    }

    private function calculateLoginRiskScore(array $login): int
    {
        $score = 0;
        
        // Failed login increases risk
        if ($login['event_type'] === AuditLogger::EVENT_LOGIN_FAILED) {
            $score += 30;
        }
        
        // Unusual IP or location would increase risk (simplified)
        if ($login['ip_address'] !== '127.0.0.1') {
            $score += 10;
        }
        
        return min(100, $score);
    }

    private function calculateSessionRiskScore(array $session): int
    {
        $score = 0;
        
        // Old sessions are riskier
        $daysSinceCreated = (time() - strtotime($session['created_at'])) / (24 * 3600);
        if ($daysSinceCreated > 30) {
            $score += 20;
        }
        
        // Inactive sessions are riskier
        $daysSinceActivity = (time() - strtotime($session['last_activity'])) / (24 * 3600);
        if ($daysSinceActivity > 7) {
            $score += 15;
        }
        
        return min(100, $score);
    }

    private function getSessionActivitySummary(string $sessionId): array
    {
        // This would query for activities in this session
        return [
            'total_requests' => 0,
            'last_endpoint' => 'N/A',
            'duration_minutes' => 0
        ];
    }

    private function calculateSecurityScore(array $user, array $securitySettings): int
    {
        $score = 50; // Base score
        
        if ($securitySettings['mfa_enabled']) {
            $score += 30;
        }
        
        if ($user['email_verified']) {
            $score += 10;
        }
        
        if ($user['phone_verified']) {
            $score += 10;
        }
        
        return min(100, $score);
    }

    private function groupEventsBySeverity(array $events): array
    {
        $grouped = [];
        foreach ($events as $event) {
            $severity = $event['severity'];
            $grouped[$severity] = ($grouped[$severity] ?? 0) + 1;
        }
        return $grouped;
    }

    private function groupEventsByCategory(array $events): array
    {
        $grouped = [];
        foreach ($events as $event) {
            $category = $event['event_category'];
            $grouped[$category] = ($grouped[$category] ?? 0) + 1;
        }
        return $grouped;
    }

    // Placeholder methods for advanced analytics
    private function getLoginAnalytics(int $userId, int $days): array { return []; }
    private function getSessionAnalytics(int $userId, int $days): array { return []; }
    private function getDeviceAnalysis(int $userId, int $days): array { return []; }
    private function getLocationAnalysis(int $userId, int $days): array { return []; }
    private function getAccountSecurityMetrics(int $userId): array { return []; }
    private function identifySuspiciousIPs(array $events): array { return []; }
    private function identifyUnusualLocations(array $events): array { return []; }
    private function analyzeFailedLoginPatterns(array $events): array { return []; }
    private function calculateOverallThreatLevel(array $ips, array $locations, array $patterns): string { return 'LOW'; }
    private function generateSecurityRecommendations(int $userId, array $events): array { return []; }

    /**
     * Send success response
     */
    private function sendSuccess(array $data, string $message = 'Success'): void
    {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Send error response
     */
    private function sendError(string $message, int $statusCode = 400, string $code = 'ERROR'): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => $code,
            'timestamp' => date('c')
        ]);
    }
}