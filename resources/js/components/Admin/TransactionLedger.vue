<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import Badge from '@/components/Badge.vue';
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

const TYPES = ['TOP_UP', 'CASH_IN', 'CASH_OUT', 'TRANSFER', 'AGENT_WITHDRAWAL', 'COMMISSION_PAYOUT', 'FLOAT_ISSUE', 'SIGNUP_BONUS'] as const;
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
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="tx in transactions" :key="tx.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
                        <td class="px-6 py-4 font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100">#{{ tx.id }}</td>
                        <td class="px-6 py-4">
                            <Badge variant="neutral">{{ label(tx.type) }}</Badge>
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
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :pagination="pagination" :loading="loading" @change-page="fetchTransactions" />
    </div>
</template>
