<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { money, phone, pin } from '@/composables/validators';
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

const recipientName = ref<string | null>(null);
const lookingUpRecipient = ref(false);
const recipientLookupError = ref<string | null>(null);

const tabConfig: Record<Tab, { label: string; endpoint: string; counterparty: 'recipientPhone' | 'agentPhone'; title: string; button: string; color: string }> = {
    transfer: {
        label: 'Send Money (P2P)',
        endpoint: '/transactions/transfer',
        counterparty: 'recipientPhone',
        title: 'Confirm Transfer',
        button: 'Send Money Now',
        color: 'bg-indigo-600 hover:bg-indigo-500',
    },
    cash_out: {
        label: 'Cash-Out (Withdraw)',
        endpoint: '/transactions/cash-out',
        counterparty: 'agentPhone',
        title: 'Confirm Cash-Out',
        button: 'Review Cash-Out',
        color: 'bg-amber-600 hover:bg-amber-500',
    },
    cash_in: {
        label: 'Cash-In (Deposit)',
        endpoint: '/transactions/cash-in',
        counterparty: 'agentPhone',
        title: 'Confirm Cash-In',
        button: 'Review Cash-In',
        color: 'bg-emerald-600 hover:bg-emerald-500',
    },
};

const current = computed(() => tabConfig[activeTab.value]);

const validationSchema = computed(() => {
    const rules: Record<string, unknown> = {
        [current.value.counterparty]: phone,
        amount: money('Amount'),
    };
    if (activeTab.value !== 'cash_in') {
        rules.pin = pin;
    }
    return rules;
});

const { handleSubmit, setErrors, resetForm, values } = useForm<{ recipientPhone: string; agentPhone: string; amount: string; pin: string }>({
    validationSchema,
    initialValues: { recipientPhone: '', agentPhone: '', amount: '', pin: '' },
});

const serverErrors = ref<Record<string, string>>({});

let lookupTimer: ReturnType<typeof setTimeout> | null = null;
watch(() => values.recipientPhone, (newPhone) => {
    recipientName.value = null;
    recipientLookupError.value = null;
    if (lookupTimer) {
        clearTimeout(lookupTimer);
    }

    if (activeTab.value !== 'transfer' || !newPhone) {
        return;
    }

    if (!/^01[3-9]\d{8}$/.test(newPhone)) {
        return;
    }

    lookupTimer = setTimeout(async () => {
        lookingUpRecipient.value = true;
        try {
            const res = await api.get('/users/lookup', { params: { phone_number: newPhone } });
            recipientName.value = res.data.data?.name ?? null;
        } catch {
            recipientName.value = null;
            recipientLookupError.value = 'Recipient not found';
        } finally {
            lookingUpRecipient.value = false;
        }
    }, 300);
});

watch(activeTab, () => {
    resetForm();
    serverErrors.value = {};
    recipientName.value = null;
    recipientLookupError.value = null;
    if (lookupTimer) {
        clearTimeout(lookupTimer);
    }
});

const estimatedFee = computed(() => {
    const amount = Number(values.amount);
    if (activeTab.value !== 'cash_out' || feeRate.value === null || !(amount > 0)) {
        return null;
    }
    return amount * feeRate.value;
});

const counterpartyLabel = computed(() => (current.value.counterparty === 'recipientPhone' ? 'Recipient' : 'Agent'));

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
        if (current.value.counterparty === 'recipientPhone') {
            body.recipient_phone = values.recipientPhone;
        } else {
            body.agent_phone = values.agentPhone;
        }
        if (activeTab.value !== 'cash_in') {
            body.pin = values.pin;
        }
        const res = await api.post(current.value.endpoint, body);
        const txId = res.data.data?.transaction?.id ?? '';
        toast.success(`${current.value.label.split(' (')[0]} of ৳${Number(values.amount).toFixed(2)} completed (Tx #${txId}).`);
        confirmOpen.value = false;
        recipientName.value = null;
        recipientLookupError.value = null;
        resetForm();
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Transaction failed.');
        confirmOpen.value = false;
        const mapped: Record<string, string> = {};
        if (fieldErrors.recipient_phone) mapped.recipientPhone = fieldErrors.recipient_phone;
        if (fieldErrors.agent_phone) mapped.agentPhone = fieldErrors.agent_phone;
        if (fieldErrors.amount) mapped.amount = fieldErrors.amount;
        if (fieldErrors.pin) mapped.pin = fieldErrors.pin;
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

                    <div v-if="current.counterparty === 'recipientPhone'" class="space-y-1">
                        <TextField
                            name="recipientPhone"
                            label="Recipient Phone Number"
                            type="tel"
                            inputmode="tel"
                            autocomplete="tel"
                            placeholder="01712345678"
                        />
                        <div v-if="lookingUpRecipient" class="text-xs text-zinc-500 animate-pulse">
                            Checking recipient...
                        </div>
                        <div v-else-if="recipientName" class="flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            Recipient: {{ recipientName }}
                        </div>
                        <div v-else-if="recipientLookupError" class="text-xs text-rose-500">
                            {{ recipientLookupError }}
                        </div>
                    </div>
                    <TextField
                        v-else
                        name="agentPhone"
                        label="Agent Phone Number"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        placeholder="01712345678"
                    />

                    <div>
                        <TextField name="amount" label="Amount (BDT)" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="500.00" />
                        <p v-if="estimatedFee !== null" class="mt-1 text-xs text-zinc-500">
                            Estimated fee: ৳{{ estimatedFee.toFixed(2) }} (total deduction: ৳{{ (Number(values.amount) + estimatedFee).toFixed(2) }})
                        </p>
                    </div>

                    <TextField
                        v-if="activeTab !== 'cash_in'"
                        name="pin"
                        label="Security PIN"
                        type="password"
                        inputmode="numeric"
                        maxlength="10"
                        autocomplete="off"
                        placeholder="Enter 5-10 digit PIN"
                    />

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
                    <dt class="text-zinc-500">{{ counterpartyLabel }} phone number</dt>
                    <dd class="font-semibold">{{ current.counterparty === 'recipientPhone' ? values.recipientPhone : values.agentPhone }}</dd>
                </div>
                <div v-if="current.counterparty === 'recipientPhone' && recipientName" class="flex justify-between">
                    <dt class="text-zinc-500">Recipient Name</dt>
                    <dd class="font-semibold text-zinc-900 dark:text-zinc-100">{{ recipientName }}</dd>
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
