<?php

namespace Modules\Roles\Enums;

enum Permission: string
{
    case UsersViewAny = 'users.view_any';
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersAssignRole = 'users.assign_role';
    case UsersGrantAdminAccess = 'users.grant_admin_access';
    case UsersVerifyPhone = 'users.verify_phone';
    case UsersViewActivity = 'users.view_activity';
    case UsersManageKyc = 'users.manage_kyc';

    case AgentsApprove = 'agents.approve';
    case AgentsSuspend = 'agents.suspend';
    case AgentsIssueFloat = 'agents.issue_float';
    case AgentsApproveWithdrawal = 'agents.approve_withdrawal';
    case AgentsUpdateCommission = 'agents.update_commission';

    case WalletsViewAny = 'wallets.view_any';
    case WalletsBlock = 'wallets.block';
    case WalletsUnblock = 'wallets.unblock';

    case TransactionsViewAny = 'transactions.view_any';
    case TransactionsReverse = 'transactions.reverse';
    case TransactionsExport = 'transactions.export';

    case SettingsUpdate = 'settings.update';
    case SettingsManageSessionTimeouts = 'settings.manage_session_timeouts';

    case PinResetInitiate = 'pin_reset.initiate';

    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';

    /**
     * Get the human-readable label/description for this permission.
     */
    public function label(): string
    {
        return match ($this) {
            self::UsersViewAny => 'View any user',
            self::UsersView => 'View user details',
            self::UsersCreate => 'Create users and staff accounts',
            self::UsersUpdate => 'Update user profile details',
            self::UsersAssignRole => 'Assign additional roles to users',
            self::UsersGrantAdminAccess => 'Grant administrative access invite to users',
            self::UsersVerifyPhone => 'Mark a user phone number as verified after an identity check',
            self::UsersViewActivity => 'View user activity: login records and audit trail',
            self::UsersManageKyc => 'Review identity documents and change KYC tiers and limits',
            self::AgentsApprove => 'Approve pending agent applications',
            self::AgentsSuspend => 'Suspend active agents',
            self::AgentsIssueFloat => 'Issue e-money float to agents against external bank deposit',
            self::AgentsApproveWithdrawal => 'Approve or reject pending agent bank withdrawals',
            self::AgentsUpdateCommission => 'Change an agent commission rate',
            self::WalletsViewAny => 'View any wallet in the system',
            self::WalletsBlock => 'Block active wallets',
            self::WalletsUnblock => 'Unblock suspended or blocked wallets',
            self::TransactionsViewAny => 'View all system transactions and sensitive fields',
            self::TransactionsReverse => 'Reverse a completed transfer, cash-in or cash-out',
            self::TransactionsExport => 'Export the transaction ledger as CSV',
            self::SettingsUpdate => 'Update global system settings and transaction caps',
            self::SettingsManageSessionTimeouts => 'Change the idle auto-logout timeout for each panel',
            self::PinResetInitiate => 'Initiate staff-assisted customer PIN reset',
            self::RolesView => 'View administrative roles and permission catalog',
            self::RolesManage => 'Create, edit, delete custom roles and sync permissions',
        };
    }

    /**
     * Get the UI display group for this permission.
     */
    public function group(): string
    {
        return match ($this) {
            self::UsersViewAny,
            self::UsersView,
            self::UsersCreate,
            self::UsersUpdate,
            self::UsersAssignRole,
            self::UsersGrantAdminAccess,
            self::UsersVerifyPhone,
            self::UsersViewActivity,
            self::UsersManageKyc => 'Users',

            self::AgentsApprove,
            self::AgentsSuspend,
            self::AgentsIssueFloat,
            self::AgentsApproveWithdrawal,
            self::AgentsUpdateCommission => 'Agents',

            self::WalletsViewAny,
            self::WalletsBlock,
            self::WalletsUnblock => 'Wallets',

            self::TransactionsViewAny,
            self::TransactionsReverse,
            self::TransactionsExport => 'Transactions',

            self::SettingsUpdate,
            self::SettingsManageSessionTimeouts => 'Settings',

            self::PinResetInitiate => 'Auth',

            self::RolesView,
            self::RolesManage => 'Roles',
        };
    }

    /**
     * Determine if this permission is restricted exclusively to super administrators.
     */
    public function isSuperAdminOnly(): bool
    {
        return match ($this) {
            self::UsersGrantAdminAccess => true,
            default => false,
        };
    }
}
