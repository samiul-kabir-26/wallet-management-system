<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Skeleton from '@/components/Skeleton.vue';
import Pagination from '@/components/Pagination.vue';
import Alert from '@/components/Alert.vue';
import type { Pagination as PaginationType, Transaction, TransactionParty } from '@/types/models';

/**
 * Filterable admin view of the transaction ledger. partyId pins it to one user (profile page).
 */
const props = defineProps<{
    partyId?: number;
    perPage?: number;
}>();

const TYPES = ['TOP_UP', 'CASH_IN', 'CASH_OUT', 'TRANSFER', 'AGENT_WITHDRAWAL', 'COMMISSION_PAYOUT', 'FLOAT_ISSUE', 'SIGNUP_BONUS', 'REVERSAL'] as const;
const STATUSES = ['COMPLETED', 'PENDING', 'FAILED', 'REJECTED'] as const;

const filters = reactive({ search: '', type: '', status: '', from: '', to: '' });
const transactions = ref<Transaction[]>([]);
const pagination = ref<PaginationType | null>(null);
const loading = ref(true);
const errorMessage = ref<string | null>(null);

const fetchTransactions = async (page = 1) => {
    loading.value = true;
    errorMessage.value = null;
    try {
        const params: Record<string, string | number> = { page, per_page: props.perPage ?? 15 };
        if (props.partyId) params.party_id = props.partyId;
        if (filters.search.trim()) params.search = filters.search.trim();
        if (filters.type) params.type = filters.type;
        if (filters.status) params.status = filters.status;
        if (filters.from) params.from_date = filters.from;
        if (filters.to) params.to_date = filters.to;

        const res = await api.get('/transactions/admin/all', { params });
        transactions.value = res.data.data?.items ?? [];
        pagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        errorMessage.value = parseApiError(err, 'Failed to fetch transactions.').message;
    } finally {
        loading.value = false;
    }
};

let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(
    () => filters.search,
    () => {
        if (searchTimer) clearTimeout(searchTimer);
        searchTimer = setTimeout(() => fetchTransactions(1), 350);
    },
);
watch(() => [filters.type, filters.status, filters.from, filters.to], () => fetchTransactions(1));

const resetFilters = () => {
    Object.assign(filters, { search: '', type: '', status: '', from: '', to: '' });
};

const partyLabel = (party: TransactionParty | null | undefined): string =>
    party ? `${party.name}${party.phone_number ? ` (${party.phone_number})` : ''}` : '—';

const label = (value: string): string => value.replaceAll('_', ' ');

// Reversals: staff holding transactions.reverse can undo a completed transfer, cash-in or cash-out once
const authStore = useAuthStore();
const toast = useToastStore();
const canReverse = computed(() => authStore.can('transactions.reverse'));
const REVERSIBLE = ['TRANSFER', 'CASH_IN', 'CASH_OUT', 'SIGNUP_BONUS'];

const isParty = (tx: Transaction): boolean => {
    const me = authStore.user?.id;
    return me !== undefined && [tx.user_id, tx.sender_id, tx.recipient_id, tx.agent_id].includes(me);
};

const isReversible = (tx: Transaction): boolean =>
    REVERSIBLE.includes(tx.type) &&
    tx.status === 'COMPLETED' &&
    !tx.reversed_by_transaction_id &&
    !isParty(tx) &&
    // A backfilled opening balance was always the customer's money
    !(tx.type === 'SIGNUP_BONUS' && tx.meta?.backfilled === true);

const reversing = ref<Transaction | null>(null);
const reversalReason = ref('');
const reversalError = ref<string | null>(null);
const submittingReversal = ref(false);

const openReversal = (tx: Transaction) => {
    reversing.value = tx;
    reversalReason.value = '';
    reversalError.value = null;
};

const closeReversal = () => {
    reversing.value = null;
};

const reversalSummary = computed(() => {
    const tx = reversing.value;
    if (!tx) return null;
    const amount = Number(tx.amount);
    const fee = Number(tx.system_fee_amount);
    const commission = Number(tx.agent_commission_amount);
    switch (tx.type) {
        case 'TRANSFER':
            return { from: tx.parties?.recipient, takeBack: amount, to: tx.parties?.sender, giveBack: amount + fee };
        case 'CASH_IN':
            return { from: tx.parties?.recipient, takeBack: amount, to: tx.parties?.agent, giveBack: amount };
        case 'SIGNUP_BONUS':
            // Clawback: the money goes back to the platform
            return { from: tx.parties?.recipient, takeBack: amount, to: null, giveBack: 0 };
        default:
            return { from: tx.parties?.agent, takeBack: amount + commission, to: tx.parties?.sender, giveBack: amount + fee };
    }
});

const submitReversal = async () => {
    if (!reversing.value) return;
    if (reversalReason.value.trim().length < 10) {
        reversalError.value = 'Give a reason of at least 10 characters; both parties and auditors will see it.';
        return;
    }
    submittingReversal.value = true;
    reversalError.value = null;
    try {
        const id = reversing.value.id;
        const res = await api.post(`/transactions/admin/${id}/reverse`, { reason: reversalReason.value.trim() });
        if (res.status === 202) {
            toast.info(`Reversal of #${id} submitted for approval.`);
        } else {
            toast.success(`Transaction #${id} reversed.`);
        }
        closeReversal();
        await fetchTransactions(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to reverse this transaction.');
        reversalError.value = fieldErrors.reason ?? message;
    } finally {
        submittingReversal.value = false;
    }
};

onMounted(() => fetchTransactions());

defineExpose({ refresh: () => fetchTransactions(pagination.value?.current_page ?? 1) });
</script>

<template>
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <!-- Filters -->
        <div class="grid grid-cols-1 gap-3 border-b border-zinc-100 p-4 sm:grid-cols-2 lg:grid-cols-6 dark:border-zinc-800">
            <input
                v-model="filters.search"
                type="search"
                :placeholder="partyId ? 'Search counterparty, phone or #TX ID' : 'Search name, phone, email or #TX ID'"
                class="lg:col-span-2 rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
            />
            <select
                v-model="filters.type"
                aria-label="Type"
                class="rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
            >
                <option value="">All types</option>
                <option v-for="type in TYPES" :key="type" :value="type">{{ label(type) }}</option>
            </select>
            <select
                v-model="filters.status"
                aria-label="Status"
                class="rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
            >
                <option value="">All statuses</option>
                <option v-for="status in STATUSES" :key="status" :value="status">{{ label(status) }}</option>
            </select>
            <input
                v-model="filters.from"
                type="date"
                aria-label="From date"
                class="rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
            />
            <div class="flex gap-2">
                <input
                    v-model="filters.to"
                    type="date"
                    aria-label="To date"
                    :min="filters.from || undefined"
                    class="min-w-0 flex-1 rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                />
                <button
                    type="button"
                    class="rounded-lg border border-zinc-300 px-3 text-xs font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="resetFilters"
                >
                    Reset
                </button>
            </div>
        </div>

        <div v-if="errorMessage" class="p-4">
            <Alert type="error" :message="errorMessage" dismissible @close="errorMessage = null" />
        </div>

        <div v-if="loading && transactions.length === 0" class="p-6"><Skeleton :rows="5" height="h-6" /></div>

        <div v-else-if="transactions.length === 0" class="p-12 text-center text-sm text-zinc-500">
            No transactions match these filters.
        </div>

        <div v-else class="overflow-x-auto" :class="{ 'opacity-60': loading }">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                    <tr>
                        <th class="px-6 py-3.5">TX ID</th>
                        <th class="px-6 py-3.5">Type</th>
                        <th class="px-6 py-3.5">From → To</th>
                        <th class="px-6 py-3.5">Amount</th>
                        <th class="px-6 py-3.5">Fee</th>
                        <th class="px-6 py-3.5">Agent Comm</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5">Timestamp</th>
                        <th v-if="canReverse" class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="tx in transactions" :key="tx.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
                        <td class="px-6 py-4 font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100">#{{ tx.id }}</td>
                        <td class="px-6 py-4">
                            <Badge :variant="tx.type === 'REVERSAL' ? 'warning' : 'neutral'">{{ label(tx.type) }}</Badge>
                            <div v-if="tx.reversed_transaction_id" class="mt-1 text-[11px] text-zinc-500">Reverses #{{ tx.reversed_transaction_id }}</div>
                            <div v-if="tx.reversed_by_transaction_id" class="mt-1 text-[11px] font-medium text-rose-600 dark:text-rose-400">
                                Reversed by #{{ tx.reversed_by_transaction_id }}
                            </div>
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            <div>{{ partyLabel(tx.parties?.sender ?? (tx.type === 'CASH_IN' ? tx.parties?.agent : null)) }}</div>
                            <div class="text-zinc-400">→ {{ partyLabel(tx.parties?.recipient ?? (tx.type === 'CASH_OUT' ? tx.parties?.agent : null)) }}</div>
                            <div v-if="tx.external_reference" class="mt-0.5 font-mono text-[10px] text-zinc-400">Ref: {{ tx.external_reference }}</div>
                        </td>
                        <td class="px-6 py-4 font-semibold text-zinc-900 dark:text-zinc-100">৳{{ Number(tx.amount).toFixed(2) }}</td>
                        <td class="px-6 py-4 text-xs text-zinc-500">৳{{ Number(tx.system_fee_amount).toFixed(2) }}</td>
                        <td class="px-6 py-4 text-xs text-zinc-500">৳{{ Number(tx.agent_commission_amount).toFixed(2) }}</td>
                        <td class="px-6 py-4">
                            <Badge :variant="tx.status === 'COMPLETED' ? 'success' : tx.status === 'FAILED' || tx.status === 'REJECTED' ? 'danger' : 'warning'">
                                {{ tx.status }}
                            </Badge>
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-500">{{ new Date(tx.created_at).toLocaleString() }}</td>
                        <td v-if="canReverse" class="px-6 py-4 text-right whitespace-nowrap">
                            <button
                                v-if="isReversible(tx)"
                                type="button"
                                class="rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-400"
                                @click="openReversal(tx)"
                            >
                                Reverse
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :pagination="pagination" :loading="loading" @change-page="fetchTransactions" />

        <Modal :show="reversing !== null" :title="reversing ? `Reverse ${label(reversing.type)} #${reversing.id}` : ''" @close="closeReversal">
            <div v-if="reversing && reversalSummary" class="space-y-4 text-sm">
                <dl class="space-y-2 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/60">
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500">Take back from</dt>
                        <dd class="text-right font-medium">{{ partyLabel(reversalSummary.from) }} · ৳{{ reversalSummary.takeBack.toFixed(2) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500">Return to</dt>
                        <dd class="text-right font-medium">
                            <template v-if="reversalSummary.to === null">Platform (bonus clawed back)</template>
                            <template v-else>{{ partyLabel(reversalSummary.to) }} · ৳{{ reversalSummary.giveBack.toFixed(2) }}</template>
                        </dd>
                    </div>
                </dl>
                <p class="text-xs text-zinc-500">
                    A new REVERSAL transaction is written and the original stays as it is. This can only be done once, and fails if the
                    party giving the money back has already spent it. Both parties get a receipt.
                </p>
                <label class="block space-y-1">
                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Reason (shown to both parties and auditors)</span>
                    <textarea
                        v-model="reversalReason"
                        rows="3"
                        maxlength="500"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        placeholder="e.g. Sent to the wrong number, confirmed with both customers by phone"
                    />
                </label>
                <Alert v-if="reversalError" type="error" :message="reversalError" />
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        @click="closeReversal"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="submittingReversal"
                        class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
                        @click="submitReversal"
                    >
                        {{ submittingReversal ? 'Reversing...' : 'Reverse transaction' }}
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>
