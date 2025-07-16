# Technology Stack

## Core Technologies
- **Language**: PHP 8.1+ (supports 8.1, 8.2, 8.3, 8.4)
- **Database**: MySQL with PDO extension
- **Deployment**: Wasmer.io platform
- **CI/CD**: GitHub Actions with parallel deployment

## Dependencies
- **vlucas/phpdotenv**: Environment configuration management
- **Composer**: PHP dependency management with PSR-4 autoloading

## Infrastructure
- **Platform**: Wasmer.io with single concurrency scaling
- **Notifications**: Firebase (shared across all microservices)
- **Communication**: SMS and Email integration

## Environment Configuration
All services use standardized environment variables:
- `DB_HOST`, `DB_PORT`, `DB_NAME`: Database connection
- `DB_USERNAME`, `DB_PASSWORD`: Database credentials

When adding new environment variables, always:
1. Document the variable purpose and which microservice uses it
2. Update the relevant README file
3. Add to the service's app.yaml configuration

## Common Commands

### Development
```bash
# Install PHP dependencies
cd [service]/app
composer install --prefer-dist
composer dump-autoload --optimize
```

### Deployment
```bash
# Deploy single service
cd [service]
wasmer deploy --token=$TOKEN --non-interactive --no-wait --no-persist-id

# Deploy all services (handled by GitHub Actions)
git push origin [branch]
```

### Project Setup
```bash
# Each service follows the same structure
# Ensure wasmer.toml exists in service root
# Ensure composer.json exists in service/app/
```