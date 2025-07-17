# Auth Service

This is the authentication microservice for the platform, providing centralized authentication and authorization services for all other microservices.

## Features

- **Multi-method Authentication**: Supports passkeys, magic links, social logins, and MFA
- **JWT Token Management**: Secure token generation, validation, and refresh
- **Social Authentication**: Integration with Google, Facebook, Apple, GitHub, Amazon, X/Twitter, Discord, and Microsoft
- **Multi-Factor Authentication**: TOTP and SMS-based MFA support
- **Service-to-Service Authentication**: APIs for other microservices to validate tokens and check permissions
- **Security Monitoring**: Comprehensive audit logging and threat detection
- **Mobile Optimization**: Biometric authentication and offline support

## Architecture

The auth service acts as the central authentication hub that all other microservices depend on for:
- Token validation
- Permission checking
- User role management
- Service authentication

## Authentication Methods

| Auth Type | Typical Usage | Notes |
|-----------|---------------|-------|
| Passkeys (WebAuthn) | Passwordless, biometric + hardware keys | Best phishing-resistant method |
| Magic Link | Email passwordless login | Great UX, email-dependent |
| Social Logins | OAuth2 via Google/Facebook/Apple/etc. | Easy onboarding, external dependency |
| Passwords + MFA | Traditional login plus OTP or security key | Baseline fallback and security |
| Phone Auth (SMS/OTP) | For fallback MFA or direct login | Use cautiously due to SIM swap risks |
| API Tokens | Programmatic access | Scoped, revocable |

## Installation

1. Install dependencies:
```bash
cd auth/app
composer install
```

2. Configure environment variables:
```bash
cp .env.example .env
# Edit .env with your configuration
```

3. Run the service:
```bash
php -S localhost:8000 index.php
```

## API Endpoints

### Public Endpoints
- `GET /health` - Health check
- `GET /info` - Service information
- `POST /auth/login` - User login
- `POST /auth/register` - User registration

### Internal Endpoints (for other microservices)
- `POST /internal/validate-token` - Token validation
- `POST /internal/check-permissions` - Permission checking
- `GET /internal/user-roles` - User role information

## Testing

Run tests with PHPUnit:
```bash
./vendor/bin/phpunit
```

## Dependencies

All other microservices (multivendor, pay, social, delivery) depend on this auth service for authentication and authorization.
