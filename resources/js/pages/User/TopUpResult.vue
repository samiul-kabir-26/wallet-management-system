<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { api } from '@/services/api';
import { useWalletStore } from '@/stores/wallet';
import UserLayout from '@/layouts/UserLayout.vue';
import Alert from '@/components/Alert.vue';
import Badge from '@/components/Badge.vue';
import Skeleton from '@/components/Skeleton.vue';

const props = defineProps<{
    transactionId: number;
    outcome: 'success' | 'fail' | 'cancel' | null;
}>();

useAuthGuard('USER');

type TopUp = {
    id: number;
    amount: number | string;
    currency: string;
    status: 'COMPLETED' | 'PENDING' | 'FAILED' | 'REJECTED';
    external_reference: string | null;
    recipient_wallet_balance_after?: number | string | null;
    created_at: string;
    meta: {
        payment?: { card_type?: string | null; card_no?: string | null; bank_tran_id?: string | null };
        on_hold?: { reason: string };
    } | null;
};

const walletStore = useWalletStore();
const topUp = ref<TopUp | null>(null);
const loading = ref(true);
const checking = ref(false);
const error = ref<string | null>(null);

const isOnHold = computed(() => topUp.value?.status === 'PENDING' && !!topUp.value.meta?.on_hold);
const amount = computed(() => Number(topUp.value?.amount ?? 0).toFixed(2));

const statusBadge = computed<{ label: string; variant: 'success' | 'danger' | 'warning' }>(() => {
    switch (topUp.value?.status) {
        case 'COMPLETED':
            return { label: 'COMPLETED', variant: 'success' };
        case 'FAILED':
        case 'REJECTED':
            return { label: topUp.value.status, variant: 'danger' };
        default:
            return { label: isOnHold.value ? 'UNDER REVIEW' : 'PENDING', variant: 'warning' };
    }
});

const heading = computed(() => {
    if (!topUp.value) return 'Top-up';
    if (topUp.value.status === 'COMPLETED') return 'Money added';
    if (topUp.value.status === 'FAILED') return props.outcome === 'cancel' ? 'Payment cancelled' : 'Payment not completed';
    if (isOnHold.value) return 'Payment under review';
    return 'Waiting for confirmation';
});

const explanation = computed(() => {
    if (!topUp.value) return '';
    if (topUp.value.status === 'COMPLETED') return `৳${amount.value} has been added to your wallet.`;
    if (topUp.value.status === 'FAILED') {
        return props.outcome === 'cancel'
            ? 'You cancelled the payment. Nothing was added to your wallet.'
            : 'The payment did not go through. Nothing was added to your wallet. You can start a new top-up.';
    }
    if (isOnHold.value) {
        return 'We received your payment, but it needs a manual check before it is added to your wallet. Contact support if this takes long.';
    }
    return 'SSLCommerz has not confirmed this payment yet. This usually takes a few seconds.';
});

const loadTopUp = async () => {
    try {
        const { data } = await api.get<{ data: { transaction: TopUp } }>(`/transactions/${props.transactionId}`);
        topUp.value = data.data.transaction;
    } catch (err: unknown) {
        error.value = parseApiError(err, 'Could not load this top-up.').message;
    }
};

const checkAgain = async () => {
    checking.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ data: { transaction: TopUp } }>(`/transactions/top-up/${props.transactionId}/refresh`);
        topUp.value = data.data.transaction;
        if (topUp.value.status === 'COMPLETED') {
            await walletStore.fetchWallet();
        }
    } catch (err: unknown) {
        error.value = parseApiError(err, 'Could not reach the payment gateway. Try again in a moment.').message;
    } finally {
        checking.value = false;
    }
};

onMounted(async () => {
    await loadTopUp();
    // The browser may arrive before SSLCommerz's confirmation is processed; ask once straight away
    if (topUp.value?.status === 'PENDING' && !isOnHold.value) {
        await checkAgain();
    }
    if (topUp.value?.status === 'COMPLETED') {
        await walletStore.fetchWallet();
    }
    loading.value = false;
});
</script>

<template>
    <Head title="Top-up Result - WalletMS" />
    <UserLayout>
        <div class="mx-auto max-w-lg space-y-6">
            <div v-if="error">
                <Alert type="error" :message="error" />
            </div>

            <div class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <Skeleton v-if="loading && !topUp" :rows="4" height="h-6" />

                <template v-else-if="topUp">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ heading }}</h1>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ explanation }}</p>
                        </div>
                        <Badge :variant="statusBadge.variant">{{ statusBadge.label }}</Badge>
                    </div>

                    <dl class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                        <div class="flex justify-between py-2">
                            <dt class="text-zinc-500">Amount</dt>
                            <dd class="font-semibold text-zinc-900 dark:text-zinc-100">৳{{ amount }} {{ topUp.currency }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 py-2">
                            <dt class="text-zinc-500">Reference</dt>
                            <dd class="truncate font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ topUp.external_reference }}</dd>
                        </div>
                        <div v-if="topUp.meta?.payment?.card_type" class="flex justify-between gap-4 py-2">
                            <dt class="text-zinc-500">Paid with</dt>
                            <dd class="text-right text-zinc-700 dark:text-zinc-300">
                                {{ topUp.meta.payment.card_type }}
                                <span v-if="topUp.meta.payment.card_no" class="block font-mono text-xs">{{ topUp.meta.payment.card_no }}</span>
                            </dd>
                        </div>
                        <div v-if="topUp.status === 'COMPLETED' && topUp.recipient_wallet_balance_after != null" class="flex justify-between py-2">
                            <dt class="text-zinc-500">New balance</dt>
                            <dd class="font-semibold text-emerald-600 dark:text-emerald-400">
                                ৳{{ Number(topUp.recipient_wallet_balance_after).toFixed(2) }}
                            </dd>
                        </div>
                    </dl>

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <button
                            v-if="topUp.status === 'PENDING' && !isOnHold"
                            type="button"
                            :disabled="checking"
                            class="flex-1 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50"
                            @click="checkAgain"
                        >
                            {{ checking ? 'Checking...' : 'Check again' }}
                        </button>
                        <Link
                            href="/user/wallet"
                            class="flex-1 rounded-lg border border-zinc-300 px-4 py-2 text-center text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            {{ topUp.status === 'FAILED' ? 'Try again' : 'Back to wallet' }}
                        </Link>
                        <Link
                            href="/user/history"
                            class="flex-1 rounded-lg border border-zinc-300 px-4 py-2 text-center text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            View history
                        </Link>
                    </div>
                </template>
            </div>
        </div>
    </UserLayout>
</template>
