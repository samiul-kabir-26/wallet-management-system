<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from 'vee-validate';
import Modal from '@/components/Modal.vue';
import TextField from '@/components/Forms/TextField.vue';
import { parseApiError } from '@/composables/useApiError';
import { money, required } from '@/composables/validators';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import type { User } from '@/types/models';

const props = defineProps<{
    agent: User | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'issued'): void;
}>();

const toast = useToastStore();
const loading = ref(false);

/**
 * One idempotency key per issuance attempt: retrying after a network error reuses it,
 * so the server returns the original transaction instead of crediting the agent twice.
 */
const idempotencyKey = ref('');

const { handleSubmit, resetForm, setErrors } = useForm<{ amount: string; externalReference: string; description: string }>({
    validationSchema: {
        amount: money('Amount'),
        externalReference: required('Bank deposit reference'),
    },
    initialValues: { amount: '', externalReference: '', description: '' },
});

watch(
    () => props.agent,
    (agent) => {
        if (agent) {
            idempotencyKey.value = crypto.randomUUID();
            resetForm();
        }
    },
);

const handleIssue = handleSubmit(async (values) => {
    if (!props.agent) return;
    loading.value = true;
    try {
        await api.post('/transactions/admin/float-issue', {
            agent_id: props.agent.id,
            amount: Number(values.amount).toFixed(2),
            external_reference: values.externalReference.trim(),
            description: values.description || null,
            idempotency_key: idempotencyKey.value,
        });
        toast.success(`Issued ৳${Number(values.amount).toFixed(2)} float to ${props.agent.name}.`);
        emit('issued');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to issue float.');
        const mapped: Record<string, string> = {};
        if (fieldErrors.amount) mapped.amount = fieldErrors.amount;
        if (fieldErrors.external_reference) mapped.externalReference = fieldErrors.external_reference;
        if (fieldErrors.description) mapped.description = fieldErrors.description;
        if (Object.keys(mapped).length > 0) {
            setErrors(mapped);
        }
        toast.error(message);
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <Modal :show="!!agent" title="Issue Agent Float" @close="emit('close')">
        <form class="space-y-4" novalidate @submit.prevent="handleIssue">
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Credit e-money float to <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ agent?.name }}</span>
                ({{ agent?.phone_number }}). Only issue float after the agent's bank deposit has been confirmed.
            </p>

            <TextField name="amount" label="Amount (BDT)" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="10000.00" />
            <TextField name="externalReference" label="Bank Deposit Reference" placeholder="e.g. BANK-SLIP-2026-00042" />
            <TextField name="description" label="Note (optional)" placeholder="Branch, cheque number, etc." />

            <div class="flex justify-end gap-2 pt-2">
                <button
                    type="button"
                    class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="emit('close')"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :disabled="loading"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    {{ loading ? 'Issuing...' : 'Issue Float' }}
                </button>
            </div>
        </form>
    </Modal>
</template>
