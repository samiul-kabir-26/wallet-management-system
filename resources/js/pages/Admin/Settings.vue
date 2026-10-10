<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { rate } from '@/composables/validators';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Skeleton from '@/components/Skeleton.vue';
import TextField from '@/components/Forms/TextField.vue';

useAuthGuard('ADMIN');

const toast = useToastStore();
const loading = ref(true);
const saving = ref(false);

const { handleSubmit, setValues, setErrors, values } = useForm<{ feeRate: string; commissionRate: string }>({
    validationSchema: {
        feeRate: rate('Transaction fee rate'),
        commissionRate: rate('Agent commission rate'),
    },
    initialValues: { feeRate: '', commissionRate: '' },
});

const asPercent = (value: string): string => (value === '' || Number.isNaN(Number(value)) ? '-' : `${(Number(value) * 100).toFixed(2)}%`);

const fetchSettings = async () => {
    loading.value = true;
    try {
        const res = await api.get('/system-settings');
        const settings = res.data.data?.settings;
        if (settings) {
            setValues({
                feeRate: String(settings.system_fee_rate),
                commissionRate: String(settings.agent_commission_rate),
            });
        }
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to fetch system settings.').message);
    } finally {
        loading.value = false;
    }
};

const handleSaveSettings = handleSubmit(async (formValues) => {
    saving.value = true;
    try {
        await api.patch('/system-settings', {
            system_fee_rate: Number(formValues.feeRate),
            agent_commission_rate: Number(formValues.commissionRate),
        });
        toast.success('System settings updated successfully!');
        await fetchSettings();
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to update system settings.');
        const mapped: Record<string, string> = {};
        if (fieldErrors.system_fee_rate) mapped.feeRate = fieldErrors.system_fee_rate;
        if (fieldErrors.agent_commission_rate) mapped.commissionRate = fieldErrors.agent_commission_rate;
        setErrors(mapped);
        toast.error(message);
    } finally {
        saving.value = false;
    }
});

onMounted(() => {
    fetchSettings();
});
</script>

<template>
    <Head title="System Settings - WalletMS Admin" />
    <AdminLayout>
        <div class="max-w-3xl space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    Financial System Configuration
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Define platform fee rates and initial commission defaults applied across operations
                </p>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <Skeleton v-if="loading" :rows="4" height="h-8" />
                <form v-else class="space-y-6" novalidate @submit.prevent="handleSaveSettings">
                    <div class="space-y-2">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Global Transaction Fee Rate (Decimal, 0-1)</p>
                        <p class="text-xs text-zinc-500">
                            Applied to cash-out withdrawals. For example, 0.02 represents a 2% system fee charged to the customer.
                        </p>
                        <div class="flex items-start gap-3">
                            <div class="w-48">
                                <TextField name="feeRate" label="Fee rate" type="number" step="0.0001" min="0" inputmode="decimal" />
                            </div>
                            <span class="pt-7 text-sm font-semibold text-zinc-700 dark:text-zinc-300">= {{ asPercent(values.feeRate) }}</span>
                        </div>
                    </div>

                    <div class="space-y-2 border-t border-zinc-100 pt-6 dark:border-zinc-800">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Default Agent Commission Rate (Decimal, 0-1)</p>
                        <p class="text-xs text-zinc-500">
                            Used as the default commission rate when onboarding and approving newly registered agents.
                        </p>
                        <div class="flex items-start gap-3">
                            <div class="w-48">
                                <TextField name="commissionRate" label="Commission rate" type="number" step="0.0001" min="0" inputmode="decimal" />
                            </div>
                            <span class="pt-7 text-sm font-semibold text-zinc-700 dark:text-zinc-300">= {{ asPercent(values.commissionRate) }}</span>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-zinc-100 pt-6 dark:border-zinc-800">
                        <button
                            type="submit"
                            :disabled="saving"
                            class="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {{ saving ? 'Saving Changes...' : 'Save System Settings' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
