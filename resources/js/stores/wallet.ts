import { ref } from 'vue';
import { defineStore } from 'pinia';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import type { Wallet } from '@/types/models';

export const useWalletStore = defineStore('wallet', () => {
    const wallet = ref<Wallet | null>(null);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    const fetchWallet = async (): Promise<Wallet | null> => {
        loading.value = true;
        error.value = null;
        try {
            const res = await api.get('/wallets/me');
            wallet.value = res.data.data?.wallet ?? res.data.data;
            return wallet.value;
        } catch (err: unknown) {
            error.value = parseApiError(err, 'Failed to fetch wallet.').message;
            return null;
        } finally {
            loading.value = false;
        }
    };

    const reset = (): void => {
        wallet.value = null;
        error.value = null;
    };

    return { wallet, loading, error, fetchWallet, reset };
});
