import axios, { type AxiosError, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios';
import { router } from '@inertiajs/vue3';

let onUnauthorizedCallback: (() => void) | null = null;

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

// Request Interceptor: Attach Sanctum Bearer token from localStorage
api.interceptors.request.use(
    (config: InternalAxiosRequestConfig) => {
        if (typeof window !== 'undefined') {
            const token = localStorage.getItem('token');
            if (token) {
                config.headers.Authorization = `Bearer ${token}`;
            }
        }
        return config;
    },
    (error) => Promise.reject(error),
);

// Response Interceptor: Handle 401 Unauthorized (clear credentials & redirect to login)
api.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
        if (error.response?.status === 401) {
            if (typeof window !== 'undefined') {
                localStorage.removeItem('token');
                localStorage.removeItem('user');

                if (onUnauthorizedCallback) {
                    onUnauthorizedCallback();
                }

                if (window.location.pathname !== '/login') {
                    router.visit('/login');
                }
            }
        }
        return Promise.reject(error);
    },
);

export default api;
