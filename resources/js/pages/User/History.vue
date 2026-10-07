<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { api } from '@/services/api';
import UserLayout from '@/layouts/UserLayout.vue';
import Badge from '@/components/Badge.vue';
import Pagination from '@/components/Pagination.vue';
import Alert from '@/components/Alert.vue';
import type { Pagination as PaginationType, Transaction } from '@/types/models';

useAuthGuard('USER');

const transactions = ref<Transaction[]>([]);
const pagination = ref<PaginationType | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const currentPage = ref(1);

const fetchHistory = async (page = 1) => {
    loading.value = true;
    error.value = null;
    currentPage.value = page;
    try {
        const res = await api.get('/transactions/history', {
            params: {
                page,
                per_page: 15,
            },
        });
        transactions.value = res.data.data?.items ?? [];
        pagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        error.value = apiError.response?.data?.message || 'Failed to fetch transaction history.';
    } finally {
        loading.value = false;
    }
};

const handlePageChange = (page: number) => {
    fetchHistory(page);
};

onMounted(() => {
    fetchHistory();
});
</script>

<template>
    <Head title="Transaction History - WalletMS" />
    <UserLayout>
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        Transaction History
                    </h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Comprehensive ledger of all your debits, credits, and withdrawals
                    </p>
                </div>
            </div>

            <div v-if="error" class="mb-4">
                <Alert type="error" :message="error" />
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div v-if="loading && transactions.length === 0" class="p-12 text-center text-sm text-zinc-500">
                    Loading records...
                </div>

                <div v-else-if="transactions.length === 0" class="p-12 text-center text-sm text-zinc-500">
                    No transactions found in your history.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-6 py-3.5">TX ID</th>
                                <th class="px-6 py-3.5">Type</th>
                                <th class="px-6 py-3.5">Amount</th>
                                <th class="px-6 py-3.5">Fee</th>
                                <th class="px-6 py-3.5">Status</th>
                                <th class="px-6 py-3.5">Date & Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="tx in transactions" :key="tx.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
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

                <Pagination
                    :pagination="pagination"
                    :loading="loading"
                    @change-page="handlePageChange"
                />
            </div>
        </div>
    </UserLayout>
</template>
