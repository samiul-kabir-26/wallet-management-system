# Database Assessment

## Critical Issues

### 1. ❌ CRITICAL: TRANSACTIONS Table Foreign Key Ambiguity

**Problem:**
```
TRANSACTIONS has:
  - user_id
  - agent_id
  - sender_id
  - recipient_id
  - initiated_by
```

These fields have unclear semantics:
- Is `user_id` the owner of the transaction record?
- Or does it represent a specific role in the transaction?
- Why are there 5 different user-like foreign keys?

**Example confusion:**
- A user makes a cash-in from an agent
- Is `user_id` the customer? `sender_id`? Both?
- Who is `initiated_by` when agent collects cash?

**Recommendation:**
Redesign TRANSACTIONS to clarify roles based on transaction type:

```
Type: TRANSFER
- sender_id -> user who sends
- recipient_id -> user who receives
- initiated_by -> who started it (same as sender)

Type: CASH_IN (user gives cash to agent, agent deposits to system)
- user_id -> customer
- agent_id -> the agent
- initiated_by -> customer OR agent?

Type: CASH_OUT (agent gives cash to user, system withdraws from user)
- user_id -> customer
- agent_id -> the agent
- initiated_by -> customer OR agent?

Type: DEPOSIT (user tops up via bank/payment)
- user_id -> customer
- initiated_by -> customer

Type: COMMISSION_PAYOUT (system pays agent commission)
- agent_id -> recipient of commission
- initiated_by -> system (or NULL)
```

**Action:** Clarify semantics in a comment or migration. At minimum, document what each field means for each transaction type.

---

### 2. ⚠️ CAPS Table: User Timezone Handling

**Problem:**
```
CAPS has:
  - daily_used
  - monthly_used
  - last_reset_date
```

No timezone information. When does "daily" reset? Midnight in which timezone?

**Issues:**
- User in UTC+6 (Bangladesh) vs UTC-5 (USA) see different "days"
- `last_reset_date` doesn't clarify intent
- Concurrent transactions near midnight are problematic

**Recommendation:**
- Store a `timezone` field on USERS (or use a configuration)
- Document that caps reset at midnight in user's timezone
- Test concurrent transactions at midnight boundaries

---

### 3. ⚠️ AGENT_INFO: Historical Commission Rate

**Problem:**
```
AGENT_INFO has:
  - commission_rate (current)
```

When system commission changes (SYSTEM_SETTINGS.agent_commission), old transactions retain stale rates.

**Current schema:**
- TRANSACTIONS.commission_rate stores the rate used for that transaction ✓ (correct)
- But AGENT_INFO only stores current rate ✗ (incomplete for history)

**Recommendation:**
This is actually OK as-is if:
- TRANSACTIONS.commission_rate is always set at transaction time
- You never need to query "what was agent X's commission rate on date Y"

If you need historical agent rates, add:
```
AGENT_COMMISSION_HISTORY {
  agent_id (FK)
  commission_rate
  effective_from
  effective_until (nullable)
}
```

For now, document that transaction commission is immutable once recorded.

---

### 4. ⚠️ WALLETS: Race Condition Risk

**Problem:**
```
WALLETS {
  balance (decimal)
  is_blocked (boolean)
}
```

When two concurrent transfers happen:
1. Thread A reads balance = 1000
2. Thread B reads balance = 1000
3. Thread A deducts 500 → 500
4. Thread B deducts 600 → 400 ❌ (should have failed)

**Recommendation:**
- Always use `DB::transaction()` with `SELECT ... FOR UPDATE` locking
- Never read and then update; update with conditions
- Detailed in Financial Architecture section

---

### 5. ⚠️ Missing Constraints

**Password:**
```
USERS.password
```
Should have NOT NULL constraint in migration.

**Transaction Status Enum:**
```
TRANSACTIONS.status ENUM (COMPLETED|PENDING|FAILED)
```
Should enforce at DB level (MySQL ENUM or CHECK constraint).

**Soft Deletes:**
```
USERS.is_deleted = false
```
OK but consider if you want `deleted_at` timestamp instead for auditing.

**Decimal Precision:**
```
All balance/amount fields use DECIMAL
```
Should specify `DECIMAL(15,2)` or similar in migrations.

---

### 6. ⚠️ System Settings Design

**Problem:**
```
SYSTEM_SETTINGS {
  key (unique)
  transaction_fee
  agent_commission
}
```

Stores all settings in one table. Current approach:
- `key` is unique (SYSTEM_SETTINGS)
- Multiple fields in one row

**Works, but unclear:**
- Is there only one row ever?
- What if you add 10 more settings?

**Recommendation:**
Keep it simple for now. Document that:
- There is exactly one SYSTEM_SETTINGS record
- Use migrations to update defaults, not seeders
- If settings become numerous, refactor to key-value structure later

---

### 7. ✅ What's Good

- ✓ Foreign key relationships are mostly clear
- ✓ Timestamps (created_at, updated_at) present
- ✓ Soft deletes (is_deleted) present for users
- ✓ User roles use junction table (USER_ROLES) ✓ scalable
- ✓ Auth providers allow multiple login methods
- ✓ Wallet is 1:1 with user (no multi-wallet confusion)

---

## Summary of Actions

### Immediate (Before Coding)
1. Document TRANSACTIONS fields: what does each mean for each transaction type?
2. Specify DECIMAL precision in migrations (e.g., DECIMAL(15,2))
3. Add NOT NULL constraint to passwords

### Before Transaction Implementation
1. Clarify timezone handling for caps
2. Plan database locking strategy for concurrent balance updates

### Later (Refactor if needed)
1. Add AGENT_COMMISSION_HISTORY if you need it
2. Move to key-value SYSTEM_SETTINGS if it grows
3. Consider `deleted_at` if soft deletes become complex

---

## Database Integrity Checklist

When writing migrations:
- [ ] All foreign keys have ON DELETE rules (CASCADE, RESTRICT, SET NULL)
- [ ] All money fields are DECIMAL(15,2)
- [ ] Unique constraints where appropriate (email, user_id on wallets, etc.)
- [ ] Indexes on frequently queried columns (user_id, agent_id, created_at)
- [ ] Comments on ambiguous fields
