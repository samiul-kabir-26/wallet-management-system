<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { api } from '@/services/api';
import UserLayout from '@/layouts/UserLayout.vue';
import Badge from '@/components/Badge.vue';
import Alert from '@/components/Alert.vue';
import type { Transaction, Wallet } from '@/types/models';

const { authStore } = useAuthGuard('USER');

const wallet = ref<Wallet | null>(null);
const recentTransactions = ref<Transaction[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const fetchData = async () => {
    loading.value = true;
    error.value = null;
    try {
        const [walletRes, txRes] = await Promise.all([
            api.get('/wallets/me'),
            api.get('/transactions/history', { params: { per_page: 5 } }),
        ]);

        wallet.value = walletRes.data.data?.wallet ?? walletRes.data.data;
        recentTransactions.value = txRes.data.data?.items ?? [];
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        error.value = apiError.response?.data?.message || 'Failed to load wallet data.';
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchData();
});
</script>

<template>
    <Head title="Customer Dashboard - WalletMS" />
    <UserLayout>
        <div class="space-y-6">
            <!-- Welcome Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        Hello, {{ authStore.user?.name ?? 'Customer' }} 👋
                    </h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Phone: {{ authStore.user?.phone_number ?? 'N/A' }} | Manage your balance and transfers
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Link
                        href="/user/wallet"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 transition"
                    >
                        + Top-Up Balance
                    </Link>
                    <Link
                        href="/user/transfer"
                        class="rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 shadow-xs hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 transition"
                    >
                        Send Money
                    </Link>
                </div>
            </div>

            <div v-if="error" class="mb-4">
                <Alert type="error" :message="error" />
            </div>

            <!-- Stats & Balance Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Balance Card -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between text-zinc-500 dark:text-zinc-400">
                        <span class="text-xs font-semibold uppercase tracking-wider">Available Balance</span>
                        <Badge :variant="wallet?.is_blocked ? 'danger' : 'success'">
                            {{ wallet?.is_blocked ? 'Blocked' : 'Active' }}
                        </Badge>
                    </div>
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-4xl font-extrabold tracking-tight text-zinc-900 dark:text-zinc-100">
                            {{ Number(wallet?.balance ?? 0).toFixed(2) }}
                        </span>
                        <span class="text-lg font-bold text-zinc-500">{{ wallet?.currency ?? 'BDT' }}</span>
                    </div>
                    <div class="mt-4 border-t border-zinc-100 pt-3 text-xs text-zinc-500 dark:border-zinc-800">
                        Wallet ID: #{{ wallet?.id ?? '...' }}
                    </div>
                </div>

                <!-- Daily Cap Card -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between text-zinc-500 dark:text-zinc-400">
                        <span class="text-xs font-semibold uppercase tracking-wider">Daily Limit Cap</span>
                        <span class="text-xs font-medium">Resets 00:00 UTC</span>
                    </div>
                    <div class="mt-4">
                        <div class="flex justify-between text-sm font-semibold">
                            <span>Remaining:</span>
                            <span class="text-emerald-600 dark:text-emerald-400">
                                ৳{{ Number(wallet?.caps?.daily_remaining ?? 0).toFixed(2) }}
                            </span>
                        </div>
                        <div class="mt-2 text-xs text-zinc-500">
                            Used ৳{{ Number(wallet?.caps?.daily_used ?? 0).toFixed(2) }} of ৳{{ Number(wallet?.caps?.daily_limit ?? 10000).toFixed(2) }}
                        </div>
                    </div>
                </div>

                <!-- Monthly Cap Card -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between text-zinc-500 dark:text-zinc-400">
                        <span class="text-xs font-semibold uppercase tracking-wider">Monthly Limit Cap</span>
                        <span class="text-xs font-medium">Resets 1st of month</span>
                    </div>
                    <div class="mt-4">
                        <div class="flex justify-between text-sm font-semibold">
                            <span>Remaining:</span>
                            <span class="text-emerald-600 dark:text-emerald-400">
                                ৳{{ Number(wallet?.caps?.monthly_remaining ?? 0).toFixed(2) }}
                            </span>
                        </div>
                        <div class="mt-2 text-xs text-zinc-500">
                            Used ৳{{ Number(wallet?.caps?.monthly_used ?? 0).toFixed(2) }} of ৳{{ Number(wallet?.caps?.monthly_limit ?? 50000).toFixed(2) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions Table -->
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between p-6 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Recent Transactions</h2>
                        <p class="text-xs text-zinc-500">Latest financial movements on your account</p>
                    </div>
                    <Link
                        href="/user/history"
                        class="text-xs font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                    >
                        View All History →
                    </Link>
                </div>

                <div v-if="loading" class="p-8 text-center text-sm text-zinc-500">
                    Loading transactions...
                </div>

                <div v-else-if="recentTransactions.length === 0" class="p-8 text-center text-sm text-zinc-500">
                    No transactions recorded yet.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-6 py-3">TX ID</th>
                                <th class="px-6 py-3">Type</th>
                                <th class="px-6 py-3">Amount</th>
                                <th class="px-6 py-3">Fee</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="tx in recentTransactions" :key="tx.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
                                <td class="px-6 py-4 font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                    #{{ tx.id }}
                                </td>
                                <td class="px-6 py-4">
                                    <Badge variant="neutral">{{ tx.type }}</Badge>
                                </td>
                                <td class="px-6 py-4 font-semibold text-zinc-900 dark:text-zinc-100">
                                    ৳{{ Number(tx.amount).toFixed(2) }}
                                </td>
                                <td class="px-6 py-4 text-xs text-zinc-500">
                                    ৳{{ Number(tx.system_fee_amount).toFixed(2) }}
                                </td>
                                <td class="px-6 py-4">
                                    <Badge :variant="tx.status === 'COMPLETED' ? 'success' : tx.status === 'FAILED' ? 'danger' : 'warning'">
                                        {{ tx.status }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4 text-xs text-zinc-500">
                                    {{ new Date(tx.created_at).toLocaleString() }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
