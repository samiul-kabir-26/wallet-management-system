<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Pagination from '@/components/Pagination.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { Pagination as PaginationType, Transaction, TransactionStatus } from '@/types/models';

useAuthGuard('ADMIN', 'agents.approve_withdrawal');

const authStore = useAuthStore();
const toast = useToastStore();

const status = ref<TransactionStatus>('PENDING');
const withdrawals = ref<Transaction[]>([]);
const pagination = ref<PaginationType | null>(null);
const loading = ref(true);

const selected = ref<Transaction | null>(null);
const decision = ref<'approve' | 'reject' | null>(null);
const reference = ref('');
const note = ref('');
const reason = ref('');
const formError = ref<string | null>(null);
const submitting = ref(false);

const fetchWithdrawals = async (page = 1) => {
    loading.value = true;
    try {
        const res = await api.get('/transactions/admin/all', {
            params: { type: 'AGENT_WITHDRAWAL', status: status.value, page, per_page: 15 },
        });
        withdrawals.value = res.data.data?.items ?? [];
        pagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load withdrawals.').message);
    } finally {
        loading.value = false;
    }
};

watch(status, () => fetchWithdrawals(1));

const isOwnRequest = (tx: Transaction): boolean => tx.agent_id === authStore.user?.id;

const openReview = (tx: Transaction, action: 'approve' | 'reject') => {
    selected.value = tx;
    decision.value = action;
    reference.value = '';
    note.value = '';
    reason.value = '';
    formError.value = null;
};

const closeReview = () => {
    decision.value = null;
    selected.value = null;
};

const submitReview = async () => {
    if (!selected.value || !decision.value) return;

    if (decision.value === 'approve' && !reference.value.trim()) {
        formError.value = 'Enter the bank transfer reference for this payout.';
        return;
    }
    if (decision.value === 'reject' && reason.value.trim().length < 5) {
        formError.value = 'Give a reason of at least 5 characters; the agent and auditors will see it.';
        return;
    }

    submitting.value = true;
    formError.value = null;
    try {
        const id = selected.value.id;
        if (decision.value === 'approve') {
            await api.patch(`/transactions/admin/withdrawals/${id}/approve`, {
                external_reference: reference.value.trim(),
                note: note.value.trim() || null,
            });
            toast.success(`Withdrawal #${id} approved.`);
        } else {
            await api.patch(`/transactions/admin/withdrawals/${id}/reject`, { reason: reason.value.trim() });
            toast.success(`Withdrawal #${id} rejected and the funds were returned to the agent.`);
        }
        closeReview();
        await fetchWithdrawals(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to review this withdrawal.');
        formError.value = fieldErrors.external_reference ?? fieldErrors.reason ?? message;
    } finally {
        submitting.value = false;
    }
};

const review = (tx: Transaction): Record<string, unknown> | null =>
    (tx.meta?.review as Record<string, unknown> | undefined) ?? null;

onMounted(() => fetchWithdrawals());
</script>

<template>
    <Head title="Agent Withdrawals - WalletMS Admin" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">Agent Bank Withdrawals</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        The amount is already held from the agent's wallet. Approve once the bank transfer is sent, or reject to return the funds.
                    </p>
                </div>
                <select
                    v-model="status"
                    aria-label="Status"
                    class="rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                >
                    <option value="PENDING">Pending review</option>
                    <option value="COMPLETED">Approved</option>
                    <option value="REJECTED">Rejected</option>
                </select>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div v-if="loading && withdrawals.length === 0" class="p-6"><Skeleton :rows="5" height="h-6" /></div>

                <div v-else-if="withdrawals.length === 0" class="p-12 text-center text-sm text-zinc-500">
                    {{ status === 'PENDING' ? 'No withdrawals are waiting for review.' : 'No withdrawals in this list.' }}
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-6 py-3.5">TX ID</th>
                                <th class="px-6 py-3.5">Agent</th>
                                <th class="px-6 py-3.5">Amount</th>
                                <th class="px-6 py-3.5">Requested</th>
                                <th class="px-6 py-3.5">{{ status === 'PENDING' ? 'Note' : 'Review' }}</th>
                                <th class="px-6 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="tx in withdrawals" :key="tx.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
                                <td class="px-6 py-4 font-mono text-xs font-semibold">#{{ tx.id }}</td>
                                <td class="px-6 py-4">
                                    <Link
                                        v-if="tx.agent_id"
                                        :href="`/admin/users/${tx.agent_id}`"
                                        class="font-medium text-zinc-900 hover:text-indigo-600 dark:text-zinc-100 dark:hover:text-indigo-400"
                                    >
                                        {{ tx.parties?.agent?.name ?? `Agent #${tx.agent_id}` }}
                                    </Link>
                                    <div class="text-xs text-zinc-500">{{ tx.parties?.agent?.phone_number ?? '' }}</div>
                                </td>
                                <td class="px-6 py-4 font-semibold">৳{{ Number(tx.amount).toFixed(2) }}</td>
                                <td class="px-6 py-4 text-xs text-zinc-500">{{ new Date(tx.created_at).toLocaleString() }}</td>
                                <td class="px-6 py-4 text-xs text-zinc-500">
                                    <template v-if="status === 'PENDING'">{{ tx.description ?? '—' }}</template>
                                    <template v-else-if="review(tx)">
                                        <Badge :variant="tx.status === 'COMPLETED' ? 'success' : 'danger'">{{ tx.status }}</Badge>
                                        <div class="mt-1">
                                            {{ tx.status === 'COMPLETED' ? `Ref: ${tx.external_reference ?? '—'}` : `Reason: ${review(tx)?.reason ?? '—'}` }}
                                        </div>
                                    </template>
                                </td>
                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    <template v-if="tx.status === 'PENDING'">
                                        <span v-if="isOwnRequest(tx)" class="text-xs text-zinc-400">Your own request</span>
                                        <template v-else>
                                            <button
                                                type="button"
                                                class="rounded-md bg-emerald-50 border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-400"
                                                @click="openReview(tx, 'approve')"
                                            >
                                                Approve
                                            </button>
                                            <button
                                                type="button"
                                                class="ml-2 rounded-md bg-rose-50 border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-400"
                                                @click="openReview(tx, 'reject')"
                                            >
                                                Reject
                                            </button>
                                        </template>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :pagination="pagination" :loading="loading" @change-page="fetchWithdrawals" />
            </div>
        </div>

        <Modal
            :show="decision !== null"
            :title="decision === 'approve' ? `Approve withdrawal #${selected?.id}` : `Reject withdrawal #${selected?.id}`"
            @close="closeReview"
        >
            <form class="space-y-4" novalidate @submit.prevent="submitReview">
                <p class="text-sm text-zinc-700 dark:text-zinc-300">
                    <strong>৳{{ Number(selected?.amount ?? 0).toFixed(2) }}</strong> requested by
                    <strong>{{ selected?.parties?.agent?.name ?? `Agent #${selected?.agent_id}` }}</strong>.
                </p>

                <template v-if="decision === 'approve'">
                    <div>
                        <label for="withdrawal-reference" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Bank transfer reference *</label>
                        <input
                            id="withdrawal-reference"
                            v-model="reference"
                            type="text"
                            maxlength="255"
                            placeholder="e.g. BEFTN-20261014-0042"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                        <p class="mt-1 text-xs text-zinc-500">Only approve after the money has actually been sent. Each reference can be used once.</p>
                    </div>
                    <div>
                        <label for="withdrawal-note" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Note (optional)</label>
                        <input
                            id="withdrawal-note"
                            v-model="note"
                            type="text"
                            maxlength="500"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                </template>

                <div v-else>
                    <label for="withdrawal-reason" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Reason for rejection *</label>
                    <textarea
                        id="withdrawal-reason"
                        v-model="reason"
                        rows="3"
                        maxlength="500"
                        placeholder="e.g. Bank account name does not match the agent's NID"
                        class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                    <p class="mt-1 text-xs text-zinc-500">The full amount goes back to the agent's wallet immediately.</p>
                </div>

                <p v-if="formError" class="text-xs text-rose-600 dark:text-rose-400">{{ formError }}</p>

                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="closeReview"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        :class="decision === 'approve' ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-rose-600 hover:bg-rose-500'"
                    >
                        {{ submitting ? 'Saving...' : decision === 'approve' ? 'Approve Payout' : 'Reject & Refund' }}
                    </button>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>
