# Financial Architecture

## Core Principle

**Financial operations are not simple CRUD. They are high-risk business logic requiring:**

- Atomicity (all or nothing)
- Consistency (balances always add up)
- Isolation (concurrent requests don't interfere)
- Durability (once done, never lost)
- Idempotency (retries are safe)
- Auditability (every change is traceable)

---

## The Problem: Concurrent Transfers

### Example Race Condition

```
Wallet A has $1000

Request 1: Transfer $600
Request 2: Transfer $600

Bad (no locking):
  Request 1: Read balance = 1000
  Request 2: Read balance = 1000
  Request 1: Deduct 600 → 400 (write)
  Request 2: Deduct 600 → 400 (write) ❌ WRONG
  Final balance: 400 (should be -200, or reject one)

Good (with locking):
  Request 1: Lock wallet → Read balance = 1000
  Request 2: Lock wallet → WAIT (locked by Request 1)
  Request 1: Deduct 600 → 400 (write)
  Request 1: Release lock
  Request 2: Lock wallet → Read balance = 400
  Request 2: Insufficient balance → REJECT
  Final balance: 400 ✓ CORRECT
```

---

## Database Locking Strategy

### Read-Lock (SELECT ... FOR UPDATE)

```php
DB::transaction(function() {
  $wallet = Wallet::where('user_id', $userId)
    ->lockForUpdate()  // Other requests WAIT
    ->first();
  
  if ($wallet->balance < $amount) {
    throw new InsufficientBalanceException();
  }
  
  $wallet->balance -= $amount;
  $wallet->save();
});
```

When Request 2 tries the same lock, it waits until Request 1 releases it.

### Multiple Wallets

When a transfer involves two wallets, lock both to avoid deadlock:

```php
DB::transaction(function() {
  // Lock both wallets in consistent order (by ID)
  // This prevents deadlock where:
  //   Request 1 locks A, waits for B
  //   Request 2 locks B, waits for A
  
  $fromWallet = Wallet::where('user_id', min($fromId, $toId))
    ->lockForUpdate()
    ->first();
  
  $toWallet = Wallet::where('user_id', max($fromId, $toId))
    ->lockForUpdate()
    ->first();
  
  // Now safe to modify both
  $fromWallet->balance -= $amount;
  $toWallet->balance += $amount;
  
  $fromWallet->save();
  $toWallet->save();
});
```

---

## Transaction Types & Logic

### 1. TOP_UP (User deposits money via bank/payment)

**Actors:** User, System

**Flow:**
```
User initiates top-up (external payment)
  ↓
System receives confirmation from payment gateway
  ↓
Lock user's wallet
  ↓
Add amount to wallet.balance
  ↓
Record transaction (TOP_UP, COMPLETED)
  ↓
Release lock
```

**What Can Go Wrong:**
- Payment gateway confirms twice (idempotency key prevents)
- System crashes after payment, before wallet update (transaction log is source of truth)
- Negative balance is impossible (only adds)

**Validation:**
- amount > 0
- amount reasonable for user's tier

---

### 2. TRANSFER (User sends money to another user)

**Actors:** Sender, Recipient, System

**Flow:**
```
User A initiates transfer to User B
  ↓
Validate:
  - Sender has amount
  - Sender wallet not blocked
  - Recipient wallet not blocked
  - Daily cap not exceeded for sender
  - Sender not suspended (if agent)
  ↓
Calculate:
  - fee = amount * system_transaction_fee
  - total_amount = amount + fee
  ↓
DB::transaction():
  Lock sender wallet
  Lock recipient wallet (in consistent order)
  
  Check:
    - Sender balance >= total_amount
    - Wallets not blocked
  
  Modify:
    - sender.balance -= total_amount
    - recipient.balance += amount  (recipient doesn't pay fee)
    - Update sender daily/monthly caps
  
  Record:
    - Transaction with TRANSFER, COMPLETED
    - Store balance_after for both
  ↓
Release locks
```

**Financial Invariant:**
```
Transaction.amount + Transaction.fee_amount = total_amount
sender.balance_before - total_amount = sender.balance_after
recipient.balance_before + amount = recipient.balance_after

AND never:
  balance < 0
  fee > 0.1 (reasonable upper limit)
```

**Test:**
```
Sender: 1000 → 700
Recipient: 500 → 900
Fee: 100 (10%)
Amount: 200

Invariant check:
  1000 - 300 = 700 ✓
  500 + 200 = 700 ✓
  200 + 100 = 300 ✓
```

---

### 3. CASH_IN (User gives cash to agent, agent deposits to system)

**Actors:** User, Agent, System

**Questions to clarify (from requirements assessment):**
- Does user pay the fee?
- Does agent pay the fee?
- Both?

**Assumption for now:**
User pays fee, agent gets net amount.

**Flow:**
```
User gives physical cash to agent
User initiates CASH_IN via app: {amount: 1000, agent_id: 5}
  ↓
Validate:
  - Agent exists and is APPROVED
  - User wallet not blocked
  - Amount reasonable
  - Daily cap not exceeded
  ↓
Calculate:
  - fee = amount * system_transaction_fee
  - commission = (amount - fee) * agent_commission_rate
  - agent_receives = amount - fee
  ↓
DB::transaction():
  Lock user wallet
  Lock agent wallet
  
  Check:
    - User balance >= amount
    - Agent wallet not blocked
  
  Modify:
    - user.balance -= amount (user pays net, fee included)
    - agent.balance += (amount - fee)
    - agent_info.total_commission += commission
    - Update user daily/monthly caps
  
  Record:
    Transaction: {
      type: CASH_IN,
      user_id: user_id,
      agent_id: agent_id,
      amount: amount,
      fee_amount: fee,
      commission_amount: commission,
      status: COMPLETED
    }
  ↓
Release locks
```

**Financial Check:**
```
User balance: 1000
CASH_IN: 1000
Fee (2%): 20
Commission (1%): 9.80

User balance after: 0 (paid 1000)
Agent balance before: 500
Agent balance after: 980 (received 1000 - 20)
Agent commission: +9.80 (recorded, not added to balance yet)

Invariant:
  user: 1000 - 1000 = 0 ✓
  agent: 500 + 980 = 1480 ✓
  fee: 20 ✓
  commission: 9.80 ✓
  total: 20 + 9.80 + 980 = 1000 ✓ (equals original amount)
```

---

### 4. CASH_OUT (User takes cash from agent)

**Assumption:**
User withdraws from their wallet, agent receives cash.

**Flow:**
```
User initiates CASH_OUT: {amount: 500, agent_id: 5}
  ↓
Validate:
  - Agent exists and is APPROVED
  - User balance >= amount
  - User wallet not blocked
  - Agent wallet not blocked
  ↓
Calculate:
  - fee = amount * system_transaction_fee
  - commission = amount * agent_commission_rate
  - agent_receives = amount + fee + commission
  ↓
DB::transaction():
  Lock user wallet
  Lock agent wallet
  
  Check:
    - User balance >= amount
  
  Modify:
    - user.balance -= amount (user only loses principal)
    - agent.balance += (amount + fee + commission)
    - agent_info.total_commission += commission
    - Update user caps
  
  Record:
    Transaction: {
      type: CASH_OUT,
      user_id: user_id,
      agent_id: agent_id,
      amount: amount,
      fee_amount: fee,
      commission_amount: commission,
      status: COMPLETED
    }
  ↓
Release locks
```

**Note:**
The fee + commission are benefits to the agent. This makes agent willing to service CASH_OUT (they get more).

---

### 5. AGENT_WITHDRAWAL (Agent withdraws their balance)

**Actors:** Agent, System

**Flow:**
```
Agent initiates withdrawal: {amount: 1000}
  ↓
Validate:
  - Agent is APPROVED (only approved can withdraw)
  - Agent balance >= amount
  - No pending transactions
  ↓
DB::transaction():
  Lock agent wallet
  
  Check:
    - Agent balance >= amount
  
  Modify:
    - agent.balance -= amount
  
  Record:
    Transaction: {
      type: AGENT_WITHDRAWAL,
      agent_id: agent_id,
      amount: amount,
      status: COMPLETED
    }
  ↓
Release lock
```

---

### 6. COMMISSION_PAYOUT (System pays agents their commission)

**Actors:** System, Agents (multiple)

**When?**
- Daily batch job?
- On-demand?
- Never (commission stays in agent_info.total_commission)?

**For now (MVP):** Commission is recorded but not automatically paid. Agents see it as `agent_info.total_commission`.

**Future:** Background job that creates COMMISSION_PAYOUT transactions periodically.

---

## Caps Implementation

```php
// Check if transaction would exceed daily cap
public function checkDailyCap(User $user, decimal $amount): bool {
  $cap = $user->caps;
  
  // Get today's usage in user's timezone
  $today = now($user->timezone)->startOfDay();
  
  $todayUsed = Transaction::where('user_id', $user->id)
    ->where('status', 'COMPLETED')
    ->whereDate('created_at', $today)
    ->sum('amount');
  
  return ($todayUsed + $amount) <= $cap->daily_cap;
}

// Reset caps daily (via cron or observer)
public function resetCapsIfNeeded(User $user): void {
  $cap = $user->caps;
  $now = now($user->timezone);
  $lastReset = $cap->last_reset_date?->setTimezone($user->timezone);
  
  // If last reset was yesterday in user's timezone, reset today
  if (!$lastReset || $lastReset->date() !== $now->date()) {
    $cap->daily_used = 0;
    $cap->last_reset_date = $now;
    
    // Reset monthly if new month
    if ($lastReset?->month !== $now->month) {
      $cap->monthly_used = 0;
    }
    
    $cap->save();
  }
}
```

**Concurrency Issue:**
Two transactions at midnight might both see `daily_used = 0` and reset.

**Solution:**
Use `last_reset_date` to determine if reset already happened today.

---

## Failure & Rollback

### What If Transaction Fails?

```php
DB::transaction(function() {
  // Step 1: Lock wallets
  $fromWallet = Wallet::lockForUpdate()->find($fromId);
  $toWallet = Wallet::lockForUpdate()->find($toId);
  
  // Step 2: Validate
  if ($fromWallet->balance < $amount) {
    throw new InsufficientBalanceException();
  }
  
  // Step 3: Modify
  $fromWallet->balance -= $amount;
  $toWallet->balance += $amount;
  
  // Step 4: Save
  $fromWallet->save();
  $toWallet->save();
  
  // Step 5: Record transaction
  Transaction::create([
    'type' => 'TRANSFER',
    'amount' => $amount,
    'status' => 'COMPLETED',
    ...
  ]);
  
  // If exception thrown ANYWHERE in this block:
  // - ALL database changes are rolled back
  // - Wallets revert to original state
  // - Transaction never created
});
```

This is guaranteed by Laravel's `DB::transaction()`.

---

## Idempotency

**Problem:**
Client retries a request before getting response. Server processes it twice.

**Solution:**
Idempotency key - client provides unique key per request.

```php
// Client sends:
POST /api/v1/transactions/transfer
{
  "amount": 100,
  "recipient_id": 5,
  "idempotency_key": "transfer-uuid-12345"
}

// Server:
// On first request: process normally, store result with key
// On retry: look up key, return cached result
```

For now (MVP), not required. But document it for future.

---

## Auditability: What to Record

Every transaction must record:

```
Transaction {
  id
  initiated_by       (who started it)
  sender_id          (who lost money)
  recipient_id       (who gained money)
  agent_id           (if agent involved)
  type               (TRANSFER, CASH_IN, etc.)
  amount             (principal amount)
  fee_amount         (system fee)
  commission_amount  (agent commission)
  commission_rate    (rate used, for historical record)
  fee_rate           (rate used)
  status             (COMPLETED, PENDING, FAILED)
  
  *_wallet_balance_after  (snapshot of balances after)
  
  created_at         (timestamp)
  meta               (JSON for extra data)
}
```

**Benefits:**
- Can replay any transaction to understand what happened
- Balances are snapshots, never lost
- Commission rates are recorded (what % was used?)
- Can audit who approved agents, changed settings

---

## Testing Financial Operations

```php
// tests/Feature/Transactions/TransferTest.php

class TransferTest extends TestCase {
  public function test_transfer_updates_both_balances() {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    
    $sender->wallet->balance = 1000;
    $sender->wallet->save();
    
    $recipient->wallet->balance = 500;
    $recipient->wallet->save();
    
    $this->actingAs($sender)
      ->postJson('/api/v1/transactions/transfer', [
        'amount' => 100,
        'recipient_id' => $recipient->id,
      ])
      ->assertStatus(201);
    
    $sender->wallet->refresh();
    $recipient->wallet->refresh();
    
    // Assuming 2% fee
    $this->assertEquals(897, $sender->wallet->balance); // 1000 - 100 - 2 - 1 commission
    $this->assertEquals(600, $recipient->wallet->balance); // 500 + 100
  }
  
  public function test_concurrent_transfers_dont_overdraw() {
    $sender = User::factory()->create();
    $recipient1 = User::factory()->create();
    $recipient2 = User::factory()->create();
    
    $sender->wallet->balance = 100;
    $sender->wallet->save();
    
    // Simulate concurrent requests
    $request1 = function() use ($sender, $recipient1) {
      $this->actingAs($sender)
        ->postJson('/api/v1/transactions/transfer', [
          'amount' => 60,
          'recipient_id' => $recipient1->id,
        ]);
    };
    
    $request2 = function() use ($sender, $recipient2) {
      $this->actingAs($sender)
        ->postJson('/api/v1/transactions/transfer', [
          'amount' => 60,
          'recipient_id' => $recipient2->id,
        ]);
    };
    
    // In reality, one should succeed and one should fail
    // This test needs queue or parallel request simulation
  }
  
  public function test_transfer_records_transaction() {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    
    $this->actingAs($sender)
      ->postJson('/api/v1/transactions/transfer', [
        'amount' => 100,
        'recipient_id' => $recipient->id,
      ]);
    
    $transaction = Transaction::latest()->first();
    
    $this->assertEquals('TRANSFER', $transaction->type);
    $this->assertEquals('COMPLETED', $transaction->status);
    $this->assertEquals($sender->id, $transaction->sender_id);
    $this->assertEquals($recipient->id, $transaction->recipient_id);
    $this->assertNotNull($transaction->fee_amount);
  }
}
```

---

## Summary

Financial operations require:

✅ Atomic transactions (all or nothing)
✅ Row-level locking (prevent race conditions)
✅ Comprehensive validation before modifications
✅ Transaction records that capture every detail
✅ Careful error handling and rollback
✅ Cap enforcement with timezone awareness
✅ Commission tracking and audit trails
✅ Extensive testing, especially concurrency

This is not simple CRUD. Treat it with respect.
