# Improvement Proposals

Status: proposals. Done: 1 and 2 (branch `feat/sslcommerz-top-up`), 3 (branch `feat/top-up-reconciliation`), 4 (branch `feat/ledger-integrity-check`), 5 (branch `feat/opening-balance-backfill`), 6 (branch `feat/transaction-receipts`), 7 (branch `feat/sms-gateway`), 8 (branch `feat/transaction-reversals`), 9 (branch `feat/maker-checker`), 10 (branch `feat/agent-float-requests`), 12 (branch `feat/signup-bonus-controls`), 16 (branch `chore/engineering`).
Status: proposals. Done: 1 and 2 (branch `feat/sslcommerz-top-up`), 3 (branch `feat/top-up-reconciliation`), 4 (branch `feat/ledger-integrity-check`), 5 (branch `feat/opening-balance-backfill`), 6 (branch `feat/transaction-receipts`), 7 (branch `feat/sms-gateway`), 8 (branch `feat/transaction-reversals`), 9 (branch `feat/maker-checker`), 10 (branch `feat/agent-float-requests`), 12 (branch `feat/signup-bonus-controls`), 15 (branch `feat/security-hardening`).
Status: proposals. Done: 1 and 2 (branch `feat/sslcommerz-top-up`), 3 (branch `feat/top-up-reconciliation`), 4 (branch `feat/ledger-integrity-check`), 5 (branch `feat/opening-balance-backfill`), 6 (branch `feat/transaction-receipts`), 7 (branch `feat/sms-gateway`), 8 (branch `feat/transaction-reversals`), 9 (branch `feat/maker-checker`), 10 (branch `feat/agent-float-requests`), 12 (branch `feat/signup-bonus-controls`), 14 (branch `feat/admin-metrics-export`).
Last reviewed against: `7afe84b` (signup bonus, commission and profile editing, directory search).

Each proposal says what changes, why, which module it belongs to, and what can go wrong. Items marked **Money** move or create balance and need the full treatment: DB transaction, row locks, idempotency, audit entry, and tests for the financial invariants.

---

## Summary

| # | Proposal | Area | Priority | Effort |
|---|---|---|---|---|
| 1 | Top-up through the SSLCommerz sandbox — **done** | Transactions, User panel | High | L |
| 2 | Remove the PIN from top-up — **done** | Transactions, User panel | High (ships with 1) | S |
| 3 | Reconcile stuck gateway payments — **done** | Transactions | High (ships with 1) | M |
| 4 | Ledger integrity check — **done** | Wallets, Transactions | High | S |
| 5 | Opening-balance backfill for legacy wallets — **done** | Wallets | Medium | S |
| 6 | Transaction receipts (SMS / email) — **done** | Transactions, Notifications | Medium | M |
| 7 | Real SMS gateway — **done** (Twilio; free trial texts verified numbers) | Authentication | Medium | M |
| 8 | Reversals and refunds — **done** (single approver; maker-checker is proposal 9) | Transactions | Medium | L |
| 9 | Maker-checker for sensitive admin actions — **done** (float, settings, reversals, commission rates) | Roles, SystemSettings | Medium | L |
| 10 | Agent float requests — **done** | Transactions, Agent panel | Medium | M |
| 11 | KYC tiers and per-user caps | Users, Caps | Medium | L |
| 12 | Signup bonus abuse controls — **done** (daily budget, clawback; no deactivation trigger exists yet) | Transactions | Medium | M |
| 13 | Request money | Transactions, User panel | Low | M |
| 14 | Admin dashboard metrics and ledger export | Admin panel | Low | M |
| 15 | Security hardening (device binding, login alerts, TOTP) — **done** (plus session list) | Authentication | Low–Medium | M–L |
| 14 | Admin dashboard metrics and ledger export — **done** | Admin panel | Low | M |
| 15 | Security hardening (device binding, login alerts, TOTP) | Authentication | Low–Medium | M–L |
| 16 | Engineering (OpenAPI, CI, static analysis) — **done** (PHPStan level 7 now covers Modules; level 8 is 82 errors away) | Project | Ongoing | S–M |

---

## 1. Top-up through the SSLCommerz sandbox **(Money)**

### Today

`POST /transactions/top-up` credits the wallet immediately with `status = COMPLETED`. `docs/API.md` §5.1 says so itself: `TOP_UP` "stands in for a bank or card payment gateway and creates new e-money". In other words, anyone with a user token can create money.

### Proposal

Credit the wallet **only after SSLCommerz confirms the payment**. The user enters an amount on the Wallet page, is redirected to the SSLCommerz sandbox checkout, pays with a test card or mobile banking, and comes back to a result page.

### Flow

```text
User panel                 Laravel API                         SSLCommerz (sandbox)
    |  POST /transactions/top-up   |                                     |
    |  { amount, idempotency_key } |                                     |
    |----------------------------->| create TOP_UP row, status PENDING   |
    |                              | tran_id = our unique reference      |
    |                              |-- POST session (gwprocess/v4) ----->|
    |                              |<---- GatewayPageURL, sessionkey ----|
    |<---- { redirect_url } -------|                                     |
    |-------- browser redirect to GatewayPageURL ----------------------->|
    |                              |<======= IPN (server to server) =====|  val_id, tran_id, status
    |                              | validate val_id via validator API   |
    |                              | lock row → COMPLETED, credit wallet |
    |<------- browser POST to success_url / fail_url / cancel_url -------|
    |  result page reads the row   |                                     |
```

### Design points

- **The wallet is credited exactly once, from the server side.** The IPN and the browser `success_url` POST can both arrive, in either order, more than once. Both must go through one service method that:
  1. locks the `TOP_UP` row by `tran_id` (`lockForUpdate`),
  2. returns early unless it is still `PENDING`,
  3. calls the SSLCommerz **validation API** with `val_id` and accepts only `VALID`/`VALIDATED`,
  4. checks the validated `amount`, `currency` and `tran_id` against the stored row (stops amount tampering),
  5. credits the wallet, sets `COMPLETED`, stores `val_id` / `bank_tran_id` in `external_reference` (already unique) and the raw gateway payload in `meta`.
- **Never trust the browser redirect on its own.** A forged POST to `success_url` must achieve nothing, because step 3 asks SSLCommerz directly.
- **Failure and cancel** set `FAILED` without touching the balance. `PENDING`, `COMPLETED`, `FAILED` and `REJECTED` already exist in the `transactions.status` enum.
- **Routes:**
  - `POST /api/v1/transactions/top-up` (user token) starts the session.
  - `POST /api/v1/payments/sslcommerz/ipn` is public and throttled. It is authenticated by the validation call, not by a token.
  - `success_url`, `fail_url` and `cancel_url` are web routes. They are cross-site POSTs, so exclude them from CSRF in `bootstrap/app.php`. They only redirect to an Inertia result page; they never credit by themselves (they call the same idempotent method).
- **Configuration:** `config/payments.php` with `SSLCOMMERZ_STORE_ID`, `SSLCOMMERZ_STORE_PASSWORD`, `SSLCOMMERZ_SANDBOX=true`, and base URLs switched by the sandbox flag. Get sandbox credentials from the SSLCommerz developer portal. Never commit them.
- **Module placement:** a `Modules/Payments` module (gateway client, callback controller, `PaymentGateway` interface only if a second gateway is realistic) that calls into `TransactionService` for the ledger write. The gateway's HTTP details stay out of the Transactions module.
- **HTTP client:** Laravel's `Http` facade is enough. A third-party SSLCommerz package is not needed.
- **Flutter:** the same `redirect_url` opens in an in-app browser; the app then polls `GET /transactions/{id}` for the final status.
- **Limits:** keep `transactions.limits.top_up`. Top-ups are incoming money and stay outside the daily/monthly caps, as now.

### Before you start

Check the request and response field names (`store_id`, `store_passwd`, `total_amount`, `tran_id`, `GatewayPageURL`, `val_id`, `status`) against the current SSLCommerz developer documentation. The integration guide changes between API versions.

### Tests

- A browser `success_url` POST with a fake `val_id` does not credit (use `Http::fake` to return an invalid validation response).
- An IPN followed by a success redirect credits once.
- A validated amount that differs from the stored amount is rejected and flagged.
- Fail and cancel leave the balance untouched.
- A blocked wallet: decide whether to refuse at session creation (recommended) and what to do if the wallet is blocked while the user is paying. The money has already left the customer's bank, so park it and flag it for staff rather than dropping it.

---

## 2. Remove the PIN from top-up

### Today

`TopUpRequest` requires `pin`, `TransactionService::topUp()` calls `PinVerifier::verify()`, and the Wallet page asks for the PIN.

### Why it can go

The PIN protects money **leaving** a wallet. With a gateway, top-up only brings money in, and the payer authenticates at SSLCommerz (card 3-D Secure OTP, or mobile banking PIN). Asking for the wallet PIN adds friction and protects nothing. It also burns attempts on the shared PIN lockout counter, so a mistyped top-up can lock the user out of transfers.

### Keep

- The user token (`ability:user`) and `idempotency_key`.
- The PIN on transfer, cash-out, cash-in and agent withdrawal.

### Changes

- `TopUpRequest`: drop the `pin` rule and its messages.
- `TransactionService::topUp()`: drop the `$pin` parameter and the `PinVerifier` call.
- `resources/js/pages/User/Wallet.vue`: drop the PIN field from the schema and the payload.
- Update the top-up tests, `docs/API.md` §5.1 and the Postman request.

### Order

Ship this **together with** proposal 1, not before. While top-up still creates money out of thin air, the PIN is the only thing stopping a stolen token from inflating a balance.

---

## 3. Reconcile stuck gateway payments **(Money)**

IPNs get lost. A scheduled job (every 10–15 minutes) picks `TOP_UP` rows that have been `PENDING` for longer than N minutes and asks SSLCommerz for the status by `tran_id`. It then finishes them through the same idempotent method as proposal 1. Rows still unresolved after 24 hours are marked `FAILED` with a reason in `meta`. Show a "pending payments" list in the admin panel.

---

## 4. Ledger integrity check

Since the signup bonus is now a ledger row, every new wallet's balance should equal the sum of its ledger rows:

```text
balance = Σ credits (recipient / agent side) − Σ debits (sender side, incl. fees)
          − held PENDING withdrawals + refunded REJECTED withdrawals
```

Add an Artisan command (`wallets:verify-ledger`), scheduled daily. It reports wallets where the stored balance and the recomputed balance differ, and writes an alert to the log or emails staff. It is cheap to build and catches every money bug that the tests miss. A test can assert the invariant after the integration flows in `tests/Integration/TransactionFlowsTest.php`.

Wallets created before `7afe84b` will fail the check until proposal 5 is done.

---

## 5. Opening-balance backfill for legacy wallets **(Money)**

Wallets created before the signup bonus change got 50.00 from the column default, with no transaction row. Add a one-off command that writes an `OPENING_BALANCE` (or `SIGNUP_BONUS` with `meta.backfilled = true`) row for each wallet whose recomputed ledger total differs from its balance by exactly the old default. Run it once, review the output, keep the command for audit. This is a decision for you: it adds an enum value and touches production data, so dry-run mode first.

---

## 6. Transaction receipts

Send a short SMS / email (queued `Notification`) to both sides of a transfer, cash-in and cash-out: amount, fee, counterparty (masked like the recipient lookup), new balance, transaction ID. bKash and Nagad users expect it, and it is the fastest way a customer notices fraud on their account. Respect a per-user opt-out for email, never for SMS on outgoing money.

---

## 7. Real SMS gateway

`SmsChannel` currently writes messages to the log (`SMS_LOG_CHANNEL`). Registration OTP, phone verification and the receipts in proposal 6 all depend on it. Add a driver for a Bangladeshi SMS provider behind the existing channel, keep the log driver for local and testing, and queue the sends.

---

## 8. Reversals and refunds **(Money)**

There is no way to correct a mistaken transfer or a disputed cash-out. Add a `REVERSAL` transaction type that references the original transaction (`meta.reverses_transaction_id`, unique so it can happen only once), moves the money back, and returns the fee if policy says so. Staff-initiated only, behind a new permission, with a required reason, and ideally behind maker-checker (proposal 9). Never edit or delete the original row.

Decide up front what happens when the recipient has already spent the money: fail, or allow a negative balance as a recorded debt. The first is simpler.

---

## 9. Maker-checker for sensitive admin actions

Float issuance, fee/commission/bonus changes in System Settings, commission-rate changes and reversals currently take effect on one admin's say-so. Add a "pending change" record: one staff member proposes, a different one approves, and the change is applied in the approval transaction. Withdrawal approval already follows this pattern (staff cannot review their own withdrawal). Start with float issuance and settings.

---

## 10. Agent float requests **(Money)**

Today staff with `agents.issue_float` push float to an agent. Let agents request float from the Agent panel with the bank-deposit reference and a slip upload. Finance then approves (credit through the existing float-issue path) or rejects. This mirrors the agent withdrawal flow in the other direction and removes manual back-and-forth.

---

## 11. KYC tiers and per-user caps

Everyone gets the same 10,000 / 50,000 caps. Add tiers (for example: phone-verified only → low caps; NID verified by staff → standard caps; agent → agent caps), stored on the user or the `caps` row and changed by staff with an audit entry. NID image upload can reuse the avatar upload pipeline, on a private disk instead of `public`.

---

## 12. Signup bonus abuse controls **(Money)**

Each wallet gets one bonus, but nothing stops one person from registering many SIM cards. Options, cheapest first:

- A daily budget for total bonus paid (`signup_bonus_daily_budget` setting). Registration still succeeds once it is exhausted, just without a bonus.
- Delay the bonus until the first outgoing transaction or until phone verification by staff.
- Claw back the bonus when staff deactivate an account within N days of signup (as a ledger row, not by editing the balance).
- Referral bonus later, built on the same service and idempotency pattern.

---

## 13. Request money

A user asks another user for an amount. The payer sees the request and pays it with their PIN through the existing transfer path, or declines it. It needs a `payment_requests` table with a status, and the transfer's `meta` records which request it settled. Expire requests after a few days.

---

## 14. Admin dashboard metrics and ledger export

- Daily volume by type, fees earned, commission paid, bonus paid, new signups, pending withdrawals.
- CSV export of the filtered admin ledger (stream it, and put it behind its own permission because it is bulk personal data).

---

## 15. Security hardening

- **Device binding for PIN login:** a new device needs an SMS OTP once. This cuts PIN brute-force from stolen phone numbers.
- **Login alerts:** notify on a new device or IP for admin accounts.
- **TOTP for staff** as an alternative to email OTP (Laravel can verify TOTP with a small library; justify the dependency first).
- **Session list** in My Account with "log out this device", built on the existing Sanctum tokens.

---

## 16. Engineering

- Generate an OpenAPI spec from the routes and Form Requests, and keep `docs/API.md` and the Postman collection in sync from it. Several sections had drifted before this review.
- Add CI that runs Pint, PHPStan (`phpstan.neon` exists), `vue-tsc`, the build and the Pest suite on every PR.
- ~~Move `chk.php` out of the repo~~ (removed).
- Raise the PHPStan level one step at a time. Level 7 now passes over `app/` **and** `Modules/` (it previously skipped Modules and failed with 96 errors); level 8 currently reports 82 nullability errors.
- Format the frontend: `npm run check` reports 93 files out of format. Run `vp check --fix` once the open feature branches are merged, then add it to CI.
