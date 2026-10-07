<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { api } from '@/services/api';
import AgentLayout from '@/layouts/AgentLayout.vue';
import Alert from '@/components/Alert.vue';
import type { Wallet } from '@/types/models';

useAuthGuard('AGENT');

const wallet = ref<Wallet | null>(null);
const amount = ref<number | ''>('');
const loading = ref(false);
const successMessage = ref<string | null>(null);
const errorMessage = ref<string | null>(null);

const fetchWallet = async () => {
    try {
        const res = await api.get('/wallets/me');
        wallet.value = res.data.data?.wallet ?? res.data.data;
    } catch {
        // Ignore
    }
};

const handleWithdrawal = async () => {
    if (!amount.value || Number(amount.value) <= 0) {
        errorMessage.value = 'Please enter a valid withdrawal amount.';
        return;
    }

    loading.value = true;
    errorMessage.value = null;
    successMessage.value = null;

    try {
        const res = await api.post('/transactions/agent/withdrawal', {
            amount: Number(amount.value),
            idempotency_key: crypto.randomUUID(),
        });

        const txId = res.data.data?.id ?? '';
        successMessage.value = `Successfully initiated bank withdrawal of ৳${Number(amount.value).toFixed(2)}! (Tx ID: #${txId})`;
        amount.value = '';
        await fetchWallet();
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Withdrawal failed. Check balance and agent status.';
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchWallet();
});
</script>

<template>
    <Head title="Bank Withdrawal - WalletMS Agent" />
    <AgentLayout>
        <div class="max-w-xl mx-auto space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    Agent Bank Withdrawal
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Disburse earned commissions and wallet funds directly to your bank account
                </p>
            </div>

            <div v-if="successMessage" class="mb-4">
                <Alert type="success" :message="successMessage" dismissible @close="successMessage = null" />
            </div>
            <div v-if="errorMessage" class="mb-4">
                <Alert type="error" :message="errorMessage" dismissible @close="errorMessage = null" />
            </div>

            <!-- Current Balance Card -->
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 flex justify-between items-center">
                <div>
                    <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Available Balance</span>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mt-1">
                        ৳{{ Number(wallet?.balance ?? 0).toFixed(2) }}
                    </div>
                </div>
                <div class="text-xs text-zinc-400">
                    Currency: {{ wallet?.currency ?? 'BDT' }}
                </div>
            </div>

            <!-- Form -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <form class="space-y-4" @submit.prevent="handleWithdrawal">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Withdrawal Amount (BDT)</label>
                        <input
                            v-model="amount"
                            type="number"
                            step="0.01"
                            min="1"
                            required
                            placeholder="500.00"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>

                    <button
                        type="submit"
                        :disabled="loading"
                        class="w-full rounded-lg bg-amber-600 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-amber-500 disabled:opacity-50 transition"
                    >
                        {{ loading ? 'Processing Bank Transfer...' : 'Withdraw to Bank' }}
                    </button>
                </form>
            </div>
        </div>
    </AgentLayout>
</template>
