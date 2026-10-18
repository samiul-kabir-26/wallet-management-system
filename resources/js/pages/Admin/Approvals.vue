<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Alert from '@/components/Alert.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Pagination from '@/components/Pagination.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { Pagination as PaginationType } from '@/types/models';

useAuthGuard('ADMIN');

type ApprovalStatus = 'PENDING' | 'APPROVED' | 'REJECTED' | 'CANCELLED';

interface ApprovalRequest {
    id: number;
    action: 'FLOAT_ISSUE' | 'SETTINGS_UPDATE' | 'TRANSACTION_REVERSAL' | 'COMMISSION_RATE_UPDATE';
    status: ApprovalStatus;
    summary: string;
    payload: Record<string, unknown>;
    requested_by: { id: number; name: string } | null;
    reviewed_by: { id: number; name: string } | null;
    reviewed_at: string | null;
    review_note: string | null;
    result: Record<string, unknown> | null;
    can_review: boolean;
    can_cancel: boolean;
    created_at: string;
}

type Tab = 'review' | 'mine' | 'history';

const toast = useToastStore();
const tab = ref<Tab>('review');
const requests = ref<ApprovalRequest[]>([]);
const pagination = ref<PaginationType | null>(null);
const loading = ref(true);
const required = ref(true);

const ACTION_LABELS: Record<ApprovalRequest['action'], string> = {
    FLOAT_ISSUE: 'Float issue',
    SETTINGS_UPDATE: 'Settings change',
    TRANSACTION_REVERSAL: 'Reversal',
    COMMISSION_RATE_UPDATE: 'Commission rate',
};

const STATUS_VARIANTS: Record<ApprovalStatus, 'warning' | 'success' | 'danger' | 'neutral'> = {
    PENDING: 'warning',
    APPROVED: 'success',
    REJECTED: 'danger',
    CANCELLED: 'neutral',
};

const fetchRequests = async (page = 1) => {
    loading.value = true;
    try {
        const params: Record<string, string | number> = { page, per_page: 15 };
        if (tab.value === 'review') params.reviewable = 1;
        if (tab.value === 'mine') params.mine = 1;
        const res = await api.get('/approvals', { params });
        requests.value = (res.data.data?.items ?? []) as ApprovalRequest[];
        pagination.value = res.data.data?.pagination ?? null;
        required.value = res.data.meta?.required ?? true;
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load approval requests.').message);
    } finally {
        loading.value = false;
    }
};

watch(tab, () => fetchRequests(1));

/**
 * Human-readable lines describing what the request will change.
 */
const details = (request: ApprovalRequest): string[] => {
    const p = request.payload as Record<string, any>;
    switch (request.action) {
        case 'FLOAT_ISSUE':
            return [`Amount: ৳${Number(p.amount).toFixed(2)}`, `Bank reference: ${p.external_reference}`, ...(p.description ? [`Note: ${p.description}`] : [])];
        case 'SETTINGS_UPDATE':
            return Object.entries((p.changes ?? {}) as Record<string, { before: unknown; after: unknown }>).map(
                ([key, change]) => `${key.replaceAll('_', ' ')}: ${change.before ?? 'not set'} → ${change.after}`,
            );
        case 'TRANSACTION_REVERSAL':
            return [`Transaction #${p.transaction_id}`, `Reason: ${p.reason}`];
        case 'COMMISSION_RATE_UPDATE':
            return [`Rate: ${p.previous_rate ?? '—'} → ${p.commission_rate}`];
        default:
            return [];
    }
};

const reviewing = ref<ApprovalRequest | null>(null);
const decision = ref<'approve' | 'reject'>('approve');
const note = ref('');
const formError = ref<string | null>(null);
const submitting = ref(false);

const openReview = (request: ApprovalRequest, action: 'approve' | 'reject') => {
    reviewing.value = request;
    decision.value = action;
    note.value = '';
    formError.value = null;
};

const submitReview = async () => {
    if (!reviewing.value) return;
    if (decision.value === 'reject' && note.value.trim().length < 5) {
        formError.value = 'Give a reason of at least 5 characters for the requester.';
        return;
    }
    submitting.value = true;
    formError.value = null;
    try {
        const id = reviewing.value.id;
        await api.post(`/approvals/${id}/${decision.value}`, { note: note.value.trim() || null });
        toast.success(decision.value === 'approve' ? `Request #${id} approved and applied.` : `Request #${id} rejected.`);
        reviewing.value = null;
        await fetchRequests(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to review this request.');
        formError.value = fieldErrors.note ?? message;
    } finally {
        submitting.value = false;
    }
};

const cancelRequest = async (request: ApprovalRequest) => {
    try {
        await api.post(`/approvals/${request.id}/cancel`);
        toast.success(`Request #${request.id} cancelled.`);
        await fetchRequests(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to cancel this request.').message);
    }
};

onMounted(() => fetchRequests());
</script>

<template>
    <Head title="Approvals - WalletMS Admin" />
    <AdminLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">Approvals</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Float issues, settings changes, reversals and commission-rate changes take effect only after a second staff member who holds
                    the same permission approves them. You cannot approve your own requests.
                </p>
            </div>

            <Alert
                v-if="!required"
                type="warning"
                message="Maker-checker is turned off on this environment (MAKER_CHECKER_ENABLED=false), so these actions apply immediately."
            />

            <div class="flex gap-2 border-b border-zinc-200 dark:border-zinc-800">
                <button
                    v-for="item in [
                        { key: 'review', label: 'Awaiting my review' },
                        { key: 'mine', label: 'My requests' },
                        { key: 'history', label: 'All' },
                    ] as const"
                    :key="item.key"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm font-medium transition"
                    :class="
                        tab === item.key
                            ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400'
                            : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'
                    "
                    @click="tab = item.key"
                >
                    {{ item.label }}
                </button>
            </div>

            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div v-if="loading && requests.length === 0" class="p-6"><Skeleton :rows="4" height="h-6" /></div>

                <div v-else-if="requests.length === 0" class="p-12 text-center text-sm text-zinc-500">
                    {{ tab === 'review' ? 'Nothing is waiting for your approval.' : 'No requests in this list.' }}
                </div>

                <ul v-else class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <li v-for="request in requests" :key="request.id" class="flex flex-col gap-3 p-5 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs font-semibold text-zinc-500">#{{ request.id }}</span>
                                <Badge variant="info">{{ ACTION_LABELS[request.action] }}</Badge>
                                <Badge :variant="STATUS_VARIANTS[request.status]">{{ request.status }}</Badge>
                            </div>
                            <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ request.summary }}</p>
                            <ul class="space-y-0.5 text-xs text-zinc-500">
                                <li v-for="line in details(request)" :key="line">{{ line }}</li>
                            </ul>
                            <p class="text-xs text-zinc-400">
                                Requested by {{ request.requested_by?.name ?? '—' }} · {{ new Date(request.created_at).toLocaleString() }}
                            </p>
                            <p v-if="request.reviewed_by" class="text-xs text-zinc-400">
                                {{ request.status === 'APPROVED' ? 'Approved' : 'Rejected' }} by {{ request.reviewed_by.name }}
                                <template v-if="request.review_note">: “{{ request.review_note }}”</template>
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <template v-if="request.can_review">
                                <button
                                    type="button"
                                    class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-400"
                                    @click="openReview(request, 'approve')"
                                >
                                    Approve
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-400"
                                    @click="openReview(request, 'reject')"
                                >
                                    Reject
                                </button>
                            </template>
                            <button
                                v-if="request.can_cancel"
                                type="button"
                                class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                @click="cancelRequest(request)"
                            >
                                Cancel
                            </button>
                        </div>
                    </li>
                </ul>

                <Pagination :pagination="pagination" :loading="loading" @change-page="fetchRequests" />
            </div>
        </div>

        <Modal
            :show="reviewing !== null"
            :title="reviewing ? `${decision === 'approve' ? 'Approve' : 'Reject'} request #${reviewing.id}` : ''"
            @close="reviewing = null"
        >
            <div v-if="reviewing" class="space-y-4 text-sm">
                <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ reviewing.summary }}</p>
                <ul class="space-y-0.5 rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-300">
                    <li v-for="line in details(reviewing)" :key="line">{{ line }}</li>
                </ul>
                <p v-if="decision === 'approve'" class="text-xs text-zinc-500">Approving applies the change immediately and cannot be undone here.</p>
                <label class="block space-y-1">
                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                        {{ decision === 'approve' ? 'Note (optional)' : 'Reason (shown to the requester)' }}
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
                        @click="reviewing = null"
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
                        {{ submitting ? 'Saving...' : decision === 'approve' ? 'Approve and apply' : 'Reject' }}
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
