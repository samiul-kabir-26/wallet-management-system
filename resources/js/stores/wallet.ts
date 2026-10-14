import { ref } from 'vue';
import { defineStore } from 'pinia';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import type { Caps, Wallet } from '@/types/models';

export const useWalletStore = defineStore('wallet', () => {
    const wallet = ref<Wallet | null>(null);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    const fetchWallet = async (): Promise<Wallet | null> => {
        loading.value = true;
        error.value = null;
        try {
            const res = await api.get('/wallets/me');
            const fetched: Wallet = res.data.data?.wallet ?? res.data.data;

            // Caps limit customer spending, so they only exist for USER wallets
            if (fetched?.kind === 'USER') {
                const capsRes = await api.get('/transactions/caps');
                fetched.caps = capsRes.data.data?.caps as Caps;
            }

            wallet.value = fetched;
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
