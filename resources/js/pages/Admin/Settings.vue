<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { api } from '@/services/api';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Alert from '@/components/Alert.vue';

useAuthGuard('ADMIN');

const transactionFeeRate = ref<number>(0.05);
const agentCommissionRate = ref<number>(0.01);
const loading = ref(true);
const saving = ref(false);
const successMessage = ref<string | null>(null);
const errorMessage = ref<string | null>(null);

const fetchSettings = async () => {
    loading.value = true;
    errorMessage.value = null;
    try {
        const res = await api.get('/system-settings');
        const settings = res.data.data?.settings;
        if (settings) {
            transactionFeeRate.value = Number(settings.transaction_fee_rate);
            agentCommissionRate.value = Number(settings.agent_commission_rate);
        }
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Failed to fetch system settings.';
    } finally {
        loading.value = false;
    }
};

const handleSaveSettings = async () => {
    saving.value = true;
    errorMessage.value = null;
    successMessage.value = null;
    try {
        await api.patch('/system-settings', {
            transaction_fee_rate: Number(transactionFeeRate.value),
            agent_commission_rate: Number(agentCommissionRate.value),
        });
        successMessage.value = 'System settings updated successfully!';
        await fetchSettings();
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Failed to update system settings.';
    } finally {
        saving.value = false;
    }
};

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

            <div v-if="successMessage" class="mb-4">
                <Alert type="success" :message="successMessage" dismissible @close="successMessage = null" />
            </div>
            <div v-if="errorMessage" class="mb-4">
                <Alert type="error" :message="errorMessage" dismissible @close="errorMessage = null" />
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <form class="space-y-6" @submit.prevent="handleSaveSettings">
                    <!-- System Fee Rate -->
                    <div class="space-y-2">
                        <label class="block text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            Global Transaction Fee Rate (Decimal)
                        </label>
                        <p class="text-xs text-zinc-500">
                            Applied to cash-out withdrawals. For example, 0.05 represents a 5% system fee charged to the customer.
                        </p>
                        <div class="flex items-center gap-3">
                            <input
                                v-model="transactionFeeRate"
                                type="number"
                                step="0.001"
                                min="0"
                                max="0.5"
                                required
                                class="block w-48 rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                            <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                                = {{ (Number(transactionFeeRate) * 100).toFixed(1) }}%
                            </span>
                        </div>
                    </div>

                    <div class="border-t border-zinc-100 dark:border-zinc-800 pt-6 space-y-2">
                        <label class="block text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            Default Agent Commission Rate (Decimal)
                        </label>
                        <p class="text-xs text-zinc-500">
                            Used as the default commission rate when onboarding and approving newly registered agents.
                        </p>
                        <div class="flex items-center gap-3">
                            <input
                                v-model="agentCommissionRate"
                                type="number"
                                step="0.001"
                                min="0"
                                max="0.5"
                                required
                                class="block w-48 rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                            <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                                = {{ (Number(agentCommissionRate) * 100).toFixed(1) }}%
                            </span>
                        </div>
                    </div>

                    <div class="border-t border-zinc-100 dark:border-zinc-800 pt-6 flex justify-end">
                        <button
                            type="submit"
                            :disabled="saving || loading"
                            class="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 disabled:opacity-50 transition"
                        >
                            {{ saving ? 'Saving Changes...' : 'Save System Settings' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
