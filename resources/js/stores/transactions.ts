import { ref } from 'vue';
import { defineStore } from 'pinia';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import type { Pagination, Transaction } from '@/types/models';

export const useTransactionStore = defineStore('transactions', () => {
    const items = ref<Transaction[]>([]);
    const pagination = ref<Pagination | null>(null);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    const fetchHistory = async (page = 1, perPage = 15): Promise<void> => {
        loading.value = true;
        error.value = null;
        try {
            const res = await api.get('/transactions/history', {
                params: { page, per_page: perPage },
            });
            items.value = res.data.data?.items ?? [];
            pagination.value = res.data.data?.pagination ?? null;
        } catch (err: unknown) {
            error.value = parseApiError(err, 'Failed to fetch transaction history.').message;
        } finally {
            loading.value = false;
        }
    };

    const reset = (): void => {
        items.value = [];
        pagination.value = null;
        error.value = null;
    };

    return { items, pagination, loading, error, fetchHistory, reset };
});
