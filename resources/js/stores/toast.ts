import { ref } from 'vue';
import { defineStore } from 'pinia';

export type ToastType = 'success' | 'error' | 'warning' | 'info';

export interface Toast {
    id: number;
    type: ToastType;
    message: string;
}

export const useToastStore = defineStore('toast', () => {
    const toasts = ref<Toast[]>([]);
    let nextId = 1;

    const dismiss = (id: number): void => {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    };

    const push = (type: ToastType, message: string, durationMs = 5000): void => {
        const id = nextId++;
        toasts.value.push({ id, type, message });
        if (durationMs > 0 && typeof window !== 'undefined') {
            window.setTimeout(() => dismiss(id), durationMs);
        }
    };

    return {
        toasts,
        dismiss,
        success: (message: string) => push('success', message),
        error: (message: string) => push('error', message, 7000),
        warning: (message: string) => push('warning', message),
        info: (message: string) => push('info', message),
    };
});
