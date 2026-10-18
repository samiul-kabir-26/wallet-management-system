<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import Alert from '@/components/Alert.vue';
import Skeleton from '@/components/Skeleton.vue';

/**
 * Business-day metrics for the admin dashboard (GET /reports/overview, transactions.view_any).
 */
interface Overview {
    range: { days: number; from: string; to: string; timezone: string };
    totals: {
        volume: number;
        transactions: number;
        fees_earned: number;
        commission_paid: number;
        signup_bonus_paid: number;
        signups: number;
    };
    daily: { date: string; volume: number; count: number; signups: number }[];
    queues: {
        pending_withdrawals: { count: number; amount: number };
        pending_float_requests: { count: number };
        unfinished_top_ups: { count: number };
        pending_approvals: { count: number };
    };
    e_money: { total: number; user_wallets: number; agent_wallets: number };
}

const days = ref(14);
const overview = ref<Overview | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const bdt = (n: number) => `৳${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const load = async () => {
    loading.value = true;
    error.value = null;
    try {
        overview.value = (await api.get('/reports/overview', { params: { days: days.value } })).data.data as Overview;
    } catch (err: unknown) {
        error.value = parseApiError(err, 'Failed to load metrics.').message;
    } finally {
        loading.value = false;
    }
};

watch(days, load);
onMounted(load);

const peak = computed(() => Math.max(1, ...(overview.value?.daily.map((d) => d.volume) ?? [1])));

const cards = computed(() => {
    const t = overview.value?.totals;
    if (!t) return [];
    return [
        { label: 'Volume', value: bdt(t.volume), hint: `${t.transactions} transactions` },
        { label: 'Fees earned', value: bdt(t.fees_earned), hint: 'net of reversals' },
        { label: 'Commission paid', value: bdt(t.commission_paid), hint: 'to agents' },
        { label: 'Signup bonus paid', value: bdt(t.signup_bonus_paid), hint: 'net of clawbacks' },
        { label: 'New customers & agents', value: String(t.signups), hint: 'registrations' },
        { label: 'E-money held', value: bdt(overview.value!.e_money.total), hint: `agents ${bdt(overview.value!.e_money.agent_wallets)}` },
    ];
});
</script>

<template>
    <div class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Activity</h2>
                <p v-if="overview" class="text-xs text-zinc-500">
                    {{ overview.range.from }} to {{ overview.range.to }} ({{ overview.range.timezone }} business days, completed transactions)
                </p>
            </div>
            <select
                v-model.number="days"
                aria-label="Range"
                class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
            >
                <option :value="7">Last 7 days</option>
                <option :value="14">Last 14 days</option>
                <option :value="30">Last 30 days</option>
                <option :value="90">Last 90 days</option>
            </select>
        </div>

        <Alert v-if="error" type="error" :message="error" />
        <Skeleton v-if="loading && !overview" :rows="4" height="h-8" />

        <template v-if="overview">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                <div v-for="card in cards" :key="card.label" class="rounded-xl border border-zinc-100 p-3 dark:border-zinc-800">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">{{ card.label }}</div>
                    <div class="mt-1 text-lg font-bold text-zinc-900 dark:text-zinc-100">{{ card.value }}</div>
                    <div class="text-[11px] text-zinc-400">{{ card.hint }}</div>
                </div>
            </div>

            <!-- Daily volume -->
            <div>
                <div class="flex h-32 items-end gap-1" role="img" :aria-label="`Daily volume over the last ${overview.range.days} days`">
                    <div
                        v-for="day in overview.daily"
                        :key="day.date"
                        class="group relative flex-1 rounded-t bg-indigo-500/80 hover:bg-indigo-600 dark:bg-indigo-400/70"
                        :style="{ height: `${Math.max(2, (day.volume / peak) * 100)}%` }"
                        :title="`${day.date}: ${bdt(day.volume)} · ${day.count} tx · ${day.signups} signups`"
                    />
                </div>
                <div class="mt-1 flex justify-between text-[10px] text-zinc-400">
                    <span>{{ overview.daily[0]?.date }}</span>
                    <span>{{ overview.daily[overview.daily.length - 1]?.date }}</span>
                </div>
            </div>

            <!-- Waiting work -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Link href="/admin/withdrawals" class="rounded-xl border border-zinc-200 p-3 text-xs hover:border-indigo-500 dark:border-zinc-800">
                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ overview.queues.pending_withdrawals.count }} withdrawals</div>
                    <div class="text-zinc-500">{{ bdt(overview.queues.pending_withdrawals.amount) }} held, awaiting review</div>
                </Link>
                <Link href="/admin/float-requests" class="rounded-xl border border-zinc-200 p-3 text-xs hover:border-indigo-500 dark:border-zinc-800">
                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ overview.queues.pending_float_requests.count }} float requests</div>
                    <div class="text-zinc-500">awaiting review</div>
                </Link>
                <Link href="/admin/top-ups" class="rounded-xl border border-zinc-200 p-3 text-xs hover:border-indigo-500 dark:border-zinc-800">
                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ overview.queues.unfinished_top_ups.count }} top-ups</div>
                    <div class="text-zinc-500">unfinished or on hold</div>
                </Link>
                <Link href="/admin/approvals" class="rounded-xl border border-zinc-200 p-3 text-xs hover:border-indigo-500 dark:border-zinc-800">
                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ overview.queues.pending_approvals.count }} approvals</div>
                    <div class="text-zinc-500">waiting for a second staff member</div>
                </Link>
            </div>
        </template>
    </div>
</template>
