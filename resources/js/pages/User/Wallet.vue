<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { api } from '@/services/api';
import UserLayout from '@/layouts/UserLayout.vue';
import Badge from '@/components/Badge.vue';
import Alert from '@/components/Alert.vue';
import type { Wallet } from '@/types/models';

useAuthGuard('USER');

const wallet = ref<Wallet | null>(null);
const loading = ref(true);
const topUpLoading = ref(false);
const topUpAmount = ref<number | ''>('');
const successMessage = ref<string | null>(null);
const errorMessage = ref<string | null>(null);

const fetchWallet = async () => {
    loading.value = true;
    try {
        const res = await api.get('/wallets/me');
        wallet.value = res.data.data?.wallet ?? res.data.data;
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Failed to fetch wallet.';
    } finally {
        loading.value = false;
    }
};

const handleTopUp = async () => {
    if (!topUpAmount.value || topUpAmount.value <= 0) {
        errorMessage.value = 'Please specify a valid top-up amount.';
        return;
    }

    topUpLoading.value = true;
    errorMessage.value = null;
    successMessage.value = null;

    try {
        const res = await api.post('/transactions/top-up', {
            amount: Number(topUpAmount.value),
            idempotency_key: crypto.randomUUID(),
        });
        successMessage.value = `Successfully added ৳${Number(topUpAmount.value).toFixed(2)} to your wallet!`;
        topUpAmount.value = '';
        await fetchWallet();
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Top-up failed.';
    } finally {
        topUpLoading.value = false;
    }
};

const dailyPercentage = computed(() => {
    if (!wallet.value?.caps?.daily_limit) return 0;
    return Math.min(100, Math.round((wallet.value.caps.daily_used / wallet.value.caps.daily_limit) * 100));
});

const monthlyPercentage = computed(() => {
    if (!wallet.value?.caps?.monthly_limit) return 0;
    return Math.min(100, Math.round((wallet.value.caps.monthly_used / wallet.value.caps.monthly_limit) * 100));
});

onMounted(() => {
    fetchWallet();
});
</script>

<template>
    <Head title="My Wallet & Caps - WalletMS" />
    <UserLayout>
        <div class="space-y-6 max-w-4xl mx-auto">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    Wallet Overview & Limits
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Review your account balance, transaction limits, and load funds
                </p>
            </div>

            <div v-if="successMessage" class="mb-4">
                <Alert type="success" :message="successMessage" dismissible @close="successMessage = null" />
            </div>
            <div v-if="errorMessage" class="mb-4">
                <Alert type="error" :message="errorMessage" dismissible @close="errorMessage = null" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Balance & Top-Up Card -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-6">
                    <div>
                        <div class="flex items-center justify-between text-zinc-500 dark:text-zinc-400">
                            <span class="text-xs font-semibold uppercase tracking-wider">Account Balance</span>
                            <Badge :variant="wallet?.is_blocked ? 'danger' : 'success'">
                                {{ wallet?.is_blocked ? 'BLOCKED' : 'ACTIVE' }}
                            </Badge>
                        </div>
                        <div class="mt-3 flex items-baseline gap-2">
                            <span class="text-4xl font-extrabold text-zinc-900 dark:text-zinc-100">
                                ৳{{ Number(wallet?.balance ?? 0).toFixed(2) }}
                            </span>
                            <span class="text-sm font-semibold text-zinc-500">{{ wallet?.currency ?? 'BDT' }}</span>
                        </div>
                    </div>

                    <!-- Top-Up Form -->
                    <div class="pt-6 border-t border-zinc-100 dark:border-zinc-800">
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Simulate Direct Top-Up</h3>
                        <p class="text-xs text-zinc-500 mb-4">Add demo balance into your personal wallet.</p>

                        <form class="space-y-3" @submit.prevent="handleTopUp">
                            <div>
                                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Amount (BDT)</label>
                                <div class="mt-1 flex gap-2">
                                    <input
                                        v-model="topUpAmount"
                                        type="number"
                                        step="0.01"
                                        min="1"
                                        required
                                        placeholder="500.00"
                                        class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                                    />
                                    <button
                                        type="submit"
                                        :disabled="topUpLoading || wallet?.is_blocked"
                                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 disabled:opacity-50 transition"
                                    >
                                        {{ topUpLoading ? 'Processing...' : 'Load Funds' }}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Transaction Limits Card -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-6">
                    <div>
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Regulatory Limits & Caps</h2>
                        <p class="text-xs text-zinc-500">Security thresholds automatically monitored on every transfer</p>
                    </div>

                    <!-- Daily Cap -->
                    <div class="space-y-2">
                        <div class="flex justify-between text-xs font-semibold">
                            <span>Daily Limit: ৳{{ Number(wallet?.caps?.daily_limit ?? 10000).toFixed(2) }}</span>
                            <span class="text-indigo-600 dark:text-indigo-400">{{ dailyPercentage }}% Used</span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                            <div
                                class="h-full bg-indigo-600 transition-all duration-500"
                                :style="{ width: `${dailyPercentage}%` }"
                            />
                        </div>
                        <div class="flex justify-between text-[11px] text-zinc-500">
                            <span>Used: ৳{{ Number(wallet?.caps?.daily_used ?? 0).toFixed(2) }}</span>
                            <span class="font-medium text-emerald-600 dark:text-emerald-400">
                                Remaining: ৳{{ Number(wallet?.caps?.daily_remaining ?? 0).toFixed(2) }}
                            </span>
                        </div>
                    </div>

                    <!-- Monthly Cap -->
                    <div class="space-y-2 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <div class="flex justify-between text-xs font-semibold">
                            <span>Monthly Limit: ৳{{ Number(wallet?.caps?.monthly_limit ?? 50000).toFixed(2) }}</span>
                            <span class="text-indigo-600 dark:text-indigo-400">{{ monthlyPercentage }}% Used</span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                            <div
                                class="h-full bg-indigo-600 transition-all duration-500"
                                :style="{ width: `${monthlyPercentage}%` }"
                            />
                        </div>
                        <div class="flex justify-between text-[11px] text-zinc-500">
                            <span>Used: ৳{{ Number(wallet?.caps?.monthly_used ?? 0).toFixed(2) }}</span>
                            <span class="font-medium text-emerald-600 dark:text-emerald-400">
                                Remaining: ৳{{ Number(wallet?.caps?.monthly_remaining ?? 0).toFixed(2) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
