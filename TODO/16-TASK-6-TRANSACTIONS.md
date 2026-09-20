# Task 6: Module 4 — Transaction Processing

**Status:** Ready after Task 5  
**Estimated Duration:** 6-8 hours  
**Difficulty:** Hard (most complex)

---

## Objective

Implement all transaction types with complete financial consistency, idempotency, and audit trails.

---

## What You'll Learn

- Service layer architecture
- Command pattern for transactions
- Fee and commission calculations
- Idempotency keys
- Transaction status management
- Financial data consistency
- Concurrency under load

---

## Transaction Types Overview

| Type | Flow | Actors |
|------|------|--------|
| TOP_UP | User deposits via agent | User + Agent |
| CASH_IN | User receives via agent | User + Agent |
| CASH_OUT | User withdraws via agent | User + Agent |
| TRANSFER | User to user | Sender + Recipient |
| AGENT_WITHDRAWAL | Agent withdraws commission | Agent |
| COMMISSION_PAYOUT | System pays commission | System + Agent |

---

## Step 1: Transaction Service Architecture

### Create Service Layer

**File:** `app/Services/Transactions/TransactionService.php`

Orchestrates transaction processing.

**File:** `app/Services/Transactions/TransactionProcessor.php`

Processes each transaction type.

**File:** `app/Services/Transactions/FeeCalculator.php`

Calculates system fees.

**File:** `app/Services/Transactions/CommissionCalculator.php`

Calculates agent commissions.

---

## Step 2: Implement Each Transaction Type

### Transaction 1: TOP_UP

**Route:** `POST /api/v1/transactions/top-up`

**Request:**
```json
{
  "amount": 5000,
  "agent_id": 2,
  "description": "Deposit via agent"
}
```

**Flow:**
1. Validate user, agent (must be APPROVED)
2. Get system fee rate from settings
3. Calculate:
   - system_fee_amount = amount * system_fee_rate
   - actual_credit = amount (no commission for top-up)
4. Update balances (in transaction):
   - User wallet: +amount
   - System wallet: +system_fee_amount
5. Create Transaction record:
   - type: TOP_UP
   - amount: 5000
   - system_fee_amount: calculated
   - status: COMPLETED
   - initiated_by: current user

---

### Transaction 2: CASH_IN

**Route:** `POST /api/v1/transactions/cash-in`

**Similar to TOP_UP but with agent commission.**

**Flow:**
1. Validate user, agent (APPROVED)
2. Calculate:
   - system_fee = amount * system_fee_rate
   - agent_commission = amount * agent.commission_rate
   - net_to_user = amount - system_fee - agent_commission
3. Update balances:
   - User wallet: +net_to_user
   - Agent wallet: +agent_commission
   - System: +system_fee
4. Create Transaction record

---

### Transaction 3: CASH_OUT

**Route:** `POST /api/v1/transactions/cash-out`

**Withdraw money via agent.**

**Flow:**
1. Validate user, agent (APPROVED)
2. Validate wallet not blocked
3. Check balance >= amount
4. Check caps not exceeded
5. Calculate fees/commissions (reverse: debit user)
6. Update balances:
   - User wallet: -amount
   - Agent wallet: +commission
   - System: +fee
7. Update caps:
   - daily_used += amount
   - monthly_used += amount
8. Create Transaction record

---

### Transaction 4: TRANSFER

**Route:** `POST /api/v1/transactions/transfer`

**User to user transfer.**

**Flow:**
1. Validate sender, recipient (both users)
2. Validate sender wallet not blocked
3. Check balance >= amount
4. Check caps not exceeded
5. Update balances (in transaction with row locks):
   - Sender: -amount
   - Recipient: +amount
6. Update sender caps
7. Create Transaction record with sender_id, recipient_id

---

### Transaction 5: AGENT_WITHDRAWAL

**Route:** `POST /api/v1/transactions/agent/withdrawal`

**Agent withdraws accumulated commission.**

**Flow:**
1. Validate current user is agent (APPROVED)
2. Validate wallet balance >= amount
3. Check caps if needed
4. Update balance: -amount
5. Create Transaction record

---

### Transaction 6: COMMISSION_PAYOUT (Automatic)

Triggered automatically after TOP_UP/CASH_IN transactions.

**Flow:**
1. Calculate commission from original transaction
2. Create wallet debit for agent
3. Create COMMISSION_PAYOUT transaction record
4. Update agent_info.total_commission

---

## Step 3: Critical: Transaction Consistency

### Idempotency Key

Prevent duplicate charges on retry.

```php
$transaction = Transaction::where('idempotency_key', $request->idempotency_key)
    ->first();

if ($transaction) {
    return $transaction; // Already processed
}

// Process new transaction
```

**Client:** Generate UUID or `hash('sha256', json_encode($request))`  
**Server:** Store idempotency_key, check before processing

---

### Database Transaction Wrapper

```php
public function processTransaction($data) {
    return DB::transaction(function () use ($data) {
        // Check idempotency
        if ($existing = Transaction::where('idempotency_key', $data['idempotency_key'])->first()) {
            return $existing;
        }
        
        // Lock all wallets involved
        $wallets = Wallet::whereIn('user_id', [$data['sender_id'], $data['recipient_id']])
            ->lockForUpdate()
            ->get();
        
        // Validate
        // Update balances
        // Create transaction record
        
        return $transaction;
    }, attempts: 3);
}
```

---

### Status Tracking

```php
// Always COMPLETED for successful transactions
// Only FAILED if exception thrown and rolled back
$transaction->status = 'COMPLETED';

// If error: exception caught in controller, respond 400/422
```

---

## Step 4: Cap Enforcement

### Create CapService

**File:** `app/Services/Caps/CapService.php`

**Methods:**
```php
public function checkCaps($userId, $amount) {
    $cap = Cap::where('user_id', $userId)->first();
    
    if ($cap->daily_used + $amount > $cap->daily_cap) {
        throw new DailyCapsExceededException();
    }
    
    if ($cap->monthly_used + $amount > $cap->monthly_cap) {
        throw new MonthlyCapsExceededException();
    }
    
    return true;
}

public function updateCaps($userId, $amount) {
    Cap::where('user_id', $userId)->update([
        'daily_used' => DB::raw("daily_used + {$amount}"),
        'monthly_used' => DB::raw("monthly_used + {$amount}"),
    ]);
}
```

### Create Scheduled Jobs

**File:** `app/Jobs/ResetDailyCaps.php`

Runs daily at midnight UTC.

```php
class ResetDailyCaps implements ShouldQueue {
    public function handle() {
        Cap::query()->update(['daily_used' => 0]);
    }
}
```

**File:** `app/Jobs/ResetMonthlyCaps.php`

Runs monthly on 1st at midnight UTC.

**Register in `app/Console/Kernel.php`:**
```php
$schedule->job(new ResetDailyCaps::class)->dailyAt('00:00')->timezone('UTC');
$schedule->job(new ResetMonthlyCaps::class)->monthlyOn(1, '00:00')->timezone('UTC');
```

---

## Step 5: API Endpoints

### Endpoint 1-4: Transaction Creation

Each transaction type has its own endpoint:
- `POST /api/v1/transactions/top-up`
- `POST /api/v1/transactions/cash-in`
- `POST /api/v1/transactions/cash-out`
- `POST /api/v1/transactions/transfer`
- `POST /api/v1/transactions/agent/withdrawal`

All follow similar pattern:
1. Authorize user
2. Validate request
3. Call TransactionService
4. Handle exceptions
5. Return transaction record

---

### Endpoint 5: Transaction History

**Route:** `GET /api/v1/transactions/history`

**Query Parameters:**
```
?type=TRANSFER&status=COMPLETED&page=1&per_page=15
```

**Response:**
```json
{
  "success": true,
  "data": {
    "transactions": [
      {
        "id": 1,
        "type": "TRANSFER",
        "amount": 500,
        "status": "COMPLETED",
        "sender": {...},
        "recipient": {...},
        "created_at": "2026-09-20T10:30:00Z"
      }
    ],
    "pagination": {...}
  }
}
```

**Authorization:**
- Users see own transactions (as sender, recipient, user_id, agent_id)
- ADMIN sees all transactions

**Logic:**
- Filter transactions where user involved
- Paginate
- Eager load relationships

---

### Endpoint 6: Transaction Details

**Route:** `GET /api/v1/transactions/:id`

**Response:**
```json
{
  "success": true,
  "data": {
    "transaction": {
      "id": 1,
      "type": "TRANSFER",
      "amount": 500,
      "system_fee_amount": 0,
      "agent_commission_amount": 0,
      "sender_id": 3,
      "recipient_id": 5,
      "status": "COMPLETED",
      "sender_wallet_balance_after": 4500,
      "recipient_wallet_balance_after": 5500,
      "created_at": "2026-09-20T10:30:00Z"
    }
  }
}
```

**Authorization:**
- User can view if involved (sender, recipient, agent, or user_id)
- ADMIN can view any

---

### Endpoint 7: Admin Transaction Audit

**Route:** `GET /api/v1/transactions/admin/all`

**Authorization:** ADMIN or SUPER_ADMIN

**Features:**
- List all transactions
- Filter by type, status, user, date range
- Pagination
- Sort by created_at

---

## Step 6: Testing

### Unit Tests

**File:** `tests/Unit/Services/Transactions/TransactionServiceTest.php`

Test:
- Fee calculations
- Commission calculations
- Cap checking

**File:** `tests/Unit/Services/Transactions/FeeCalculatorTest.php`

Test:
- Different fee rates
- Edge cases (0%, 100%)

---

### Feature Tests

**File:** `tests/Feature/Transactions/TransferTest.php`

```
✅ Valid transfer succeeds
✅ Sender balance decremented
✅ Recipient balance incremented
❌ Insufficient balance fails
❌ Blocked wallet fails
❌ Both users required
```

**File:** `tests/Feature/Transactions/CapsTest.php`

```
✅ Transaction succeeds within caps
❌ Transaction fails if exceeds daily cap
❌ Transaction fails if exceeds monthly cap
✅ Caps updated after transaction
```

**File:** `tests/Feature/Transactions/BlockedWalletTest.php`

```
❌ CASH_IN to blocked wallet fails
❌ CASH_OUT from blocked wallet fails
✅ TRANSFER from blocked wallet fails
✅ TRANSFER to blocked wallet succeeds (receives blocked)
```

**File:** `tests/Feature/Transactions/IdempotencyTest.php`

```
✅ First request succeeds
✅ Second request with same idempotency_key returns same transaction
✅ Balance not updated twice
```

**File:** `tests/Feature/Transactions/ConcurrencyTest.php`

```
✅ Two simultaneous transfers from same user don't double-spend
✅ Both transactions complete successfully with correct balances
❌ Third transaction that would exceed balance fails
```

**File:** `tests/Feature/Transactions/ConsistencyTest.php`

```
✅ Failed transaction doesn't partially update balances
✅ Rollback works on exception
✅ Audit trail records initiated_by correctly
✅ Transaction status correct (COMPLETED or FAILED)
```

---

## Files to Create (Summary)

### Services
- `app/Services/Transactions/TransactionService.php`
- `app/Services/Transactions/TransactionProcessor.php`
- `app/Services/Transactions/FeeCalculator.php`
- `app/Services/Transactions/CommissionCalculator.php`
- `app/Services/Caps/CapService.php`

### Controllers
- `app/Http/Controllers/Transactions/TransactionController.php`

### Requests
- `app/Http/Requests/Transactions/TopUpRequest.php`
- `app/Http/Requests/Transactions/CashInRequest.php`
- `app/Http/Requests/Transactions/CashOutRequest.php`
- `app/Http/Requests/Transactions/TransferRequest.php`
- `app/Http/Requests/Transactions/AgentWithdrawalRequest.php`

### Exceptions
- `app/Exceptions/Transactions/InsufficientBalanceException.php`
- `app/Exceptions/Transactions/DailyCapsExceededException.php`
- `app/Exceptions/Transactions/MonthlyCapsExceededException.php`
- `app/Exceptions/Transactions/AgentNotApprovedException.php`
- `app/Exceptions/Transactions/TransactionFailedException.php`

### Jobs
- `app/Jobs/ResetDailyCaps.php`
- `app/Jobs/ResetMonthlyCaps.php`

### Resources
- `app/Http/Resources/TransactionResource.php`

### Routes
- Update `routes/api.php`

### Tests
- `tests/Feature/Transactions/TransferTest.php`
- `tests/Feature/Transactions/TopUpTest.php`
- `tests/Feature/Transactions/CashInOutTest.php`
- `tests/Feature/Transactions/CapsTest.php`
- `tests/Feature/Transactions/BlockedWalletTest.php`
- `tests/Feature/Transactions/IdempotencyTest.php`
- `tests/Feature/Transactions/ConcurrencyTest.php`
- `tests/Feature/Transactions/ConsistencyTest.php`
- `tests/Unit/Services/Transactions/TransactionServiceTest.php`
- `tests/Unit/Services/Transactions/FeeCalculatorTest.php`

---

## Checklist

Before moving to Task 7, verify:

- [ ] All transaction types process correctly
- [ ] Balances update atomically
- [ ] Concurrent transactions don't cause race conditions
- [ ] Idempotency key prevents duplicates
- [ ] Caps enforced correctly
- [ ] Blocked wallets prevent operations
- [ ] Fees calculated accurately
- [ ] Commissions calculated and paid
- [ ] Transaction history shows correct data
- [ ] Admin can audit all transactions
- [ ] Scheduled jobs reset caps daily/monthly
- [ ] All tests pass
- [ ] Transaction records complete (balance_after, initiated_by, etc.)

---

## Key Learnings

After completing this task, you should understand:

✅ Service layer architecture  
✅ Financial transaction processing  
✅ Idempotency and deduplication  
✅ Cap enforcement  
✅ Commission payouts  
✅ Concurrency under load  

---

## Next Task

Once complete and all tests pass, proceed to **Task 7: System Settings**.
