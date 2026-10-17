<script setup lang="ts">
import { useForm } from 'vee-validate';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import { parseApiError } from '@/composables/useApiError';
import { pin, pinConfirmation, type Rule } from '@/composables/validators';
import TextField from '@/components/Forms/TextField.vue';

const toast = useToastStore();

const differentFromCurrent: Rule = (value, ctx) => {
    const valid = pin(value);
    if (valid !== true) {
        return valid;
    }
    return String(value) !== String(ctx?.form.current_pin ?? '') ? true : 'The new PIN must be different from your current PIN.';
};

const { handleSubmit, isSubmitting, resetForm, setErrors } = useForm<{
    current_pin: string;
    new_pin: string;
    new_pin_confirmation: string;
}>({
    validationSchema: {
        current_pin: pin,
        new_pin: differentFromCurrent,
        new_pin_confirmation: pinConfirmation('new_pin'),
    },
    initialValues: { current_pin: '', new_pin: '', new_pin_confirmation: '' },
});

const onSubmit = handleSubmit(async (values) => {
    try {
        const res = await api.post('/auth/change-pin', values);
        resetForm();
        toast.success(res.data.message ?? 'PIN changed.');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to change PIN.');
        // A wrong current PIN comes back under "pin"
        if (fieldErrors.pin) {
            fieldErrors.current_pin = fieldErrors.pin;
        }
        setErrors(fieldErrors);
        toast.error(message);
    }
});
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="onSubmit">
        <TextField name="current_pin" label="Current PIN" type="password" inputmode="numeric" autocomplete="current-password" />
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <TextField name="new_pin" label="New PIN (5-10 digits)" type="password" inputmode="numeric" autocomplete="new-password" />
            <TextField name="new_pin_confirmation" label="Confirm new PIN" type="password" inputmode="numeric" autocomplete="new-password" />
        </div>
        <p class="text-xs text-zinc-500">
            This PIN signs you in and approves your transactions. Never share it, including with support staff. Other devices
            are signed out after the change.
        </p>
        <div class="flex justify-end">
            <button
                type="submit"
                :disabled="isSubmitting"
                class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50"
            >
                {{ isSubmitting ? 'Saving...' : 'Change PIN' }}
            </button>
        </div>
    </form>
</template>
