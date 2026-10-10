<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { money, positiveInteger } from '@/composables/validators';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import UserLayout from '@/layouts/UserLayout.vue';
import TextField from '@/components/Forms/TextField.vue';
import ConfirmModal from '@/components/Modals/ConfirmModal.vue';

useAuthGuard('USER');

type Tab = 'transfer' | 'cash_out' | 'cash_in';

const toast = useToastStore();
const activeTab = ref<Tab>('transfer');
const confirmOpen = ref(false);
const submitting = ref(false);
const feeRate = ref<number | null>(null);

const tabConfig: Record<Tab, { label: string; endpoint: string; counterparty: 'recipientId' | 'agentId'; title: string; button: string; color: string }> = {
    transfer: {
        label: 'Send Money (P2P)',
        endpoint: '/transactions/transfer',
        counterparty: 'recipientId',
        title: 'Confirm Transfer',
        button: 'Send Money Now',
        color: 'bg-indigo-600 hover:bg-indigo-500',
    },
    cash_out: {
        label: 'Cash-Out (Withdraw)',
        endpoint: '/transactions/cash-out',
        counterparty: 'agentId',
        title: 'Confirm Cash-Out',
        button: 'Review Cash-Out',
        color: 'bg-amber-600 hover:bg-amber-500',
    },
    cash_in: {
        label: 'Cash-In (Deposit)',
        endpoint: '/transactions/cash-in',
        counterparty: 'agentId',
        title: 'Confirm Cash-In',
        button: 'Review Cash-In',
        color: 'bg-emerald-600 hover:bg-emerald-500',
    },
};

const current = computed(() => tabConfig[activeTab.value]);

const validationSchema = computed(() => ({
    [current.value.counterparty]: positiveInteger(current.value.counterparty === 'recipientId' ? 'Recipient user ID' : 'Agent user ID'),
    amount: money('Amount'),
}));

const { handleSubmit, setErrors, resetForm, values } = useForm<{ recipientId: string; agentId: string; amount: string }>({
    validationSchema,
    initialValues: { recipientId: '', agentId: '', amount: '' },
});

const serverErrors = ref<Record<string, string>>({});

watch(activeTab, () => {
    resetForm();
    serverErrors.value = {};
});

const estimatedFee = computed(() => {
    const amount = Number(values.amount);
    if (activeTab.value !== 'cash_out' || feeRate.value === null || !(amount > 0)) {
        return null;
    }
    return amount * feeRate.value;
});

const counterpartyLabel = computed(() => (current.value.counterparty === 'recipientId' ? 'Recipient' : 'Agent'));

const onValid = handleSubmit(() => {
    confirmOpen.value = true;
});

const submit = async () => {
    submitting.value = true;
    serverErrors.value = {};
    try {
        const body: Record<string, unknown> = {
            amount: Number(values.amount),
            idempotency_key: crypto.randomUUID(),
        };
        if (current.value.counterparty === 'recipientId') {
            body.recipient_id = Number(values.recipientId);
        } else {
            body.agent_id = Number(values.agentId);
        }
        const res = await api.post(current.value.endpoint, body);
        const txId = res.data.data?.id ?? '';
        toast.success(`${current.value.label.split(' (')[0]} of ৳${Number(values.amount).toFixed(2)} completed (Tx #${txId}).`);
        confirmOpen.value = false;
        resetForm();
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Transaction failed.');
        confirmOpen.value = false;
        const mapped: Record<string, string> = {};
        if (fieldErrors.recipient_id) mapped.recipientId = fieldErrors.recipient_id;
        if (fieldErrors.agent_id) mapped.agentId = fieldErrors.agent_id;
        if (fieldErrors.amount) mapped.amount = fieldErrors.amount;
        setErrors(mapped);
        toast.error(message);
    } finally {
        submitting.value = false;
    }
};

onMounted(async () => {
    try {
        const res = await api.get('/system-settings');
        const rate = res.data.data?.settings?.system_fee_rate;
        feeRate.value = rate !== undefined ? Number(rate) : null;
    } catch {
        feeRate.value = null; // Settings may be admin-only; the server still applies the real fee.
    }
});
</script>

<template>
    <Head title="Send Money & Transfers - WalletMS" />
    <UserLayout>
        <div class="max-w-2xl mx-auto space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">Financial Movements</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Transfer money directly to users or transact with registered financial agents
                </p>
            </div>

            <div class="flex rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
                <button
                    v-for="(cfg, key) in tabConfig"
                    :key="key"
                    type="button"
                    class="flex-1 rounded-lg py-2.5 text-xs font-semibold transition"
                    :class="
                        activeTab === key
                            ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100'
                            : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400'
                    "
                    @click="activeTab = key"
                >
                    {{ cfg.label }}
                </button>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <form class="space-y-4" novalidate @submit.prevent="onValid">
                    <div
                        v-if="activeTab === 'cash_out'"
                        class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
                    >
                        <template v-if="feeRate !== null">
                            Cash-Out incurs a <strong>{{ (feeRate * 100).toFixed(2) }}% system fee</strong> on top of the amount.
                        </template>
                        <template v-else>Cash-Out incurs a system fee, added on top of the amount. The exact fee is shown on your receipt.</template>
                    </div>
                    <div
                        v-if="activeTab === 'cash_in'"
                        class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300"
                    >
                        Cash-In is free of charge. You receive the exact amount loaded by the agent.
                    </div>

                    <TextField
                        v-if="current.counterparty === 'recipientId'"
                        name="recipientId"
                        label="Recipient User ID"
                        type="number"
                        min="1"
                        inputmode="numeric"
                        placeholder="e.g. 2"
                    />
                    <TextField v-else name="agentId" label="Agent User ID" type="number" min="1" inputmode="numeric" placeholder="e.g. 3" />

                    <div>
                        <TextField name="amount" label="Amount (BDT)" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="500.00" />
                        <p v-if="estimatedFee !== null" class="mt-1 text-xs text-zinc-500">
                            Estimated fee: ৳{{ estimatedFee.toFixed(2) }} (total deduction: ৳{{ (Number(values.amount) + estimatedFee).toFixed(2) }})
                        </p>
                    </div>

                    <button
                        type="submit"
                        :disabled="submitting"
                        class="w-full rounded-lg py-2.5 text-sm font-semibold text-white shadow-xs transition disabled:opacity-50"
                        :class="current.color"
                    >
                        {{ current.button }}
                    </button>
                </form>
            </div>
        </div>

        <ConfirmModal
            :open="confirmOpen"
            :title="current.title"
            :loading="submitting"
            confirm-label="Confirm & Submit"
            @cancel="confirmOpen = false"
            @confirm="submit"
        >
            <dl class="space-y-2">
                <div class="flex justify-between">
                    <dt class="text-zinc-500">{{ counterpartyLabel }} user ID</dt>
                    <dd class="font-semibold">#{{ current.counterparty === 'recipientId' ? values.recipientId : values.agentId }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-zinc-500">Amount</dt>
                    <dd class="font-semibold">৳{{ Number(values.amount || 0).toFixed(2) }}</dd>
                </div>
                <div v-if="estimatedFee !== null" class="flex justify-between">
                    <dt class="text-zinc-500">Estimated fee</dt>
                    <dd class="font-semibold">৳{{ estimatedFee.toFixed(2) }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-zinc-500">This action cannot be undone once submitted.</p>
        </ConfirmModal>
    </UserLayout>
</template>
