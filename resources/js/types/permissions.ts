export const PERMISSIONS = [
    'users.view_any',
    'users.view',
    'users.create',
    'users.update',
    'users.assign_role',
    'users.grant_admin_access',
    'agents.approve',
    'agents.suspend',
    'wallets.view_any',
    'wallets.block',
    'wallets.unblock',
    'transactions.view_any',
    'settings.update',
    'pin_reset.initiate',
    'roles.view',
    'roles.manage',
] as const;

export type PermissionName = (typeof PERMISSIONS)[number];
