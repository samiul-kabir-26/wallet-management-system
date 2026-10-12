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

    case AgentsApprove = 'agents.approve';
    case AgentsSuspend = 'agents.suspend';

    case WalletsViewAny = 'wallets.view_any';
    case WalletsBlock = 'wallets.block';
    case WalletsUnblock = 'wallets.unblock';

    case TransactionsViewAny = 'transactions.view_any';

    case SettingsUpdate = 'settings.update';

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
            self::AgentsApprove => 'Approve pending agent applications',
            self::AgentsSuspend => 'Suspend active agents',
            self::WalletsViewAny => 'View any wallet in the system',
            self::WalletsBlock => 'Block active wallets',
            self::WalletsUnblock => 'Unblock suspended or blocked wallets',
            self::TransactionsViewAny => 'View all system transactions and sensitive fields',
            self::SettingsUpdate => 'Update global system settings and transaction caps',
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
            self::UsersGrantAdminAccess => 'Users',

            self::AgentsApprove,
            self::AgentsSuspend => 'Agents',

            self::WalletsViewAny,
            self::WalletsBlock,
            self::WalletsUnblock => 'Wallets',

            self::TransactionsViewAny => 'Transactions',

            self::SettingsUpdate => 'Settings',

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
