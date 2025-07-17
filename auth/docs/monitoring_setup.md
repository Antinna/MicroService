# Authentication Service Monitoring Setup

This document provides comprehensive guidance for setting up monitoring and alerting for the Authentication Service.

## Health Check Endpoints

The service provides multiple health check endpoints for different monitoring needs:

### Basic Health Check
- **Endpoint**: `GET /health`
- **Purpose**: Simple health status for load balancers
- **Response**: Basic status information
- **Use Case**: Load balancer health checks, simple monitoring

```bash
curl http://localhost:8080/auth/health
```

### Detailed Health Check
- **Endpoint**: `GET /health/detailed`
- **Purpose**: Comprehensive health information
- **Response**: Detailed service status, system metrics, performance data
- **Use Case**: Monitoring dashboards, troubleshooting

```bash
curl http://localhost:8080/auth/health/detailed
```

### Readiness Probe
- **Endpoint**: `GET /health/ready`
- **Purpose**: Kubernetes readiness probe
- **Response**: Service readiness status
- **Use Case**: Container orchestration, traffic routing decisions

```bash
curl http://localhost:8080/auth/health/ready
```

### Liveness Probe
- **Endpoint**: `GET /health/live`
- **Purpose**: Kubernetes liveness probe
- **Response**: Service liveness status
- **Use Case**: Container restart decisions

```bash
curl http://localhost:8080/auth/health/live
```

### Metrics Endpoint
- **Endpoint**: `GET /health/metrics`
- **Purpose**: Performance and operational metrics
- **Response**: Detailed metrics for monitoring systems
- **Use Case**: Prometheus, Grafana, custom monitoring

```bash
curl http://localhost:8080/auth/health/metrics
```

### Service Information
- **Endpoint**: `GET /health/info`
- **Purpose**: Service metadata and configuration
- **Response**: Version, features, runtime information
- **Use Case**: Service discovery, configuration validation

```bash
curl http://localhost:8080/auth/health/info
```

## Monitoring Components

### 1. Service Health Checker
- **File**: `Services/ServiceHealthChecker.php`
- **Purpose**: Core health checking logic
- **Features**:
  - Database connectivity testing
  - JWT service validation
  - Session service testing
  - Rate limiter functionality
  - External API connectivity
  - System resource monitoring
  - Memory and disk usage tracking

### 2. Health Controller
- **File**: `Controllers/HealthController.php`
- **Purpose**: HTTP endpoints for health checks
- **Features**:
  - Multiple endpoint types
  - Proper HTTP status codes
  - JSON response formatting
  - Error handling and logging
  - Performance metrics collection

### 3. Health Routes
- **File**: `Routes/HealthRoutes.php`
- **Purpose**: Route registration for health endpoints
- **Features**:
  - Multiple endpoint aliases
  - Kubernetes-compatible endpoints
  - Simple ping endpoint
  - Auto-registration capability

## Kubernetes Integration

### Deployment Configuration

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: auth-service
spec:
  template:
    spec:
      containers:
      - name: auth-service
        image: auth-service:latest
        ports:
        - containerPort: 8080
        livenessProbe:
          httpGet:
            path: /health/live
            port: 8080
          initialDelaySeconds: 30
          periodSeconds: 10
          timeoutSeconds: 5
          failureThreshold: 3
        readinessProbe:
          httpGet:
            path: /health/ready
            port: 8080
          initialDelaySeconds: 5
          periodSeconds: 5
          timeoutSeconds: 3
          failureThreshold: 3
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
```

### Service Configuration

```yaml
apiVersion: v1
kind: Service
metadata:
  name: auth-service
spec:
  selector:
    app: auth-service
  ports:
  - port: 80
    targetPort: 8080
  type: ClusterIP
```

## Prometheus Integration

### Metrics Collection

The `/health/metrics` endpoint provides metrics in a format suitable for Prometheus:

```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'auth-service'
    static_configs:
      - targets: ['auth-service:8080']
    metrics_path: '/health/metrics'
    scrape_interval: 30s
    scrape_timeout: 10s
```

### Key Metrics

- **Authentication Metrics**:
  - `auth_login_attempts_total`
  - `auth_login_success_rate`
  - `auth_mfa_attempts_total`
  - `auth_active_sessions`

- **Performance Metrics**:
  - `auth_response_time_ms`
  - `auth_memory_usage_mb`
  - `auth_database_connections`

- **Security Metrics**:
  - `auth_security_events_total`
  - `auth_rate_limit_violations`
  - `auth_suspicious_activities`

## Grafana Dashboard

### Dashboard Configuration

```json
{
  "dashboard": {
    "title": "Authentication Service Monitoring",
    "panels": [
      {
        "title": "Service Health Status",
        "type": "stat",
        "targets": [
          {
            "expr": "auth_service_health_status",
            "legendFormat": "Health Status"
          }
        ]
      },
      {
        "title": "Login Success Rate",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(auth_login_success_total[5m]) / rate(auth_login_attempts_total[5m]) * 100",
            "legendFormat": "Success Rate %"
          }
        ]
      },
      {
        "title": "Response Time",
        "type": "graph",
        "targets": [
          {
            "expr": "auth_response_time_ms",
            "legendFormat": "Response Time (ms)"
          }
        ]
      },
      {
        "title": "Active Sessions",
        "type": "graph",
        "targets": [
          {
            "expr": "auth_active_sessions",
            "legendFormat": "Active Sessions"
          }
        ]
      }
    ]
  }
}
```

## Alerting Rules

### Prometheus Alerting

```yaml
# alerts.yml
groups:
- name: auth-service
  rules:
  - alert: AuthServiceDown
    expr: up{job="auth-service"} == 0
    for: 1m
    labels:
      severity: critical
    annotations:
      summary: "Authentication service is down"
      description: "Authentication service has been down for more than 1 minute"

  - alert: HighLoginFailureRate
    expr: rate(auth_login_failures_total[5m]) / rate(auth_login_attempts_total[5m]) > 0.5
    for: 2m
    labels:
      severity: warning
    annotations:
      summary: "High login failure rate"
      description: "Login failure rate is above 50% for 2 minutes"

  - alert: DatabaseConnectionIssue
    expr: auth_database_health_status != 1
    for: 30s
    labels:
      severity: critical
    annotations:
      summary: "Database connection issue"
      description: "Authentication service cannot connect to database"

  - alert: HighMemoryUsage
    expr: auth_memory_usage_percent > 90
    for: 5m
    labels:
      severity: warning
    annotations:
      summary: "High memory usage"
      description: "Memory usage is above 90% for 5 minutes"

  - alert: SecurityIncident
    expr: increase(auth_security_events_total[1m]) > 10
    for: 0s
    labels:
      severity: critical
    annotations:
      summary: "Security incident detected"
      description: "High number of security events detected"
```

## Load Balancer Configuration

### HAProxy Configuration

```
backend auth-service
    balance roundrobin
    option httpchk GET /health
    http-check expect status 200
    server auth1 auth-service-1:8080 check inter 10s
    server auth2 auth-service-2:8080 check inter 10s
    server auth3 auth-service-3:8080 check inter 10s
```

### NGINX Configuration

```nginx
upstream auth_backend {
    server auth-service-1:8080;
    server auth-service-2:8080;
    server auth-service-3:8080;
}

server {
    location /health {
        access_log off;
        proxy_pass http://auth_backend;
        proxy_set_header Host $host;
    }
    
    location / {
        proxy_pass http://auth_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

## Logging Integration

### Structured Logging

The service uses structured logging for better monitoring integration:

```json
{
  "timestamp": "2023-07-15T10:30:45Z",
  "level": "INFO",
  "message": "Health check completed",
  "context": {
    "status": "healthy",
    "response_time_ms": 45.2,
    "services_checked": 8,
    "critical_issues": 0
  }
}
```

### Log Aggregation

Configure log shipping to centralized logging systems:

```yaml
# filebeat.yml
filebeat.inputs:
- type: log
  paths:
    - /var/log/auth-service/*.log
  fields:
    service: auth-service
    environment: production
  json.keys_under_root: true

output.elasticsearch:
  hosts: ["elasticsearch:9200"]
  index: "auth-service-%{+yyyy.MM.dd}"
```

## Monitoring Best Practices

### 1. Health Check Frequency
- **Load Balancer**: Every 10-30 seconds
- **Kubernetes Probes**: Every 5-10 seconds
- **Monitoring Systems**: Every 30-60 seconds
- **Detailed Checks**: Every 5-15 minutes

### 2. Alert Thresholds
- **Critical**: Service down, database unavailable, security incidents
- **Warning**: High error rates, resource usage above 80%, slow response times
- **Info**: Service restarts, configuration changes, maintenance events

### 3. Response Time Targets
- **Health Checks**: < 1 second
- **Basic Operations**: < 500ms
- **Complex Operations**: < 2 seconds
- **Database Queries**: < 100ms

### 4. Resource Monitoring
- **Memory Usage**: Alert at 80%, critical at 90%
- **CPU Usage**: Alert at 70%, critical at 85%
- **Disk Space**: Alert at 80%, critical at 90%
- **Database Connections**: Alert at 80% of pool size

## Troubleshooting Guide

### Common Issues

1. **Service Unhealthy**
   - Check database connectivity
   - Verify JWT configuration
   - Review system resources
   - Check external API availability

2. **High Response Times**
   - Monitor database performance
   - Check memory usage
   - Review concurrent connections
   - Analyze slow queries

3. **Authentication Failures**
   - Check rate limiting configuration
   - Review security logs
   - Verify external service status
   - Monitor suspicious activities

### Debug Commands

```bash
# Check service health
curl -s http://localhost:8080/auth/health | jq

# Get detailed metrics
curl -s http://localhost:8080/auth/health/metrics | jq

# Test database connectivity
curl -s http://localhost:8080/auth/health/detailed | jq '.services.database'

# Monitor real-time logs
tail -f /var/log/auth-service/application.log | jq

# Check system resources
curl -s http://localhost:8080/auth/health/detailed | jq '.system_metrics'
```

This monitoring setup provides comprehensive visibility into the Authentication Service's health, performance, and security status, enabling proactive issue detection and resolution.