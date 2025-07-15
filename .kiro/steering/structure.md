# Project Structure

## Root Level Organization
The project follows a microservices architecture with each service in its own directory:

```
├── auth/           # Authentication service
├── delivery/       # Delivery management service  
├── multivendor/    # Multi-vendor marketplace service
├── pay/            # Payment processing service
└── .kiro/          # Kiro IDE configuration
```

## Service Structure Pattern
Each service follows the same standardized structure:

```
service-name/
├── app/                    # PHP application code
│   ├── bootstrap/          # Application bootstrapping
│   ├── config/             # Service-specific configuration
│   ├── Helpers/            # Utility classes and helpers
│   ├── composer.json       # PHP dependencies
│   └── info.php           # Service information
├── config/                 # Runtime configuration files
├── app.yaml               # Wasmer deployment configuration
├── wasmer.toml            # Wasmer runtime settings
└── README.md              # Service documentation
```

## Conventions
- Each service is completely independent and self-contained
- All services use identical deployment configuration patterns
- Configuration is externalized through environment variables
- Services communicate via APIs (no shared databases or files)
- Each service has its own dependency management via composer.json

## Adding New Services
When creating a new service:
1. Create directory following the naming convention (lowercase, descriptive)
2. Copy the standard structure from an existing service
3. Update `app.yaml` with unique `name` and `app_id`
4. Customize the `/app` directory with service-specific PHP code
5. Update the service README.md with purpose and API documentation