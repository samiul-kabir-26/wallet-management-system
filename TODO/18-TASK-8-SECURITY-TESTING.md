# Task 8: Security & Testing

**Status:** Ready after Task 7  
**Estimated Duration:** 4-5 hours  
**Difficulty:** Medium

---

## Objective

Harden security and ensure comprehensive test coverage.

---

## Step 1: Security Review

### SQL Injection
- ✅ All queries use parameterized bindings (Eloquent)
- ✅ Never concatenate user input into queries

### XSS (Cross-Site Scripting)
- ✅ API returns JSON, no HTML templates
- Frontend responsibility: sanitize output

### CSRF
- API uses JWT tokens, not session cookies
- No CSRF token needed for JSON APIs

### Authorization
- ✅ All endpoints check authorization
- ✅ Use Policies for model-level checks
- ✅ Use Middleware for route-level checks

### Rate Limiting

**File:** `app/Http/Middleware/ThrottleRequests.php`

Apply to auth endpoints:
```php
Route::post('/auth/login', 'AuthController@login')->middleware('throttle:5,1'); // 5 per minute
Route::post('/auth/admin/login', 'AuthController@adminLogin')->middleware('throttle:5,1');
Route::post('/auth/admin/verify-otp', 'AuthController@verifyOtp')->middleware('throttle:10,1');
```

### Input Validation
- ✅ All endpoints have Form Requests
- ✅ Validate data types, lengths, formats

### Sensitive Data
- ❌ Never return passwords/PINs in API responses
- ✅ Use Resources to exclude fields
- ✅ Hide stack traces in production

### Password/PIN Storage
- ✅ Use bcrypt via `Hash::make()`
- ✅ Never log passwords
- ✅ Never send in emails

### Error Handling

**File:** `app/Exceptions/Handler.php`

```php
public function render($request, Throwable $exception) {
    if (app()->isProduction()) {
        // Don't expose stack traces
        return response()->json([
            'success' => false,
            'message' => 'An error occurred'
        ], 500);
    }
    
    // Development: show details
    return parent::render($request, $exception);
}
```

### Mass Assignment

**File:** `app/Models/User.php`

Define fillable or guarded:
```php
protected $guarded = ['id', 'password', 'pin'];
// or
protected $fillable = ['name', 'email', 'phone_number'];
```

---

## Step 2: API Exception Handling

### Create Custom Exceptions

```
app/Exceptions/
├── Handlers/
│   └── CustomExceptionHandler.php
├── Auth/
│   ├── InvalidCredentialsException.php
│   └── OtpExpiredException.php
├── Wallets/
│   ├── InsufficientBalanceException.php
│   └── WalletBlockedException.php
└── Transactions/
    ├── CapExceededException.php
    └── AgentNotApprovedException.php
```

### Exception Responses

Each exception renders JSON:
```php
class WalletBlockedException extends Exception {
    public function render($request) {
        return response()->json([
            'success' => false,
            'message' => 'Wallet is blocked'
        ], 403);
    }
}
```

### Global Handler

**File:** `app/Exceptions/Handler.php`

Catch and format all exceptions:
- 400: Bad request (validation)
- 401: Unauthorized (auth)
- 403: Forbidden (authorization)
- 404: Not found
- 422: Unprocessable entity (business rule)
- 500: Server error

---

## Step 3: Logging & Monitoring

### Create Log Events

**Financial operations:**
```php
Log::info('Transaction created', [
    'transaction_id' => $transaction->id,
    'type' => $transaction->type,
    'amount' => $transaction->amount,
    'user_id' => auth()->id(),
]);
```

**Authentication:**
```php
Log::warning('Failed login attempt', [
    'phone_number' => $request->phone_number,
    'ip' => $request->ip(),
    'user_agent' => $request->userAgent(),
]);
```

**Authorization failures:**
```php
Log::warning('Authorization denied', [
    'user_id' => auth()->id(),
    'action' => 'approve_agent',
    'resource_id' => $agentId,
]);
```

---

## Step 4: Comprehensive Testing

### Test Structure

```
tests/
├── Feature/
│   ├── Auth/
│   ├── Users/
│   ├── Wallets/
│   └── Transactions/
├── Unit/
│   └── Services/
└── Integration/
    └── TransactionFlowsTest.php
```

### Test Coverage Goals

- 80%+ code coverage
- All endpoints tested
- Happy path + error paths
- Edge cases
- Concurrency scenarios

### Test Checklist

**Authentication (15+ tests)**
- [ ] Register valid user
- [ ] Register duplicate phone
- [ ] Login valid credentials
- [ ] Login invalid PIN
- [ ] Admin login + OTP flow
- [ ] OTP expiration
- [ ] OTP max attempts
- [ ] Token refresh
- [ ] Password reset
- [ ] Logout

**Authorization (10+ tests)**
- [ ] User cannot list users
- [ ] Admin can list users
- [ ] User cannot view other user
- [ ] ADMIN cannot register ADMIN
- [ ] SUPER_ADMIN can register ADMIN
- [ ] Unapproved agent cannot transact
- [ ] Blocked wallet prevents operations

**Transactions (20+ tests)**
- [ ] Valid transfer succeeds
- [ ] Insufficient balance fails
- [ ] Blocked wallet fails
- [ ] Caps enforced
- [ ] Concurrent transfers safe
- [ ] Idempotency prevents duplicates
- [ ] Fees calculated correctly
- [ ] Commissions paid
- [ ] Transaction history accurate
- [ ] Failed transaction rolls back

**Wallets (8+ tests)**
- [ ] Can view own wallet
- [ ] Admin can view any wallet
- [ ] Can block wallet
- [ ] Can unblock wallet
- [ ] Blocked wallet prevents debit
- [ ] Concurrent updates safe

---

## Files to Create

### Exception Handlers
- `app/Exceptions/Handler.php` (update)
- `app/Exceptions/Auth/InvalidCredentialsException.php`
- `app/Exceptions/Wallets/InsufficientBalanceException.php`
- etc.

### Middleware
- `app/Http/Middleware/ThrottleRequests.php` (configure)

### Tests
- Create all test files in tests/ directory

---

## Checklist

- [ ] All inputs validated
- [ ] All endpoints authorized
- [ ] Rate limiting on auth endpoints
- [ ] Passwords/PINs never in responses
- [ ] Error messages don't leak information
- [ ] Stack traces hidden in production
- [ ] Logs record important events
- [ ] 80%+ test coverage
- [ ] All test files created
- [ ] Tests passing

---

## Next Task

Once complete, proceed to **Task 9: API Documentation**.
