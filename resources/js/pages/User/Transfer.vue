<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { api } from '@/services/api';
import UserLayout from '@/layouts/UserLayout.vue';
import Alert from '@/components/Alert.vue';

useAuthGuard('USER');

const activeTab = ref<'transfer' | 'cash_out' | 'cash_in'>('transfer');

// Form states
const recipientId = ref<number | ''>('');
const agentId = ref<number | ''>('');
const amount = ref<number | ''>('');

const loading = ref(false);
const successMessage = ref<string | null>(null);
const errorMessage = ref<string | null>(null);

const estimatedCashOutFee = computed(() => {
    if (!amount.value || Number(amount.value) <= 0) return 0;
    return Number(amount.value) * 0.05; // 5% standard fee
});

const resetForm = () => {
    recipientId.value = '';
    agentId.value = '';
    amount.value = '';
};

const handleSendMoney = async () => {
    if (!recipientId.value) {
        errorMessage.value = 'Please provide a valid recipient user ID.';
        return;
    }
    loading.value = true;
    errorMessage.value = null;
    successMessage.value = null;
    try {
        const res = await api.post('/transactions/transfer', {
            recipient_id: Number(recipientId.value),
            amount: Number(amount.value),
            idempotency_key: crypto.randomUUID(),
        });
        const txId = res.data.data?.id ?? '';
        successMessage.value = `Successfully transferred ৳${Number(amount.value).toFixed(2)} to User #${recipientId.value}! (Tx ID: #${txId})`;
        resetForm();
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Transfer failed. Check recipient ID and balance.';
    } finally {
        loading.value = false;
    }
};

const handleCashOut = async () => {
    if (!agentId.value) {
        errorMessage.value = 'Please provide a valid agent user ID.';
        return;
    }
    loading.value = true;
    errorMessage.value = null;
    successMessage.value = null;
    try {
        const res = await api.post('/transactions/cash-out', {
            agent_id: Number(agentId.value),
            amount: Number(amount.value),
            idempotency_key: crypto.randomUUID(),
        });
        const txId = res.data.data?.id ?? '';
        successMessage.value = `Successfully initiated Cash-Out of ৳${Number(amount.value).toFixed(2)} with Agent #${agentId.value}! (Tx ID: #${txId})`;
        resetForm();
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Cash-out failed. Ensure agent ID is an approved agent.';
    } finally {
        loading.value = false;
    }
};

const handleCashIn = async () => {
    if (!agentId.value) {
        errorMessage.value = 'Please provide a valid agent user ID.';
        return;
    }
    loading.value = true;
    errorMessage.value = null;
    successMessage.value = null;
    try {
        const res = await api.post('/transactions/cash-in', {
            agent_id: Number(agentId.value),
            amount: Number(amount.value),
            idempotency_key: crypto.randomUUID(),
        });
        const txId = res.data.data?.id ?? '';
        successMessage.value = `Successfully cashed-in ৳${Number(amount.value).toFixed(2)} from Agent #${agentId.value}! (Tx ID: #${txId})`;
        resetForm();
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Cash-in failed.';
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <Head title="Send Money & Transfers - WalletMS" />
    <UserLayout>
        <div class="max-w-2xl mx-auto space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    Financial Movements
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Transfer money directly to users or transact with registered financial agents
                </p>
            </div>

            <!-- Tab Buttons -->
            <div class="flex rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
                <button
                    type="button"
                    class="flex-1 rounded-lg py-2.5 text-xs font-semibold transition"
                    :class="
                        activeTab === 'transfer'
                            ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100'
                            : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400'
                    "
                    @click="activeTab = 'transfer'; errorMessage = null; successMessage = null;"
                >
                    Send Money (P2P)
                </button>
                <button
                    type="button"
                    class="flex-1 rounded-lg py-2.5 text-xs font-semibold transition"
                    :class="
                        activeTab === 'cash_out'
                            ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100'
                            : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400'
                    "
                    @click="activeTab = 'cash_out'; errorMessage = null; successMessage = null;"
                >
                    Cash-Out (Withdraw)
                </button>
                <button
                    type="button"
                    class="flex-1 rounded-lg py-2.5 text-xs font-semibold transition"
                    :class="
                        activeTab === 'cash_in'
                            ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100'
                            : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400'
                    "
                    @click="activeTab = 'cash_in'; errorMessage = null; successMessage = null;"
                >
                    Cash-In (Deposit)
                </button>
            </div>

            <div v-if="successMessage" class="mb-4">
                <Alert type="success" :message="successMessage" dismissible @close="successMessage = null" />
            </div>
            <div v-if="errorMessage" class="mb-4">
                <Alert type="error" :message="errorMessage" dismissible @close="errorMessage = null" />
            </div>

            <!-- Form Card -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Send Money Form -->
                <form v-if="activeTab === 'transfer'" class="space-y-4" @submit.prevent="handleSendMoney">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Recipient User ID</label>
                        <input
                            v-model="recipientId"
                            type="number"
                            min="1"
                            required
                            placeholder="e.g. 2"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Amount (BDT)</label>
                        <input
                            v-model="amount"
                            type="number"
                            step="0.01"
                            min="1"
                            required
                            placeholder="200.00"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>

                    <button
                        type="submit"
                        :disabled="loading"
                        class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 disabled:opacity-50 transition"
                    >
                        {{ loading ? 'Processing Transfer...' : 'Send Money Now' }}
                    </button>
                </form>

                <!-- Cash-Out Form -->
                <form v-else-if="activeTab === 'cash_out'" class="space-y-4" @submit.prevent="handleCashOut">
                    <div class="rounded-lg bg-amber-50 p-3 text-xs text-amber-800 border border-amber-200 dark:bg-amber-950/30 dark:border-amber-800 dark:text-amber-300">
                        Cash-Out incurs a <strong>5% system fee</strong>. If you withdraw ৳1000, ৳1050 will be deducted from your wallet balance.
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Agent User ID</label>
                        <input
                            v-model="agentId"
                            type="number"
                            min="1"
                            required
                            placeholder="e.g. 3"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Withdrawal Amount (BDT)</label>
                        <input
                            v-model="amount"
                            type="number"
                            step="0.01"
                            min="1"
                            required
                            placeholder="500.00"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                        <p v-if="amount" class="mt-1 text-xs text-zinc-500">
                            Estimated fee: ৳{{ estimatedCashOutFee.toFixed(2) }} (Total deduction: ৳{{ (Number(amount) + estimatedCashOutFee).toFixed(2) }})
                        </p>
                    </div>

                    <button
                        type="submit"
                        :disabled="loading"
                        class="w-full rounded-lg bg-amber-600 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-amber-500 disabled:opacity-50 transition"
                    >
                        {{ loading ? 'Processing Cash-Out...' : 'Confirm Cash-Out' }}
                    </button>
                </form>

                <!-- Cash-In Form -->
                <form v-else class="space-y-4" @submit.prevent="handleCashIn">
                    <div class="rounded-lg bg-emerald-50 p-3 text-xs text-emerald-800 border border-emerald-200 dark:bg-emerald-950/30 dark:border-emerald-800 dark:text-emerald-300">
                        Cash-In is completely free of charge. You receive the exact amount loaded by the agent.
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Agent User ID</label>
                        <input
                            v-model="agentId"
                            type="number"
                            min="1"
                            required
                            placeholder="e.g. 3"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Cash-In Amount (BDT)</label>
                        <input
                            v-model="amount"
                            type="number"
                            step="0.01"
                            min="1"
                            required
                            placeholder="1000.00"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>

                    <button
                        type="submit"
                        :disabled="loading"
                        class="w-full rounded-lg bg-emerald-600 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-emerald-500 disabled:opacity-50 transition"
                    >
                        {{ loading ? 'Processing Cash-In...' : 'Accept Cash-In' }}
                    </button>
                </form>
            </div>
        </div>
    </UserLayout>
</template>
