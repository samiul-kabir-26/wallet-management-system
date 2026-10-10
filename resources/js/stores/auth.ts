import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { router } from '@inertiajs/vue3';
import { api, setOnUnauthorizedCallback } from '@/services/api';
import { useWalletStore } from '@/stores/wallet';
import { useTransactionStore } from '@/stores/transactions';
import { useUserStore } from '@/stores/user';
import type { User } from '@/types/models';

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

export const useAuthStore = defineStore('auth', () => {
    const token = ref<string | null>(
        typeof window !== 'undefined' ? localStorage.getItem('token') : null,
    );
    const user = ref<User | null>(getInitialUser());
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    const isAuthenticated = computed<boolean>(() => !!token.value);
    const roles = computed<string[]>(() => user.value?.roles ?? []);
    const isUser = computed<boolean>(() => roles.value.includes('USER'));
    const isAgent = computed<boolean>(() => roles.value.includes('AGENT'));
    const isAdmin = computed<boolean>(() =>
        roles.value.some((r) => ['SUPER_ADMIN', 'ADMIN', 'MODERATOR'].includes(r)),
    );
    const isSuperAdmin = computed<boolean>(() =>
        roles.value.includes('SUPER_ADMIN'),
    );

    const hasRole = (role: string): boolean => roles.value.includes(role);

    const setAuth = (newToken: string, newUser: User): void => {
        token.value = newToken;
        user.value = newUser;
        if (typeof window !== 'undefined') {
            localStorage.setItem('token', newToken);
            localStorage.setItem('user', JSON.stringify(newUser));
        }
    };

    const clearAuth = (): void => {
        token.value = null;
        user.value = null;
        useWalletStore().reset();
        useTransactionStore().reset();
        useUserStore().reset();
        if (typeof window !== 'undefined') {
            localStorage.removeItem('token');
            localStorage.removeItem('user');
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
            setAuth(data.token, data.user);
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
            setAuth(data.token, data.user);
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
            setAuth(data.token, data.user);
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
                setAuth(data.token, data.user);
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
            // Note: Users can view their own profile via GET /users/:id or me
            if (user.value?.id) {
                const res = await api.get(`/users/${user.value.id}`);
                const updatedUser = res.data.data?.user || res.data.data;
                if (updatedUser) {
                    user.value = { ...user.value, ...updatedUser };
                    if (typeof window !== 'undefined') {
                        localStorage.setItem('user', JSON.stringify(user.value));
                    }
                }
            }
            return user.value;
        } catch {
            return null;
        }
    };

    const logout = async (): Promise<void> => {
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
            router.visit('/login');
        }
    };

    return {
        token,
        user,
        loading,
        error,
        isAuthenticated,
        roles,
        isUser,
        isAgent,
        isAdmin,
        isSuperAdmin,
        hasRole,
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
