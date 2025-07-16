# Multivendor Service

Core vendor management service for dairy and vegetable delivery platform.

## Features

- **Vendor Onboarding**: Registration, KYC, location, business type classification
- **Product Management**: Perishable goods with expiry tracking, organic certification, batch traceability
- **Subscription Engine**: Automated recurring orders with capacity management
- **Delivery Coordination**: Cold-chain logistics and freshness windows
- **Analytics Dashboard**: Sales reports, performance metrics, customer feedback
- **Payment Integration**: Vendor payouts and promotion management
- **Compliance**: FSSAI licensing and food safety traceability
- **Admin Panel**: Cross-microservice migration management

## Environment Variables

### Database Configuration
- `DB_HOST`: Database host (default: localhost)
- `DB_PORT`: Database port (default: 3306)
- `DB_NAME`: Database name (default: multivendor)
- `DB_USERNAME`: Database username (default: root)
- `DB_PASSWORD`: Database password (default: empty)

### Admin Panel
- `ADMIN_USERNAME`: Admin panel username (default: admin)
- `ADMIN_PASSWORD`: Admin panel password (default: admin)

### Application Settings
- `APP_ENV`: Environment (production/development)
- `APP_DEBUG`: Debug mode (true/false)

### Notification Services
- `FIREBASE_SERVER_KEY`: Firebase server key for push notifications
- `SMS_API_KEY`: SMS service API key
- `EMAIL_SMTP_HOST`: SMTP server host
- `EMAIL_SMTP_PORT`: SMTP server port (default: 587)
- `EMAIL_USERNAME`: Email service username
- `EMAIL_PASSWORD`: Email service password

## API Endpoints

### Health Check
- `GET /health` - Service health status
- `GET /info` - Service information

### Admin Panel
- `GET /admin` - Admin panel interface
- `POST /admin/migrate` - Run migrations across all services

## Development

```bash
# Install dependencies
cd multivendor/app
composer install

# Run the service
php -S localhost:8000 index.php
```

## Architecture

The service follows a modular architecture with:
- **Services**: Business logic layer
- **Repositories**: Data access layer
- **Controllers**: API endpoints
- **Models**: Data models
- **Jobs**: Background processing
- **Middleware**: Request processing