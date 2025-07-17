# Authentication Service SDK Examples

This document provides practical code examples for integrating the Authentication Service across different platforms and frameworks.

## JavaScript/TypeScript Examples

### Basic Setup

```typescript
import { AuthClient, AuthConfig } from '@example/auth-js-sdk';

const config: AuthConfig = {
  clientId: 'your_client_id',
  redirectUri: 'https://your-app.com/callback',
  apiUrl: 'https://api.example.com/auth',
  debug: process.env.NODE_ENV === 'development'
};

const authClient = new AuthClient(config);
```

### Complete Authentication Flow

```typescript
class AuthService {
  private authClient: AuthClient;
  private tokenStorage: TokenStorage;

  constructor() {
    this.authClient = new AuthClient(config);
    this.tokenStorage = new TokenStorage();
    this.setupTokenRefresh();
  }

  async login(credentials: LoginCredentials): Promise<LoginResult> {
    try {
      const result = await this.authClient.login({
        ...credentials,
        deviceInfo: this.getDeviceInfo()
      });

      if (result.success) {
        if (result.data.mfa_required) {
          return this.handleMFAFlow(result.data);
        } else {
          this.tokenStorage.setTokens(
            result.data.access_token,
            result.data.refresh_token
          );
          return result;
        }
      }
    } catch (error) {
      this.handleAuthError(error);
      throw error;
    }
  }

  private setupTokenRefresh(): void {
    this.authClient.onTokenExpired(async () => {
      try {
        const refreshToken = this.tokenStorage.getRefreshToken();
        const result = await this.authClient.refreshToken(refreshToken);
        
        this.tokenStorage.setTokens(
          result.access_token,
          result.refresh_token
        );
      } catch (error) {
        this.handleTokenRefreshFailure();
      }
    });
  }
}
```

## React Examples

### Authentication Context

```tsx
import React, { createContext, useContext, useEffect, useState } from 'react';

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [authClient] = useState(() => new AuthClient(authConfig));

  const login = async (credentials: LoginCredentials) => {
    setIsLoading(true);
    try {
      const result = await authClient.login(credentials);
      
      if (result.data.mfa_required) {
        throw new MFARequiredError(result.data.mfa_token, result.data.mfa_methods);
      }
      
      localStorage.setItem('accessToken', result.data.access_token);
      setUser(result.data.user);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <AuthContext.Provider value={{ user, isLoading, login }}>
      {children}
    </AuthContext.Provider>
  );
};
```

### Login Component

```tsx
const LoginForm: React.FC = () => {
  const { login, isLoading } = useAuth();
  const [formData, setFormData] = useState({ email: '', password: '' });
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    try {
      await login(formData);
    } catch (error) {
      if (error instanceof MFARequiredError) {
        // Handle MFA flow
      } else {
        setError('Login failed. Please try again.');
      }
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <input
        type="email"
        value={formData.email}
        onChange={(e) => setFormData(prev => ({ ...prev, email: e.target.value }))}
        required
      />
      <input
        type="password"
        value={formData.password}
        onChange={(e) => setFormData(prev => ({ ...prev, password: e.target.value }))}
        required
      />
      <button type="submit" disabled={isLoading}>
        {isLoading ? 'Logging in...' : 'Login'}
      </button>
    </form>
  );
};
```

## Android Examples

### Authentication Manager

```kotlin
class AuthManager(private val context: Context) {
    private val authClient: AuthClient
    private val _authState = MutableLiveData<AuthState>()
    
    val authState: LiveData<AuthState> = _authState
    
    init {
        authClient = AuthClient.Builder(context)
            .setClientId(BuildConfig.AUTH_CLIENT_ID)
            .setApiUrl(BuildConfig.AUTH_API_URL)
            .build()
    }
    
    fun login(loginData: LoginData, callback: AuthCallback<LoginResult>) {
        _authState.value = AuthState.Loading
        
        authClient.login(
            email = loginData.email,
            password = loginData.password,
            deviceInfo = getDeviceInfo(),
            callback = object : AuthCallback<LoginResult> {
                override fun onSuccess(result: LoginResult) {
                    if (result.mfaRequired) {
                        _authState.value = AuthState.MFARequired(
                            result.mfaToken,
                            result.mfaMethods
                        )
                    } else {
                        _authState.value = AuthState.Authenticated(result.user)
                    }
                }
                
                override fun onError(error: AuthError) {
                    _authState.value = AuthState.Error(error)
                }
            }
        )
    }
}
```

## iOS Examples

### SwiftUI Authentication Manager

```swift
class AuthManager: ObservableObject {
    @Published var authState: AuthState = .loading
    
    private let authClient: AuthClient
    
    init() {
        self.authClient = AuthClient(
            clientId: Bundle.main.infoDictionary?["AUTH_CLIENT_ID"] as? String ?? "",
            apiUrl: Bundle.main.infoDictionary?["AUTH_API_URL"] as? String ?? ""
        )
    }
    
    func login(credentials: LoginCredentials) -> AnyPublisher<LoginResult, AuthError> {
        authState = .loading
        
        return authClient.login(credentials: credentials, deviceInfo: getDeviceInfo())
            .handleEvents(receiveOutput: { [weak self] result in
                if result.mfaRequired {
                    self?.authState = .mfaRequired(
                        mfaToken: result.mfaToken,
                        mfaMethods: result.mfaMethods
                    )
                } else {
                    self?.authState = .authenticated(user: result.user)
                }
            })
            .eraseToAnyPublisher()
    }
}
```

## Flutter Examples

### Authentication Service

```dart
class AuthService {
  final AuthClient _authClient;
  final StreamController<AuthState> _authStateController = StreamController<AuthState>.broadcast();
  
  Stream<AuthState> get authState => _authStateController.stream;
  
  AuthService() : _authClient = AuthClient(
    clientId: 'your_client_id',
    apiUrl: 'https://api.example.com/auth',
  );
  
  Future<void> login(String email, String password) async {
    _authStateController.add(AuthState.loading());
    
    try {
      final result = await _authClient.login(
        email: email,
        password: password,
        deviceInfo: _getDeviceInfo(),
      );
      
      if (result.mfaRequired) {
        _authStateController.add(AuthState.mfaRequired(
          mfaToken: result.mfaToken,
          mfaMethods: result.mfaMethods,
        ));
      } else {
        await _saveTokens(result.accessToken, result.refreshToken);
        _authStateController.add(AuthState.authenticated(result.user));
      }
    } catch (e) {
      _authStateController.add(AuthState.error(e.toString()));
    }
  }
}
```

## Node.js Backend Examples

### Express.js Middleware

```typescript
export const authenticateToken = async (
  req: AuthenticatedRequest,
  res: Response,
  next: NextFunction
) => {
  try {
    const authHeader = req.headers.authorization;
    const token = authHeader && authHeader.split(' ')[1];

    if (!token) {
      return res.status(401).json({
        success: false,
        error: { code: 'MISSING_TOKEN', message: 'Access token is required' }
      });
    }

    const validationResult = await authClient.validateToken(token);
    
    if (!validationResult.valid) {
      return res.status(401).json({
        success: false,
        error: { code: 'INVALID_TOKEN', message: 'Invalid or expired token' }
      });
    }

    req.user = validationResult.user;
    next();
  } catch (error) {
    return res.status(500).json({
      success: false,
      error: { code: 'VALIDATION_ERROR', message: 'Token validation failed' }
    });
  }
};
```

This document provides comprehensive examples for integrating the Authentication Service across different platforms and frameworks.