# Wallet Management System — API Usage Examples

This guide provides end-to-end `curl` and JSON payload examples for the most common workflows in the Wallet Management System.

---

## 1. Customer Registration & Authentication Journey

### 1.1 Register User
```bash
curl -X POST https://api.wallet.local/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Rahim Khan",
    "phone_number": "01711223344",
    "pin": "12345",
    "pin_confirmation": "12345",
    "role": "USER"
  }'
```

**Response (201 Created):**
```json
{
  "success": true,
  "message": "User registered successfully",
  "data": {
    "user": {
      "id": 15,
      "name": "Rahim Khan",
      "phone_number": "01711223344",
      "roles": ["USER"],
      "is_active": "ACTIVE"
    },
    "token": "15|wms_token_abc123...",
    "token_type": "Bearer",
    "abilities": ["user"]
  }
}
```

---

### 1.2 User Login
```bash
curl -X POST https://api.wallet.local/api/v1/auth/user/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "phone_number": "01711223344",
    "pin": "12345"
  }'
```

---

## 2. Wallet & Balance Top-Up Journey

### 2.1 Check Current Wallet Balance
```bash
curl -X GET https://api.wallet.local/api/v1/wallets/me \
  -H "Authorization: Bearer 15|wms_token_abc123..." \
  -H "Accept: application/json"
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "wallet": {
      "id": 8,
      "user_id": 15,
      "balance": 50.00,
      "currency": "BDT",
      "is_blocked": false
    }
  }
}
```

---

### 2.2 Top-Up Balance
```bash
curl -X POST https://api.wallet.local/api/v1/transactions/top-up \
  -H "Authorization: Bearer 15|wms_token_abc123..." \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "amount": 5000.00,
    "idempotency_key": "topup-req-20261007-001",
    "description": "Debit card top up"
  }'
```

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Top-up completed successfully.",
  "data": {
    "transaction": {
      "id": 201,
      "user_id": 15,
      "type": "TOP_UP",
      "amount": 5000.00,
      "system_fee_amount": 0.00,
      "agent_commission_amount": 0.00,
      "currency": "BDT",
      "status": "COMPLETED",
      "idempotency_key": "topup-req-20261007-001",
      "recipient_wallet_balance_after": 5050.00
    }
  }
}
```

---

## 3. Peer-to-Peer (P2P) Balance Transfer

Transfer 1,200.00 BDT to another user (`recipient_id: 16`):

```bash
curl -X POST https://api.wallet.local/api/v1/transactions/transfer \
  -H "Authorization: Bearer 15|wms_token_abc123..." \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "recipient_id": 16,
    "amount": 1200.00,
    "idempotency_key": "transfer-req-20261007-002",
    "description": "Shared expense"
  }'
```

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Transfer completed successfully.",
  "data": {
    "transaction": {
      "id": 202,
      "user_id": 15,
      "sender_id": 15,
      "recipient_id": 16,
      "type": "TRANSFER",
      "amount": 1200.00,
      "status": "COMPLETED",
      "idempotency_key": "transfer-req-20261007-002",
      "sender_wallet_balance_after": 3850.00,
      "recipient_wallet_balance_after": 1250.00
    }
  }
}
```

---

## 4. Cash-Out via Agent Counter

Assume `system_fee_rate` is 5% (0.05) and the Agent's `commission_rate` is 2% (0.02).  
When user cashes out 2,000.00 BDT through Agent (`agent_id: 20`):
- Fee deducted from user: 2,000 × 0.05 = 100.00 BDT
- Total user debit: 2,100.00 BDT
- Agent commission credited: 2,000 × 0.02 = 40.00 BDT
- Total agent credit: 2,040.00 BDT

```bash
curl -X POST https://api.wallet.local/api/v1/transactions/cash-out \
  -H "Authorization: Bearer 15|wms_token_abc123..." \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "agent_id": 20,
    "amount": 2000.00,
    "idempotency_key": "cashout-req-20261007-003",
    "description": "Counter withdrawal"
  }'
```

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Cash-out completed successfully.",
  "data": {
    "transaction": {
      "id": 203,
      "user_id": 15,
      "sender_id": 15,
      "agent_id": 20,
      "type": "CASH_OUT",
      "amount": 2000.00,
      "system_fee_amount": 100.00,
      "system_fee_rate": 0.0500,
      "agent_commission_amount": 40.00,
      "agent_commission_rate": 0.0200,
      "status": "COMPLETED",
      "idempotency_key": "cashout-req-20261007-003",
      "sender_wallet_balance_after": 1750.00,
      "agent_wallet_balance_after": 2040.00
    }
  }
}
```

---

## 5. Agent Withdrawal Journey

Agent withdraws 1,500.00 BDT from their wallet:

```bash
curl -X POST https://api.wallet.local/api/v1/transactions/agent/withdrawal \
  -H "Authorization: Bearer 20|agent_token_xyz..." \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "amount": 1500.00,
    "idempotency_key": "withdraw-req-20261007-004",
    "description": "Weekly commission cash out"
  }'
```

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Withdrawal completed successfully.",
  "data": {
    "transaction": {
      "id": 205,
      "user_id": 20,
      "agent_id": 20,
      "type": "AGENT_WITHDRAWAL",
      "amount": 1500.00,
      "status": "COMPLETED",
      "sender_wallet_balance_after": 540.00
    }
  }
}
```

---

## 6. Administrative Agent Approval & Global Settings

### 6.1 Approve Agent with Custom Commission Rate
```bash
curl -X PATCH https://api.wallet.local/api/v1/users/20/approve-agent \
  -H "Authorization: Bearer 1|admin_token_super..." \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "commission_rate": 0.025
  }'
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Agent approved successfully",
  "data": {
    "agent": {
      "user_id": 20,
      "status": "APPROVED",
      "commission_rate": 0.025,
      "approved_at": "2026-10-07T12:00:00.000000Z",
      "approved_by": 1
    }
  }
}
```

---

### 6.2 Update System Settings
```bash
curl -X PATCH https://api.wallet.local/api/v1/system-settings \
  -H "Authorization: Bearer 1|admin_token_super..." \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "system_fee_rate": 0.035,
    "agent_commission_rate": 0.015
  }'
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Settings updated successfully",
  "data": {
    "settings": {
      "system_fee_rate": 0.035,
      "agent_commission_rate": 0.015
    }
  }
}
```
