<script setup lang="ts">
import { ref, watch } from 'vue';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import { useToastStore } from '@/stores/toast';
import Alert from '@/components/Alert.vue';
import Modal from '@/components/Modal.vue';
import type { KycTier, KycTierInfo, UserProfile } from '@/types/models';

/**
 * Staff tool (users.manage_kyc) to move a customer to another KYC tier or give them custom limits.
 */
const props = defineProps<{
    show: boolean;
    profile: UserProfile;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'updated', user: UserProfile): void;
}>();

// Tier limits come from config/kyc.php through the API, so this list never drifts from the server
const tiers = ref<KycTierInfo[]>([]);
const bdt = (n: number) => `৳${n.toLocaleString('en-US')}`;
const needsVerification = (tier: KycTier) => tiers.value.find((t) => t.tier === tier)?.requires_verification ?? true;

const loadTiers = async () => {
    if (tiers.value.length > 0) return;
    try {
        tiers.value = (await api.get('/users/me/kyc')).data.data.tiers as KycTierInfo[];
    } catch {
        error.value = 'Could not load the tier list.';
    }
};

const toast = useToastStore();
const mode = ref<'tier' | 'custom'>('tier');
const tier = ref<KycTier>('BASIC');
const dailyCap = ref('');
const monthlyCap = ref('');
const reason = ref('');
const error = ref<string | null>(null);
const saving = ref(false);

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        loadTiers();
        mode.value = 'tier';
        tier.value = props.profile.kyc?.tier ?? 'BASIC';
        dailyCap.value = String(Number(props.profile.caps?.daily_cap ?? 0));
        monthlyCap.value = String(Number(props.profile.caps?.monthly_cap ?? 0));
        reason.value = '';
        error.value = null;
    },
);

const save = async () => {
    if (reason.value.trim().length < 5) {
        error.value = 'Give a reason of at least 5 characters; it is kept in the audit log.';
        return;
    }
    saving.value = true;
    error.value = null;
    try {
        const res =
            mode.value === 'tier'
                ? await api.patch(`/users/${props.profile.id}/kyc-tier`, { tier: tier.value, reason: reason.value.trim() })
                : await api.patch(`/users/${props.profile.id}/caps`, {
                      daily_cap: Number(dailyCap.value).toFixed(2),
                      monthly_cap: Number(monthlyCap.value).toFixed(2),
                      reason: reason.value.trim(),
                  });
        toast.success(res.data.message ?? 'Limits updated.');
        emit('updated', res.data.data.user as UserProfile);
        emit('close');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to update the limits.');
        error.value = fieldErrors.tier ?? fieldErrors.daily_cap ?? fieldErrors.monthly_cap ?? fieldErrors.reason ?? message;
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <Modal :show="show" title="KYC Tier & Limits" @close="emit('close')">
        <div class="space-y-4 text-sm">
            <div class="flex gap-2">
                <button
                    v-for="option in [
                        { key: 'tier', label: 'Change tier' },
                        { key: 'custom', label: 'Custom limits' },
                    ] as const"
                    :key="option.key"
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-xs font-semibold"
                    :class="
                        mode === option.key
                            ? 'border-indigo-600 bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300'
                            : 'border-zinc-300 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300'
                    "
                    @click="mode = option.key"
                >
                    {{ option.label }}
                </button>
            </div>

            <div v-if="mode === 'tier'" class="space-y-2">
                <label
                    v-for="option in tiers"
                    :key="option.tier"
                    class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                    :class="{ 'opacity-50': needsVerification(option.tier) && !profile.kyc?.verified_at }"
                >
                    <input
                        v-model="tier"
                        type="radio"
                        :value="option.tier"
                        class="mt-0.5"
                        :disabled="needsVerification(option.tier) && !profile.kyc?.verified_at"
                    />
                    <span>
                        <span class="block font-medium text-zinc-900 dark:text-zinc-100">{{ option.label }}</span>
                        <span class="block text-xs text-zinc-500">{{ bdt(option.daily_cap) }} / day · {{ bdt(option.monthly_cap) }} / month</span>
                        <span v-if="needsVerification(option.tier) && !profile.kyc?.verified_at" class="block text-xs text-amber-600">
                            Needs verified identity documents
                        </span>
                    </span>
                </label>
                <p class="text-xs text-zinc-500">Changing the tier resets any custom limits to the tier's limits. Usage so far is kept.</p>
            </div>

            <div v-else class="grid grid-cols-2 gap-3">
                <label class="space-y-1">
                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Daily limit (BDT)</span>
                    <input
                        v-model="dailyCap"
                        type="number"
                        min="0"
                        step="0.01"
                        class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </label>
                <label class="space-y-1">
                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Monthly limit (BDT)</span>
                    <input
                        v-model="monthlyCap"
                        type="number"
                        min="0"
                        step="0.01"
                        class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </label>
            </div>

            <label class="block space-y-1">
                <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Reason (kept in the audit log)</span>
                <textarea
                    v-model="reason"
                    rows="2"
                    maxlength="500"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                />
            </label>
            <Alert v-if="error" type="error" :message="error" />
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    @click="emit('close')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    :disabled="saving"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                    @click="save"
                >
                    {{ saving ? 'Saving...' : 'Save' }}
                </button>
            </div>
        </div>
    </Modal>
</template>
