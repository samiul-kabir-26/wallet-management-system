export interface User {
    id: number;
    name: string;
    phone_number: string | null;
    email: string | null;
    roles: string[];
    is_verified?: boolean;
    is_active?: string;
    created_at?: string;
    updated_at?: string;
    wallet?: Wallet | null;
    agent_info?: AgentInfo | null;
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
    balance: number | string;
    currency: string;
    is_blocked: boolean;
    created_at?: string;
    caps?: Caps;
    user?: User;
}

export type TransactionType =
    | 'TOP_UP'
    | 'CASH_IN'
    | 'CASH_OUT'
    | 'TRANSFER'
    | 'AGENT_WITHDRAWAL'
    | 'COMMISSION_PAYOUT';

export type TransactionStatus = 'COMPLETED' | 'PENDING' | 'FAILED';

export interface Transaction {
    id: number;
    user_id?: number | null;
    sender_id?: number | null;
    recipient_id?: number | null;
    agent_id?: number | null;
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
    transaction_fee_rate: number;
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
