# Project Structure

## Repository Organization
This is a monorepo containing multiple microservices, each following a consistent structure pattern.

## Root Level
```
├── .github/workflows/     # CI/CD deployment workflows
├── .kiro/                # Kiro IDE configuration and steering
├── auth/                 # Authentication microservice
├── delivery/             # Delivery management microservice
├── multivendor/          # Vendor management microservice
├── pay/                  # Payment processing microservice
├── social/               # Social features microservice
└── ReadMe.md            # Main project documentation
```

## Microservice Structure
Each microservice follows this standardized pattern:
```
[service-name]/
├── app/                  # Application code
│   ├── bootstrap/        # Application bootstrapping
│   ├── config/          # Service-specific configuration
│   ├── Helpers/         # Utility functions and helpers
│   └── composer.json    # PHP dependencies and autoloading
├── config/              # Runtime configuration (php.ini, etc.)
├── app.yaml            # Wasmer deployment configuration
├── wasmer.toml         # Wasmer project configuration
└── README.md           # Service-specific documentation
```

## Naming Conventions
- **Services**: Lowercase directory names (auth, pay, social, delivery, multivendor)
- **Namespaces**: PascalCase with vendor prefix (`Antinna\Auth\`, `Antinna\Pay\`)
- **Autoloading**: PSR-4 standard with service root as namespace root

## Configuration Management
- **Environment**: Managed via app.yaml with standardized variable names
- **Service Config**: Each service has its own config/ directory
- **Shared Standards**: All services use identical database connection variables

## Development Guidelines
- Each microservice is independently deployable
- Shared configurations should be documented in main README
- New services should follow the established directory structure
- All services must include wasmer.toml and app.yaml for deployment