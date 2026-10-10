<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { api } from '@/services/api';
import AgentLayout from '@/layouts/AgentLayout.vue';
import Badge from '@/components/Badge.vue';
import Skeleton from '@/components/Skeleton.vue';
import Alert from '@/components/Alert.vue';
import type { AgentInfo, Transaction, Wallet } from '@/types/models';

const { authStore } = useAuthGuard('AGENT');

const wallet = ref<Wallet | null>(null);
const agentInfo = ref<AgentInfo | null>(null);
const recentTransactions = ref<Transaction[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const fetchData = async () => {
    loading.value = true;
    error.value = null;
    try {
        const [walletRes, userRes, txRes] = await Promise.all([
            api.get('/wallets/me'),
            api.get(`/users/${authStore.user?.id}`),
            api.get('/transactions/history', { params: { per_page: 5 } }),
        ]);

        wallet.value = walletRes.data.data?.wallet ?? walletRes.data.data;
        agentInfo.value = userRes.data.data?.user?.agent_info ?? userRes.data.data?.agent_info ?? null;
        recentTransactions.value = txRes.data.data?.items ?? [];
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        error.value = apiError.response?.data?.message || 'Failed to load agent dashboard data.';
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchData();
});
</script>

<template>
    <Head title="Agent Dashboard - WalletMS" />
    <AgentLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        Agent Dashboard
                    </h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Agent: {{ authStore.user?.name }} ({{ authStore.user?.phone_number }})
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Link
                        href="/agent/withdrawal"
                        class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-amber-500 transition"
                    >
                        Bank Withdrawal
                    </Link>
                </div>
            </div>

            <!-- Approval Status Alert -->
            <div v-if="agentInfo?.status === 'PENDING'" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                <div class="flex items-center gap-2 font-semibold">
                    <span>⚠️ Account Pending Approval</span>
                </div>
                <p class="mt-1 text-xs">
                    Your agent registration is currently awaiting administrative approval. You will not be able to process cash-in, cash-out, or withdrawals until an administrator verifies and activates your profile.
                </p>
            </div>

            <div v-if="agentInfo?.status === 'SUSPENDED'" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
                <div class="flex items-center gap-2 font-semibold">
                    <span>⛔ Account Suspended</span>
                </div>
                <p class="mt-1 text-xs">
                    Your agent status is currently SUSPENDED. All cash-in, cash-out, and withdrawal operations are halted. Contact administrative support.
                </p>
            </div>

            <div v-if="error" class="mb-4">
                <Alert type="error" :message="error" />
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Balance -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between text-zinc-500 dark:text-zinc-400">
                        <span class="text-xs font-semibold uppercase tracking-wider">Agent Wallet Balance</span>
                        <Badge :variant="agentInfo?.status === 'APPROVED' ? 'success' : 'warning'">
                            {{ agentInfo?.status ?? 'PENDING' }}
                        </Badge>
                    </div>
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-4xl font-extrabold tracking-tight text-zinc-900 dark:text-zinc-100">
                            {{ Number(wallet?.balance ?? 0).toFixed(2) }}
                        </span>
                        <span class="text-lg font-bold text-zinc-500">BDT</span>
                    </div>
                </div>

                <!-- Commission Rate -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between text-zinc-500 dark:text-zinc-400">
                        <span class="text-xs font-semibold uppercase tracking-wider">Commission Rate</span>
                        <span class="text-xs font-medium">Per Cash-Out Fee</span>
                    </div>
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-4xl font-extrabold tracking-tight text-amber-600 dark:text-amber-400">
                            {{ (Number(agentInfo?.commission_rate ?? 0) * 100).toFixed(1) }}%
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-zinc-500">
                        Percentage earned on system cash-out fees.
                    </p>
                </div>

                <!-- Total Commission Earned -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between text-zinc-500 dark:text-zinc-400">
                        <span class="text-xs font-semibold uppercase tracking-wider">Total Commission Earned</span>
                        <Badge variant="success">Accumulated</Badge>
                    </div>
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-4xl font-extrabold tracking-tight text-emerald-600 dark:text-emerald-400">
                            ৳{{ Number(agentInfo?.total_commission ?? 0).toFixed(2) }}
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-zinc-500">
                        Lifetime commission credited to balance.
                    </p>
                </div>
            </div>

            <!-- Recent Agent Transactions Table -->
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between p-6 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Recent Transactions</h2>
                        <p class="text-xs text-zinc-500">Cash-in, cash-out, and withdrawal activity</p>
                    </div>
                    <Link
                        href="/agent/transactions"
                        class="text-xs font-semibold text-amber-600 hover:text-amber-500 dark:text-amber-400"
                    >
                        View All Activity →
                    </Link>
                </div>

                <div v-if="loading" class="p-6"><Skeleton :rows="5" height="h-6" /></div>

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
                                <th class="px-6 py-3">Commission</th>
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
                                <td class="px-6 py-4 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                    ৳{{ Number(tx.agent_commission_amount).toFixed(2) }}
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
    </AgentLayout>
</template>
