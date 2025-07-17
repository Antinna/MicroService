# Authentication Service Integration Guide

## Introduction

This guide provides step-by-step instructions for integrating the Authentication Service into your applications. The service offers multiple authentication methods, including password-based, biometric, passkey (WebAuthn), magic links, and multi-factor authentication.

## Prerequisites

Before you begin integration, ensure you have:

1. An account with access to the Authentication Service dashboard
2. API credentials (client ID and secret)
3. Development environment set up for your platform
4. Basic understanding of authentication concepts

## Getting Started

### 1. Register Your Application

First, register your application in the Authentication Service dashboard:

1. Log in to the dashboard at `https://dashboard.example.com`
2. Navigate to "Applications" and click "Create New Application"
3. Enter your application details:
   - Name: Your application name
   - Redirect URIs: URLs where users will be redirected after authentication
   - Allowed Origins: Domains that can make requests to the auth service
4. Select the authentication methods you want to enable
5. Click "Create" to generate your client ID and secret

### 2. Install the SDK

Choose the appropriate SDK for your platform:

#### JavaScript (Web)

```bash
npm install @example/auth-js-sdk
# or
yarn add @example/auth-js-sdk
```

#### Android

Add to your `build.gradle`:

```gradle
dependencies {
    implementation 'com.example:auth-android-sdk:1.0.0'
}
```

#### iOS (Swift Package Manager)

Add the package dependency to your `Package.swift`:

```swift
dependencies: [
    .package(url: "https://github.com/example/auth-ios-sdk.git", from: "1.0.0")
]
```

#### Flutter

```bash
flutter pub add example_auth_sdk
```

### 3. Initialize the SDK

#### JavaScript

```javascript
import { AuthClient } from '@example/auth-js-sdk';

const authClient = new AuthClient({
  clientId: 'your_client_id',
  redirectUri: 'https://your-app.com/callback',
  apiUrl: 'https://api.example.com/auth'
});
```

#### Android (Kotlin)

```kotlin
import com.example.auth.AuthClient

val authClient = AuthClient.Builder(context)
    .setClientId("your_client_id")
    .setRedirectUri("com.yourapp://callback")
    .setApiUrl("https://api.example.com/auth")
    .build()
```

#### iOS (Swift)

```swift
import ExampleAuthSDK

let authClient = AuthClient(
    clientId: "your_client_id",
    redirectUri: "com.yourapp://callback",
    apiUrl: "https://api.example.com/auth"
)
```

#### Flutter

```dart
import 'package:example_auth_sdk/example_auth_sdk.dart';

final authClient = AuthClient(
  clientId: 'your_client_id',
  redirectUri: 'com.yourapp://callback',
  apiUrl: 'https://api.example.com/auth',
);
```

## Authentication Flows

### Password-Based Authentication

#### User Registration

##### JavaScript

```javascript
try {
  const result = await authClient.register({
    email: 'user@example.com',
    password: 'securePassword123!',
    name: 'John Doe',
    phoneNumber: '+1234567890'
  });
  
  console.log('Registration successful:', result);
  // Handle verification if required
} catch (error) {
  console.error('Registration failed:', error);
}
```

##### Android (Kotlin)

```kotlin
authClient.register(
    email = "user@example.com",
    password = "securePassword123!",
    name = "John Doe",
    phoneNumber = "+1234567890",
    callback = object : AuthCallback<RegisterResult> {
        override fun onSuccess(result: RegisterResult) {
            // Handle successful registration
        }
        
        override fun onError(error: AuthError) {
            // Handle error
        }
    }
)
```

##### iOS (Swift)

```swift
authClient.register(
    email: "user@example.com",
    password: "securePassword123!",
    name: "John Doe",
    phoneNumber: "+1234567890"
) { result in
    switch result {
    case .success(let registerResult):
        // Handle successful registration
    case .failure(let error):
        // Handle error
    }
}
```

##### Flutter

```dart
try {
  final result = await authClient.register(
    email: 'user@example.com',
    password: 'securePassword123!',
    name: 'John Doe',
    phoneNumber: '+1234567890',
  );
  
  // Handle successful registration
} catch (e) {
  // Handle error
}
```

#### User Login

##### JavaScript

```javascript
try {
  const result = await authClient.login({
    email: 'user@example.com',
    password: 'securePassword123!',
    deviceInfo: {
      deviceId: 'browser-123',
      deviceName: 'Chrome on Windows',
      appVersion: '1.0.0'
    }
  });
  
  if (result.mfaRequired) {
    // Handle MFA challenge
    const mfaToken = result.mfaToken;
    // Redirect to MFA verification screen
  } else {
    // Store tokens and redirect to authenticated area
    localStorage.setItem('accessToken', result.accessToken);
    localStorage.setItem('refreshToken', result.refreshToken);
  }
} catch (error) {
  console.error('Login failed:', error);
}
```

### Multi-Factor Authentication (MFA)

#### TOTP (Time-based One-Time Password) Setup

##### JavaScript

```javascript
// Enable TOTP MFA
try {
  const setupResult = await authClient.enableTOTP();
  
  // Display QR code to user
  const qrCodeElement = document.getElementById('qr-code');
  qrCodeElement.src = setupResult.qrCode;
  
  // Show secret key as backup
  document.getElementById('secret-key').textContent = setupResult.secret;
  
  // After user scans QR code and enters verification code
  const verificationCode = document.getElementById('totp-code').value;
  const activationResult = await authClient.activateTOTP(
    setupResult.setupToken,
    verificationCode
  );
  
  // Store backup codes securely
  console.log('Backup codes:', activationResult.backupCodes);
} catch (error) {
  console.error('TOTP setup failed:', error);
}
```

### Biometric Authentication

#### Enrollment

##### JavaScript (Web - WebAuthn)

```javascript
// Check if biometric authentication is supported
if (await authClient.isBiometricSupported()) {
  try {
    const enrollmentResult = await authClient.enrollBiometric({
      biometricType: 'fingerprint',
      deviceInfo: {
        deviceId: 'browser-123',
        deviceName: 'Chrome on Windows',
        osVersion: navigator.userAgent
      }
    });
    
    console.log('Biometric enrollment successful:', enrollmentResult);
  } catch (error) {
    console.error('Biometric enrollment failed:', error);
  }
} else {
  console.log('Biometric authentication not supported on this device');
}
```

### Magic Link Authentication

##### JavaScript

```javascript
// Request magic link
try {
  await authClient.requestMagicLink({
    email: 'user@example.com',
    redirectUrl: 'https://your-app.com/auth/callback',
    deviceInfo: {
      deviceId: 'browser-123',
      deviceName: 'Chrome on Windows',
      ipAddress: '192.168.1.1'
    }
  });
  
  console.log('Magic link sent to email');
} catch (error) {
  console.error('Magic link request failed:', error);
}

// Handle magic link callback (on redirect page)
const urlParams = new URLSearchParams(window.location.search);
const token = urlParams.get('token');

if (token) {
  try {
    const authResult = await authClient.verifyMagicLink(token);
    
    // Store tokens
    localStorage.setItem('accessToken', authResult.accessToken);
    localStorage.setItem('refreshToken', authResult.refreshToken);
    
    console.log('Magic link authentication successful');
  } catch (error) {
    console.error('Magic link verification failed:', error);
  }
}
```

### Passkey (WebAuthn) Authentication

#### Registration

##### JavaScript

```javascript
// Begin passkey registration
try {
  const registrationOptions = await authClient.beginPasskeyRegistration({
    deviceName: 'MacBook Pro'
  });
  
  // Create credential using WebAuthn API
  const credential = await navigator.credentials.create({
    publicKey: registrationOptions
  });
  
  // Complete registration
  const registrationResult = await authClient.completePasskeyRegistration(credential);
  
  console.log('Passkey registered successfully:', registrationResult);
} catch (error) {
  console.error('Passkey registration failed:', error);
}
```

#### Authentication

##### JavaScript

```javascript
// Begin passkey authentication
try {
  const authenticationOptions = await authClient.beginPasskeyAuthentication({
    email: 'user@example.com'
  });
  
  // Get credential using WebAuthn API
  const credential = await navigator.credentials.get({
    publicKey: authenticationOptions
  });
  
  // Complete authentication
  const authResult = await authClient.completePasskeyAuthentication(credential);
  
  // Store tokens
  localStorage.setItem('accessToken', authResult.accessToken);
  localStorage.setItem('refreshToken', authResult.refreshToken);
  
  console.log('Passkey authentication successful');
} catch (error) {
  console.error('Passkey authentication failed:', error);
}
```

## Token Management

### Automatic Token Refresh

##### JavaScript

```javascript
// Set up automatic token refresh
authClient.onTokenExpired(async () => {
  try {
    const refreshToken = localStorage.getItem('refreshToken');
    const newTokens = await authClient.refreshToken(refreshToken);
    
    localStorage.setItem('accessToken', newTokens.accessToken);
    localStorage.setItem('refreshToken', newTokens.refreshToken);
    
    console.log('Tokens refreshed successfully');
  } catch (error) {
    console.error('Token refresh failed:', error);
    // Redirect to login
    window.location.href = '/login';
  }
});
```

## Error Handling

### Common Error Codes

- `INVALID_CREDENTIALS` - Invalid email/password combination
- `USER_NOT_FOUND` - User account does not exist
- `ACCOUNT_LOCKED` - User account is temporarily locked
- `MFA_REQUIRED` - Multi-factor authentication is required
- `INVALID_MFA_CODE` - Invalid MFA verification code
- `TOKEN_EXPIRED` - Access token has expired
- `RATE_LIMIT_EXCEEDED` - Too many requests, rate limit exceeded
- `BIOMETRIC_NOT_SUPPORTED` - Biometric authentication not supported
- `PASSKEY_NOT_SUPPORTED` - Passkey authentication not supported
- `NETWORK_ERROR` - Network connection error
- `SERVER_ERROR` - Internal server error

### Error Handling Best Practices

##### JavaScript

```javascript
try {
  const result = await authClient.login(credentials);
  // Handle success
} catch (error) {
  switch (error.code) {
    case 'INVALID_CREDENTIALS':
      showError('Invalid email or password');
      break;
    case 'ACCOUNT_LOCKED':
      showError('Account is temporarily locked. Please try again later.');
      break;
    case 'MFA_REQUIRED':
      // Redirect to MFA verification
      redirectToMFA(error.data.mfaToken);
      break;
    case 'RATE_LIMIT_EXCEEDED':
      showError('Too many login attempts. Please wait before trying again.');
      break;
    default:
      showError('Login failed. Please try again.');
  }
}
```

## Security Best Practices

### Token Storage

#### Web Applications

```javascript
// Use secure storage for tokens
class SecureTokenStorage {
  static setTokens(accessToken, refreshToken) {
    // Store access token in memory or short-lived storage
    sessionStorage.setItem('accessToken', accessToken);
    
    // Store refresh token in httpOnly cookie (server-side)
    // or secure localStorage with encryption
    localStorage.setItem('refreshToken', this.encrypt(refreshToken));
  }
  
  static getAccessToken() {
    return sessionStorage.getItem('accessToken');
  }
  
  static getRefreshToken() {
    const encryptedToken = localStorage.getItem('refreshToken');
    return encryptedToken ? this.decrypt(encryptedToken) : null;
  }
  
  static clearTokens() {
    sessionStorage.removeItem('accessToken');
    localStorage.removeItem('refreshToken');
  }
  
  static encrypt(data) {
    // Implement encryption logic
    return btoa(data); // Simple base64 encoding (use proper encryption in production)
  }
  
  static decrypt(encryptedData) {
    // Implement decryption logic
    return atob(encryptedData); // Simple base64 decoding (use proper decryption in production)
  }
}
```

## Testing

### Unit Testing

#### JavaScript (Jest)

```javascript
// auth.test.js
import { AuthClient } from '@example/auth-js-sdk';

describe('AuthClient', () => {
  let authClient;
  
  beforeEach(() => {
    authClient = new AuthClient({
      clientId: 'test_client_id',
      redirectUri: 'http://localhost:3000/callback',
      apiUrl: 'http://localhost:8080/auth'
    });
  });
  
  test('should register user successfully', async () => {
    const mockResponse = {
      success: true,
      data: {
        user_id: 123,
        email: 'test@example.com',
        verification_required: true
      }
    };
    
    // Mock fetch
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve(mockResponse)
    });
    
    const result = await authClient.register({
      email: 'test@example.com',
      password: 'password123',
      name: 'Test User'
    });
    
    expect(result.success).toBe(true);
    expect(result.data.email).toBe('test@example.com');
  });
});
```

## Troubleshooting

### Common Issues

#### 1. CORS Issues (Web)

**Problem:** Cross-origin requests are blocked

**Solution:** Configure CORS headers on the auth service or use a proxy

```javascript
// If using a development proxy
const { createProxyMiddleware } = require('http-proxy-middleware');

app.use('/api', createProxyMiddleware({
  target: 'http://localhost:8080',
  changeOrigin: true,
  pathRewrite: {
    '^/api': '/auth'
  }
}));
```

#### 2. Token Refresh Loop

**Problem:** Infinite token refresh attempts

**Solution:** Implement proper token refresh logic with retry limits

```javascript
class TokenManager {
  constructor() {
    this.refreshPromise = null;
    this.maxRetries = 3;
    this.retryCount = 0;
  }
  
  async refreshToken() {
    if (this.refreshPromise) {
      return this.refreshPromise;
    }
    
    if (this.retryCount >= this.maxRetries) {
      throw new Error('Max refresh retries exceeded');
    }
    
    this.refreshPromise = this.performRefresh();
    
    try {
      const result = await this.refreshPromise;
      this.retryCount = 0;
      return result;
    } catch (error) {
      this.retryCount++;
      throw error;
    } finally {
      this.refreshPromise = null;
    }
  }
  
  async performRefresh() {
    // Actual refresh logic
  }
}
```

## Support and Resources

### Documentation

- [API Reference](./api_reference.md)
- [SDK Documentation](https://docs.example.com/auth-sdk)
- [Migration Guide](./migration_guide.md)

### Community

- [Developer Forum](https://forum.example.com/auth)
- [Stack Overflow](https://stackoverflow.com/questions/tagged/example-auth)
- [GitHub Issues](https://github.com/example/auth-service/issues)

### Support

- Email: support@example.com
- Developer Portal: https://developers.example.com
- Status Page: https://status.example.com

---

This integration guide provides comprehensive instructions for implementing authentication in your applications. For specific use cases or advanced configurations, please refer to the API reference or contact our support team.