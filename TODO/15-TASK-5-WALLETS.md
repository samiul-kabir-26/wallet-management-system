# Task 5: Module 3 — Wallet Management

**Status:** Ready after Task 4  
**Estimated Duration:** 3-4 hours  
**Difficulty:** Medium (introduces database locking)

---

## Objective

Implement wallet operations, balance management, and blocking/unblocking functionality.

---

## What You'll Learn

- Database transactions
- Row locking (SELECT ... FOR UPDATE)
- Atomic balance updates
- Decimal arithmetic for financial operations
- Race condition prevention
- Wallet authorization policies

---

## Step 1: Understand Database Transactions & Locking

### Race Condition Example

Two simultaneous requests, same wallet:

```
Request 1: Read balance (1000)
Request 2: Read balance (1000)
Request 1: Deduct 500 → Balance = 500, Save
Request 2: Deduct 300 → Balance = 700, Save (WRONG!)

Expected: 200, Got: 700 ❌
```

### Solution: Row Locking

```php
$wallet = Wallet::where('user_id', $userId)
    ->lockForUpdate()  // Lock this row
    ->first();

$wallet->balance -= $amount;
$wallet->save();
```

Only one request can hold the lock at a time.

### Laravel Transactions

```php
DB::transaction(function () {
    // All DB operations here
    // If any throws exception: all rollback
    // If all succeed: all commit
});
```

---

## Step 2: Wallet Endpoints

### Endpoint 1: Get Own Wallet

**Route:** `GET /api/v1/wallets/me`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "wallet": {
      "id": 1,
      "user_id": 3,
      "balance": 1000.50,
      "currency": "BDT",
      "is_blocked": false,
      "blocked_by": null,
      "blocked_at": null
    }
  }
}
```

**Authorization:** Authenticated user (any role)

**Business Logic:**
1. Get current user
2. Find wallet by user_id
3. Return wallet

**What to Create:**
- `app/Http/Controllers/Wallets/WalletController.php`
- `app/Http/Resources/WalletResource.php`

---

### Endpoint 2: List All Wallets (Admin)

**Route:** `GET /api/v1/wallets/admin/all`

**Query Parameters:**
```
?is_blocked=false&user_id=5&page=1&per_page=15
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "wallets": [
      {...},
      {...}
    ],
    "pagination": {
      "total": 100,
      "per_page": 15,
      "current_page": 1,
      "last_page": 7
    }
  }
}
```

**Authorization:** ADMIN or SUPER_ADMIN

**Business Logic:**
1. Authorize user (ADMIN check)
2. Build query with filters:
   - Filter by is_blocked (if provided)
   - Filter by user_id (if provided)
3. Paginate (default 15 per page)
4. Return wallets with pagination

---

### Endpoint 3: Block Wallet

**Route:** `PATCH /api/v1/wallets/:id/block`

**Request Body:**
```json
{
  "reason": "Suspicious activity detected"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Wallet blocked successfully",
  "data": {
    "wallet": {
      "id": 1,
      "user_id": 3,
      "is_blocked": true,
      "blocked_by": 1,
      "blocked_at": "2026-09-20T10:30:00Z"
    }
  }
}
```

**Authorization:** ADMIN or SUPER_ADMIN

**Business Logic:**
1. Authorize user
2. Find wallet by ID
3. Validate wallet not already blocked
4. Update wallet:
   - is_blocked = true
   - blocked_by = current user id
   - blocked_at = now()
5. Return updated wallet

**What You Should Understand:**
- Idempotency: blocking already-blocked wallet shouldn't error
- Audit trail: record who blocked and when
- Blocked wallet behavior: validated in transaction layer, not here

---

### Endpoint 4: Unblock Wallet

**Route:** `PATCH /api/v1/wallets/:id/unblock`

**Response (200):**
```json
{
  "success": true,
  "message": "Wallet unblocked successfully",
  "data": {
    "wallet": {
      "id": 1,
      "user_id": 3,
      "is_blocked": false,
      "blocked_by": null,
      "blocked_at": null
    }
  }
}
```

**Authorization:** ADMIN or SUPER_ADMIN

**Business Logic:**
1. Authorize user
2. Find wallet by ID
3. Validate wallet is actually blocked
4. Update wallet:
   - is_blocked = false
   - blocked_by = null (or keep history?)
   - blocked_at = null
5. Return updated wallet

**Question:** Should we keep blocked_by/blocked_at for history, or clear them?  
**Answer:** Keep them for audit trail. blocked_at shows "last blocked at", not current state.

---

## Step 3: Wallet Service & Balance Updates

### Create WalletService

**File:** `app/Services/Wallets/WalletService.php`

**Critical Method:**
```php
public function updateBalance($userId, $amount, $type = 'CREDIT') {
    return DB::transaction(function () use ($userId, $amount, $type) {
        $wallet = Wallet::where('user_id', $userId)
            ->lockForUpdate()
            ->first();
        
        if (!$wallet) {
            throw new WalletNotFoundException();
        }
        
        if ($wallet->is_blocked) {
            throw new WalletBlockedException();
        }
        
        if ($type === 'DEBIT' && $wallet->balance < $amount) {
            throw new InsufficientBalanceException();
        }
        
        $operation = $type === 'CREDIT' ? '+' : '-';
        $wallet->balance = DB::raw("balance {$operation} {$amount}");
        $wallet->save();
        
        return $wallet->refresh();
    });
}
```

**Key Points:**
- Database transaction ensures atomicity
- Row locking prevents race conditions
- Wallet blocked check prevents blocked wallet operations
- Balance check prevents negative balance
- Return refreshed wallet after update

**What You Should Understand:**
- Why locking is necessary
- Atomic operations with DB::raw()
- Exception handling for business rules

---

### Create WalletPolicy

**File:** `app/Policies/WalletPolicy.php`

**Methods:**
```php
public function view(User $user, Wallet $wallet) {
    // User can view own wallet
    // ADMIN can view any wallet
}

public function block(User $user) {
    // Only ADMIN can block
}

public function unblock(User $user) {
    // Only ADMIN can unblock
}
```

---

## Step 4: Custom Exceptions

**Files to create:**
```
app/Exceptions/Wallets/
├── WalletNotFoundException.php
├── WalletBlockedException.php
└── InsufficientBalanceException.php
```

**Example:**
```php
// WalletBlockedException.php
class WalletBlockedException extends Exception {
    public function render($request) {
        return response()->json([
            'success' => false,
            'message' => 'This wallet is blocked and cannot perform operations'
        ], 403);
    }
}
```

---

## Step 5: Testing

### Unit Tests

**File:** `tests/Unit/Services/Wallets/WalletServiceTest.php`

Test:
- Balance update (credit)
- Balance update (debit)
- Insufficient balance → exception
- Blocked wallet → exception

### Feature Tests

**File:** `tests/Feature/Wallets/GetWalletTest.php`

Test scenarios:
```
✅ User can view own wallet
❌ User cannot view another user's wallet (403)
✅ ADMIN can view any wallet
✅ Balance is accurate
```

**File:** `tests/Feature/Wallets/BlockWalletTest.php`

Test scenarios:
```
❌ Regular user cannot block (403)
✅ ADMIN blocks wallet
✅ is_blocked = true
✅ blocked_by and blocked_at recorded
❌ Block already-blocked wallet (idempotent check)
```

**File:** `tests/Feature/Wallets/UnblockWalletTest.php`

Test scenarios:
```
✅ ADMIN unblocks wallet
✅ is_blocked = false
```

**File:** `tests/Feature/Wallets/BalanceTest.php`

Test scenarios:
```
✅ Balance updates correctly
✅ Concurrent requests don't cause race conditions
❌ Cannot debit more than balance (403)
❌ Cannot debit blocked wallet (403)
```

**Concurrency Test Example:**
```php
public function test_concurrent_debits_are_safe() {
    $wallet = Wallet::first();
    $wallet->balance = 1000;
    $wallet->save();
    
    // Simulate two concurrent requests
    Parallel::run([
        fn() => $this->walletService->updateBalance(1, 500, 'DEBIT'),
        fn() => $this->walletService->updateBalance(1, 300, 'DEBIT'),
    ]);
    
    $wallet->refresh();
    $this->assertEquals(200, $wallet->balance); // 1000 - 500 - 300
}
```

---

## Files to Create (Summary)

### Controllers
- `app/Http/Controllers/Wallets/WalletController.php`

### Services
- `app/Services/Wallets/WalletService.php`

### Policies
- `app/Policies/WalletPolicy.php`

### Exceptions
- `app/Exceptions/Wallets/WalletNotFoundException.php`
- `app/Exceptions/Wallets/WalletBlockedException.php`
- `app/Exceptions/Wallets/InsufficientBalanceException.php`

### Resources
- `app/Http/Resources/WalletResource.php`

### Requests
- `app/Http/Requests/Wallets/BlockWalletRequest.php`
- `app/Http/Requests/Wallets/UnblockWalletRequest.php`

### Routes
- Update `routes/api.php`

### Tests
- `tests/Feature/Wallets/GetWalletTest.php`
- `tests/Feature/Wallets/BlockWalletTest.php`
- `tests/Feature/Wallets/UnblockWalletTest.php`
- `tests/Feature/Wallets/BalanceTest.php`
- `tests/Unit/Services/Wallets/WalletServiceTest.php`

---

## Checklist

Before moving to Task 6, verify:

- [ ] User can view own wallet
- [ ] ADMIN can view all wallets
- [ ] ADMIN can block/unblock wallets
- [ ] Blocked wallet status recorded with timestamps
- [ ] Balance updates atomically
- [ ] Race conditions don't cause double-debit
- [ ] Insufficient balance prevents debit
- [ ] Blocked wallets cannot be debited
- [ ] Decimal precision maintained (DECIMAL 19,2)
- [ ] All tests pass

---

## Key Learnings

After completing this task, you should understand:

✅ Database transactions  
✅ Row locking  
✅ Atomic operations  
✅ Race condition prevention  
✅ Financial operation safety  
✅ Custom exceptions  

---

## Next Task

Once complete and all tests pass, proceed to **Task 6: Transaction Processing** (most complex).
