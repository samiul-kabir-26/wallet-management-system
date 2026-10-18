<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { useAuthStore } from '@/stores/auth';
import { parseApiError } from '@/composables/useApiError';
import { rate, type Rule } from '@/composables/validators';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Skeleton from '@/components/Skeleton.vue';
import TextField from '@/components/Forms/TextField.vue';
import SessionTimeoutForm from '@/components/Forms/SessionTimeoutForm.vue';

useAuthGuard('ADMIN');

const authStore = useAuthStore();
const toast = useToastStore();
const loading = ref(true);
const saving = ref(false);

const bonusEnabled = ref(true);

/** Signup bonus: 0 or more, at most 2 decimal places. Zero is allowed and means "no bonus". */
const bonusAmountRule: Rule = (value) => {
    if (value === undefined || value === null || String(value).trim() === '') {
        return 'Signup bonus amount is required.';
    }
    return /^\d+(\.\d{1,2})?$/.test(String(value)) ? true : 'Signup bonus amount must be 0 or more, with at most 2 decimal places.';
};

/** Daily bonus budget: 0 (no limit) or more, at most 2 decimal places. */
const bonusBudgetRule: Rule = (value) => {
    if (value === undefined || value === null || String(value).trim() === '') {
        return 'Daily budget is required (0 means no limit).';
    }
    return /^\d+(\.\d{1,2})?$/.test(String(value)) ? true : 'Daily budget must be 0 or more, with at most 2 decimal places.';
};

const paidToday = ref(0);
const clawbackDays = ref(30);

const { handleSubmit, setValues, setErrors, values } = useForm<{ feeRate: string; commissionRate: string; bonusAmount: string; bonusBudget: string }>({
    validationSchema: {
        feeRate: rate('Transaction fee rate'),
        commissionRate: rate('Agent commission rate'),
        bonusAmount: bonusAmountRule,
        bonusBudget: bonusBudgetRule,
    },
    initialValues: { feeRate: '', commissionRate: '', bonusAmount: '', bonusBudget: '0.00' },
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
                bonusAmount: Number(settings.signup_bonus_amount ?? 0).toFixed(2),
                bonusBudget: Number(settings.signup_bonus_daily_budget ?? 0).toFixed(2),
            });
            paidToday.value = Number(settings.signup_bonus_paid_today ?? 0);
            clawbackDays.value = Number(settings.signup_bonus_clawback_days ?? 30);
            bonusEnabled.value = Boolean(settings.signup_bonus_enabled);
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
        const res = await api.patch('/system-settings', {
            system_fee_rate: Number(formValues.feeRate),
            agent_commission_rate: Number(formValues.commissionRate),
            signup_bonus_enabled: bonusEnabled.value,
            signup_bonus_amount: Number(formValues.bonusAmount),
            signup_bonus_daily_budget: Number(formValues.bonusBudget),
        });
        if (res.status === 202) {
            toast.info('Changes submitted for approval. They take effect once another staff member approves them.');
        } else {
            toast.success(res.data.message ?? 'System settings updated successfully!');
        }
        await fetchSettings();
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to update system settings.');
        const mapped: Record<string, string> = {};
        if (fieldErrors.system_fee_rate) mapped.feeRate = fieldErrors.system_fee_rate;
        if (fieldErrors.agent_commission_rate) mapped.commissionRate = fieldErrors.agent_commission_rate;
        if (fieldErrors.signup_bonus_amount) mapped.bonusAmount = fieldErrors.signup_bonus_amount;
        if (fieldErrors.signup_bonus_daily_budget) mapped.bonusBudget = fieldErrors.signup_bonus_daily_budget;
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

                    <div class="space-y-2 border-t border-zinc-100 pt-6 dark:border-zinc-800">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Signup Bonus (BDT)</p>
                                <p class="text-xs text-zinc-500">
                                    Credited to every new user and agent wallet at registration, recorded as a SIGNUP_BONUS transaction. Changes apply to new accounts only.
                                </p>
                            </div>
                            <label class="inline-flex shrink-0 cursor-pointer items-center gap-2">
                                <input
                                    v-model="bonusEnabled"
                                    type="checkbox"
                                    role="switch"
                                    :aria-checked="bonusEnabled"
                                    :disabled="!authStore.can('settings.update')"
                                    class="peer sr-only"
                                />
                                <span
                                    class="relative h-6 w-11 rounded-full bg-zinc-300 transition after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:bg-indigo-600 peer-checked:after:translate-x-5 peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500 peer-disabled:opacity-50 dark:bg-zinc-700"
                                ></span>
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ bonusEnabled ? 'Enabled' : 'Disabled' }}</span>
                            </label>
                        </div>
                        <div class="w-48">
                            <TextField name="bonusAmount" label="Bonus amount" type="number" step="0.01" min="0" inputmode="decimal" />
                        </div>
                        <div class="w-48">
                            <TextField name="bonusBudget" label="Daily budget (0 = no limit)" type="number" step="0.01" min="0" inputmode="decimal" />
                        </div>
                    </div>
                    <p class="text-xs text-zinc-500">
                        Paid today: ৳{{ paidToday.toFixed(2) }}<template v-if="Number(values.bonusBudget) > 0"> of ৳{{ Number(values.bonusBudget).toFixed(2) }}</template>.
                        Once the budget is used up, new accounts still register but get no bonus until tomorrow. Staff can claw a bonus back from
                        the transaction ledger within {{ clawbackDays }} days.
                    </p>

                    <div v-if="authStore.can('settings.update')" class="flex justify-end border-t border-zinc-100 pt-6 dark:border-zinc-800">
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

            <SessionTimeoutForm />
        </div>
    </AdminLayout>
</template>
