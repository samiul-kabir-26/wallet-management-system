<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import { useToastStore } from '@/stores/toast';
import Alert from '@/components/Alert.vue';
import Badge from '@/components/Badge.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { KycSubmission, KycTier, KycTierInfo } from '@/types/models';

/**
 * Customer view of their KYC tier and limits, with the identity document upload that raises them.
 */
interface KycStatus {
    tier: KycTier;
    label: string;
    verified_at: string | null;
    custom_limits: boolean;
    caps: { daily_limit: number; monthly_limit: number };
    tiers: KycTierInfo[];
    latest_submission: KycSubmission | null;
}

const MAX_BYTES = 5 * 1024 * 1024;
const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

const toast = useToastStore();
const status = ref<KycStatus | null>(null);
const loading = ref(true);
const submitting = ref(false);
const formError = ref<string | null>(null);
const form = ref({ document_type: 'NID', document_number: '', full_name: '', date_of_birth: '' });
const front = ref<File | null>(null);
const back = ref<File | null>(null);

const pending = computed(() => status.value?.latest_submission?.status === 'PENDING');
const canSubmit = computed(() => !!status.value && !status.value.verified_at && !pending.value);
const bdt = (n: number) => `৳${n.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;

const load = async () => {
    loading.value = true;
    try {
        status.value = (await api.get('/users/me/kyc')).data.data as KycStatus;
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load your verification status.').message);
    } finally {
        loading.value = false;
    }
};

const pick = (side: 'front' | 'back', event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    formError.value = null;
    if (file && (!TYPES.includes(file.type) || file.size > MAX_BYTES)) {
        formError.value = 'Each photo must be a JPG, PNG or WebP image up to 5 MB.';
        return;
    }
    if (side === 'front') front.value = file;
    else back.value = file;
};

const submit = async () => {
    if (!front.value || !back.value) {
        formError.value = 'Add a photo of the front and the back of the document.';
        return;
    }
    const data = new FormData();
    Object.entries(form.value).forEach(([key, value]) => data.append(key, value.trim()));
    data.append('front', front.value);
    data.append('back', back.value);

    submitting.value = true;
    formError.value = null;
    try {
        await api.post('/users/me/kyc', data, { headers: { 'Content-Type': 'multipart/form-data' } });
        toast.success('Documents submitted. You will get an SMS once they are reviewed.');
        await load();
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to submit your documents.');
        formError.value = Object.values(fieldErrors)[0] ?? message;
    } finally {
        submitting.value = false;
    }
};

onMounted(load);
</script>

<template>
    <div class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <Skeleton v-if="loading && !status" :rows="3" height="h-6" />
        <template v-else-if="status">
            <div class="flex items-start justify-between gap-4">
                <div class="space-y-1">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Verification &amp; limits</p>
                    <p class="text-xs text-zinc-500">
                        {{ bdt(status.caps.daily_limit) }} per day · {{ bdt(status.caps.monthly_limit) }} per month for cash-outs and transfers
                        <span v-if="status.custom_limits">(custom limits set by customer care)</span>
                    </p>
                </div>
                <Badge :variant="status.verified_at ? 'success' : 'warning'">{{ status.label }}</Badge>
            </div>

            <ul class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <li
                    v-for="tier in status.tiers"
                    :key="tier.tier"
                    class="rounded-lg border p-3 text-xs"
                    :class="tier.tier === status.tier ? 'border-indigo-500 bg-indigo-50/60 dark:bg-indigo-950/30' : 'border-zinc-200 dark:border-zinc-700'"
                >
                    <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ tier.label }}</p>
                    <p class="text-zinc-500">{{ tier.description }}</p>
                    <p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ bdt(tier.daily_cap) }} / day</p>
                </li>
            </ul>

            <Alert v-if="status.verified_at" type="success" message="Your identity is verified." />
            <Alert v-else-if="pending" type="info" message="Your documents are being reviewed. You will get an SMS with the result." />
            <Alert
                v-else-if="status.latest_submission?.status === 'REJECTED'"
                type="warning"
                :message="`Your last submission was not accepted: ${status.latest_submission.review_note ?? ''} Please try again.`"
            />

            <form v-if="canSubmit" class="space-y-3 border-t border-zinc-100 pt-4 dark:border-zinc-800" novalidate @submit.prevent="submit">
                <p class="text-xs text-zinc-500">Verify your identity to move to the Standard tier and raise your limits.</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="space-y-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                        Document
                        <select
                            v-model="form.document_type"
                            class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        >
                            <option value="NID">National ID (NID)</option>
                            <option value="PASSPORT">Passport</option>
                            <option value="DRIVING_LICENSE">Driving licence</option>
                        </select>
                    </label>
                    <label class="space-y-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                        Document number
                        <input
                            v-model="form.document_number"
                            class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </label>
                    <label class="space-y-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                        Name as on the document
                        <input
                            v-model="form.full_name"
                            class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </label>
                    <label class="space-y-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                        Date of birth
                        <input
                            v-model="form.date_of_birth"
                            type="date"
                            class="block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </label>
                    <label class="space-y-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                        Photo of the front
                        <input type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-xs" @change="pick('front', $event)" />
                    </label>
                    <label class="space-y-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                        Photo of the back
                        <input type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-xs" @change="pick('back', $event)" />
                    </label>
                </div>
                <Alert v-if="formError" type="error" :message="formError" />
                <div class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {{ submitting ? 'Submitting...' : 'Submit for verification' }}
                    </button>
                </div>
            </form>
        </template>
    </div>
</template>
