import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { router } from '@inertiajs/vue3';
import { api, setOnUnauthorizedCallback, setTokenResolver } from '@/services/api';
import { useWalletStore } from '@/stores/wallet';
import { useTransactionStore } from '@/stores/transactions';
import { useUserStore } from '@/stores/user';
import type { User } from '@/types/models';
import type { PermissionName } from '@/types/permissions';

export type Panel = 'admin' | 'agent' | 'user';

const PANELS: Panel[] = ['admin', 'agent', 'user'];
// Pre-panel storage keys from older builds; removed so a stale shared session cannot linger
const LEGACY_KEYS = ['token', 'user', 'permissions', 'idle_timeout_minutes', 'last_activity_at'];

/**
 * The panel a URL belongs to, or null for public pages such as /login.
 */
export function panelFromPath(path?: string): Panel | null {
    const pathname = path ?? (typeof window !== 'undefined' ? window.location.pathname : '');
    const match = pathname.match(/^\/(admin|agent|user)(\/|$)/);
    return match ? (match[1] as Panel) : null;
}

/**
 * Each panel keeps its own session in localStorage, so an admin tab and an agent tab in the same
 * browser hold separate tokens and separate idle timers instead of overwriting each other.
 */
export function panelStorageKey(panel: Panel, key: string): string {
    return `${panel}:${key}`;
}

function readStorage(key: string): string | null {
    if (typeof window === 'undefined') {
        return null;
    }
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeStorage(key: string, value: string | null): void {
    if (typeof window === 'undefined') {
        return;
    }
    try {
        if (value === null) {
            localStorage.removeItem(key);
        } else {
            localStorage.setItem(key, value);
        }
    } catch {
        // Storage unavailable (private mode, blocked site data): the session lives in memory only
    }
}

function readJson<T>(key: string, fallback: T): T {
    const stored = readStorage(key);
    if (!stored) {
        return fallback;
    }
    try {
        return JSON.parse(stored) as T;
    } catch {
        return fallback;
    }
}

function readPositiveNumber(key: string): number | null {
    const stored = Number(readStorage(key));
    return Number.isFinite(stored) && stored > 0 ? stored : null;
}

LEGACY_KEYS.forEach((key) => writeStorage(key, null));

export const useAuthStore = defineStore('auth', () => {
    // The panel whose session this tab is using; follows the URL (see usePanel)
    const panel = ref<Panel | null>(panelFromPath());
    const token = ref<string | null>(null);
    const user = ref<User | null>(null);
    const permissions = ref<string[]>([]);
    // Server-issued idle timeout for the panel this token belongs to
    const idleTimeoutMinutes = ref<number | null>(null);

    const keyFor = (name: string): string | null => (panel.value ? panelStorageKey(panel.value, name) : null);

    /**
     * Load the stored session of a panel into this tab (none for public pages).
     */
    const hydrate = (): void => {
        const tokenKey = keyFor('token');
        token.value = tokenKey ? readStorage(tokenKey) : null;
        user.value = token.value ? readJson<User | null>(keyFor('user')!, null) : null;
        permissions.value = token.value ? readJson<string[]>(keyFor('permissions')!, []) : [];
        idleTimeoutMinutes.value = token.value ? readPositiveNumber(keyFor('idle_timeout_minutes')!) : null;
    };

    /**
     * Switch this tab to the session of the panel it is now showing.
     */
    const usePanel = (nextPanel: Panel | null): void => {
        if (nextPanel === panel.value) {
            return;
        }
        panel.value = nextPanel;
        hydrate();
    };

    hydrate();
    setTokenResolver(() => token.value);

    /** localStorage key of this panel's shared last-activity timestamp, or null on public pages. */
    const activityStorageKey = computed<string | null>(() => keyFor('last_activity_at'));
    const tokenStorageKey = computed<string | null>(() => keyFor('token'));

    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    const isAuthenticated = computed<boolean>(() => !!token.value);
    const roles = computed<string[]>(() => user.value?.roles ?? []);
    const isUser = computed<boolean>(() => roles.value.includes('USER'));
    const isAgent = computed<boolean>(() => roles.value.includes('AGENT'));
    const isSuperAdmin = computed<boolean>(() =>
        roles.value.includes('SUPER_ADMIN'),
    );
    const isAdmin = computed<boolean>(() =>
        isSuperAdmin.value ||
        roles.value.includes('ADMIN') ||
        permissions.value.length > 0 ||
        roles.value.some((r) => !['USER', 'AGENT'].includes(r)),
    );

    // Staff holding a temporary password must replace it before the admin API accepts anything else
    const mustChangePassword = computed<boolean>(() => !!user.value?.must_change_password);

    const hasRole = (role: string): boolean => roles.value.includes(role);

    const can = (permission: PermissionName): boolean => {
        if (isSuperAdmin.value) {
            return true;
        }
        return permissions.value.includes(permission);
    };

    const setIdleTimeout = (minutes: number | null | undefined): void => {
        idleTimeoutMinutes.value = typeof minutes === 'number' && minutes > 0 ? minutes : null;
        const key = keyFor('idle_timeout_minutes');
        if (key) {
            writeStorage(key, idleTimeoutMinutes.value ? String(idleTimeoutMinutes.value) : null);
        }
    };

    const persistUser = (): void => {
        if (!panel.value) {
            return;
        }
        writeStorage(keyFor('user')!, user.value ? JSON.stringify(user.value) : null);
        writeStorage(keyFor('permissions')!, JSON.stringify(permissions.value));
    };

    /**
     * Store a freshly issued token for the panel it was issued for.
     */
    const setAuth = (newToken: string, newUser: User, idleTimeout: number | null | undefined, forPanel: Panel): void => {
        panel.value = forPanel;
        token.value = newToken;
        user.value = newUser;
        permissions.value = Array.isArray(newUser.permissions) ? newUser.permissions : [];
        writeStorage(keyFor('token')!, newToken);
        writeStorage(keyFor('last_activity_at')!, String(Date.now()));
        persistUser();
        setIdleTimeout(idleTimeout);
    };

    /**
     * Merge server-side profile changes (name, avatar, verification) into the stored user.
     */
    const updateUser = (changes: Partial<User>): void => {
        if (!user.value) {
            return;
        }
        user.value = { ...user.value, ...changes };
        persistUser();
    };

    const clearAuth = (): void => {
        if (panel.value) {
            ['token', 'user', 'permissions', 'idle_timeout_minutes', 'last_activity_at'].forEach((name) =>
                writeStorage(keyFor(name)!, null),
            );
        }
        token.value = null;
        user.value = null;
        permissions.value = [];
        idleTimeoutMinutes.value = null;
        useWalletStore().reset();
        useTransactionStore().reset();
        useUserStore().reset();
    };

    // Automatically clear store when Axios catches a 401
    setOnUnauthorizedCallback(clearAuth);

    const loginUser = async (phoneNumber: string, pin: string): Promise<User> => {
        loading.value = true;
        error.value = null;
        try {
            const res = await api.post('/auth/user/login', {
                phone_number: phoneNumber,
                pin,
            });
            const data = res.data.data;
            setAuth(data.token, data.user, data.idle_timeout_minutes, 'user');
            return data.user;
        } catch (err: unknown) {
            const apiError = err as { response?: { data?: { message?: string } } };
            const msg = apiError.response?.data?.message || 'Login failed. Please check credentials.';
            error.value = msg;
            throw new Error(msg);
        } finally {
            loading.value = false;
        }
    };

    const loginAgent = async (phoneNumber: string, pin: string): Promise<User> => {
        loading.value = true;
        error.value = null;
        try {
            const res = await api.post('/auth/agent/login', {
                phone_number: phoneNumber,
                pin,
            });
            const data = res.data.data;
            setAuth(data.token, data.user, data.idle_timeout_minutes, 'agent');
            return data.user;
        } catch (err: unknown) {
            const apiError = err as { response?: { data?: { message?: string } } };
            const msg = apiError.response?.data?.message || 'Login failed. Please check credentials.';
            error.value = msg;
            throw new Error(msg);
        } finally {
            loading.value = false;
        }
    };

    /**
     * Password step. Returns the account's email: the OTP step identifies the account by email,
     * whether the person typed their email or their phone number here.
     */
    const adminLogin = async (identifier: string, password: string): Promise<{ message: string; email: string }> => {
        loading.value = true;
        error.value = null;
        try {
            const res = await api.post('/auth/admin/login', {
                identifier,
                password,
            });
            return {
                message: res.data.message || 'OTP sent to your registered email',
                email: res.data.data?.email ?? identifier,
            };
        } catch (err: unknown) {
            const apiError = err as { response?: { data?: { message?: string } } };
            const msg = apiError.response?.data?.message || 'Admin authentication failed.';
            error.value = msg;
            throw new Error(msg);
        } finally {
            loading.value = false;
        }
    };

    const adminVerifyOtp = async (email: string, otpCode: string): Promise<User> => {
        loading.value = true;
        error.value = null;
        try {
            const res = await api.post('/auth/admin/verify-otp', {
                email,
                otp_code: otpCode,
            });
            const data = res.data.data;
            setAuth(data.token, data.user, data.idle_timeout_minutes, 'admin');
            return data.user;
        } catch (err: unknown) {
            const apiError = err as { response?: { data?: { message?: string } } };
            const msg = apiError.response?.data?.message || 'Invalid or expired OTP code.';
            error.value = msg;
            throw new Error(msg);
        } finally {
            loading.value = false;
        }
    };

    const register = async (payload: {
        name: string;
        phone_number: string;
        pin: string;
        pin_confirmation: string;
        role: 'USER' | 'AGENT';
        otp_code: string;
    }): Promise<{ user: User; token: string }> => {
        loading.value = true;
        error.value = null;
        try {
            const res = await api.post('/auth/register', payload);
            const data = res.data.data;
            if (data.token && data.user) {
                setAuth(data.token, data.user, data.idle_timeout_minutes, payload.role === 'AGENT' ? 'agent' : 'user');
            }
            return data;
        } catch (err: unknown) {
            const apiError = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
            const msg = apiError.response?.data?.message || 'Registration failed.';
            error.value = msg;
            throw err;
        } finally {
            loading.value = false;
        }
    };

    const fetchMe = async (): Promise<User | null> => {
        if (!token.value) {
            return null;
        }
        try {
            const res = await api.get('/auth/me');
            const updatedUser = res.data.data?.user || res.data.data;
            if (res.data.data?.idle_timeout_minutes !== undefined) {
                setIdleTimeout(res.data.data.idle_timeout_minutes);
            }
            if (updatedUser) {
                user.value = { ...user.value, ...updatedUser };
                if (Array.isArray(updatedUser.permissions)) {
                    permissions.value = updatedUser.permissions;
                }
                persistUser();
            }
            return user.value;
        } catch {
            return null;
        }
    };

    /**
     * Revoke the token and return to the login page. reason 'idle' tells the login page why.
     */
    const logout = async (reason?: 'idle'): Promise<void> => {
        loading.value = true;
        try {
            if (token.value) {
                await api.post('/auth/logout');
            }
        } catch {
            // Ignore failure on logout call
        } finally {
            clearAuth();
            loading.value = false;
            router.visit(reason === 'idle' ? '/login?reason=idle' : '/login');
        }
    };

    return {
        panel,
        usePanel,
        activityStorageKey,
        tokenStorageKey,
        token,
        user,
        permissions,
        idleTimeoutMinutes,
        loading,
        error,
        isAuthenticated,
        roles,
        isUser,
        isAgent,
        isAdmin,
        isSuperAdmin,
        mustChangePassword,
        hasRole,
        can,
        setAuth,
        updateUser,
        clearAuth,
        loginUser,
        loginAgent,
        adminLogin,
        adminVerifyOtp,
        register,
        fetchMe,
        logout,
    };
});
