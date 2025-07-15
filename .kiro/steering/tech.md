# Technology Stack

## Runtime & Deployment
- **Wasmer**: Edge computing platform for deployment
- **WASI**: WebAssembly System Interface for cross-platform execution
- **PHP 8.3.401**: Primary programming language

## Database
- **MySQL**: Primary database engine with environment-based configuration

## Configuration Files
- `wasmer.toml`: Wasmer runtime configuration and dependencies
- `app.yaml`: Application deployment configuration with environment variables
- `composer.json`: PHP dependency management

## Common Commands

### Development
```bash
# Run a service locally (from service directory)
wasmer run

# Install PHP dependencies
composer install
```

### Service Management
Each service runs on localhost:8080 when started locally. Services are configured to:
- Mount `/app` directory for application code
- Mount `/config` directory for configuration files
- Use single concurrency scaling mode

## Environment Variables
Services use environment-based configuration for:
- `DB_HOST`, `DB_PORT`, `DB_NAME`: Database connection
- `DB_USERNAME`, `DB_PASSWORD`: Database credentials

All services follow the same deployment pattern with Wasmer.io App.v0 specification.