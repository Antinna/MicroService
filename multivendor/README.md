# Multivendor Service

Core vendor management service for dairy and vegetable delivery platform with comprehensive admin panel and migration management.

## Features

- **Vendor Management**: Complete vendor registration, profile management, and role-based access
- **Product Catalog**: Perishable-specific product management with expiry tracking and batch traceability
- **Subscription Engine**: Automated subscription management and order generation with capacity checking
- **Delivery Coordination**: Smart delivery slot management and order consolidation
- **Admin Panel**: Comprehensive web interface for system administration and monitoring
- **Migration Management**: Database migration orchestration across all microservices
- **Health Monitoring**: Real-time service health monitoring with alerting
- **Compliance Tracking**: FSSAI licensing and food safety compliance management
- **Analytics Dashboard**: Vendor performance metrics, sales reports, and customer feedback
- **Payment Integration**: Vendor payouts, promotion management, and fee calculations

## Requirements

- PHP 8.1 or higher
- MySQL 5.7 or higher
- Composer
- Required PHP extensions: PDO, JSON, OpenSSL, mbstring

## Installation & Deployment

### Local Development

1. **Install dependencies**
   ```bash
   cd multivendor/app
   composer install
   ```

2. **Configure environment**
   ```bash
   # Set environment variables or create .env file
   export DB_HOST=localhost
   export DB_NAME=multivendor_db
   export DB_USERNAME=root
   export DB_PASSWORD=your_password
   export ADMIN_USERNAME=admin
   export ADMIN_PASSWORD=secure_password
   ```

3. **Run migrations**
   ```bash
   php migrate.php
   ```

4. **Start development server**
   ```bash
   php -S localhost:8080 -t app
   ```

### Production Deployment (Wasmer.io)

1. **Configure environment variables in app.yaml**
2. **Deploy using Wasmer CLI**
   ```bash
   wasmer deploy --token=$WASMER_TOKEN --non-interactive
   ```
3. **Verify deployment**
   ```bash
   php deploy.php --status
   ```

## Environment Variables

### Database Configuration
- `DB_HOST`: Database host (default: localhost)
- `DB_PORT`: Database port (default: 3306)
- `DB_NAME`: Database name (default: multivendor_db)
- `DB_USERNAME`: Database username (default: root)
- `DB_PASSWORD`: Database password (required)

### Admin Panel
- `ADMIN_USERNAME`: Admin panel username (default: admin)
- `ADMIN_PASSWORD`: Admin panel password (default: admin)
- `ADMIN_SESSION_TIMEOUT`: Session timeout in seconds (default: 3600)

### Application Settings
- `APP_ENV`: Environment (production/development/staging)
- `APP_DEBUG`: Debug mode (true/false)
- `LOG_LEVEL`: Logging level (DEBUG/INFO/WARNING/ERROR)
- `LOG_PATH`: Log file directory (default: /tmp/logs)

### Service URLs (for migration orchestration)
- `AUTH_SERVICE_URL`: Authentication service URL
- `PAY_SERVICE_URL`: Payment service URL
- `SOCIAL_SERVICE_URL`: Social service URL
- `DELIVERY_SERVICE_URL`: Delivery service URL

### External Services
- `FIREBASE_PROJECT_ID`: Firebase project ID
- `FIREBASE_PRIVATE_KEY`: Firebase private key
- `SMS_API_KEY`: SMS service API key
- `EMAIL_SMTP_HOST`: SMTP server host
- `EMAIL_SMTP_PORT`: SMTP server port (default: 587)
- `EMAIL_SMTP_USERNAME`: SMTP username
- `EMAIL_SMTP_PASSWORD`: SMTP password

## API Endpoints

### Health & Status
- `GET /health` - Service health status
- `GET /info` - Service information

### Admin Panel
- `GET /admin` - Admin panel web interface
- `POST /admin/login` - Admin authentication
- `POST /admin/logout` - Admin logout
- `GET /admin/session` - Get session info
- `POST /admin/extend-session` - Extend session

### Migration Management
- `POST /admin/migration/start` - Start migration orchestration
- `GET /admin/migration/progress/{id}` - Get migration progress
- `GET /admin/migration/progress/{id}/stream` - Real-time progress stream
- `POST /admin/migration/cancel/{id}` - Cancel running migration
- `GET /admin/migration/history` - Get migration history

### Service Health
- `GET /admin/health` - Check all services health
- `GET /admin/health/stream` - Real-time health monitoring

### Vendor Management
- `POST /api/vendors` - Register new vendor
- `GET /api/vendors/{id}` - Get vendor details
- `PUT /api/vendors/{id}` - Update vendor profile
- `POST /api/vendors/{id}/roles` - Assign vendor roles

### Product Management
- `POST /api/products` - Create product
- `GET /api/products` - List products
- `PUT /api/products/{id}` - Update product
- `DELETE /api/products/{id}` - Delete product

### Subscription Management
- `POST /api/subscriptions` - Create subscription
- `GET /api/subscriptions` - List subscriptions
- `PUT /api/subscriptions/{id}` - Update subscription
- `POST /api/subscriptions/{id}/pause` - Pause subscription

## Admin Panel

Access the comprehensive admin panel at `/admin` with your configured credentials.

### Features
- **Real-time Service Health Monitoring**: Live status of all microservices
- **Migration Management**: Database migration orchestration across services
- **One-Click Setup**: Initial system installation
- **System Information**: Platform and performance metrics
- **Migration History**: Complete audit trail of all migrations

### Default Credentials
- Username: `admin`
- Password: `admin`

**⚠️ Important: Change default credentials in production!**

### Admin Panel Capabilities
- Start and monitor database migrations across all microservices
- Real-time health monitoring with automatic refresh
- Migration progress tracking with WebSocket connections
- Service health dashboard with status indicators
- Migration history and rollback capabilities

## Database Migrations

### Local Migrations
```bash
# Check migration status
php migrate.php --status

# Run all pending migrations
php migrate.php

# Dry run (preview changes)
php migrate.php --dry-run
```

### Cross-Service Migration Orchestration
The admin panel provides powerful migration orchestration:

1. **Access Admin Panel**: Navigate to `/admin`
2. **Start Migration**: Click "Start Migration" button
3. **Configure Options**: 
   - Parallel execution
   - Continue on error
   - Add notes
4. **Monitor Progress**: Real-time progress tracking
5. **View History**: Complete migration audit trail

### Migration Features
- **Multi-service coordination**: Manages migrations across 5 microservices
- **Real-time progress**: Live updates via WebSocket connections
- **Error handling**: Detailed error reporting and recovery options
- **History tracking**: Complete audit trail with rollback capabilities
- **Health checks**: Pre-migration service health verification

## Health Monitoring

### Health Check Endpoint
```bash
curl https://your-domain.com/health
```

### Service Health Dashboard
The admin panel provides comprehensive health monitoring:
- Real-time status updates
- Response time tracking
- System resource monitoring
- Service dependency checking
- Automatic alerting for issues

## Security

### Authentication
- Secure session-based admin authentication
- IP validation and session timeout
- CSRF protection enabled
- Timing-safe credential comparison

### Data Protection
- Sensitive data automatically redacted from logs
- Secure headers for all responses
- Environment-specific security settings

### Production Security
- HTTPS enforcement
- Secure session cookies
- Content Security Policy headers
- XSS and CSRF protection

## Performance & Scaling

### Resource Configuration
- Memory limit: 512MB (configurable)
- Execution timeout: 300 seconds
- Auto-scaling: 1-5 instances based on CPU/memory

### Caching
- Health check result caching
- Configuration caching in production
- Database query optimization

## Troubleshooting

### Common Issues

1. **Database Connection Failed**
   - Verify database credentials in environment variables
   - Check database server accessibility
   - Test connection: `php health-check.php`

2. **Admin Panel Access Issues**
   - Verify `ADMIN_USERNAME` and `ADMIN_PASSWORD`
   - Check session configuration
   - Clear browser cache and cookies

3. **Migration Failures**
   - Check database permissions
   - Verify service connectivity
   - Review migration logs in admin panel

4. **Service Health Issues**
   - Verify service URLs in environment variables
   - Check network connectivity between services
   - Review service-specific logs

### Debug Mode
```bash
export APP_DEBUG=true
export LOG_LEVEL=DEBUG
```

### Deployment Verification
```bash
# Run deployment checks
php deploy.php --dry-run

# Full deployment with verification
php deploy.php

# Check deployment status
php deploy.php --status
```

## Development

### Running Tests
```bash
cd app
composer install
vendor/bin/phpunit
```

### Local Development Setup
```bash
# Install dependencies
composer install

# Set up environment
export APP_ENV=development
export APP_DEBUG=true
export DB_HOST=localhost
export DB_NAME=multivendor_dev

# Run migrations
php migrate.php

# Start development server
php -S localhost:8080 -t app
```

## Architecture

The service follows a comprehensive modular architecture:

- **Controllers**: API endpoints and admin panel
- **Services**: Business logic and orchestration
- **Repositories**: Data access layer with PDO
- **Middleware**: Request processing and logging
- **Database**: Migration system with history tracking
- **Admin Panel**: Web interface with real-time features
- **Health Monitoring**: Service availability checking
- **Error Handling**: Centralized error management
- **Logging**: Structured logging with sensitive data protection

## License

This project is licensed under the MIT License.