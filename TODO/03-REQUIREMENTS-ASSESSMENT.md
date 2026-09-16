# Requirements Assessment

## Ambiguities & Clarifications Needed

### 1. Cash-In / Cash-Out: Who Pays Commission?

**Requirement:**
```
POST /api/v1/transactions/cash-in
{
  amount: 1000,
  agentId: 5,
  description: "..."
}
```

**Ambiguity:**
Who pays the 1% agent commission?

**Options:**

A) **User pays commission (common in fintech)**
   - User: sends 1000
   - Agent gets: 990
   - Agent comm: 10 (paid by user)
   - Total charged to user: 1000

B) **Agent pays commission (less common)**
   - User: sends 1000
   - Agent receives: 1000
   - Agent pays commission: 10
   - Agent's commission revenue: 10

C) **System absorbs commission (unlikely)**
   - Agent gets 1000 + commission bonus

**Recommendation:**
In real fintech (bKash, Nagad):
- Users pay fees + commission goes to agent
- Example: "Cash-in 1000, fee: 20 (2%), you pay 1020"

**Decision needed:** Should the `amount` be gross (user pays) or net (amount deposited)?

---

### 2. Cap Inclusion: Does Failed Transaction Count?

**Requirement:**
```
User has dailyCap = 10,000
User makes transaction: 9,000 (succeeds)
User makes transaction: 2,000 (fails - insufficient balance)
```

**Question:**
- Is `daily_used` now 9,000 or 11,000?
- Should failed transactions count toward caps?

**Recommendation:**
- Only COMPLETED transactions count toward caps
- PENDING transactions should not count yet
- FAILED transactions never count

This is more fair to users and easier to reason about.

---

### 3. Agent Withdrawal: From Where?

**Requirement:**
```
POST /api/v1/transactions/agent/withdrawal
{
  amount: 500,
  description: "..."
}
```

**Ambiguity:**
Is this:
- Agent withdrawing their balance? (like a bank withdrawal)
- Agent withdrawing their accumulated commission?
- Both mixed?

**Recommendation:**
Clarify:
- Agent withdrawal is from their **wallet balance**
- Commission is earned separately and rolls into balance
- When agent withdraws 500 and has 1000 balance, new balance = 500

This is simpler. Commission goes into wallet balance, not a separate account.

---

### 4. Transaction Types: What Triggers Each?

**Current types:**
```
DEPOSIT
WITHDRAWAL
TRANSFER
COMMISSION_PAYOUT
```

**But requirements list:**
```
top-up
cash-in
cash-out
transfer
agent/withdrawal
```

**Mapping unclear:**

- top-up → DEPOSIT? (user adds money via bank)
- cash-in → ? (user receives cash from agent)
- cash-out → WITHDRAWAL? (user takes cash from agent)
- transfer → TRANSFER ✓
- agent/withdrawal → WITHDRAWAL? (but who is recipient)

**Recommendation:**
Rename transaction types to match endpoints:

```
ENUM transaction_type:
  TOP_UP              (user adds money to wallet)
  CASH_IN             (user receives physical cash from agent, agent deducts from their wallet)
  CASH_OUT            (user sends money to agent as cash)
  TRANSFER            (user sends money to another user)
  AGENT_WITHDRAWAL    (agent withdraws their balance)
  COMMISSION_PAYOUT   (system pays commission - automatic)
```

This makes the transaction ledger self-documenting.

---

### 5. Authorization: Can a User See Other Users' Transactions?

**Requirement:**
```
GET /api/v1/transactions/history
GET /api/v1/transactions/:id
```

**Missing details:**

- Can User A see User B's transaction records?
- Can User A see transactions where they are mentioned but not the primary subject?
  - Example: User A transfers to User B. Can User B see it in their history?

**Recommendation:**

| Scenario | Access? |
|----------|---------|
| User views own transactions | ✓ YES |
| User views transaction they're a party to | ✓ YES (sender/recipient) |
| User views transaction they initiated | ✓ YES (initiated_by) |
| User views agent's transaction records | ✗ NO |
| Admin views any transaction | ✓ YES |

Implement via Policy.

---

### 6. Caps: Multiple Types?

**Current design:**
```
dailyCap
monthlyCap
dailyUsed
monthlyUsed
```

**Question:**
Are these caps per-transaction-type or global?

**Example:**
- User: dailyCap = 10,000
- User does cash-in: 5,000 ✓
- User does cash-out: 6,000 ❌ (total 11,000 > 10,000)?

Or:

- User has separate caps per transaction type?
- cash-in cap = 5,000/day
- cash-out cap = 10,000/day

**Recommendation:**
Keep it simple (global caps for now):
- All transactions (cash-in, cash-out, transfer) count toward the same daily/monthly cap
- Can refine per-type later if needed

---

### 7. Refresh Token: Expiration?

**Requirement:**
```
POST /api/v1/auth/refresh-token
```

**Missing:**
- How long are refresh tokens valid?
- Can refresh tokens be revoked?
- Are they stored in DB or stateless (JWT)?

**Recommendation:**
- Access token: short-lived (15-60 minutes)
- Refresh token: longer-lived (7-30 days)
- Consider storing refresh tokens in DB for revocation on logout
- See Authentication Strategy section

---

### 8. Admin Registration: Seed or Endpoint?

**Requirement:**
> Admin can only be registered by SUPER_ADMIN

**Question:**
- How is the first SUPER_ADMIN created?
- Via migration seed or endpoint?

**Recommendation:**
- First SUPER_ADMIN via Laravel seeder (run once)
- Subsequent admins via `/api/v1/user/register` by SUPER_ADMIN
- This bootstrap problem is common in auth systems

---

### 9. Agent Approval: Temporary State?

**Requirement:**
```
PATCH /api/v1/user/:id/approve-agent
PATCH /api/v1/user/:id/suspend-agent
```

**Ambiguity:**
- Suspend is revocation, not deletion?
- Can an approved agent be re-suspended and re-approved?
- What happens to pending transactions when agent is suspended?

**Recommendation:**
- Approval is a boolean toggle (is_approved)
- Suspended agents cannot initiate new transactions
- Existing transactions remain in their state
- Document this clearly

---

### 10. Audit Trail: Who Made Changes?

**Requirement:**
```
Changes must be auditable
Who initiated an operation
Who approved an agent
Who changed system settings
```

**Missing implementation:**
- How are these changes logged?
- Database table with audit entries?
- Or just rely on `updated_by` foreign key?

**Recommendation:**
For now, use `updated_by` on tables where appropriate:
- AGENT_INFO.approved_by ✓ (already in schema)
- SYSTEM_SETTINGS.updated_by ✓ (already in schema)
- WALLETS.updated_by (add later for block/unblock)

Later, add comprehensive audit logging if needed.

---

## Summary: Must Clarify Before Coding

| # | Item | Decision Needed |
|---|------|-----------------|
| 1 | Cash-in/out fees | Who pays commission? |
| 2 | Failed transactions | Count toward caps? |
| 3 | Agent withdrawal | From wallet or commission only? |
| 4 | Transaction types | Map endpoints to enum values |
| 5 | Transaction visibility | Who can see what? |
| 6 | Caps | Global or per-type? |
| 7 | Refresh tokens | Expiration & revocation strategy |
| 8 | First SUPER_ADMIN | Creation via seed or endpoint? |
| 9 | Agent suspend | Revocable or permanent? |
| 10 | Audit trail | Comprehensive logging or `updated_by` fields? |

---

## Missing Features (Not Showstoppers)

These are good to have but not blocking:

- [ ] Transaction search / filtering API
- [ ] Export transaction CSV for admin
- [ ] Email notifications on transactions
- [ ] 2FA for sensitive operations
- [ ] Transaction reversal/refund
- [ ] Dispute handling
- [ ] Rate limiting on endpoints
- [ ] API key management for 3rd parties
- [ ] Webhook notifications
- [ ] Admin dashboard metrics

---

## Assumptions

These are what we're proceeding with until clarified:

1. **Modular architecture** - Not a monolithic `app/` folder
2. **API-first** - Web and Flutter consume the same API
3. **Single wallet per user** - Not multi-currency, multi-account
4. **Deterministic roles** - No role inheritance; USER, AGENT, ADMIN, etc. are distinct
5. **BDT currency only** - No multi-currency support yet
6. **No transaction reversal** - Once completed, transactions are immutable
7. **No complex disputes** - Disputes are out of scope for MVP
8. **No scheduled transactions** - All transactions are immediate
