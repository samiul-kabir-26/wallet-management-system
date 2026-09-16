# First Implementation Task

## Objective

Clarify ambiguous requirements so we can confidently start Module 1.

---

## Your Task: Answer These 10 Questions

Review `03-REQUIREMENTS-ASSESSMENT.md` and your own project requirements.

Provide clear answers to each question below. These answers will drive the financial logic implementation.

### 1. Cash-In Fees: Who Pays?

**Scenario:**
User initiates cash-in from Agent:
- User sends 1000 BDT to agent
- System fee rate: 2%
- Agent commission: 1%

**Questions:**
- Does the **user** pay both fee and commission?
- Or does only the **user** pay fee, and **agent** pays commission?
- Or split between them?

**Examples:**

*Option A: User pays all*
```
User sends: 1000
User pays: 1000
Fee (2%): 20 → System
Commission (1%): 10 → Agent
Agent receives: 970
User final: -1000
Agent final: +970
```

*Option B: User pays fee, agent pays commission*
```
User sends: 1000
Fee (2%): 20 → System (paid by user, so user sends 1020)
Commission (1%): 10 → System (paid by agent later)
User pays: 1020
Agent receives: 1000
Agent commission: -10 (paid later)
```

**What should happen?** (Answer: A or B or other)

---

### 2. Cash-Out Fees: Similar Question

**Scenario:**
User wants to withdraw 1000 BDT via agent:
- System fee: 2%
- Agent commission: 1%

**Question:**
- Does user pay fee + commission?
- Or agent covers these costs (and keeps remainder)?
- Or something else?

**Examples:**

*Option A: User pays all, agent keeps remainder*
```
User requests: 1000
Fee (2%): 20 → System
Commission (1%): 10 → Agent profit
User deduction: 1000
Agent receives: 990 (1000 - 20 + 10 commission)
```

*Option B: Agent collects cash but also gets fee+commission*
```
User requests: 1000
User deduction: 1000
Agent receives: 1000 + fee(20) + commission(10) = 1030
(Agent gets more, incentivizes cash-out)
```

**What should happen?** (Answer: A or B or other)

---

### 3. Failed Transactions: Do They Count Toward Caps?

**Scenario:**
User has dailyCap = 10,000
```
Transaction 1: Transfer 9000 → COMPLETED
Transaction 2: Transfer 5000 → FAILED (insufficient balance)
```

**Questions:**
- Is daily_used = 9000 or 14000?
- Should failed transactions count?

**Your answer:**

---

### 4. Transaction Type Names: Confirm My Mapping

I proposed renaming transaction types to match endpoints:

```
TOP_UP              ← /transactions/top-up
CASH_IN             ← /transactions/cash-in
CASH_OUT            ← /transactions/cash-out
TRANSFER            ← /transactions/transfer
AGENT_WITHDRAWAL    ← /transactions/agent/withdrawal
COMMISSION_PAYOUT   ← System background job
```

**Is this mapping correct?**

---

### 5. Transaction Visibility: Who Can See What?

**Scenarios:**

*Scenario A: User A transfers to User B*
- User A can view? (YES / NO)
- User B can view? (YES / NO)
- Admin can view? (YES / NO)
- Random User C can view? (YES / NO)

*Scenario B: Agent does cash-in from User*
- User can view? (YES / NO)
- Agent can view? (YES / NO)
- Admin can view? (YES / NO)

**Answer for each scenario:**

---

### 6. Agent Commission: Fixed or Variable?

**Scenario:**
- Agent has commission_rate = 0.01 (1%)
- System changes agent_commission from SYSTEM_SETTINGS to 0.02 (2%)

**Questions:**
- Old transactions (CASH_IN before change): Do they keep 1% commission?
- Or retroactively apply 2%?

**Your answer:**
(I recommend: old transactions keep their original rate, new transactions use new rate)

---

### 7. Agent Approval: Permanent or Revocable?

**Scenario:**
- Agent is approved: is_approved = true
- Later, admin suspends agent: ???

**Questions:**
- Can approved agents be suspended and later re-approved?
- Or is suspension permanent (delete from agent table)?
- What happens to pending transactions when suspended?

**Your answer:**

---

### 8. First SUPER_ADMIN: How Created?

**Question:**
- Via Laravel seeder (one-time setup)?
- Via API endpoint that anyone can call?
- Via artisan command?

**Your answer:**
(I recommend: seeder, then no one else can create SUPER_ADMIN except existing SUPER_ADMIN via API)

---

### 9. Audit Trail: How Detailed?

**Question:**
For admin audit logs, do you need:
- Just `updated_by` field on affected tables?
- Or full audit history table with all changes?
- Or detailed logs of who approved agents, changed settings?

**Your answer:**

---

### 10. Multi-Currency Support: In Scope?

**Question:**
- Single currency (BDT) only?
- Or plan for multi-currency from the start?

**Your answer:**
(I recommend: single currency MVP, refactor later if needed)

---

## How to Submit Answers

Create a file: `TODO/10-REQUIREMENTS-DECISIONS.md`

Format:

```markdown
# Requirements Decisions

## 1. Cash-In Fees
**Answer:** User pays all (fee + commission)
**Reasoning:** Similar to Nagad/bKash model

## 2. Cash-Out Fees
**Answer:** Agent collects + gets fee incentive
**Reasoning:** ...

## 3. Failed Transactions
**Answer:** Do NOT count toward caps
**Reasoning:** ...

... (continue for all 10)
```

---

## After You Answer

I will:

1. Review your answers for consistency
2. Point out any financial implications
3. Clarify edge cases
4. Then confirm we're ready to start Module 1

Once confirmed, you'll start:
- Creating migrations
- Building the User model
- Implementing authentication

No more waiting. You'll be coding.

---

## Important Notes

- **Don't overthink this.** There are often multiple valid approaches.
- **Be practical.** Choose what's simplest for MVP, refactor later if needed.
- **Ask for guidance** if something seems wrong or contradictory.
- **Document your reasoning.** Future you (and team members) will thank you.

---

## Next Steps (After You Answer)

1. ✅ You answer the 10 questions
2. ✅ I review for consistency
3. ✅ We finalize database schema if needed
4. ✅ **Module 1: Users & Authentication begins**

Let me know when you've written your answers!
