# Requirements Decisions — Finalized

Based on clarifications provided, these are the definitive requirements for the wallet system.

---

## 1. Cash-In Fees: Who Pays?

**Decision:** User pays nothing. Agent receives full amount.

```
User sends: 1000
User pays: 1000
Agent receives: 1000
System fee: 0
Agent commission: 0
```

**Reasoning:**
- Cash-in is "getting money into the system" — minimal friction.
- Goal: Encourage users to load their wallets.
- No intermediary costs for the user.

---

## 2. Cash-Out Fees: Who Pays?

**Decision:** User pays 5% system fee. Agent commission (1%) comes from system fee.

```
User initiates withdrawal: 1000
System fee (5%): 50
Agent commission (1% of fee): 10
User's wallet decreases by: 1050 (1000 + 50)
Agent's wallet increases by: 1010 (1000 + 10 commission)
System retains: 40 (50 - 10 to agent)
```

**Reasoning:**
- User pays the full fee upfront.
- System takes 5% as operational cost.
- Agent receives 1% of that fee as commission (incentive for providing cash-out service).
- Aligns incentives: more cash-outs = more agent commission.

---

## 3. Failed Transactions: Do They Count Toward Caps?

**Decision:** Failed transactions do NOT count toward caps.

```
dailyCap = 10,000
Transaction 1: Transfer 9,000 → COMPLETED
Transaction 2: Transfer 5,000 → FAILED (insufficient balance)

Result: daily_used = 9,000 (NOT 14,000)
```

**Reasoning:**
- Caps measure actual money moved, not attempted moves.
- Users shouldn't be penalized for failed attempts.
- Simpler cap logic: only successful transactions increment counters.
- Fairer to users.

---

## 4. Transaction Type Names: Confirm My Mapping

**Decision:** YES, the mapping is correct.

```
TOP_UP              ← /transactions/top-up (admin deposits)
CASH_IN             ← /transactions/cash-in (user receives via agent)
CASH_OUT            ← /transactions/cash-out (user withdraws via agent)
TRANSFER            ← /transactions/transfer (user to user)
AGENT_WITHDRAWAL    ← /transactions/agent/withdrawal (agent withdraws)
COMMISSION_PAYOUT   ← System background job (monthly commission settlement)
```

**Reasoning:**
- Matches endpoint semantics.
- Avoids confusion between generic terms (DEPOSIT, WITHDRAWAL) and specific operations.
- Clear audit trail.

---

## 5. Transaction Visibility: Who Can See What?

**Decision:** Participants + Admins can view transactions.

### Scenario A: User A transfers to User B
- User A can view? **YES** (sender)
- User B can view? **YES** (recipient)
- Admin can view? **YES** (administrative oversight)
- Random User C can view? **NO** (unauthorized)

### Scenario B: User receives cash-in from Agent
- User can view? **YES** (recipient)
- Agent can view? **YES** (service provider)
- Admin can view? **YES** (oversight)

**Reasoning:**
- Users should see their own transaction history.
- Agents should track their service transactions.
- Admins have full visibility (audit, compliance, fraud detection).
- Privacy: users can't see unrelated transactions.

---

## 6. Agent Commission: Fixed or Variable?

**Decision:** Variable. Old transactions keep original rate; new transactions use new rate.

**Scenario:**
```
Agent commission_rate = 0.01 (1%)

// Transaction 1 (2026-01-15)
cash-out 1000 with 1% commission
→ transaction record stores commission_rate = 0.01

// Admin changes SYSTEM_SETTINGS
agent_commission_rate = 0.02 (2%)

// Transaction 2 (2026-01-16)
cash-out 1000 with 2% commission
→ transaction record stores commission_rate = 0.02
```

**Reasoning:**
- Commission rates must be recorded at transaction time for accuracy.
- Prevents retroactive calculations.
- Enables rate auditing: "What rate applied when this transaction occurred?"
- If rates changed, old transactions keep original rate. New transactions use new rate.
- Fairness: agents know their exact commission when transaction completes.

---

## 7. Agent Approval: Permanent or Revocable?

**Decision:** Agents can be suspended and later re-approved. Suspension is not permanent.

**State Machine:**
```
PENDING → APPROVED → SUSPENDED → APPROVED (re-approved)
                  ↘ DELETED (permanent removal)
```

**Pending Transaction Handling:**
```
Agent is APPROVED, transactions are PENDING.
Admin suspends agent → transactions still process.
Admin deletes agent → pending transactions are CANCELLED.
```

**Reasoning:**
- Suspension enables temporary deactivation (temporary issues, policy violations).
- Re-approval allows agent to return after suspension is lifted.
- Deletion is permanent (agent removed from system entirely).
- Pending transactions on suspended agents still complete (fairness to users).
- Pending transactions on deleted agents are cancelled (no longer valid).

---

## 8. First SUPER_ADMIN: How Created?

**Decision:** Via Laravel seeder (one-time setup). No API endpoint.

**Process:**
```bash
# Initial setup
php artisan db:seed --class=SuperAdminSeeder

# Creates:
# - SUPER_ADMIN role
# - First SUPER_ADMIN user (email + password)
# - ADMIN and other roles
```

**Subsequent Admin Creation:**
```
Only SUPER_ADMIN can create new SUPER_ADMIN via API.
Only SUPER_ADMIN or ADMIN can create new ADMIN via API.
No public endpoint to create SUPER_ADMIN.
```

**Reasoning:**
- Bootstrap problem: first SUPER_ADMIN must come from somewhere.
- Seeder is Laravel convention (safe, version-controlled, auditable).
- Subsequent admins created via API with proper authorization.
- Security: prevents unauthorized admin creation.

---

## 9. Audit Trail: How Detailed?

**Decision:** Detailed audit logging via columns + optional audit table.

**Level 1: Column-based audit (minimum)**
```
Tables with sensitive fields get audit columns:
- approved_by (BIGINT FK to users)
- approved_at (TIMESTAMP)
- suspended_by (BIGINT FK to users)
- suspended_at (TIMESTAMP)
- blocked_by (BIGINT FK to users)
- blocked_at (TIMESTAMP)
- updated_by (BIGINT FK to users)
```

**Level 2: Audit log table (optional, for compliance)**
```
audit_logs:
  - id
  - user_id (who performed action)
  - action (approved_agent, suspended_agent, changed_settings, etc.)
  - resource_type (agent, wallet, setting)
  - resource_id (agent_info.id, wallet.id, etc.)
  - old_values (JSON: what changed from)
  - new_values (JSON: what changed to)
  - timestamp
```

**Tracked Actions:**
- Agent approval / suspension / deletion
- Wallet blocking / unblocking
- System settings changes
- High-risk transactions (manual review)

**Reasoning:**
- Financial system requires auditability.
- Column-based audit is simple, covers most use cases.
- Audit log table for future compliance (PCI-DSS, regulatory).
- Trace who did what and when.

---

## 10. Multi-Currency Support: In Scope?

**Decision:** Single currency (BDT) only. No multi-currency in MVP.

**Implications:**
```
- All wallets use BDT
- All transactions in BDT
- No currency conversion logic
- No exchange rates
- Database: currency field set to 'BDT' (constant)
```

**Refactoring Path (if needed later):**
- If multi-currency needed: add currency field to transactions, exchange rate lookups.
- Current single-currency design simplifies implementation significantly.

**Reasoning:**
- MVP simplicity.
- BDT is the primary requirement.
- No wasted complexity for theoretical future.
- Refactoring to multi-currency is straightforward if needed.

---

## Additional Clarifications (From Architecture Analysis)

### Authentication Strategy

> ⚠️ **Superseded 2026-09-20.** The auth model was revised to three login routes with scoped tokens, multi-role users, and staff-initiated PIN reset. **`CLAUDE.md` §9 is authoritative.** The two lines struck through below are no longer correct; the rest still holds.

- **Users/Agents:** Phone number + 5-10 digit PIN (numeric only)
- ~~**Admins/Super Admins:** Email + password + 6-digit OTP~~ → identifier may now be email **or** phone number. OTP unchanged (5-min validity, single-use, rate-limited)
- ~~**PIN Recovery:** SMS OTP~~ → **no self-service recovery.** Customer care verifies identity out of band, attaches an email, and sends a one-time signed link through which the user sets their own PIN
- **PIN Usage:** Same PIN for login AND wallet transaction verification — this is precisely why staff must never choose a PIN value

### Authorization Rules
- **SUPER_ADMIN:** Can register users, agents, and admins. Full system access.
- **ADMIN:** Can register users and agents (not admins). Can approve agents, block wallets, view transactions.
- **MODERATOR:** Subset of ADMIN permissions. Set by admin. No transaction access (no personal wallet operations).
- **AGENT:** Can participate in cash-in/cash-out, view own transactions, receive commissions.
- **USER:** Can perform all user transactions, view own transactions, see own wallet.

### Blocked Wallet Behavior
- **Blocked wallets cannot:**
  - Send money (CASH_OUT, TRANSFER)
  - Receive money (CASH_IN)
- **Enforcement:** Check wallet.is_blocked before any financial operation.

### Idempotency
- **Requirement:** Retried transactions (same request) should not charge twice.
- **Implementation:** Add `idempotency_key` (UUID) to transactions table. Client sends unique key with request.

### Cap Reset Timezone
- **All times:** UTC
- **Reset logic:** Scheduled job runs daily at midnight UTC to reset caps.
- **Storage:** All timestamps in UTC. Frontend displays in user's timezone.

---

## Next Steps

1. ✅ Requirements decisions finalized
2. → Database schema review and migrations
3. → Project setup and initial Laravel scaffolding
4. → Module 1: Users & Authentication implementation

**Ready to proceed to project setup?**

