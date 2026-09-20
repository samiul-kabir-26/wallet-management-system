# Task 3: Module 1 — Authentication Implementation

**Status:** Ready to start  
**Estimated Duration:** 4-5 hours  
**Difficulty:** Medium

---

## Objective

Implement JWT-based authentication with two parallel authentication tracks:
- **USER_TRACK:** Phone + PIN authentication
- **ADMIN_TRACK:** Email + Password + OTP (2FA) authentication

---

## What You'll Learn

- Laravel authentication concepts
- JWT token generation and validation
- OTP flow for admin 2FA
- Password hashing and verification
- Form Requests for validation
- Service classes for business logic
- Authentication middleware

---

## Architecture Overview

```
Auth Flow:
├── User Registration (phone + PIN)
├── User Login (phone + PIN)
├── Admin Login (email + password)
├── Admin OTP Verification
├── Token Refresh
├── Password Reset
└── Logout
```

**Key Files:**
- Controllers: Handle HTTP requests
- Services: Contain business logic
- Requests: Validate input
- Middleware: Protect routes
- Resources: Format responses

---

## Step 1: Understand Laravel Authentication

### Concepts to Learn

1. **Authentication vs. Authorization**
   - Authentication: Who are you? (login)
   - Authorization: What can you do? (permissions)

2. **Laravel Sanctum vs. Passport**
   - Sanctum: Simpler, API tokens + sessions
   - Passport: OAuth 2.0 compliant
   - **For this project:** Use Sanctum (simpler)

3. **JWT Token Structure**
   ```
   Header.Payload.Signature
   ```
   - Header: Token type, hashing algorithm
   - Payload: Claims (user_id, roles, expiry)
   - Signature: Cryptographic signature

4. **Password Hashing**
   - Use bcrypt (Laravel's default)
   - `Hash::make($password)` — Hash a password
   - `Hash::check($password, $hash)` — Verify password

5. **PIN vs. Password**
   - PIN: Shorter (5-10 digits), hashed same as password
   - Password: Longer, more complex

### Before You Code

Research:
- What is JWT?
- How does token refresh work?
- What is OTP and how is it used?
- What does "stateless authentication" mean?

---

## Step 2: Implement User Authentication (USER_TRACK)

### Endpoint 1: User Registration

**Route:** `POST /api/v1/auth/register`

**Request Body:**
```json
{
  "name": "John Doe",
  "phone_number": "01712345678",
  "pin": "123456",
  "role": "USER"  // or "AGENT"
}
```

**Response (201):**
```json
{
  "success": true,
  "message": "User registered successfully",
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe",
      "phone_number": "01712345678",
      "user_type": "USER_TRACK",
      "is_verified": false,
      "is_active": "ACTIVE"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer"
  }
}
```

**Business Logic:**
1. Validate input using Form Request
2. Check if phone already registered (unique constraint)
3. Hash PIN using `Hash::make()`
4. Create User record
5. Create Wallet (auto-created? or explicit?)
6. Create Cap record
7. If AGENT: Create AgentInfo (PENDING status)
8. Assign role via UserRole
9. Generate JWT token
10. Return user + token

**What to Create:**
- `app/Http/Requests/Auth/RegisterRequest.php` — Form request with validation rules
- `app/Services/Auth/UserAuthService.php` — Service class with registration logic
- `app/Http/Controllers/Auth/AuthController.php` — Controller endpoint
- `app/Http/Resources/AuthResource.php` — Format auth responses
- Route in `routes/api.php`

**Validation Rules (RegisterRequest):**
- `name`: required, string, min 2, max 255
- `phone_number`: required, phone format, unique in users table
- `pin`: required, regex (digits only), length 5-10
- `role`: required, in ['USER', 'AGENT']

**What You Should Understand:**
- How Form Requests work
- How to hash sensitive data
- How to use Eloquent factories for testing
- Wallet auto-creation strategy

---

### Endpoint 2: User Login

**Route:** `POST /api/v1/auth/login`

**Request Body:**
```json
{
  "phone_number": "01712345678",
  "pin": "123456"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe",
      "phone_number": "01712345678"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer"
  }
}
```

**Business Logic:**
1. Validate input using Form Request
2. Find user by phone_number
3. If not found: return 401 Unauthorized
4. Verify PIN using `Hash::check()`
5. If PIN incorrect: return 401 Unauthorized
6. Check if user is active (`is_active == 'ACTIVE'`)
7. Generate JWT token
8. Return user + token

**What to Create:**
- `app/Http/Requests/Auth/LoginRequest.php` — Validation
- Add login method to `UserAuthService`
- Add login endpoint to `AuthController`

**Validation Rules (LoginRequest):**
- `phone_number`: required, phone format
- `pin`: required, digits only

**What You Should Understand:**
- How to verify hashed data
- Token generation
- Error handling and status codes

---

## Step 3: Implement Admin Authentication (ADMIN_TRACK)

### Endpoint 1: Admin Login (Request OTP)

**Route:** `POST /api/v1/auth/admin/login`

**Request Body:**
```json
{
  "email": "admin@example.com",
  "password": "secure_password"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "OTP sent to your email. Please check your inbox.",
  "data": {
    "email": "admin@example.com"
  }
}
```

**Business Logic:**
1. Validate input using Form Request
2. Find user by email
3. If not found: return 401 Unauthorized
4. Verify password using `Hash::check()`
5. If password incorrect: return 401 Unauthorized
6. Check if user is admin (`user_type == 'ADMIN_TRACK'`)
7. Generate 6-digit OTP code
8. Save OTP to `otp_tokens` table with:
   - `otp_code`: generated code
   - `purpose`: 'LOGIN'
   - `expires_at`: now + 5 minutes
   - `user_id`: admin's user_id
9. Send OTP via email (stub for now)
10. Return success message

**What to Create:**
- `app/Http/Requests/Auth/AdminLoginRequest.php`
- `app/Services/Auth/AdminAuthService.php`
- `app/Services/Otp/OtpService.php` — OTP generation and validation
- Add admin login endpoint to `AuthController`

**OtpService Responsibilities:**
- `generateOtp()` — Generate 6-digit random code
- `saveOtp($user_id, $code, $purpose)` — Save to DB with expiry
- `validateOtp($email, $otp_code)` — Check if valid, not expired, not used, attempt count < max
- `markOtpAsUsed($otp_token)` — Set used_at timestamp

**What You Should Understand:**
- OTP generation logic
- Database expiry handling
- Email sending (stub)
- Rate limiting on attempts

---

### Endpoint 2: Admin Verify OTP (Complete Login)

**Route:** `POST /api/v1/auth/admin/verify-otp`

**Request Body:**
```json
{
  "email": "admin@example.com",
  "otp_code": "123456"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "id": 2,
      "name": "Admin User",
      "email": "admin@example.com"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer"
  }
}
```

**Business Logic:**
1. Validate input
2. Find user by email
3. Find OTP token for this user with purpose='LOGIN'
4. Validate OTP:
   - OTP code matches input
   - Not expired (`expires_at > now()`)
   - Not yet used (`used_at == NULL`)
   - Attempt count < max_attempts (5)
5. If validation fails:
   - Increment attempt_count
   - If attempts >= max: reject future attempts
   - Return 401 with reason
6. If valid:
   - Mark OTP as used (`used_at = now()`)
   - Generate JWT token
   - Return user + token

**What to Create:**
- `app/Http/Requests/Auth/VerifyOtpRequest.php`
- Add verify endpoint to `AuthController`
- Add verification logic to `OtpService`

**What You Should Understand:**
- Single-use tokens
- Expiration validation
- Rate limiting/attempt limiting
- Atomic updates

---

## Step 4: Token Refresh & Logout

### Endpoint 1: Refresh Token

**Route:** `POST /api/v1/auth/refresh-token`

**Request Body:**
```json
{
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Token refreshed",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer"
  }
}
```

**Business Logic:**
1. Validate refresh_token format
2. Decode refresh token
3. Check if token is blacklisted
4. Generate new access token
5. Return new token

**What You Should Understand:**
- Refresh token strategy
- Token blacklisting/revocation
- Access token expiry (shorter) vs. refresh token expiry (longer)

---

### Endpoint 2: Logout

**Route:** `POST /api/v1/auth/logout`

**Request Header:** `Authorization: Bearer {token}`

**Response (200):**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

**Business Logic:**
1. Extract token from header
2. Blacklist the token (prevent further use)
3. Return success

**Options for Blacklisting:**
- Option A: Store blacklisted tokens in `token_blacklist` table
- Option B: Store in Redis with expiry
- Option C: Just invalidate on the frontend (simpler for MVP)

**For now:** Implement Option C (frontend invalidates)

---

## Step 5: Password Reset Flow

### Endpoint 1: Request Password Reset

**Route:** `POST /api/v1/auth/forgot-password`

**Request Body:**
```json
{
  "email": "admin@example.com"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "OTP sent to your email"
}
```

**Business Logic:**
1. Find user by email
2. If not found: return success anyway (security: don't leak if email exists)
3. Generate OTP with purpose='PASSWORD_RESET'
4. Send OTP via email (stub)
5. Return success

---

### Endpoint 2: Reset Password

**Route:** `POST /api/v1/auth/reset-password`

**Request Body:**
```json
{
  "email": "admin@example.com",
  "otp_code": "123456",
  "new_password": "new_secure_password"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Password reset successfully"
}
```

**Business Logic:**
1. Validate OTP (same validation as admin login)
2. Update user's password using `Hash::make()`
3. Mark OTP as used
4. Return success

---

## Step 6: Authentication Middleware

### Create Custom Middleware

**File:** `app/Http/Middleware/Authenticate.php`

**Responsibilities:**
1. Extract token from `Authorization: Bearer {token}` header
2. Decode and validate JWT token
3. Check token expiry
4. Extract user from token
5. Inject user into request (`$request->user()`)
6. Allow request to continue
7. If invalid: return 401 Unauthorized

**What You Should Understand:**
- Middleware lifecycle
- Request/response cycle
- Token validation
- Guard system

---

## Step 7: API Resources & Responses

### Create Response Resources

**File:** `app/Http/Resources/AuthResource.php`

**Purpose:** Format authentication responses consistently

**Format:**
```json
{
  "success": true,
  "message": "Operation successful",
  "data": {
    "user": {...},
    "token": "...",
    "token_type": "Bearer",
    "expires_in": 3600
  }
}
```

**User Resource:**
- Include: id, name, email (if admin), phone_number (if user), roles
- Exclude: password, pin, password_hash

---

## Step 8: Testing

### Unit Tests

**File:** `tests/Unit/Services/Auth/UserAuthServiceTest.php`

Test:
- PIN hashing works
- PIN verification works
- User creation with wallet + caps
- Role assignment

**File:** `tests/Unit/Services/Otp/OtpServiceTest.php`

Test:
- OTP generation (6 digits)
- OTP saving with expiry
- OTP validation (not expired, not used, attempt limiting)

### Feature Tests

**File:** `tests/Feature/Auth/RegisterTest.php`

Test scenarios:
```
✅ Register with valid phone + PIN
❌ Register with existing phone number (409 Conflict)
❌ Register with invalid phone format
❌ Register with PIN too short
✅ User wallet created automatically
✅ Roles assigned
✅ Return token in response
```

**File:** `tests/Feature/Auth/LoginTest.php`

Test scenarios:
```
✅ Login with valid phone + PIN
❌ Login with invalid phone
❌ Login with wrong PIN
❌ Login with inactive user
✅ Return token
```

**File:** `tests/Feature/Auth/AdminLoginTest.php`

Test scenarios:
```
✅ Admin login with valid email + password
❌ Admin login with wrong password
✅ OTP sent successfully
❌ Verify with invalid OTP
✅ Verify with correct OTP → token returned
❌ Verify with expired OTP
❌ Verify after max attempts exceeded
```

**File:** `tests/Feature/Auth/TokenRefreshTest.php`

Test:
- Valid refresh_token → new access token
- Invalid refresh_token → 401

**File:** `tests/Feature/Auth/LogoutTest.php`

Test:
- After logout, token should be unusable

---

## Files to Create (Summary)

### Controllers
- `app/Http/Controllers/Auth/AuthController.php`

### Requests (Validation)
- `app/Http/Requests/Auth/RegisterRequest.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Http/Requests/Auth/AdminLoginRequest.php`
- `app/Http/Requests/Auth/VerifyOtpRequest.php`
- `app/Http/Requests/Auth/RefreshTokenRequest.php`
- `app/Http/Requests/Auth/ForgotPasswordRequest.php`
- `app/Http/Requests/Auth/ResetPasswordRequest.php`

### Services
- `app/Services/Auth/UserAuthService.php`
- `app/Services/Auth/AdminAuthService.php`
- `app/Services/Otp/OtpService.php`

### Middleware
- `app/Http/Middleware/Authenticate.php` (custom, if needed)

### Resources
- `app/Http/Resources/AuthResource.php`

### Routes
- Update `routes/api.php` with all auth routes

### Tests
- `tests/Feature/Auth/RegisterTest.php`
- `tests/Feature/Auth/LoginTest.php`
- `tests/Feature/Auth/AdminLoginTest.php`
- `tests/Feature/Auth/VerifyOtpTest.php`
- `tests/Feature/Auth/RefreshTokenTest.php`
- `tests/Feature/Auth/LogoutTest.php`
- `tests/Unit/Services/Auth/UserAuthServiceTest.php`
- `tests/Unit/Services/Otp/OtpServiceTest.php`

---

## Checklist

Before moving to Task 4, verify:

- [ ] User registration endpoint works (phone + PIN)
- [ ] User login endpoint works
- [ ] Admin login endpoint works (generates OTP)
- [ ] Admin OTP verification works (returns token)
- [ ] Token refresh endpoint works
- [ ] Logout endpoint works
- [ ] JWT tokens are properly formatted
- [ ] Expired tokens are rejected
- [ ] Invalid credentials return 401
- [ ] All validation rules work
- [ ] OTP expires after 5 minutes
- [ ] OTP single-use enforced
- [ ] Password reset flow works
- [ ] All tests pass
- [ ] No passwords/PINs in API responses

---

## Key Learnings

After completing this task, you should understand:

✅ How Laravel Sanctum works  
✅ JWT token structure  
✅ Form Requests for validation  
✅ Service classes for business logic  
✅ OTP generation and validation  
✅ Password hashing with bcrypt  
✅ Middleware for protecting routes  
✅ API resources for response formatting  
✅ Testing authentication flows  

---

## Next Task

Once complete and all tests pass, proceed to **Task 4: Users & Roles Management**.
