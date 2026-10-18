<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { newIdempotencyKey } from '@/composables/useIdempotencyKey';
import { money, phone } from '@/composables/validators';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import { useWalletStore } from '@/stores/wallet';
import UserLayout from '@/layouts/UserLayout.vue';
import Alert from '@/components/Alert.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Pagination from '@/components/Pagination.vue';
import Skeleton from '@/components/Skeleton.vue';
import TextField from '@/components/Forms/TextField.vue';
import type { Pagination as PaginationType } from '@/types/models';

useAuthGuard('USER');

interface PaymentRequest {
    id: number;
    direction: 'incoming' | 'outgoing';
    requester: { id: number; name: string; phone_number: string | null } | null;
    payer: { id: number; name: string; phone_number: string | null } | null;
    amount: number;
    note: string | null;
    status: 'PENDING' | 'PAID' | 'DECLINED' | 'CANCELLED' | 'EXPIRED';
    transaction_id: number | null;
    expires_at: string;
    created_at: string;
}

const toast = useToastStore();
const walletStore = useWalletStore();

const tab = ref<'incoming' | 'outgoing'>('incoming');
const requests = ref<PaymentRequest[]>([]);
const pagination = ref<PaginationType | null>(null);
const loading = ref(true);
const sending = ref(false);

const { handleSubmit, resetForm, setErrors } = useForm<{ payerPhone: string; amount: string; note: string }>({
    validationSchema: { payerPhone: phone, amount: money('Amount') },
    initialValues: { payerPhone: '', amount: '', note: '' },
});

const fetchRequests = async (page = 1) => {
    loading.value = true;
    try {
        const res = await api.get('/transactions/requests', { params: { direction: tab.value, page, per_page: 10 } });
        requests.value = res.data.data?.items ?? [];
        pagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load requests.').message);
    } finally {
        loading.value = false;
    }
};

watch(tab, () => fetchRequests(1));

const sendRequest = handleSubmit(async (values) => {
    sending.value = true;
    try {
        await api.post('/transactions/requests', {
            payer_phone: values.payerPhone.trim(),
            amount: Number(values.amount).toFixed(2),
            note: values.note.trim() || null,
        });
        toast.success('Request sent.');
        resetForm();
        tab.value === 'outgoing' ? await fetchRequests() : (tab.value = 'outgoing');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to send the request.');
        setErrors({ payerPhone: fieldErrors.payer_phone, amount: fieldErrors.amount, note: fieldErrors.note });
        toast.error(message);
    } finally {
        sending.value = false;
    }
});

// Paying is a transfer, so it needs the PIN
const paying = ref<PaymentRequest | null>(null);
const pin = ref('');
const payError = ref<string | null>(null);
const payKey = ref('');
const submittingPay = ref(false);

const openPay = (request: PaymentRequest) => {
    paying.value = request;
    pin.value = '';
    payError.value = null;
    payKey.value = newIdempotencyKey();
};

const pay = async () => {
    if (!paying.value) return;
    if (!/^\d{5,10}$/.test(pin.value)) {
        payError.value = 'Enter your 5-10 digit PIN.';
        return;
    }
    submittingPay.value = true;
    payError.value = null;
    try {
        await api.post(`/transactions/requests/${paying.value.id}/pay`, { pin: pin.value, idempotency_key: payKey.value });
        toast.success(`Paid ৳${Number(paying.value.amount).toFixed(2)}.`);
        paying.value = null;
        await Promise.all([fetchRequests(pagination.value?.current_page ?? 1), walletStore.fetchWallet()]);
    } catch (err: unknown) {
        payError.value = parseApiError(err, 'Payment failed.').message;
    } finally {
        submittingPay.value = false;
    }
};

const respond = async (request: PaymentRequest, action: 'decline' | 'cancel') => {
    try {
        await api.post(`/transactions/requests/${request.id}/${action}`);
        toast.success(action === 'decline' ? 'Request declined.' : 'Request cancelled.');
        await fetchRequests(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Something went wrong.').message);
    }
};

const statusVariant = (status: PaymentRequest['status']): 'success' | 'danger' | 'warning' | 'neutral' =>
    status === 'PAID' ? 'success' : status === 'DECLINED' ? 'danger' : status === 'PENDING' ? 'warning' : 'neutral';

onMounted(() => fetchRequests());
</script>

<template>
    <Head title="Request Money - WalletMS" />
    <UserLayout>
        <div class="mx-auto max-w-3xl space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">Request Money</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Ask someone to send you money. They get an SMS and can pay with their PIN, or decline. Requests expire after 7 days.
                </p>
            </div>

            <form
                class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900"
                novalidate
                @submit.prevent="sendRequest"
            >
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <TextField name="payerPhone" label="Their phone number" inputmode="tel" placeholder="01XXXXXXXXX" />
                    <TextField name="amount" label="Amount (BDT)" type="number" step="0.01" min="0" inputmode="decimal" placeholder="500.00" />
                </div>
                <TextField name="note" label="What is it for? (optional)" placeholder="Dinner, rent share…" />
                <div class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="sending"
                        class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {{ sending ? 'Sending...' : 'Send Request' }}
                    </button>
                </div>
            </form>

            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex gap-2 border-b border-zinc-100 px-4 dark:border-zinc-800">
                    <button
                        v-for="item in [
                            { key: 'incoming', label: 'Asked of me' },
                            { key: 'outgoing', label: 'Sent by me' },
                        ] as const"
                        :key="item.key"
                        type="button"
                        class="-mb-px border-b-2 px-3 py-3 text-sm font-medium"
                        :class="tab === item.key ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-zinc-500'"
                        @click="tab = item.key"
                    >
                        {{ item.label }}
                    </button>
                </div>
                <div v-if="loading && requests.length === 0" class="p-6"><Skeleton :rows="3" height="h-6" /></div>
                <div v-else-if="requests.length === 0" class="p-10 text-center text-sm text-zinc-500">No requests here yet.</div>
                <ul v-else class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <li v-for="request in requests" :key="request.id" class="flex flex-col gap-2 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-zinc-900 dark:text-zinc-100">৳{{ Number(request.amount).toFixed(2) }}</span>
                                <Badge :variant="statusVariant(request.status)">{{ request.status }}</Badge>
                            </div>
                            <p class="text-xs text-zinc-500">
                                {{ request.direction === 'incoming' ? 'From' : 'To' }}
                                {{ request.direction === 'incoming' ? request.requester?.name : request.payer?.name }}
                                ({{ request.direction === 'incoming' ? request.requester?.phone_number : request.payer?.phone_number }})
                                <template v-if="request.note"> · {{ request.note }}</template>
                            </p>
                            <p v-if="request.status === 'PENDING'" class="text-[11px] text-zinc-400">
                                Expires {{ new Date(request.expires_at).toLocaleDateString() }}
                            </p>
                        </div>
                        <div v-if="request.status === 'PENDING'" class="flex shrink-0 gap-2">
                            <template v-if="request.direction === 'incoming'">
                                <button
                                    type="button"
                                    class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500"
                                    @click="openPay(request)"
                                >
                                    Pay
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300"
                                    @click="respond(request, 'decline')"
                                >
                                    Decline
                                </button>
                            </template>
                            <button
                                v-else
                                type="button"
                                class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300"
                                @click="respond(request, 'cancel')"
                            >
                                Cancel
                            </button>
                        </div>
                    </li>
                </ul>
                <Pagination :pagination="pagination" :loading="loading" @change-page="fetchRequests" />
            </div>
        </div>

        <Modal :show="paying !== null" title="Pay Request" @close="paying = null">
            <div v-if="paying" class="space-y-4 text-sm">
                <p>
                    Send <strong>৳{{ Number(paying.amount).toFixed(2) }}</strong> to {{ paying.requester?.name }} ({{ paying.requester?.phone_number }})?
                    It counts toward your daily and monthly limits like any transfer.
                </p>
                <label class="block space-y-1">
                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Your PIN</span>
                    <input
                        v-model="pin"
                        type="password"
                        inputmode="numeric"
                        maxlength="10"
                        autocomplete="current-password"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </label>
                <Alert v-if="payError" type="error" :message="payError" />
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 dark:border-zinc-700 dark:text-zinc-200"
                        @click="paying = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="submittingPay"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                        @click="pay"
                    >
                        {{ submittingPay ? 'Paying...' : 'Pay now' }}
                    </button>
                </div>
            </div>
        </Modal>
    </UserLayout>
</template>
