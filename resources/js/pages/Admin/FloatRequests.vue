<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Alert from '@/components/Alert.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Pagination from '@/components/Pagination.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { FloatRequest, Pagination as PaginationType } from '@/types/models';

useAuthGuard('ADMIN', 'agents.issue_float');

const authStore = useAuthStore();
const toast = useToastStore();

const status = ref<'PENDING' | 'APPROVED' | 'REJECTED' | 'CANCELLED'>('PENDING');
const requests = ref<FloatRequest[]>([]);
const pagination = ref<PaginationType | null>(null);
const loading = ref(true);

const selected = ref<FloatRequest | null>(null);
const decision = ref<'approve' | 'reject'>('approve');
const note = ref('');
const formError = ref<string | null>(null);
const submitting = ref(false);

const fetchRequests = async (page = 1) => {
    loading.value = true;
    try {
        const res = await api.get('/transactions/admin/float-requests', { params: { status: status.value, page, per_page: 15 } });
        requests.value = res.data.data?.items ?? [];
        pagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load float requests.').message);
    } finally {
        loading.value = false;
    }
};

watch(status, () => fetchRequests(1));

const isOwn = (request: FloatRequest): boolean => request.agent?.id === authStore.user?.id;

const viewSlip = async (request: FloatRequest) => {
    try {
        const res = await api.get(`/transactions/admin/float-requests/${request.id}/slip`, { responseType: 'blob' });
        window.open(URL.createObjectURL(res.data as Blob), '_blank', 'noopener');
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Could not open the deposit slip.').message);
    }
};

const openReview = (request: FloatRequest, action: 'approve' | 'reject') => {
    selected.value = request;
    decision.value = action;
    note.value = '';
    formError.value = null;
};

const submitReview = async () => {
    if (!selected.value) return;
    if (decision.value === 'reject' && note.value.trim().length < 5) {
        formError.value = 'Give a reason of at least 5 characters; the agent receives it by SMS.';
        return;
    }
    submitting.value = true;
    formError.value = null;
    try {
        const id = selected.value.id;
        const res =
            decision.value === 'approve'
                ? await api.post(`/transactions/admin/float-requests/${id}/approve`, { note: note.value.trim() || null })
                : await api.post(`/transactions/admin/float-requests/${id}/reject`, { reason: note.value.trim() });
        if (res.status === 202) {
            toast.info(`Float request #${id} forwarded for a second approval.`);
        } else {
            toast.success(decision.value === 'approve' ? `Float issued for request #${id}.` : `Float request #${id} rejected.`);
        }
        selected.value = null;
        await fetchRequests(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to review this float request.');
        formError.value = fieldErrors.reason ?? fieldErrors.note ?? message;
    } finally {
        submitting.value = false;
    }
};

onMounted(() => fetchRequests());
</script>

<template>
    <Head title="Float Requests - WalletMS Admin" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">Agent Float Requests</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Check each slip and reference against the bank statement before approving. Approval credits the agent wallet with a
                        FLOAT_ISSUE transaction; a bank reference can only ever be credited once.
                    </p>
                </div>
                <select
                    v-model="status"
                    aria-label="Status"
                    class="rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                >
                    <option value="PENDING">Pending review</option>
                    <option value="APPROVED">Approved</option>
                    <option value="REJECTED">Rejected</option>
                    <option value="CANCELLED">Cancelled</option>
                </select>
            </div>

            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div v-if="loading && requests.length === 0" class="p-6"><Skeleton :rows="5" height="h-6" /></div>
                <div v-else-if="requests.length === 0" class="p-12 text-center text-sm text-zinc-500">No float requests in this list.</div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-6 py-3.5">ID</th>
                                <th class="px-6 py-3.5">Agent</th>
                                <th class="px-6 py-3.5">Amount</th>
                                <th class="px-6 py-3.5">Bank reference</th>
                                <th class="px-6 py-3.5">Deposited</th>
                                <th class="px-6 py-3.5">{{ status === 'PENDING' ? 'Note' : 'Review' }}</th>
                                <th class="px-6 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="request in requests" :key="request.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
                                <td class="px-6 py-4 font-mono text-xs font-semibold">#{{ request.id }}</td>
                                <td class="px-6 py-4">
                                    <Link
                                        v-if="request.agent"
                                        :href="`/admin/users/${request.agent.id}`"
                                        class="font-medium text-zinc-900 hover:text-indigo-600 dark:text-zinc-100 dark:hover:text-indigo-400"
                                    >
                                        {{ request.agent.name }}
                                    </Link>
                                    <div class="text-xs text-zinc-500">{{ request.agent?.phone_number ?? '' }}</div>
                                </td>
                                <td class="px-6 py-4 font-semibold">৳{{ Number(request.amount).toFixed(2) }}</td>
                                <td class="px-6 py-4 font-mono text-xs">{{ request.bank_reference }}</td>
                                <td class="px-6 py-4 text-xs text-zinc-500">{{ request.deposited_at }}</td>
                                <td class="px-6 py-4 text-xs text-zinc-500">
                                    <template v-if="status === 'PENDING'">
                                        <Badge v-if="request.awaiting_second_approval" variant="info">AWAITING 2ND APPROVAL</Badge>
                                        <div>{{ request.note ?? '—' }}</div>
                                    </template>
                                    <template v-else>
                                        <div>{{ request.reviewed_by?.name ?? '—' }}</div>
                                        <div v-if="request.review_note">“{{ request.review_note }}”</div>
                                        <div v-if="request.transaction_id">TX #{{ request.transaction_id }}</div>
                                    </template>
                                </td>
                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    <button
                                        type="button"
                                        class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                        @click="viewSlip(request)"
                                    >
                                        Slip
                                    </button>
                                    <template v-if="request.status === 'PENDING' && !request.awaiting_second_approval">
                                        <span v-if="isOwn(request)" class="ml-2 text-xs text-zinc-400">Your own request</span>
                                        <template v-else>
                                            <button
                                                type="button"
                                                class="ml-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-400"
                                                @click="openReview(request, 'approve')"
                                            >
                                                Approve
                                            </button>
                                            <button
                                                type="button"
                                                class="ml-2 rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-400"
                                                @click="openReview(request, 'reject')"
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
                <Pagination :pagination="pagination" :loading="loading" @change-page="fetchRequests" />
            </div>
        </div>

        <Modal
            :show="selected !== null"
            :title="selected ? `${decision === 'approve' ? 'Approve' : 'Reject'} float request #${selected.id}` : ''"
            @close="selected = null"
        >
            <div v-if="selected" class="space-y-4 text-sm">
                <dl class="space-y-1 rounded-lg bg-zinc-50 p-3 text-xs dark:bg-zinc-800/60">
                    <div class="flex justify-between"><dt class="text-zinc-500">Agent</dt><dd>{{ selected.agent?.name }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-500">Amount</dt><dd class="font-semibold">৳{{ Number(selected.amount).toFixed(2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-500">Bank reference</dt><dd class="font-mono">{{ selected.bank_reference }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-500">Deposited</dt><dd>{{ selected.deposited_at }}</dd></div>
                </dl>
                <p v-if="decision === 'approve'" class="text-xs text-zinc-500">
                    Only approve after finding this deposit on the bank statement. If maker-checker is on, a second staff member must also approve.
                </p>
                <label class="block space-y-1">
                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                        {{ decision === 'approve' ? 'Note (optional)' : 'Reason (sent to the agent by SMS)' }}
                    </span>
                    <textarea
                        v-model="note"
                        rows="3"
                        maxlength="500"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </label>
                <Alert v-if="formError" type="error" :message="formError" />
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        @click="selected = null"
                    >
                        Close
                    </button>
                    <button
                        type="button"
                        :disabled="submitting"
                        class="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        :class="decision === 'approve' ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-rose-600 hover:bg-rose-500'"
                        @click="submitReview"
                    >
                        {{ submitting ? 'Saving...' : decision === 'approve' ? 'Approve' : 'Reject' }}
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
