# Authentication Strategy for Laravel

## Overview

This project needs JWT-based authentication for API clients (web frontend and Flutter app).

Laravel has several authentication options. Here's the evaluation:

---

## Option 1: Laravel Sanctum (Recommended for This Project)

### What It Is
Laravel's built-in token-based authentication system.

### Pros
- ✅ Built into Laravel (no external package)
- ✅ Supports both token and cookie-based auth
- ✅ Token revocation (logout)
- ✅ Scoped tokens (different token types)
- ✅ Simple to implement
- ✅ Works with web and mobile clients
- ✅ Excellent Laravel documentation

### Cons
- ❌ Tokens are stored in `personal_access_tokens` table
- ❌ Stateful (requires database check on every request unless cached)
- ❌ Not true JWT (tokens are arbitrary strings, not self-contained)

### Token Flow

```
User Login
  ↓
[POST /api/v1/auth/login]
  ↓
Validate credentials
  ↓
Generate token (Sanctum)
  ↓
Return token
  ↓
Client stores token (localStorage / app storage)
  ↓
Every request includes: Authorization: Bearer <token>
  ↓
Sanctum middleware validates token against DB
  ↓
Request proceeds or returns 401
```

### Typical Token Lifetime
- Access token: single token, valid until revoked or expires
- No separate refresh token (optional to implement)

### Setup Outline
1. Laravel Sanctum is pre-installed in Laravel 11
2. Create migration: `php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"`
3. In User model: `use HasApiTokens`
4. In routes: `->middleware('auth:sanctum')`
5. Create token: `$token = $user->createToken('api-token')->plainTextToken;`
6. Revoke token: `$user->tokens()->delete();`

---

## Option 2: True JWT with `tymon/jwt-auth`

### What It Is
JWT package that creates self-contained, stateless tokens.

### Pros
- ✅ True JWT (token contains user data, signed)
- ✅ Stateless (no DB lookup per request)
- ✅ Can be used across multiple services
- ✅ Familiar to developers from Node.js/JWT world
- ✅ Standard JWT format

### Cons
- ❌ External package (not in Laravel core)
- ❌ Token revocation is harder (need blacklist table)
- ❌ More complex setup
- ❌ Overkill for single Laravel app

### Token Flow

```
User Login
  ↓
[POST /api/v1/auth/login]
  ↓
Validate credentials
  ↓
Generate JWT (tymon/jwt-auth)
  ↓
Return { access_token, refresh_token }
  ↓
Client stores tokens
  ↓
Every request: Authorization: Bearer <access_token>
  ↓
Middleware decodes JWT, verifies signature
  ↓
No DB lookup needed (stateless)
  ↓
Request proceeds
```

### Typical Token Lifetime
- Access token: 15-60 minutes
- Refresh token: 7-30 days (stored in DB)

### When to Use
- Microservices where multiple services validate tokens
- Stateless API across multiple servers
- Performance-critical with high request volume
- Mobile app requiring true offline capability

### When NOT to Use
- Single Laravel app
- Need token revocation (logout immediately)
- Database is available

---

## Option 3: API Key (Simplest)

### What It Is
Simple key-based authentication.

### Use Only For
- Service-to-service authentication
- Third-party integrations
- Not suitable for user authentication

---

## Recommendation: Use Sanctum

### Why Sanctum for This Project

1. **Single Application**: One Laravel backend for all clients
2. **Immediate Revocation**: Logout must work instantly
3. **Simplicity**: Built-in, minimal setup
4. **Flutter Compatibility**: Works perfectly with mobile apps
5. **Web Frontend**: Works with SPA (Inertia)
6. **No Complexity Needed**: JWT's statelessness isn't a requirement

### Token Strategy with Sanctum

```
Access Token
├── Generated on login
├── Valid indefinitely (or set expiration with policy)
├── Revoked on logout
└── One per active session
```

Optional: Implement refresh tokens for mobile security:
- Short-lived access token (1 hour)
- Long-lived refresh token (30 days)
- But Sanctum doesn't have built-in refresh token, would need custom implementation

Start simple: single token that's valid until revoked.

---

## Authentication Flow (Proposed)

### 1. Registration
```
POST /api/v1/auth/register
{
  "name": "John",
  "email": "john@example.com",
  "password": "secret123",
  "phone_number": "01712345678",
  "role": "USER"  // or AGENT
}

Response 201:
{
  "message": "Registration successful",
  "user": {
    "id": 1,
    "name": "John",
    "email": "john@example.com",
    "role": "USER"
  },
  "access_token": "4|abcd1234..."
}
```

### 2. Login
```
POST /api/v1/auth/login
{
  "email": "john@example.com",
  "password": "secret123"
}

Response 200:
{
  "access_token": "4|abcd1234...",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "John",
    "email": "john@example.com",
    "role": "USER"
  }
}
```

### 3. Authenticated Request
```
GET /api/v1/user/profile
Headers: Authorization: Bearer 4|abcd1234...

Response 200:
{
  "id": 1,
  "name": "John",
  "email": "john@example.com",
  "wallet": { ... }
}
```

### 4. Logout
```
POST /api/v1/auth/logout
Headers: Authorization: Bearer 4|abcd1234...

Response 200:
{
  "message": "Logged out successfully"
}
```

### 5. Password Reset (Separate Flow)

```
POST /api/v1/auth/reset-password
{
  "email": "john@example.com"
}
Response: "Reset link sent"

// User clicks link in email
GET /auth/reset?token=xyz&email=john@example.com

// User submits new password
POST /api/v1/auth/update-password
{
  "token": "xyz",
  "email": "john@example.com",
  "password": "newpass123",
  "password_confirmation": "newpass123"
}
Response: Success or 422
```

---

## Error Responses

### Unauthenticated
```
GET /api/v1/user/profile
(No Authorization header)

Response 401:
{
  "message": "Unauthenticated"
}
```

### Unauthorized
```
GET /api/v1/admin/users
Authorization: Bearer <user-token>  // user is not admin

Response 403:
{
  "message": "Unauthorized"
}
```

### Invalid Credentials
```
POST /api/v1/auth/login
{
  "email": "john@example.com",
  "password": "wrongpass"
}

Response 401:
{
  "message": "The provided credentials are incorrect."
}
```

---

## Middleware Structure

### api.php routes
```php
// Public routes (no auth)
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
  Route::post('/auth/logout', [AuthController::class, 'logout']);
  
  Route::get('/user/profile', [UserController::class, 'profile']);
  Route::get('/wallets/me', [WalletController::class, 'me']);
  
  // Admin routes
  Route::middleware('admin')->prefix('admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index']);
  });
  
  // Agent routes
  Route::middleware('agent')->prefix('agent')->group(function () {
    Route::post('/withdrawal', [AgentTransactionController::class, 'withdrawal']);
  });
});
```

### Custom Middleware

```php
// app/Http/Middleware/AdminMiddleware.php
public function handle($request, $next) {
  if (!auth()->user()?->hasRole('ADMIN', 'SUPER_ADMIN')) {
    abort(403, 'Only admins can access this.');
  }
  return $next($request);
}

// app/Http/Middleware/AgentMiddleware.php
public function handle($request, $next) {
  if (!auth()->user()?->hasRole('AGENT')) {
    abort(403, 'Only agents can access this.');
  }
  return $next($request);
}
```

Register in `app/Http/Kernel.php`:
```php
protected $routeMiddleware = [
  'admin' => \App\Http\Middleware\AdminMiddleware::class,
  'agent' => \App\Http\Middleware\AgentMiddleware::class,
];
```

---

## Implementation Checklist

- [ ] User model has `use HasApiTokens`
- [ ] Sanctum middleware in `api` route group
- [ ] AuthController with login/register/logout
- [ ] Form Requests for validation (LoginRequest, RegisterRequest)
- [ ] Custom middleware for role-based access
- [ ] Exception handling for auth errors
- [ ] API resource for user responses
- [ ] Tests for login/logout/auth flow
- [ ] Password reset flow
- [ ] Rate limiting on auth endpoints

---

## Security Considerations

1. **HTTPS Only**: Tokens must always be sent over HTTPS (configure in .env)
2. **Token Storage**:
   - Web: localStorage (vulnerable to XSS) or httpOnly cookies (better)
   - Mobile: Secure storage (keychain on iOS, Keystore on Android)
3. **Token Expiration**: Consider short-lived tokens for sensitive apps
4. **Rate Limiting**: Limit login attempts
5. **CORS**: Configure properly for web frontend
6. **CSRF**: Not needed for API tokens, but may be needed for form-based auth

---

## Summary

Use **Laravel Sanctum** for:
- Simplicity
- Built-in solution
- Works for both web and mobile
- Immediate token revocation
- No external dependencies

This is the Laravel-native, recommended approach for this project.
