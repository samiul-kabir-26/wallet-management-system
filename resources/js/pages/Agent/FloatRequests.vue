<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { money, required } from '@/composables/validators';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import AgentLayout from '@/layouts/AgentLayout.vue';
import Badge from '@/components/Badge.vue';
import Pagination from '@/components/Pagination.vue';
import Skeleton from '@/components/Skeleton.vue';
import TextField from '@/components/Forms/TextField.vue';
import type { FloatRequest, Pagination as PaginationType } from '@/types/models';

useAuthGuard('AGENT');

const MAX_SLIP_BYTES = 5 * 1024 * 1024;
const SLIP_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
const today = new Date().toISOString().slice(0, 10);

const toast = useToastStore();
const requests = ref<FloatRequest[]>([]);
const pagination = ref<PaginationType | null>(null);
const loading = ref(true);
const submitting = ref(false);
const slip = ref<File | null>(null);
const slipError = ref<string | null>(null);
const slipInput = ref<HTMLInputElement | null>(null);

const { handleSubmit, resetForm, setErrors } = useForm<{ amount: string; bankReference: string; depositedAt: string; note: string }>({
    validationSchema: {
        amount: money('Amount'),
        bankReference: required('Bank reference'),
        depositedAt: required('Deposit date'),
    },
    initialValues: { amount: '', bankReference: '', depositedAt: today, note: '' },
});

const fetchRequests = async (page = 1) => {
    loading.value = true;
    try {
        const res = await api.get('/transactions/agent/float-requests', { params: { page, per_page: 10 } });
        requests.value = res.data.data?.items ?? [];
        pagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load your float requests.').message);
    } finally {
        loading.value = false;
    }
};

const chooseSlip = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    slipError.value = null;
    if (file && !SLIP_TYPES.includes(file.type)) {
        slipError.value = 'Upload the deposit slip as a JPG, PNG, WebP or PDF file.';
        slip.value = null;
        return;
    }
    if (file && file.size > MAX_SLIP_BYTES) {
        slipError.value = 'The deposit slip must not be larger than 5 MB.';
        slip.value = null;
        return;
    }
    slip.value = file;
};

const submit = handleSubmit(async (values) => {
    if (!slip.value) {
        slipError.value = 'Attach a photo or PDF of the deposit slip.';
        return;
    }

    const form = new FormData();
    form.append('amount', Number(values.amount).toFixed(2));
    form.append('bank_reference', values.bankReference.trim());
    form.append('deposited_at', values.depositedAt);
    form.append('slip', slip.value);
    if (values.note.trim()) form.append('note', values.note.trim());

    submitting.value = true;
    try {
        await api.post('/transactions/agent/float-requests', form, { headers: { 'Content-Type': 'multipart/form-data' } });
        toast.success('Float request submitted. You will get an SMS once staff review it.');
        resetForm({ values: { amount: '', bankReference: '', depositedAt: today, note: '' } });
        slip.value = null;
        if (slipInput.value) slipInput.value.value = '';
        await fetchRequests();
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to submit the float request.');
        setErrors({
            amount: fieldErrors.amount,
            bankReference: fieldErrors.bank_reference,
            depositedAt: fieldErrors.deposited_at,
            note: fieldErrors.note,
        });
        slipError.value = fieldErrors.slip ?? null;
        toast.error(message);
    } finally {
        submitting.value = false;
    }
});

const cancelRequest = async (request: FloatRequest) => {
    try {
        await api.post(`/transactions/agent/float-requests/${request.id}/cancel`);
        toast.success(`Float request #${request.id} cancelled.`);
        await fetchRequests(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to cancel this request.').message);
    }
};

// The slip needs the bearer token, so fetch it and open it as a local object URL
const viewSlip = async (request: FloatRequest) => {
    try {
        const res = await api.get(`/transactions/agent/float-requests/${request.id}/slip`, { responseType: 'blob' });
        window.open(URL.createObjectURL(res.data as Blob), '_blank', 'noopener');
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Could not open the deposit slip.').message);
    }
};

const statusVariant = (request: FloatRequest): 'success' | 'danger' | 'warning' | 'neutral' =>
    request.status === 'APPROVED' ? 'success' : request.status === 'REJECTED' ? 'danger' : request.status === 'PENDING' ? 'warning' : 'neutral';

onMounted(() => fetchRequests());
</script>

<template>
    <Head title="Float Requests - WalletMS Agent" />
    <AgentLayout>
        <div class="mx-auto max-w-3xl space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">Request Float</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Deposited cash at the bank? Send the reference and a photo of the slip. Staff check it against the bank statement and add the
                    same amount to your agent wallet.
                </p>
            </div>

            <form
                class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900"
                novalidate
                @submit.prevent="submit"
            >
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <TextField name="amount" label="Amount deposited (BDT)" type="number" step="0.01" min="0" inputmode="decimal" placeholder="5000.00" />
                    <TextField name="depositedAt" label="Deposit date" type="date" />
                </div>
                <TextField name="bankReference" label="Bank reference / transaction ID" placeholder="e.g. DBBL-20261018-0042" />
                <TextField name="note" label="Note (optional)" placeholder="Branch, depositor name…" />
                <div class="space-y-1">
                    <label for="slip" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Deposit slip</label>
                    <input
                        id="slip"
                        ref="slipInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-amber-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-amber-700 hover:file:bg-amber-100 dark:text-zinc-300 dark:file:bg-amber-950/40 dark:file:text-amber-300"
                        @change="chooseSlip"
                    />
                    <p class="text-[11px] text-zinc-400">JPG, PNG, WebP or PDF, up to 5 MB.</p>
                    <p v-if="slipError" class="text-xs text-rose-600 dark:text-rose-400">{{ slipError }}</p>
                </div>
                <div class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="rounded-lg bg-amber-600 px-5 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-amber-500 disabled:opacity-50"
                    >
                        {{ submitting ? 'Submitting...' : 'Submit Request' }}
                    </button>
                </div>
            </form>

            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="border-b border-zinc-100 px-6 py-4 text-sm font-semibold text-zinc-900 dark:border-zinc-800 dark:text-zinc-100">
                    My requests
                </div>
                <div v-if="loading && requests.length === 0" class="p-6"><Skeleton :rows="3" height="h-6" /></div>
                <div v-else-if="requests.length === 0" class="p-10 text-center text-sm text-zinc-500">No float requests yet.</div>
                <ul v-else class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <li v-for="request in requests" :key="request.id" class="flex flex-col gap-2 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs font-semibold text-zinc-500">#{{ request.id }}</span>
                                <span class="font-semibold text-zinc-900 dark:text-zinc-100">৳{{ Number(request.amount).toFixed(2) }}</span>
                                <Badge :variant="statusVariant(request)">
                                    {{ request.awaiting_second_approval ? 'AWAITING 2ND APPROVAL' : request.status }}
                                </Badge>
                            </div>
                            <p class="text-xs text-zinc-500">Ref {{ request.bank_reference }} · deposited {{ request.deposited_at }}</p>
                            <p v-if="request.status === 'REJECTED' && request.review_note" class="text-xs text-rose-600 dark:text-rose-400">
                                Rejected: {{ request.review_note }}
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                @click="viewSlip(request)"
                            >
                                View slip
                            </button>
                            <button
                                v-if="request.status === 'PENDING' && !request.awaiting_second_approval"
                                type="button"
                                class="rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-400"
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
    </AgentLayout>
</template>
