<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/Badge.vue';
import Pagination from '@/components/Pagination.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { Pagination as PaginationType, Transaction } from '@/types/models';

useAuthGuard('ADMIN', 'transactions.view_any');

const toast = useToastStore();

type State = 'all' | 'awaiting_payment' | 'on_hold';

const state = ref<State>('all');
const topUps = ref<Transaction[]>([]);
const pagination = ref<PaginationType | null>(null);
const loading = ref(true);
const checkingId = ref<number | null>(null);

const holdLabels: Record<string, string> = {
    AMOUNT_MISMATCH: 'Paid amount differs',
    HIGH_RISK: 'Flagged high risk',
    WALLET_BLOCKED: 'Wallet blocked',
};

const holdReason = (tx: Transaction): string | null =>
    ((tx.meta?.on_hold as { reason?: string } | undefined)?.reason ?? null);

const age = (createdAt: string): string => {
    const minutes = Math.max(0, Math.floor((Date.now() - new Date(createdAt).getTime()) / 60000));
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 48) return `${hours} h ago`;
    return `${Math.floor(hours / 24)} days ago`;
};

const fetchTopUps = async (page = 1) => {
    loading.value = true;
    try {
        const res = await api.get('/payments/admin/top-ups', { params: { state: state.value, page, per_page: 15 } });
        topUps.value = res.data.data?.items ?? [];
        pagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load top-ups.').message);
    } finally {
        loading.value = false;
    }
};

watch(state, () => fetchTopUps(1));

const recheck = async (tx: Transaction) => {
    checkingId.value = tx.id;
    try {
        const res = await api.post(`/payments/admin/top-ups/${tx.id}/reconcile`);
        const updated = res.data.data.transaction as Transaction;
        const reason = holdReason(updated);
        if (updated.status === 'COMPLETED') {
            toast.success(`Top-up #${tx.id} was paid and has been credited.`);
        } else if (updated.status === 'FAILED') {
            toast.info(`Top-up #${tx.id} was not paid and is now marked failed.`);
        } else if (reason) {
            toast.info(`Top-up #${tx.id} is on hold: ${holdLabels[reason] ?? reason}.`);
        } else {
            toast.info(`The gateway has no completed payment for top-up #${tx.id} yet.`);
        }
        await fetchTopUps(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Could not reach the payment gateway.').message);
    } finally {
        checkingId.value = null;
    }
};

onMounted(() => fetchTopUps());
</script>

<template>
    <Head title="Pending Top-ups - WalletMS Admin" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">Pending Top-ups</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        SSLCommerz top-ups that are not finished. Unpaid ones are checked automatically every 10 minutes and expire after
                        24 hours. Held payments were received but need a decision before they are credited.
                    </p>
                </div>
                <select
                    v-model="state"
                    aria-label="State"
                    class="rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                >
                    <option value="all">All unfinished</option>
                    <option value="awaiting_payment">Awaiting payment</option>
                    <option value="on_hold">On hold</option>
                </select>
            </div>

            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div v-if="loading && topUps.length === 0" class="p-6"><Skeleton :rows="5" height="h-6" /></div>

                <div v-else-if="topUps.length === 0" class="p-12 text-center text-sm text-zinc-500">
                    No unfinished top-ups in this list.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-6 py-3.5">TX ID</th>
                                <th class="px-6 py-3.5">Customer</th>
                                <th class="px-6 py-3.5">Amount</th>
                                <th class="px-6 py-3.5">Started</th>
                                <th class="px-6 py-3.5">State</th>
                                <th class="px-6 py-3.5">Reference</th>
                                <th class="px-6 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="tx in topUps" :key="tx.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
                                <td class="px-6 py-4 font-mono text-xs font-semibold">#{{ tx.id }}</td>
                                <td class="px-6 py-4">
                                    <Link
                                        :href="`/admin/users/${tx.recipient_id}`"
                                        class="font-medium text-zinc-900 hover:text-indigo-600 dark:text-zinc-100 dark:hover:text-indigo-400"
                                    >
                                        {{ tx.parties?.recipient?.name ?? `User #${tx.recipient_id}` }}
                                    </Link>
                                    <div class="text-xs text-zinc-500">{{ tx.parties?.recipient?.phone_number ?? '' }}</div>
                                </td>
                                <td class="px-6 py-4 font-semibold">৳{{ Number(tx.amount).toFixed(2) }}</td>
                                <td class="px-6 py-4 text-xs text-zinc-500" :title="new Date(tx.created_at).toLocaleString()">
                                    {{ age(tx.created_at) }}
                                </td>
                                <td class="px-6 py-4">
                                    <Badge v-if="holdReason(tx)" variant="danger">ON HOLD</Badge>
                                    <Badge v-else variant="warning">AWAITING PAYMENT</Badge>
                                    <div v-if="holdReason(tx)" class="mt-1 text-xs text-zinc-500">
                                        {{ holdLabels[holdReason(tx) as string] ?? holdReason(tx) }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-xs text-zinc-500">{{ tx.external_reference }}</td>
                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    <button
                                        type="button"
                                        :disabled="checkingId === tx.id"
                                        class="rounded-md border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 disabled:opacity-50 dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300"
                                        @click="recheck(tx)"
                                    >
                                        {{ checkingId === tx.id ? 'Checking...' : 'Check with gateway' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :pagination="pagination" :loading="loading" @change-page="fetchTopUps" />
            </div>
        </div>
    </AdminLayout>
</template>
