import axios, { type AxiosError, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios';
import { router } from '@inertiajs/vue3';

let onUnauthorizedCallback: (() => void) | null = null;
let tokenResolver: () => string | null = () => null;

/**
 * Register how the current tab's bearer token is read (the auth store's per-panel session).
 */
export const setTokenResolver = (resolver: () => string | null): void => {
    tokenResolver = resolver;
};

/**
 * Register a callback to clear Pinia store state when a 401 is encountered.
 */
export const setOnUnauthorizedCallback = (callback: (() => void) | null): void => {
    onUnauthorizedCallback = callback;
};

/**
 * Shared Axios instance for all /api/v1 requests.
 */
export const api: AxiosInstance = axios.create({
    baseURL: '/api/v1',
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    },
});

// Request Interceptor: Attach the Sanctum Bearer token of this tab's panel session
api.interceptors.request.use(
    (config: InternalAxiosRequestConfig) => {
        const token = tokenResolver();
        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
    },
    (error) => Promise.reject(error),
);

// Response Interceptor: Handle 401 Unauthorized (clear credentials & redirect to login)
api.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
        // Wrong passwords and OTP codes are also 401; only a rejected token ("Unauthenticated.") ends the session
        const message = (error.response?.data as { message?: string } | undefined)?.message;
        // A session already cleared here (e.g. by sign-out) has its own redirect; don't override it
        if (error.response?.status === 401 && message === 'Unauthenticated.' && tokenResolver()) {
            if (typeof window !== 'undefined') {
                if (onUnauthorizedCallback) {
                    onUnauthorizedCallback();
                }

                if (window.location.pathname !== '/login') {
                    router.visit('/login');
                }
            }
        }

        // A temporary staff password blocks every admin endpoint until it is replaced
        const meta = (error.response?.data as { meta?: { code?: string } } | undefined)?.meta;
        if (
            error.response?.status === 403 &&
            meta?.code === 'PASSWORD_CHANGE_REQUIRED' &&
            typeof window !== 'undefined' &&
            window.location.pathname !== '/admin/account'
        ) {
            router.visit('/admin/account');
        }

        return Promise.reject(error);
    },
);

export default api;
