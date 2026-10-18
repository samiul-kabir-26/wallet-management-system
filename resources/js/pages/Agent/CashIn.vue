<script setup lang="ts">
import { newIdempotencyKey } from '@/composables/useIdempotencyKey';
import { onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { storeToRefs } from 'pinia';
import { useForm } from 'vee-validate';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { money, phone, pin } from '@/composables/validators';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import { useWalletStore } from '@/stores/wallet';
import AgentLayout from '@/layouts/AgentLayout.vue';
import Skeleton from '@/components/Skeleton.vue';
import TextField from '@/components/Forms/TextField.vue';
import ConfirmModal from '@/components/Modals/ConfirmModal.vue';

useAuthGuard('AGENT');

const toast = useToastStore();
const walletStore = useWalletStore();
const { wallet, loading: walletLoading } = storeToRefs(walletStore);
const loading = ref(false);
const confirmOpen = ref(false);

const customerName = ref<string | null>(null);
const canReceive = ref<boolean | null>(null);
const lookingUpCustomer = ref(false);
const customerLookupError = ref<string | null>(null);

const { handleSubmit, resetForm, setErrors, values } = useForm<{ customerPhone: string; amount: string; pin: string; description?: string }>({
    validationSchema: {
        customerPhone: phone,
        amount: money('Cash-in amount'),
        pin,
    },
    initialValues: { customerPhone: '', amount: '', pin: '', description: '' },
});

let lookupTimer: ReturnType<typeof setTimeout> | null = null;
watch(() => values.customerPhone, (newPhone) => {
    customerName.value = null;
    canReceive.value = null;
    customerLookupError.value = null;
    if (lookupTimer) {
        clearTimeout(lookupTimer);
    }

    if (!newPhone || !/^01[3-9]\d{8}$/.test(newPhone)) {
        return;
    }

    lookupTimer = setTimeout(async () => {
        lookingUpCustomer.value = true;
        try {
            const res = await api.get('/users/lookup', { params: { phone_number: newPhone } });
            customerName.value = res.data.data?.name ?? null;
            canReceive.value = Boolean(res.data.data?.can_receive);
            if (canReceive.value === false) {
                customerLookupError.value = 'Recipient cannot receive transfers (account inactive or wallet blocked).';
            }
        } catch {
            customerName.value = null;
            canReceive.value = false;
            customerLookupError.value = 'Customer not found.';
        } finally {
            lookingUpCustomer.value = false;
        }
    }, 300);
});

const onValid = handleSubmit(() => {
    if (canReceive.value === false) {
        toast.error('The recipient cannot receive funds.');
        return;
    }
    confirmOpen.value = true;
});

const submit = async () => {
    loading.value = true;
    try {
        const res = await api.post('/transactions/agent/cash-in', {
            customer_phone: values.customerPhone,
            amount: Number(values.amount),
            pin: values.pin,
            description: values.description || undefined,
            idempotency_key: newIdempotencyKey(),
        });
        toast.success(`Cash-in of ৳${Number(values.amount).toFixed(2)} completed (Tx #${res.data.data?.transaction?.id ?? ''}).`);
        confirmOpen.value = false;
        resetForm();
        customerName.value = null;
        canReceive.value = null;
        await walletStore.fetchWallet();
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Cash-in failed. Please verify customer phone and float balance.');
        const mapped: Record<string, string> = {};
        if (fieldErrors.customer_phone) mapped.customerPhone = fieldErrors.customer_phone;
        if (fieldErrors.amount) mapped.amount = fieldErrors.amount;
        if (fieldErrors.pin) mapped.pin = fieldErrors.pin;
        setErrors(mapped);
        confirmOpen.value = false;
        toast.error(message);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    walletStore.fetchWallet();
});
</script>

<template>
    <Head title="Customer Cash-In - WalletMS Agent" />
    <AgentLayout>
        <div class="max-w-xl mx-auto space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    Customer Cash-In (Deposit)
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Load physical cash into a registered customer's electronic wallet from your agent float
                </p>
            </div>

            <!-- Current Float Balance Card -->
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 flex justify-between items-center">
                <div>
                    <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Available Float Balance</span>
                    <Skeleton v-if="walletLoading && !wallet" class="mt-2 w-32" height="h-7" />
                    <div v-else class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mt-1">
                        ৳{{ Number(wallet?.balance ?? 0).toFixed(2) }}
                    </div>
                </div>
                <div class="text-xs text-zinc-400">
                    Currency: {{ wallet?.currency ?? 'BDT' }}
                </div>
            </div>

            <!-- Form -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <form class="space-y-4" novalidate @submit.prevent="onValid">
                    <div class="space-y-1">
                        <TextField
                            name="customerPhone"
                            label="Customer Phone Number"
                            type="tel"
                            inputmode="tel"
                            autocomplete="tel"
                            placeholder="01712345678"
                        />
                        <div v-if="lookingUpCustomer" class="text-xs text-zinc-500 animate-pulse">
                            Looking up customer...
                        </div>
                        <div v-else-if="customerName && canReceive" class="flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            Verified Customer: {{ customerName }}
                        </div>
                        <div v-else-if="customerLookupError" class="text-xs text-rose-500">
                            {{ customerLookupError }}
                        </div>
                    </div>

                    <TextField name="amount" label="Cash-In Amount (BDT)" type="number" step="0.01" min="10" max="30000" inputmode="decimal" placeholder="500.00" />

                    <TextField
                        name="pin"
                        label="Agent PIN"
                        type="password"
                        inputmode="numeric"
                        maxlength="10"
                        autocomplete="off"
                        placeholder="Enter your 5-10 digit PIN"
                    />

                    <button
                        type="submit"
                        :disabled="loading"
                        class="w-full rounded-lg bg-emerald-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-emerald-500 disabled:opacity-50"
                    >
                        {{ loading ? 'Processing...' : 'Review Cash-In' }}
                    </button>
                </form>
            </div>
        </div>

        <ConfirmModal
            :open="confirmOpen"
            title="Confirm Customer Cash-In"
            :confirm-text="loading ? 'Processing...' : 'Authorize Cash-In'"
            confirm-color="bg-emerald-600 hover:bg-emerald-500"
            @confirm="submit"
            @cancel="confirmOpen = false"
        >
            <div class="space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                <p>
                    You are loading <strong class="text-zinc-900 dark:text-zinc-100">৳{{ Number(values.amount || 0).toFixed(2) }}</strong> into the customer's wallet.
                </p>
                <p>
                    Customer: <span class="font-mono text-zinc-900 dark:text-zinc-100">{{ values.customerPhone }}</span>
                    <span v-if="customerName" class="ml-1 text-zinc-500">({{ customerName }})</span>
                </p>
                <p class="text-xs text-zinc-500">
                    This amount will be deducted 1:1 from your float balance with zero fee and zero commission. Ensure you have collected physical cash before confirming.
                </p>
            </div>
        </ConfirmModal>
    </AgentLayout>
</template>
