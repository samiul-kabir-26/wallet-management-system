# Project Roadmap: Complete Development Plan

**Status:** Task 2 Complete ✅ | Starting Task 3

---

## Overview

This document outlines all remaining tasks to complete the wallet management system, from authentication through production deployment.

### Completed
- ✅ Task 2: Project Setup & Database Schema

### Next Steps (In Order)
- Task 3: Module 1 — Authentication Implementation
- Task 4: Module 2 — Users & Roles Management
- Task 5: Module 3 — Wallet Management
- Task 6: Module 4 — Transaction Processing
- Task 7: Security Hardening & Testing
- Task 8: API Resources & Response Formatting
- Task 9: Vue Frontend Setup
- Task 10: Production Deployment

---

# Task 3: Module 1 — Authentication Implementation

**Objective:** Implement JWT-based authentication with two parallel authentication tracks.

**Duration:** 4-5 hours

**What You'll Learn:**
- Laravel Sanctum vs. Passport vs. custom JWT
- Authentication middleware
- Token generation and validation
- OTP flow for admin 2FA
- Password reset mechanisms

## Module 1 Breakdown

### Step 1: Understand Laravel Authentication Concepts

Before coding, understand:
- Laravel's authentication guard system
- Sanctum (API tokens) vs. Session-based authentication
- JWT token structure
- OTP generation and verification
- Rate limiting

### Step 2: Implement PIN Authentication (agent + user routes)

**What to build:**
- `POST /api/v1/auth/register` — Phone + PIN registration
  - Input: name, phone_number, pin, role (USER or AGENT)
  - Create user, wallet, caps, agent_info (if agent)
  - Return success message
  
- `POST /api/v1/auth/login` — Phone + PIN login
  - Input: phone_number, pin
  - Validate PIN using bcrypt
  - Generate JWT token
  - Return token + user data

**Files to create:**
1. `app/Http/Requests/Auth/RegisterRequest.php` — Form request validation
2. `app/Http/Requests/Auth/LoginRequest.php` — Login validation
3. `app/Services/Auth/UserAuthService.php` — Business logic
4. `app/Http/Controllers/Auth/AuthController.php` — API endpoints
5. Routes: `routes/api.php`

**What you should understand:**
- Form Requests for validation
- Service classes for business logic
- JWT token generation
- Hashing and verification

### Step 3: Implement Admin Authentication (admin route)

**What to build:**
- `POST /api/v1/auth/admin/login` — Email + Password + OTP
  - Input: email, password
  - Validate credentials
  - Generate OTP and send (stub for now)
  - Return message: "OTP sent to email"
  
- `POST /api/v1/auth/admin/verify-otp` — Verify OTP
  - Input: email, otp_code
  - Validate OTP (not expired, not used, attempt count < max)
  - Mark OTP as used
  - Generate JWT token
  - Return token + user data

**Files to create:**
1. `app/Http/Requests/Auth/AdminLoginRequest.php`
2. `app/Http/Requests/Auth/VerifyOtpRequest.php`
3. `app/Services/Auth/AdminAuthService.php`
4. `app/Services/Otp/OtpService.php` — OTP generation/validation
5. Update `AuthController.php`

**What you should understand:**
- OTP generation (random 6-digit code)
- OTP expiration (5 minutes)
- Attempt limiting
- Email/SMS stubs

### Step 4: Implement Token Refresh & Logout

**What to build:**
- `POST /api/v1/auth/refresh-token` — Refresh JWT
  - Input: refresh_token
  - Generate new access token
  - Return new token
  
- `POST /api/v1/auth/logout` — Invalidate token
  - Input: token
  - Blacklist token (or mark user session as invalid)
  - Return success

**What you should understand:**
- Access token vs. refresh token
- Token blacklisting strategies
- Session invalidation

### Step 5: Password Reset Flow

**What to build:**
- `POST /api/v1/auth/forgot-password` — Request password reset
  - Input: email
  - Generate OTP for PASSWORD_RESET purpose
  - Send OTP (stub)
  - Return message

- `POST /api/v1/auth/reset-password` — Reset password
  - Input: email, otp_code, new_password
  - Validate OTP
  - Update password
  - Return success

### Step 6: Authentication Middleware

**What to build:**
- Create middleware to:
  - Validate JWT token
  - Extract user from token
  - Inject user into request
  - Reject unauthorized requests

**File:**
- `app/Http/Middleware/Authenticate.php` (or custom)

### Step 7: API Resources & Responses

**What to build:**
- `app/Http/Resources/AuthResource.php` — Format auth responses
- Consistent response format:
  ```json
  {
    "success": true,
    "message": "Login successful",
    "data": {
      "user": {...},
      "token": "...",
      "refresh_token": "..."
    }
  }
  ```

### Step 8: Testing

**What to write:**
- `tests/Feature/Auth/RegisterTest.php` — User registration
- `tests/Feature/Auth/LoginTest.php` — User login
- `tests/Feature/Auth/AdminLoginTest.php` — Admin login + OTP
- `tests/Feature/Auth/TokenRefreshTest.php`

**Test scenarios:**
- Valid credentials → success
- Invalid credentials → failure
- Missing fields → validation error
- OTP expired → reject
- OTP used twice → reject
- Rate limiting → reject after max attempts

---

# Task 4: Module 2 — Users & Roles Management

**Objective:** Implement user registration, role assignment, and authorization.

**Duration:** 3-4 hours

## Module 2 Breakdown

### Step 1: User Registration Endpoints

**What to build:**
- `POST /api/v1/users/register` — Register new user (SUPER_ADMIN/ADMIN only)
  - Input: name, email, password, phone_number, role (USER/AGENT/ADMIN), address
  - Create user based on role
  - Assign role via user_roles
  - Create wallet + caps
  - If agent: create agent_info (PENDING status)
  - Return created user

- `GET /api/v1/users/all-users` — List all users (ADMIN only)
  - Input: filters (role, is_active, is_verified)
  - Pagination
  - Return user list

- `GET /api/v1/users/:id` — Get user details
  - Authorization: own user or admin
  - Return user + relationships

- `PATCH /api/v1/users/:id` — Update user
  - Authorization: own user or admin
  - Updateable fields: name, address, image, etc.
  - Return updated user

**Files to create:**
1. `app/Http/Requests/Users/RegisterUserRequest.php`
2. `app/Services/Users/UserService.php`
3. `app/Http/Controllers/Users/UserController.php`
4. `app/Http/Resources/UserResource.php`

### Step 2: Role & Permission System

**What to build:**
- Policy class for User authorization
- Gates or Policies for:
  - Can register user? (SUPER_ADMIN or ADMIN)
  - Can register admin? (SUPER_ADMIN only)
  - Can view user? (own user or admin)
  - Can update user? (own user or admin)

**Files to create:**
1. `app/Policies/UserPolicy.php`
2. Create gate/permission checks

**What you should understand:**
- Laravel Policies
- Authorization checks
- Role-based access control (RBAC)

### Step 3: Agent Approval/Suspension

**What to build:**
- `PATCH /api/v1/users/:id/approve-agent` — Approve pending agent
  - Authorization: ADMIN or SUPER_ADMIN
  - Update agent_info.status → APPROVED
  - Set approved_at, approved_by
  - Return updated agent

- `PATCH /api/v1/users/:id/suspend-agent` — Suspend approved agent
  - Authorization: ADMIN or SUPER_ADMIN
  - Update agent_info.status → SUSPENDED
  - Set suspended_at, suspended_by
  - Return updated agent

**Files to create:**
1. Update `UserService.php` with approval logic
2. Update `UserController.php` with approval endpoints

### Step 4: Testing

**What to write:**
- `tests/Feature/Users/RegisterTest.php`
- `tests/Feature/Users/AuthorizationTest.php`
- `tests/Feature/Users/ApprovalTest.php`

---

# Task 5: Module 3 — Wallet Management

**Objective:** Implement wallet operations and balance management.

**Duration:** 3-4 hours

## Module 3 Breakdown

### Step 1: Wallet Endpoints

**What to build:**
- `GET /api/v1/wallets/me` — Get own wallet
  - Authorization: authenticated user
  - Return: wallet with balance, is_blocked status

- `GET /api/v1/wallets/admin/all` — List all wallets (ADMIN only)
  - Pagination
  - Filters: user_id, is_blocked
  - Return wallet list

- `PATCH /api/v1/wallets/:id/block` — Block wallet (ADMIN only)
  - Set is_blocked = true
  - Set blocked_by = current user
  - Set blocked_at = now
  - Return updated wallet

- `PATCH /api/v1/wallets/:id/unblock` — Unblock wallet (ADMIN only)
  - Set is_blocked = false
  - Return updated wallet

**Files to create:**
1. `app/Http/Requests/Wallets/BlockWalletRequest.php`
2. `app/Services/Wallets/WalletService.php`
3. `app/Http/Controllers/Wallets/WalletController.php`
4. `app/Http/Resources/WalletResource.php`
5. `app/Policies/WalletPolicy.php`

### Step 2: Wallet Balance Updates (Core Logic)

**What to build:**
- `WalletService::updateBalance($user_id, $amount, $type)` — Update balance safely
  - Use database transaction
  - Use row locking (SELECT ... FOR UPDATE)
  - Validate wallet not blocked
  - Update balance atomically
  - Record balance_after in transaction log
  - Return updated balance

**Key concerns:**
- Race conditions under concurrent requests
- Atomic updates
- Decimal precision
- Transaction consistency

**What you should understand:**
- Database transactions
- Row locking
- Decimal arithmetic
- ACID guarantees

### Step 3: Testing

**What to write:**
- `tests/Feature/Wallets/BlockWalletTest.php`
- `tests/Feature/Wallets/BalanceTest.php`
- `tests/Feature/Wallets/ConcurrencyTest.php` — Multiple simultaneous updates

---

# Task 6: Module 4 — Transaction Processing

**Objective:** Implement all transaction types with financial consistency.

**Duration:** 6-8 hours (most complex)

## Module 4 Breakdown

### Step 1: Transaction Service Architecture

**Create:**
- `app/Services/Transactions/TransactionService.php` — Orchestrates transactions
- `app/Services/Transactions/TransactionProcessor.php` — Processes each transaction type
- `app/Services/Transactions/FeeCalculator.php` — Calculates fees
- `app/Services/Transactions/CommissionCalculator.php` — Calculates commission

**What you should understand:**
- Service layer separation
- Command pattern (one service per transaction type)
- Fee/commission calculation logic

### Step 2: Implement Transaction Types

#### TOP_UP (User deposits via agent)
- `POST /api/v1/transactions/top-up`
- Input: amount, agent_id, description
- Flow:
  1. Validate user, agent (must be approved)
  2. Calculate system fee
  3. Calculate agent commission
  4. Debit system, credit user wallet
  5. Record transaction
  6. Emit event for commission payout

#### CASH_IN (Receive money via agent)
- `POST /api/v1/transactions/cash-in`
- Input: amount, agent_id, description
- Flow: Similar to TOP_UP

#### CASH_OUT (Withdraw via agent)
- `POST /api/v1/transactions/cash-out`
- Input: amount, agent_id, description
- Validate: wallet not blocked, balance sufficient, caps not exceeded

#### TRANSFER (User to user)
- `POST /api/v1/transactions/transfer`
- Input: amount, recipient_id, description
- Flow:
  1. Validate sender + recipient
  2. Validate sender wallet not blocked
  3. Check balance, caps
  4. Debit sender, credit recipient
  5. Record transaction

#### AGENT_WITHDRAWAL (Agent withdraws commission)
- `POST /api/v1/transactions/agent/withdrawal`
- Input: amount, description
- Validate: agent approved, sufficient commission

#### COMMISSION_PAYOUT (System pays agent commission)
- Auto-triggered after transaction
- Flow:
  1. Calculate commission
  2. Credit agent wallet
  3. Record transaction

### Step 3: Transaction Consistency (CRITICAL)

**What to implement:**
- Database transaction wrapper for all financial operations
- Row locking to prevent race conditions
- Idempotency key to prevent duplicate charges
- Status tracking (COMPLETED, FAILED)
- Rollback on any error
- Audit trail (created_by, initiated_by)

**Code pattern:**
```php
public function processTransaction($data)
{
    return DB::transaction(function () use ($data) {
        // Lock both wallets
        $senderWallet = Wallet::where('user_id', $data['sender_id'])
            ->lockForUpdate()
            ->first();
        
        $recipientWallet = Wallet::where('user_id', $data['recipient_id'])
            ->lockForUpdate()
            ->first();
        
        // Validate
        // Update balances
        // Record transaction
        
        return $transaction;
    });
}
```

### Step 4: Cap Enforcement

**What to implement:**
- Check daily/monthly caps before transaction
- Update daily_used, monthly_used atomically
- Reject if cap exceeded
- Reset caps daily via scheduled job

**Files to create:**
1. `app/Jobs/ResetDailyCaps.php` — Scheduled job
2. `app/Jobs/ResetMonthlyCaps.php`
3. `app/Services/Caps/CapService.php` — Cap validation

### Step 5: API Endpoints

Create controllers for each transaction type:
- `app/Http/Controllers/Transactions/TransactionController.php`
- Endpoints for each type
- Transaction history: `GET /api/v1/transactions/history`
- Admin audit: `GET /api/v1/transactions/admin/all`
- Transaction details: `GET /api/v1/transactions/:id`

### Step 6: Financial Consistency Testing (CRITICAL)

**What to write:**
- `tests/Feature/Transactions/TransferTest.php` — Basic transfer
- `tests/Feature/Transactions/InsufficientBalanceTest.php` — Reject on insufficient balance
- `tests/Feature/Transactions/BlockedWalletTest.php` — Reject if wallet blocked
- `tests/Feature/Transactions/CapTest.php` — Enforce caps
- `tests/Feature/Transactions/ConcurrencyTest.php` — Race conditions
- `tests/Feature/Transactions/IdempotencyTest.php` — Duplicate requests
- `tests/Feature/Transactions/ConsistencyTest.php` — Balance before = after for failed txn

---

# Task 7: System Settings Management

**Objective:** Implement dynamic system configuration.

**Duration:** 1-2 hours

## Task 7 Breakdown

### Step 1: Settings Endpoints

**What to build:**
- `GET /api/v1/system-settings` — View all settings
  - Authorization: any authenticated user
  
- `PATCH /api/v1/system-settings` — Update settings
  - Authorization: ADMIN or SUPER_ADMIN
  - Fields: system_fee_rate, agent_commission_rate
  - Audit trail: record who updated and when

**Files to create:**
1. `app/Http/Controllers/SystemSettings/SettingsController.php`
2. `app/Services/SystemSettings/SettingsService.php`
3. `app/Policies/SystemSettingPolicy.php`

### Step 2: Caching & Performance

**What to implement:**
- Cache settings in Redis or file cache
- Invalidate cache on update
- Use cached values in transaction calculations

---

# Task 8: Security & Testing

**Objective:** Harden security and add comprehensive tests.

**Duration:** 4-5 hours

## Task 8 Breakdown

### Step 1: Security Review

**What to check:**
- SQL injection: All queries should use parameterized bindings ✅
- XSS: API shouldn't be vulnerable, but frontend should sanitize
- CSRF: Use CSRF tokens if not SPA
- Authorization: All endpoints protected
- Rate limiting: On auth endpoints
- Input validation: All endpoints validate input
- Mass assignment: Models use $fillable/$guarded
- Sensitive data: Passwords/PINs never in response
- Error handling: No stack traces in production

**Files to create:**
1. `app/Http/Middleware/RateLimitAuth.php` — Rate limit login attempts
2. Review all controllers for authorization
3. Review all queries for SQL injection

### Step 2: API Exception Handling

**What to create:**
- Custom exception classes for domain errors
- Global exception handler
- Consistent error response format

**Files:**
1. `app/Exceptions/Handler.php` — Global exception handler
2. Domain exceptions:
   - `app/Exceptions/InsufficientBalanceException.php`
   - `app/Exceptions/WalletBlockedException.php`
   - `app/Exceptions/AgentNotApprovedException.php`
   - `app/Exceptions/TransactionFailedException.php`

### Step 3: Comprehensive Testing

**What to write:**
- Unit tests for services
- Feature tests for all endpoints
- Integration tests for workflows
- Concurrency tests

**Test structure:**
```
tests/
├── Unit/
│   ├── Services/
│   ├── Models/
│   └── Jobs/
└── Feature/
    ├── Auth/
    ├── Users/
    ├── Wallets/
    └── Transactions/
```

### Step 4: Logging & Monitoring

**What to implement:**
- Log all financial transactions
- Log authentication failures
- Log authorization failures
- Log system errors

---

# Task 9: API Resources & Documentation

**Objective:** Format API responses consistently and document endpoints.

**Duration:** 2-3 hours

## Task 9 Breakdown

### Step 1: API Resources

**Create resource classes for:**
- `UserResource.php`
- `WalletResource.php`
- `TransactionResource.php`
- `AgentResource.php`
- `RoleResource.php`
- `SettingsResource.php`

**Standard format:**
```json
{
  "success": true,
  "message": "Operation successful",
  "data": {...},
  "meta": {
    "timestamp": "2026-09-20T10:30:00Z",
    "version": "v1"
  }
}
```

### Step 2: Pagination

**Implement:**
- Consistent pagination across list endpoints
- Links (first, last, next, prev)
- Per-page limit

### Step 3: API Documentation

**Create:**
- `docs/API.md` — Complete API reference
- Endpoint descriptions
- Request/response examples
- Error codes

---

# Task 10: Vue Frontend (Optional - Scope Dependent)

**Objective:** Build admin/agent/user web interfaces.

**Duration:** 8-12 hours (depends on scope)

## Task 10 Breakdown

### Step 1: Frontend Setup

- `npm install` dependencies (Vue 3, Pinia, Vue Router, Axios)
- Create project structure
- Setup authentication in frontend (token storage, refresh)

### Step 2: Layouts & Navigation

- Admin layout
- Agent layout
- User layout
- Authentication layout

### Step 3: Pages

**Admin Panel:**
- Dashboard
- User management
- Agent approval
- Wallet blocking
- Transaction audit
- Settings management

**Agent Panel:**
- Dashboard
- Commission history
- Withdrawal requests

**User Panel:**
- Dashboard
- Wallet balance
- Transaction history
- Profile

### Step 4: Forms & Validation

- Login form
- Registration form
- Transaction form
- Settings form

### Step 5: Error Handling & UX

- Loading states
- Error messages
- Success notifications
- Loading skeletons

---

# Task 11: Production Preparation

**Objective:** Prepare for production deployment.

**Duration:** 2-3 hours

## Task 11 Breakdown

### Step 1: Environment Configuration

- Production .env setup
- Database credentials
- JWT secrets
- Email/SMS providers

### Step 2: Database Optimization

- Create indexes on frequently queried columns
- Analyze query performance
- Review migration for constraints

### Step 3: Performance Optimization

- Cache queries
- Optimize N+1 queries
- API rate limiting
- Background jobs for long operations

### Step 4: Monitoring & Logging

- Logging service setup
- Error tracking (Sentry, etc.)
- Database monitoring
- API monitoring

### Step 5: Deployment

- CI/CD pipeline (GitHub Actions)
- Database migration strategy
- Zero-downtime deployment
- Rollback strategy

---

# Summary Timeline

| Task | Module | Duration | Status |
|------|--------|----------|--------|
| 1 | Requirements & Architecture | 2h | ✅ Complete |
| 2 | Database & Models | 2-3h | ✅ Complete |
| 3 | Authentication | 4-5h | ⏳ Next |
| 4 | Users & Roles | 3-4h | ⏳ Pending |
| 5 | Wallets | 3-4h | ⏳ Pending |
| 6 | Transactions | 6-8h | ⏳ Pending |
| 7 | Settings | 1-2h | ⏳ Pending |
| 8 | Security & Testing | 4-5h | ⏳ Pending |
| 9 | API & Docs | 2-3h | ⏳ Pending |
| 10 | Frontend | 8-12h | ⏳ Pending |
| 11 | Production | 2-3h | ⏳ Pending |
| **Total** | | **38-50h** | |

---

# Next Action

**Ready for Task 3: Module 1 — Authentication?**

I will guide you through each step without writing code for you. You'll learn:
- Laravel Sanctum
- JWT concepts
- OTP generation
- Form Requests
- Service classes
- Middleware

Let me know when you're ready to start! 🚀
