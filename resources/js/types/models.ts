export interface User {
    id: number;
    name: string;
    phone_number: string | null;
    email: string | null;
    roles: string[];
    permissions?: string[];
    is_verified?: boolean;
    is_active?: string;
    address?: string | null;
    avatar_url?: string | null;
    receipt_emails_enabled?: boolean;
    must_change_password?: boolean;
    created_at?: string;
    updated_at?: string;
    wallet?: Wallet | null;
    agent_info?: AgentInfo | null;
}

export interface PermissionCatalogItem {
    id: number;
    name: string;
    label: string;
    group: string;
    is_super_admin_only?: boolean;
}

export interface RoleModel {
    id: number;
    name: string;
    description: string | null;
    panel: 'admin' | 'agent' | 'user';
    is_system: boolean;
    users_count?: number;
    permissions_count?: number;
    permissions?: PermissionCatalogItem[];
    created_at?: string;
    updated_at?: string;
}

export interface Caps {
    daily_limit: number;
    daily_used: number;
    daily_remaining: number;
    monthly_limit: number;
    monthly_used: number;
    monthly_remaining: number;
}

export interface Wallet {
    id: number;
    user_id: number;
    kind?: 'USER' | 'AGENT';
    balance: number | string;
    currency: string;
    is_blocked: boolean;
    created_at?: string;
    caps?: Caps;
    user?: User;
    owner?: { id: number; name: string; phone_number: string | null; email: string | null } | null;
}

export type TransactionType =
    | 'TOP_UP'
    | 'CASH_IN'
    | 'CASH_OUT'
    | 'TRANSFER'
    | 'AGENT_WITHDRAWAL'
    | 'COMMISSION_PAYOUT'
    | 'FLOAT_ISSUE'
    | 'SIGNUP_BONUS'
    | 'REVERSAL';

export type TransactionStatus = 'COMPLETED' | 'PENDING' | 'FAILED' | 'REJECTED';

export interface TransactionParty {
    id: number;
    name: string;
    phone_number: string | null;
}

export interface Transaction {
    id: number;
    user_id?: number | null;
    sender_id?: number | null;
    recipient_id?: number | null;
    agent_id?: number | null;
    sender_wallet_id?: number | null;
    recipient_wallet_id?: number | null;
    agent_wallet_id?: number | null;
    sender_wallet_balance_after?: number | null;
    recipient_wallet_balance_after?: number | null;
    agent_wallet_balance_after?: number | null;
    source_wallet_id?: number | null;
    destination_wallet_id?: number | null;
    type: TransactionType;
    amount: number | string;
    system_fee_amount: number | string;
    agent_commission_amount: number | string;
    net_amount?: number | string;
    status: TransactionStatus;
    description: string | null;
    idempotency_key?: string;
    external_reference?: string | null;
    reversed_transaction_id?: number | null;
    reversed_by_transaction_id?: number | null;
    meta?: Record<string, unknown> | null;
    parties?: {
        sender: TransactionParty | null;
        recipient: TransactionParty | null;
        agent: TransactionParty | null;
    };
    created_at: string;
    source_wallet?: Wallet;
    destination_wallet?: Wallet;
}

export interface AgentInfo {
    id: number;
    user_id: number;
    status: 'PENDING' | 'APPROVED' | 'SUSPENDED';
    commission_rate: number | string;
    total_commission: number | string;
    approved_at: string | null;
    approved_by: number | null;
    suspended_at: string | null;
    suspended_by: number | null;
    user?: User;
}

export interface SystemSettings {
    system_fee_rate: number;
    agent_commission_rate: number;
}

export interface Pagination {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
}

export interface PaginatedResponse<T> {
    items: T[];
    pagination: Pagination;
}

export interface UserProfile extends User {
    address?: string | null;
    wallets?: Wallet[];
    caps?: { daily_cap: number | string; monthly_cap: number | string; daily_used: number | string; monthly_used: number | string } | null;
}

export interface LoginActivityRecord {
    id: number;
    panel: 'admin' | 'agent' | 'user';
    identifier: string;
    status: 'SUCCESS' | 'FAILED';
    failure_reason: string | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string;
}

export interface AuditLogRecord {
    id: number;
    action: string;
    actor: { id: number; name: string } | null;
    auditable_type: string | null;
    auditable_id: number | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
}
