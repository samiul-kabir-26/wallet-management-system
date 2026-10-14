import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { router } from '@inertiajs/vue3';
import { api, setOnUnauthorizedCallback } from '@/services/api';
import { useWalletStore } from '@/stores/wallet';
import { useTransactionStore } from '@/stores/transactions';
import { useUserStore } from '@/stores/user';
import type { User } from '@/types/models';
import type { PermissionName } from '@/types/permissions';

function getInitialUser(): User | null {
    if (typeof window === 'undefined') {
        return null;
    }
    try {
        const stored = localStorage.getItem('user');
        return stored ? (JSON.parse(stored) as User) : null;
    } catch {
        return null;
    }
}

function getInitialPermissions(): string[] {
    if (typeof window === 'undefined') {
        return [];
    }
    try {
        const stored = localStorage.getItem('permissions');
        if (stored) {
            return JSON.parse(stored) as string[];
        }
        const userStored = localStorage.getItem('user');
        if (userStored) {
            const u = JSON.parse(userStored);
            return Array.isArray(u.permissions) ? (u.permissions as string[]) : [];
        }
        return [];
    } catch {
        return [];
    }
}

function getInitialIdleTimeout(): number | null {
    if (typeof window === 'undefined') {
        return null;
    }
    const stored = Number(localStorage.getItem('idle_timeout_minutes'));
    return Number.isFinite(stored) && stored > 0 ? stored : null;
}

export const useAuthStore = defineStore('auth', () => {
    const token = ref<string | null>(
        typeof window !== 'undefined' ? localStorage.getItem('token') : null,
    );
    const user = ref<User | null>(getInitialUser());
    const permissions = ref<string[]>(getInitialPermissions());
    // Server-issued idle timeout for the panel this token belongs to
    const idleTimeoutMinutes = ref<number | null>(getInitialIdleTimeout());
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

    const hasRole = (role: string): boolean => roles.value.includes(role);

    const can = (permission: PermissionName): boolean => {
        if (isSuperAdmin.value) {
            return true;
        }
        return permissions.value.includes(permission);
    };

    const setIdleTimeout = (minutes: number | null | undefined): void => {
        idleTimeoutMinutes.value = typeof minutes === 'number' && minutes > 0 ? minutes : null;
        if (typeof window !== 'undefined') {
            if (idleTimeoutMinutes.value) {
                localStorage.setItem('idle_timeout_minutes', String(idleTimeoutMinutes.value));
            } else {
                localStorage.removeItem('idle_timeout_minutes');
            }
        }
    };

    const setAuth = (newToken: string, newUser: User, idleTimeout?: number | null): void => {
        token.value = newToken;
        user.value = newUser;
        permissions.value = Array.isArray(newUser.permissions) ? newUser.permissions : [];
        setIdleTimeout(idleTimeout);
        if (typeof window !== 'undefined') {
            localStorage.setItem('token', newToken);
            localStorage.setItem('user', JSON.stringify(newUser));
            localStorage.setItem('permissions', JSON.stringify(permissions.value));
            localStorage.setItem('last_activity_at', String(Date.now()));
        }
    };

    const clearAuth = (): void => {
        token.value = null;
        user.value = null;
        permissions.value = [];
        setIdleTimeout(null);
        useWalletStore().reset();
        useTransactionStore().reset();
        useUserStore().reset();
        if (typeof window !== 'undefined') {
            localStorage.removeItem('token');
            localStorage.removeItem('user');
            localStorage.removeItem('permissions');
            localStorage.removeItem('last_activity_at');
        }
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
            setAuth(data.token, data.user, data.idle_timeout_minutes);
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
            setAuth(data.token, data.user, data.idle_timeout_minutes);
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

    const adminLogin = async (identifier: string, password: string): Promise<string> => {
        loading.value = true;
        error.value = null;
        try {
            const res = await api.post('/auth/admin/login', {
                identifier,
                password,
            });
            return res.data.message || 'OTP sent to your registered email';
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
            setAuth(data.token, data.user, data.idle_timeout_minutes);
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
    }): Promise<{ user: User; token: string }> => {
        loading.value = true;
        error.value = null;
        try {
            const res = await api.post('/auth/register', payload);
            const data = res.data.data;
            if (data.token && data.user) {
                setAuth(data.token, data.user, data.idle_timeout_minutes);
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
                if (typeof window !== 'undefined') {
                    localStorage.setItem('user', JSON.stringify(user.value));
                    localStorage.setItem('permissions', JSON.stringify(permissions.value));
                }
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
        hasRole,
        can,
        setAuth,
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
