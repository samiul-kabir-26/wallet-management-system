<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { storeToRefs } from 'pinia';
import { useForm } from 'vee-validate';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { money } from '@/composables/validators';
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

const { handleSubmit, resetForm, setErrors, values } = useForm<{ amount: string }>({
    validationSchema: { amount: money('Withdrawal amount') },
    initialValues: { amount: '' },
});

const onValid = handleSubmit(() => {
    confirmOpen.value = true;
});

const submit = async () => {
    loading.value = true;
    try {
        const res = await api.post('/transactions/agent/withdrawal', {
            amount: Number(values.amount),
            idempotency_key: crypto.randomUUID(),
        });
        toast.success(`Bank withdrawal of ৳${Number(values.amount).toFixed(2)} initiated (Tx #${res.data.data?.id ?? ''}).`);
        confirmOpen.value = false;
        resetForm();
        await walletStore.fetchWallet();
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Withdrawal failed. Check balance and agent status.');
        if (fieldErrors.amount) {
            setErrors({ amount: fieldErrors.amount });
        }
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
    <Head title="Bank Withdrawal - WalletMS Agent" />
    <AgentLayout>
        <div class="max-w-xl mx-auto space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    Agent Bank Withdrawal
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Disburse earned commissions and wallet funds directly to your bank account
                </p>
            </div>

            <!-- Current Balance Card -->
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 flex justify-between items-center">
                <div>
                    <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Available Balance</span>
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
                    <TextField name="amount" label="Withdrawal Amount (BDT)" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="500.00" />
                    <button
                        type="submit"
                        :disabled="loading"
                        class="w-full rounded-lg bg-amber-600 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-amber-500 disabled:opacity-50 transition"
                    >
                        Review Withdrawal
                    </button>
                </form>
            </div>
        </div>
        <ConfirmModal
            :open="confirmOpen"
            title="Confirm Bank Withdrawal"
            :loading="loading"
            confirm-label="Withdraw to Bank"
            @cancel="confirmOpen = false"
            @confirm="submit"
        >
            You are about to withdraw <strong>৳{{ Number(values.amount || 0).toFixed(2) }}</strong> from your wallet to your bank account.
        </ConfirmModal>
    </AgentLayout>
</template>
