<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { storeToRefs } from 'pinia';
import { useForm } from 'vee-validate';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { money } from '@/composables/validators';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import { useWalletStore } from '@/stores/wallet';
import UserLayout from '@/layouts/UserLayout.vue';
import Badge from '@/components/Badge.vue';
import Skeleton from '@/components/Skeleton.vue';
import TextField from '@/components/Forms/TextField.vue';

useAuthGuard('USER');

const toast = useToastStore();
const walletStore = useWalletStore();
const { wallet, loading } = storeToRefs(walletStore);
const topUpLoading = ref(false);

const { handleSubmit, resetForm, setErrors } = useForm<{ amount: string }>({
    validationSchema: { amount: money('Amount') },
    initialValues: { amount: '' },
});

const handleTopUp = handleSubmit(async (values) => {
    topUpLoading.value = true;
    try {
        await api.post('/transactions/top-up', {
            amount: Number(values.amount),
            idempotency_key: crypto.randomUUID(),
        });
        toast.success(`Successfully added ৳${Number(values.amount).toFixed(2)} to your wallet!`);
        resetForm();
        await walletStore.fetchWallet();
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Top-up failed.');
        if (fieldErrors.amount) {
            setErrors({ amount: fieldErrors.amount });
        }
        toast.error(message);
    } finally {
        topUpLoading.value = false;
    }
});

const dailyPercentage = computed(() => {
    if (!wallet.value?.caps?.daily_limit) return 0;
    return Math.min(100, Math.round((wallet.value.caps.daily_used / wallet.value.caps.daily_limit) * 100));
});

const monthlyPercentage = computed(() => {
    if (!wallet.value?.caps?.monthly_limit) return 0;
    return Math.min(100, Math.round((wallet.value.caps.monthly_used / wallet.value.caps.monthly_limit) * 100));
});

onMounted(async () => {
    const result = await walletStore.fetchWallet();
    if (!result && walletStore.error) {
        toast.error(walletStore.error);
    }
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
                        <Skeleton v-if="loading && !wallet" class="mt-3" height="h-10" />
                        <div v-else class="mt-3 flex items-baseline gap-2">
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

                        <form class="space-y-3" novalidate @submit.prevent="handleTopUp">
                            <TextField name="amount" label="Amount (BDT)" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="500.00" />
                            <button
                                type="submit"
                                :disabled="topUpLoading || wallet?.is_blocked"
                                class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 disabled:opacity-50 transition"
                            >
                                {{ topUpLoading ? 'Processing...' : 'Load Funds' }}
                            </button>
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
